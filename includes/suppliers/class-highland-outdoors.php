<?php
/**
 * Highland Outdoors connector — NetSuite SuiteAnalytics web query feed.
 *
 * Feed format documented in research/FEED-NOTES.md. Single HTML <table>,
 * first row is headers, numeric cells prefixed with '=' (Excel formula
 * artefact), section-header rows mixed in with product rows.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Highland_Outdoors
 */
class GCO_Stock_Sync_Highland_Outdoors extends GCO_Stock_Sync_Abstract_Supplier {

	/**
	 * Default feed URL, shipped as a starting value — always overridable via settings.
	 *
	 * @var string
	 */
	const DEFAULT_FEED_URL = 'https://687183.app.netsuite.com/app/reporting/webquery.nl?compid=687183&entity=-5&email=johnb@highlandoutdoors.co.uk&role=1050&cr=1965&hash=AAEJ7tMQgQelTtsA_bfgPhqV1JBNZ5sMB754qObS-sQzpt41Nuw';

	/**
	 * Minimum number of product rows required for a fetch to be considered valid.
	 *
	 * @var int
	 */
	const DEFAULT_MIN_ROWS = 10;

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'highland_outdoors';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Highland Outdoors', 'gco-stock-sync' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields() {
		return array(
			'feed_url' => array(
				'label'       => __( 'Feed URL', 'gco-stock-sync' ),
				'type'        => 'url',
				'default'     => self::DEFAULT_FEED_URL,
				'description' => __( 'NetSuite web query URL. Contact Highland Outdoors for a fresh URL if the hash expires.', 'gco-stock-sync' ),
			),
			'min_rows' => array(
				'label'       => __( 'Minimum Product Rows', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_MIN_ROWS,
				'description' => __( 'If the feed returns fewer product rows than this, the fetch is rejected as suspiciously empty.', 'gco-stock-sync' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_settings() {
		return array(
			'feed_url' => self::DEFAULT_FEED_URL,
			'min_rows' => self::DEFAULT_MIN_ROWS,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return $this->is_https_url( $this->get_setting( 'feed_url', '' ) );
	}

	/**
	 * Whether a URL is well-formed and uses the https scheme.
	 *
	 * Feed URLs are admin-configured; restricting to https guards against a
	 * misconfigured or malicious setting pointing wp_remote_get() at an
	 * internal http:// address (SSRF).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_https_url( $url ) {
		if ( empty( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch() {
		$url = $this->get_setting( 'feed_url', self::DEFAULT_FEED_URL );

		if ( ! $this->is_https_url( $url ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'Feed URL must be a valid https:// address.', 'gco-stock-sync' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'GCO-Stock-Sync/' . GCO_STOCK_SYNC_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$this->store_debug_body( $body );
			/* translators: %d: HTTP status code */
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', sprintf( __( 'Unexpected HTTP status: %d', 'gco-stock-sync' ), $code ) );
		}

		if ( '' === trim( (string) $body ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'Empty response body.', 'gco-stock-sync' ) );
		}

		if ( false === stripos( $body, '<table' ) ) {
			$this->store_debug_body( $body );
			return GCO_Stock_Sync_Fetch_Result::failure( 'parse_failed', __( 'Response does not contain the expected feed table.', 'gco-stock-sync' ) );
		}

		$rows = $this->parse_rows( $body );

		if ( null === $rows ) {
			$this->store_debug_body( $body );
			return GCO_Stock_Sync_Fetch_Result::failure( 'parse_failed', __( 'Unable to parse the feed table.', 'gco-stock-sync' ) );
		}

		$raw_row_count = count( $rows );
		$items         = $this->extract_products( $rows );

		$min_rows = absint( $this->get_setting( 'min_rows', self::DEFAULT_MIN_ROWS ) );
		if ( count( $items ) < $min_rows ) {
			$this->store_debug_body( $body );
			return GCO_Stock_Sync_Fetch_Result::failure( 'suspiciously_empty', __( 'Feed returned fewer product rows than the configured minimum.', 'gco-stock-sync' ), $raw_row_count );
		}

		return GCO_Stock_Sync_Fetch_Result::success( $items, $raw_row_count );
	}

	/**
	 * Parse the HTML body into an array of row cell-text arrays (data rows only,
	 * header row excluded). Returns null if the table can't be found/parsed.
	 *
	 * @param string $body Raw HTML response body.
	 * @return array[]|null
	 */
	private function parse_rows( $body ) {
		$previous_setting = libxml_use_internal_errors( true );

		$dom    = new DOMDocument();
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		if ( ! $loaded ) {
			return null;
		}

		$xpath      = new DOMXPath( $dom );
		$table_rows = $xpath->query( '//table[1]//tr' );

		if ( false === $table_rows || 0 === $table_rows->length ) {
			return null;
		}

		$rows = array();
		foreach ( $table_rows as $index => $tr ) {
			// Skip the header row.
			if ( 0 === $index ) {
				continue;
			}

			$cells = array();
			foreach ( $xpath->query( './td', $tr ) as $td ) {
				$cells[] = trim( $td->textContent );
			}

			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}

		return $rows;
	}

	/**
	 * Filter raw rows down to normalised product items, excluding section headers.
	 *
	 * Column layout (per research/FEED-NOTES.md):
	 * 0 = Qty Available, 1 = Trade Price, 2 = Name (SKU), 3 = Brand Name,
	 * 4 = Description, 5 = Internal ID.
	 *
	 * @param array[] $rows Raw data rows (cell text arrays).
	 * @return array
	 */
	private function extract_products( $rows ) {
		$items = array();

		foreach ( $rows as $cells ) {
			if ( count( $cells ) < 6 ) {
				continue;
			}

			$sku          = trim( $cells[2] );
			$raw_qty      = trim( $cells[0] );
			$raw_price    = trim( $cells[1] );
			$internal_id  = trim( $cells[5] );

			if ( '' === $sku ) {
				continue;
			}

			// Section-header rule: empty price AND qty is exactly "=0" (or 0).
			$qty_is_zero_literal = in_array( $raw_qty, array( '=0', '0', '' ), true );
			if ( '' === $raw_price && $qty_is_zero_literal ) {
				continue;
			}

			$qty = $this->normalise_qty( $raw_qty );
			if ( null === $qty ) {
				continue;
			}

			$items[] = array(
				'sku'         => $sku,
				'qty'         => $qty,
				'name'        => $sku,
				'internal_id' => $internal_id,
			);
		}

		return $items;
	}

	/**
	 * Normalise a raw quantity cell value into an integer.
	 *
	 * Strips the NetSuite '=' formula prefix and commas, clamps negatives to
	 * zero, and returns null for values that can't be parsed as a number.
	 *
	 * @param string $raw Raw cell text.
	 * @return int|null
	 */
	private function normalise_qty( $raw ) {
		$value = trim( $raw );

		if ( '' === $value ) {
			return null;
		}

		$value = ltrim( $value, '=' );
		$value = str_replace( ',', '', $value );
		$value = trim( $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$qty = (int) round( (float) $value );

		return max( 0, $qty );
	}
}
