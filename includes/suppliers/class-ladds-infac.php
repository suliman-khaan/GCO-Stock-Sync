<?php
/**
 * Ladds Guns (INFAC) connector — Odoo storefront JSON-RPC per-product lookups.
 *
 * Unlike Highland Outdoors (one request → whole catalogue), Ladds requires one
 * request per product against the Odoo `get_combination_info` route, keyed by
 * Odoo's own internal `product_template_id` / `product_id` pair rather than a
 * SKU. See research/LADDS-NOTES.md for the confirmed request/response shape —
 * notably, the endpoint is fully stateless: anonymous requests succeed with no
 * cookies, no CSRF token, and no Referer header, so GCO_Stock_Sync_Odoo_Session
 * exists only for polite cookie passthrough and the bounded single retry.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Ladds_Infac
 */
class GCO_Stock_Sync_Ladds_Infac extends GCO_Stock_Sync_Abstract_Supplier {

	/**
	 * Default storefront base URL.
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URL = 'https://www.laddsguns.com';

	/**
	 * Route that returns stock/price info for one product combination.
	 *
	 * @var string
	 */
	const COMBINATION_INFO_PATH = '/website_sale/get_combination_info';

	/**
	 * Hostname (or parent domain) the base URL must resolve under — SSRF guard.
	 *
	 * @var string
	 */
	const REQUIRED_HOST = 'laddsguns.com';

	/**
	 * Default minimum percentage of mapped products that must return a usable
	 * quantity for the fetch to be accepted (the "ratio gate").
	 *
	 * @var int
	 */
	const DEFAULT_MIN_SUCCESS_RATIO = 60;

	/**
	 * Default per-request timeout, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_REQUEST_TIMEOUT = 15;

	/**
	 * Pause between sequential per-product requests, in microseconds.
	 *
	 * @var int
	 */
	const REQUEST_PAUSE_MICROSECONDS = 300000;

