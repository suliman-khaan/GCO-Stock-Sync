<?php
/**
 * Browning connector — Sana Commerce GraphQL, one batched stock request for
 * every mapped item, authenticated via GCO_Stock_Sync_Browning_Auth_Session's
 * Microsoft refresh-token cycle. See BROWNING-CONNECTOR-PLAN.md and
 * research/BROWNING-NOTES.md for the full architecture and how it was
 * verified live before any of this was written.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Browning
 */
class GCO_Stock_Sync_Browning extends GCO_Stock_Sync_Abstract_Supplier {

	/**
	 * Default minimum percentage of mapped products that must return a
	 * usable quantity for the fetch to be accepted (the "ratio gate").
	 *
	 * @var int
	 */
	const DEFAULT_MIN_SUCCESS_RATIO = 60;

	/**
	 * Default timeout for the stock query request, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_REQUEST_TIMEOUT = 20;

	/**
	 * Maximum item numbers sent in a single stock query. The client's real
	 * catalogue is a few dozen safes, so this is a defensive cap rather than
	 * an expected code path — chunked automatically if ever exceeded.
	 *
	 * @var int
	 */
	const MAX_ITEMS_PER_REQUEST = 200;

	/**
	 * Literal GraphQL query for the stock check — confirmed working as a
	 * plain query string (unlike the SSO mutation, which requires Sana's
	 * persisted-query hash; see research/BROWNING-NOTES.md).
	 *
	 * @var string
	 */
	const STOCK_QUERY = ' query CalculatedProductStocks($options:ProductsLoadOptions!){catalog{products(options:$options){products{id inventory secondaryInventory isOrderable}}}}';

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'browning';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Browning Gun Safes', 'gco-stock-sync' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields() {
		return array(
			'refresh_token'     => array(
				'label'       => __( 'Microsoft Refresh Token', 'gco-stock-sync' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'One-time setup: log into dealer.browning.eu, open DevTools → Application → Local Storage → https://dealer.browning.eu, copy the "au.rt" value, and paste it here. The connector then refreshes itself automatically. If this ever expires, this page will show a notice asking you to repeat this step.', 'gco-stock-sync' ),
			),
			'product_map'       => array(
				'label'       => __( 'Product Map', 'gco-stock-sync' ),
				'type'        => 'textarea',
				'default'     => '',
				/* translators: example row is literal, not translatable */
				'description' => __( 'One product per line: woo_sku , browning_item_number. Blank lines and lines starting with # are ignored. Example: GCO-BR-SD14 , C192102431', 'gco-stock-sync' ),
			),
			'min_success_ratio' => array(
				'label'       => __( 'Minimum Success Ratio (%)', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_MIN_SUCCESS_RATIO,
				'description' => __( 'If fewer than this percentage of mapped products return a usable quantity, the whole fetch is rejected and nothing on the site changes.', 'gco-stock-sync' ),
			),
			'request_timeout'   => array(
				'label'       => __( 'Request Timeout (seconds)', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_REQUEST_TIMEOUT,
				'description' => __( 'Timeout for the stock query request.', 'gco-stock-sync' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_settings() {
		return array(
			'refresh_token'     => '',
			'product_map'       => '',
			'min_success_ratio' => self::DEFAULT_MIN_SUCCESS_RATIO,
			'request_timeout'   => self::DEFAULT_REQUEST_TIMEOUT,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Deliberately does not verify the refresh token still works — that
	 * would mean a live network call on every admin page load. A dead
	 * token is instead caught by fetch() and surfaced as a distinct
	 * 'needs_reauthentication' failure, with a status notice via
	 * get_status_notices().
	 */
	public function is_configured() {
		if ( '' === trim( (string) $this->get_setting( 'refresh_token', '' ) ) ) {
			return false;
		}

		$map = $this->parse_product_map( $this->get_setting( 'product_map', '' ) );

		return ! empty( $map['rows'] );
	}

	/**
	 * Status notices for the admin settings page — currently just the
	 * "needs reauthentication" warning, when applicable. Empty array in the
	 * normal case. Consumed by an optional, duck-typed hook in
	 * class-settings-page.php (added in a later phase) — safe to exist here
	 * unused until then.
	 *
	 * @return string[]
	 */
	public function get_status_notices() {
		$session = new GCO_Stock_Sync_Browning_Auth_Session( $this->get_option_name() );

		if ( ! $session->is_reauth_needed() ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %s: reason reported by Microsoft */
				__( 'Browning needs reauthentication (%s). Use the "Connect Browning" steps below to get a fresh token.', 'gco-stock-sync' ),
				$session->get_reauth_message()
			),
		);
	}

	/**
	 * Self-contained HTML block (trusted, plugin-authored — never user
	 * input) with step-by-step connect/reconnect instructions, including a
	 * bookmarklet that copies the Microsoft refresh token to the clipboard
	 * directly from an already-logged-in dealer.browning.eu tab.
	 *
	 * This does NOT change the architecture or remove the human step — the
	 * one-time (and, eventually, periodic) interactive login is unavoidable
	 * and was already promised to the client this way (see
	 * BROWNING-CONNECTOR-PLAN.md §3.1: we don't control Browning's Azure app
	 * registration's redirect URIs, so this can never be fully automated
	 * without separate hosting + automated email reading — a materially
	 * different, more expensive approach the client already declined). This
	 * only removes the DevTools/Local-Storage digging, replacing it with one
	 * click, for whoever manages this site.
	 *
	 * Consumed by an optional, duck-typed hook in class-settings-page.php's
	 * render_supplier_tab() — safe to exist here even before that hook is added.
	 *
	 * @return string
	 */
	public function get_connect_helper_html() {
		// Reads Local Storage on whatever page it's run on. Clicking it
		// directly on this WP admin page is harmless (dealer.browning.eu's
		// data simply isn't there) — the alert just says so. It only does
		// anything useful when clicked from a bookmark while actually on a
		// logged-in dealer.browning.eu tab.
		$bookmarklet_js = "javascript:(function(){try{var t=localStorage.getItem('au.rt');if(!t){alert('Could not find the refresh token on this page. Make sure you are logged into https://dealer.browning.eu/ first, then click this bookmark again.');return;}if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(function(){alert('Browning refresh token copied! Go back to the WordPress tab, paste it into the Microsoft Refresh Token field, and click Save.');},function(){window.prompt('Copy this value (Ctrl+C), then paste it into the Refresh Token field in WordPress:',t);});}else{window.prompt('Copy this value (Ctrl+C), then paste it into the Refresh Token field in WordPress:',t);}}catch(e){alert('Error reading the token: '+e.message);}})();";

		$session      = new GCO_Stock_Sync_Browning_Auth_Session( $this->get_option_name() );
		$needs_action = ! $this->is_configured() || $session->is_reauth_needed();

		ob_start();
		?>
		<details class="gco-ss-section"<?php echo $needs_action ? ' open' : ''; ?>>
			<summary style="cursor: pointer; font-weight: 600;"><?php esc_html_e( 'Connecting or reconnecting Browning', 'gco-stock-sync' ); ?></summary>
			<ol style="margin-top: 12px;">
				<li>
					<?php
					printf(
						/* translators: %s: dealer portal login link (HTML) */
						esc_html__( 'Log into %s in this browser — enter your email, then the one-time code Microsoft emails you.', 'gco-stock-sync' ),
						'<a href="https://dealer.browning.eu/en-gb/profile/login?backurl=/en-gb/" target="_blank" rel="noopener">dealer.browning.eu</a>' // phpcs:ignore -- literal trusted HTML, printf %s placeholder
					);
					?>
				</li>
				<li>
					<?php esc_html_e( 'Once logged in and looking at the dealer site, drag this button to your bookmarks bar (only needs doing once, ever):', 'gco-stock-sync' ); ?>
					<p><a class="button" href="<?php echo esc_attr( $bookmarklet_js ); ?>"><?php esc_html_e( 'Get Browning Token', 'gco-stock-sync' ); ?></a></p>
				</li>
				<li><?php esc_html_e( 'Still on the dealer.browning.eu tab, click that bookmark. It copies the token to your clipboard and confirms it worked.', 'gco-stock-sync' ); ?></li>
				<li><?php esc_html_e( 'Come back to this tab, paste the token into the "Microsoft Refresh Token" field below, and click Save.', 'gco-stock-sync' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'This only needs doing once to connect Browning. If it ever needs reconnecting later (rare — every few weeks or months, per Microsoft\'s own policy), a warning will appear on this tab — just repeat these same steps.', 'gco-stock-sync' ); ?></p>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Parse the product-map textarea into normalised rows.
	 *
	 * Format: `woo_sku , browning_item_number` — one per line. Blank lines
	 * and lines starting with `#` are skipped silently. A line with the
	 * wrong field count or a blank SKU/item number is dropped and counted
	 * as invalid rather than causing a fetch failure. Browning item numbers
	 * are alphanumeric (e.g. C192102431), so this only validates non-empty,
	 * not numeric — unlike Ladds' purely-numeric IDs.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array {
	 *     @type array $rows          Valid rows: sku, item_number.
	 *     @type int   $invalid_count Number of non-blank, non-comment lines dropped.
	 * }
	 */
	private function parse_product_map( $raw ) {
		$rows          = array();
		$invalid_count = 0;

		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}

			$fields = array_map( 'trim', explode( ',', $line ) );

			if ( 2 !== count( $fields ) ) {
				++$invalid_count;
				continue;
			}

			list( $sku, $item_number ) = $fields;

			if ( '' === $sku || '' === $item_number ) {
				++$invalid_count;
				continue;
			}

			$rows[] = array(
				'sku'         => $sku,
				'item_number' => $item_number,
			);
		}

		return array(
			'rows'          => $rows,
			'invalid_count' => $invalid_count,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch() {
		$refresh_token = trim( (string) $this->get_setting( 'refresh_token', '' ) );

		if ( '' === $refresh_token ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'No Browning refresh token configured.', 'gco-stock-sync' ) );
		}

		$map  = $this->parse_product_map( $this->get_setting( 'product_map', '' ) );
		$rows = $map['rows'];

		if ( empty( $rows ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'No valid product map rows configured.', 'gco-stock-sync' ) );
		}

		$session = new GCO_Stock_Sync_Browning_Auth_Session( $this->get_option_name() );
		$bearer  = $session->get_bearer_token();

		if ( is_wp_error( $bearer ) ) {
			$error_code = ( 'browning_needs_reauth' === $bearer->get_error_code() ) ? 'needs_reauthentication' : 'fetch_failed';

			return GCO_Stock_Sync_Fetch_Result::failure( $error_code, $bearer->get_error_message() );
		}

		$timeout = max( 1, absint( $this->get_setting( 'request_timeout', self::DEFAULT_REQUEST_TIMEOUT ) ) );

		// Index rows by item_number for quick lookup once results come back.
		$rows_by_item_number = array();
		foreach ( $rows as $row ) {
			$rows_by_item_number[ $row['item_number'] ] = $row;
		}

		$stock_by_item_number = array();
		$chunks                = array_chunk( array_keys( $rows_by_item_number ), self::MAX_ITEMS_PER_REQUEST );

		foreach ( $chunks as $chunk ) {
			$chunk_result = $this->query_stock( $bearer, $chunk, $timeout );

			// A failed chunk just means those items won't have usable data —
			// they'll be counted as per-item failures below and weighed by
			// the ratio gate, same philosophy as every other connector.
			foreach ( $chunk_result as $item_number => $product_data ) {
				$stock_by_item_number[ $item_number ] = $product_data;
			}
		}

		$total     = count( $rows );
		$items     = array();
		$succeeded = 0;

		foreach ( $rows as $row ) {
			$qty = $this->extract_qty( $stock_by_item_number[ $row['item_number'] ] ?? null );

			if ( null !== $qty ) {
				++$succeeded;
				$items[] = array(
					'sku'         => $row['sku'],
					'qty'         => $qty,
					'name'        => $row['sku'],
					'internal_id' => $row['item_number'],
				);
			}
		}

		$min_ratio = min( 100, max( 1, absint( $this->get_setting( 'min_success_ratio', self::DEFAULT_MIN_SUCCESS_RATIO ) ) ) );
		$required  = (int) ceil( $total * $min_ratio / 100 );

		if ( $succeeded < $required ) {
			return GCO_Stock_Sync_Fetch_Result::failure(
				'suspiciously_empty',
				sprintf(
					/* translators: 1: number of products that returned a usable quantity, 2: total mapped products, 3: required minimum percentage */
					__( 'Only %1$d of %2$d mapped products returned a usable quantity (below the configured %3$d%% minimum).', 'gco-stock-sync' ),
					$succeeded,
					$total,
					$min_ratio
				),
				$total
			);
		}

		return GCO_Stock_Sync_Fetch_Result::success( $items, $total );
	}

	/**
	 * Run one batched stock query for the given item numbers.
	 *
	 * @param string   $bearer      Sana Bearer token.
	 * @param string[] $item_numbers Browning item numbers to check.
	 * @param int      $timeout     Request timeout in seconds.
	 * @return array Map of item_number => product data array (only for items
	 *               Browning actually returned data for — a network/parse
	 *               failure for this chunk returns an empty array, treated
	 *               as a per-item failure for everything in the chunk).
	 */
	private function query_stock( $bearer, array $item_numbers, $timeout ) {
		if ( empty( $item_numbers ) ) {
			return array();
		}

		$response = wp_remote_post(
			GCO_Stock_Sync_Browning_Auth_Session::GRAPH_ENDPOINT,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'  => 'application/json; charset=UTF-8',
					'Authorization' => 'Bearer ' . $bearer,
				),
				'body'    => wp_json_encode(
					array(
						'query'     => self::STOCK_QUERY,
						'variables' => array(
							'options' => array(
								'ids'   => array_values( $item_numbers ),
								'uomId' => null,
								'page'  => array(
									'size'  => count( $item_numbers ),
									'index' => 0,
								),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->store_debug_body( $response->get_error_message() );
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		$products = $json['data']['catalog']['products']['products'] ?? null;

		if ( ! is_array( $products ) ) {
			$this->store_debug_body( $body );
			return array();
		}

		$result = array();
		foreach ( $products as $product ) {
			if ( isset( $product['id'] ) ) {
				$result[ $product['id'] ] = $product;
			}
		}

		return $result;
	}

	/**
	 * Extract a normalised integer quantity from one item's product data, or
	 * null if it should be omitted from items (per-item failure — never
	 * written as qty 0).
	 *
	 * Uses `inventory` only — the exact field the client cross-checked
	 * against the portal's own stock display. `secondaryInventory` is a
	 * genuinely separate stock pool in this API (confirmed in recon, not a
	 * fallback-when-missing like Ladds' delivery_stock_data), so it is
	 * deliberately not used here. `isOrderable` is likewise not used to
	 * override the qty-based status, mirroring the Ladds precedent that a
	 * similar flag was unreliable.
	 *
	 * @param array|null $product_data Product data for one item, or null if
	 *                                 Browning never returned this item.
	 * @return int|null
	 */
	private function extract_qty( $product_data ) {
		if ( ! is_array( $product_data ) || ! isset( $product_data['inventory'] ) || ! is_numeric( $product_data['inventory'] ) ) {
			return null;
		}

		return max( 0, (int) floor( (float) $product_data['inventory'] ) );
	}
}