	/**
	 * Total wall-clock budget for a whole fetch() call, in seconds.
	 *
	 * @var int
	 */
	const WALL_CLOCK_CAP_SECONDS = 120;

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'ladds_infac';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Ladds Guns (INFAC)', 'gco-stock-sync' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields() {
		return array(
			'base_url'          => array(
				'label'       => __( 'Storefront Base URL', 'gco-stock-sync' ),
				'type'        => 'url',
				'default'     => self::DEFAULT_BASE_URL,
				'description' => __( 'Ladds Guns Odoo storefront root, e.g. https://www.laddsguns.com.', 'gco-stock-sync' ),
			),
			'product_map'       => array(
				'label'       => __( 'Product Map', 'gco-stock-sync' ),
				'type'        => 'textarea',
				'default'     => '',
				/* translators: example rows are literal, not translatable */
				'description' => __( 'One product per line: woo_sku , product_template_id , product_id. Blank lines and lines starting with # are ignored. Example: INFAC-SD14 , 11950 , 14209', 'gco-stock-sync' ),
			),
			'min_success_ratio' => array(
				'label'       => __( 'Minimum Success Ratio (%)', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_MIN_SUCCESS_RATIO,
				'description' => __( 'If fewer than this percentage of mapped products return a usable quantity, the whole fetch is rejected and nothing on the site changes.', 'gco-stock-sync' ),
			),
			'request_timeout'   => array(
				'label'       => __( 'Per-Request Timeout (seconds)', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_REQUEST_TIMEOUT,
				'description' => __( 'Timeout for each individual product lookup.', 'gco-stock-sync' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_settings() {
		return array(
			'base_url'          => self::DEFAULT_BASE_URL,
			'product_map'       => '',
			'min_success_ratio' => self::DEFAULT_MIN_SUCCESS_RATIO,
			'request_timeout'   => self::DEFAULT_REQUEST_TIMEOUT,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! $this->is_valid_base_url( $this->get_setting( 'base_url', self::DEFAULT_BASE_URL ) ) ) {
			return false;
		}

		$map = $this->parse_product_map( $this->get_setting( 'product_map', '' ) );

		return ! empty( $map['rows'] );
	}

	/**
	 * Whether a URL is well-formed, https, and resolves under the required host.
	 *
	 * Restricting to https + laddsguns.com guards against a misconfigured or
	 * malicious setting pointing wp_remote_post() at an arbitrary internal
	 * address (SSRF) — same approach as Highland's feed URL guard.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_valid_base_url( $url ) {
		if ( empty( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return self::REQUIRED_HOST === $host || ( strlen( $host ) > strlen( self::REQUIRED_HOST ) && '.' . self::REQUIRED_HOST === substr( $host, -1 - strlen( self::REQUIRED_HOST ) ) );
	}

	/**
	 * Parse the product-map textarea into normalised rows.
	 *
	 * Format: `woo_sku , product_template_id , product_id` — one per line.
	 * Blank lines and lines starting with `#` are skipped silently. A line
	 * with the wrong field count, a blank SKU, or a non-positive ID is
	 * dropped and counted as invalid rather than causing a fetch failure —
	 * the invalid count is surfaced to the caller so it isn't silently lost.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array {
	 *     @type array $rows          Valid rows: sku, product_template_id, product_id.
	 *     @type int   $invalid_count Number of non-blank, non-comment lines that were dropped.
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

			if ( 3 !== count( $fields ) ) {
				++$invalid_count;
				continue;
			}

			list( $sku, $template_id_raw, $product_id_raw ) = $fields;

			$template_id = absint( $template_id_raw );
			$product_id  = absint( $product_id_raw );

			if ( '' === $sku || $template_id <= 0 || $product_id <= 0 ) {
				++$invalid_count;
				continue;
			}

			$rows[] = array(
				'sku'                 => $sku,
				'product_template_id' => $template_id,
				'product_id'          => $product_id,
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
		$base_url = $this->get_setting( 'base_url', self::DEFAULT_BASE_URL );

		if ( ! $this->is_valid_base_url( $base_url ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'Storefront Base URL must be a valid https:// laddsguns.com address.', 'gco-stock-sync' ) );
		}

		$map  = $this->parse_product_map( $this->get_setting( 'product_map', '' ) );
		$rows = $map['rows'];

		if ( empty( $rows ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'No valid product map rows configured.', 'gco-stock-sync' ) );
		}

		$timeout = max( 1, absint( $this->get_setting( 'request_timeout', self::DEFAULT_REQUEST_TIMEOUT ) ) );
		$session = new GCO_Stock_Sync_Odoo_Session( $base_url );

		$total     = count( $rows );
		$items     = array();
		$succeeded = 0;
		$start     = microtime( true );

		foreach ( $rows as $index => $row ) {
			if ( ( microtime( true ) - $start ) > self::WALL_CLOCK_CAP_SECONDS ) {
				break; // Stop and evaluate what was collected against the ratio gate below.
			}

			$result = $session->call(
				self::COMBINATION_INFO_PATH,
				array(
					'product_template_id' => $row['product_template_id'],
					'product_id'          => $row['product_id'],
					'combination'         => array(),
					'add_qty'             => 1,
					'pricelist_id'        => null,
					'parent_combination'  => array(),
					'context'             => array(),
				),
				$timeout
			);

			// If the very first lookup can't even reach the host, treat it as a
			// connectivity failure rather than looping through the rest.
			if ( 0 === $index && ! $result['ok'] ) {
				$message = $result['transport_error'] instanceof WP_Error
					? $result['transport_error']->get_error_message()
					: __( 'Unable to reach the Ladds Guns storefront.', 'gco-stock-sync' );

				return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', $message );
			}

			$qty = $this->extract_qty( $result );

			if ( null !== $qty ) {
				++$succeeded;
				$items[] = array(
					'sku'         => $row['sku'],
					'qty'         => $qty,
					'name'        => $row['sku'],
					'internal_id' => $row['product_template_id'] . ':' . $row['product_id'],
				);
			} elseif ( ! $result['ok'] || ( is_array( $result['decoded'] ) && isset( $result['decoded']['error'] ) ) ) {
				$this->store_debug_body( $result['raw_body'] );
			}

			if ( $index < $total - 1 ) {
				usleep( self::REQUEST_PAUSE_MICROSECONDS );
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
	 * Extract a normalised integer quantity from one product's call() result,
	 * or null if the product should be omitted from items (per-product
	 * failure — never written as qty 0).
	 *
	 * Preference order: `result.free_qty`, then `result.delivery_stock_data.quantity`
	 * as a fallback. Zero is only ever returned when Ladds explicitly sent a
	 * numeric zero for one of these fields.
	 *
	 * @param array $result Result of GCO_Stock_Sync_Odoo_Session::call().
	 * @return int|null
	 */
	private function extract_qty( $result ) {
		if ( ! $result['ok'] ) {
			return null; // Transport failure for this product — omit, don't hammer further.
		}

		$decoded = $result['decoded'];

		if ( ! is_array( $decoded ) || isset( $decoded['error'] ) ) {
			return null; // Unparseable body or an Odoo-level error object.
		}

		if ( ! isset( $decoded['result'] ) || ! is_array( $decoded['result'] ) ) {
			return null;
		}

		$data = $decoded['result'];

		if ( isset( $data['free_qty'] ) && is_numeric( $data['free_qty'] ) ) {
			return $this->normalise_qty( $data['free_qty'] );
		}

		if ( isset( $data['delivery_stock_data']['quantity'] ) && is_numeric( $data['delivery_stock_data']['quantity'] ) ) {
			return $this->normalise_qty( $data['delivery_stock_data']['quantity'] );
		}

		return null; // Both free_qty and the fallback missing — never treat as qty 0.
	}

	/**
	 * Normalise a raw numeric quantity into a non-negative integer.
	 *
	 * @param int|float|string $raw Raw numeric value from the Odoo response.
	 * @return int
	 */
	private function normalise_qty( $raw ) {
		return max( 0, (int) floor( (float) $raw ) );
	}
}
