<?php
/**
 * Phoenix Safe connector — daily stock report published by Phoenix as an
 * .xlsx on SharePoint behind an anonymous "anyone with the link" share.
 *
 * The Item Number column is the WooCommerce SKU, so no product map is needed;
 * rows with no matching product simply log as unmatched, same as Highland.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Phoenix_Safe
 */
class GCO_Stock_Sync_Phoenix_Safe extends GCO_Stock_Sync_Abstract_Supplier {

	/**
	 * Minimum usable product rows for a report to be accepted.
	 *
	 * @var int
	 */
	const DEFAULT_MIN_ROWS = 5;

	/**
	 * Oldest report (days since last saved) that may be synced. Phoenix update
	 * on working mornings, so this has to survive a bank-holiday weekend.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_AGE_DAYS = 5;

	/**
	 * Per-request timeout, in seconds.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Redirects to walk by hand before giving up.
	 *
	 * @var int
	 */
	const MAX_REDIRECTS = 5;

	/**
	 * Largest report download accepted, in bytes.
	 *
	 * @var int
	 */
	const MAX_DOWNLOAD_BYTES = 5242880;

	/**
	 * Largest single XML part read out of the workbook, in bytes.
	 *
	 * @var int
	 */
	const MAX_XML_BYTES = 20971520;

	/**
	 * Column headers, matched case-insensitively so a reordered sheet still
	 * reads correctly and a renamed column fails instead of misreading.
	 */
	const HEADER_SKU = 'item number';
	const HEADER_QTY = 'qty on hand';

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'phoenix';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Phoenix Safe', 'gco-stock-sync' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reuses the feed_url / min_rows keys so the https validation already in
	 * sanitize_supplier_settings() applies here too.
	 */
	public function get_settings_fields() {
		return array(
			'feed_url'     => array(
				'label'       => __( 'Stock Report Link', 'gco-stock-sync' ),
				'type'        => 'url',
				'default'     => '',
				'description' => __( 'The SharePoint "anyone with the link" share for the Phoenix stock report. If the link came through a chat app with an emoji after sharepoint.com/, replace the emoji with :x:', 'gco-stock-sync' ),
			),
			'min_rows'     => array(
				'label'       => __( 'Minimum Product Rows', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_MIN_ROWS,
				'description' => __( 'If the report has fewer usable rows than this, it is rejected and nothing on the site changes.', 'gco-stock-sync' ),
			),
			'max_age_days' => array(
				'label'       => __( 'Maximum Report Age (days)', 'gco-stock-sync' ),
				'type'        => 'number',
				'default'     => self::DEFAULT_MAX_AGE_DAYS,
				'description' => __( 'If Phoenix have not saved the report within this many days, it is rejected and nothing on the site changes.', 'gco-stock-sync' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_settings() {
		return array(
			'feed_url'     => '',
			'min_rows'     => self::DEFAULT_MIN_ROWS,
			'max_age_days' => self::DEFAULT_MAX_AGE_DAYS,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return self::is_sharepoint_url( $this->get_setting( 'feed_url', '' ) );
	}

	/**
	 * Whether a URL is https on a SharePoint host. Applied to the configured
	 * link and to every redirect, so neither can reach anywhere else (SSRF).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function is_sharepoint_url( $url ) {
		if ( empty( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$suffix = '.sharepoint.com';

		return strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Disable ALPN for SharePoint requests only. This host's cURL/OpenSSL stack
	 * mishandles ALPN against Microsoft's gateways (BROWNING-NOTES.md §6a).
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 * @param array                $r      Request args (unused).
	 * @param string               $url    Request URL.
	 */
	public static function disable_alpn_for_sharepoint( $handle, $r, $url ) {
		if ( self::is_sharepoint_url( $url ) ) {
			curl_setopt( $handle, CURLOPT_SSL_ENABLE_ALPN, false );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch() {
		$url = (string) $this->get_setting( 'feed_url', '' );

		if ( ! self::is_sharepoint_url( $url ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'Stock Report Link must be a valid https:// SharePoint link.', 'gco-stock-sync' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', __( 'The PHP zip extension is required to read the Phoenix stock report.', 'gco-stock-sync' ) );
		}

		// Hooked only for this download, so no other request on the site is affected.
		add_action( 'http_api_curl', array( __CLASS__, 'disable_alpn_for_sharepoint' ), 10, 3 );

		try {
			$body = $this->download_report( $url );
		} finally {
			remove_action( 'http_api_curl', array( __CLASS__, 'disable_alpn_for_sharepoint' ), 10 );
		}

		if ( is_wp_error( $body ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', $body->get_error_message() );
		}

		$report = $this->parse_report( $body );

		if ( is_wp_error( $report ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'parse_failed', $report->get_error_message() );
		}

		$raw_row_count = count( $report['rows'] );

		$max_age_days = max( 1, absint( $this->get_setting( 'max_age_days', self::DEFAULT_MAX_AGE_DAYS ) ) );

		if ( null === $report['modified'] ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'stale_feed', __( 'The report has no saved date, so its age cannot be checked.', 'gco-stock-sync' ), $raw_row_count );
		}

		if ( ( time() - $report['modified'] ) > $max_age_days * DAY_IN_SECONDS ) {
			return GCO_Stock_Sync_Fetch_Result::failure(
				'stale_feed',
				sprintf(
					/* translators: 1: date the report was last saved, 2: maximum age in days */
					__( 'The report was last saved on %1$s, which is more than %2$d days ago.', 'gco-stock-sync' ),
					gmdate( 'Y-m-d H:i', $report['modified'] ),
					$max_age_days
				),
				$raw_row_count
			);
		}

		$items = $this->extract_items( $report['rows'] );

		$min_rows = max( 1, absint( $this->get_setting( 'min_rows', self::DEFAULT_MIN_ROWS ) ) );

		if ( count( $items ) < $min_rows ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'suspiciously_empty', __( 'The report has fewer usable rows than the configured minimum.', 'gco-stock-sync' ), $raw_row_count );
		}

		// A report of nothing but zeros is a broken export, not a sold-out range.
		// Accepting it would mark every Phoenix product out of stock at once.
		if ( 0 === array_sum( array_column( $items, 'qty' ) ) ) {
			return GCO_Stock_Sync_Fetch_Result::failure( 'suspiciously_empty', __( 'Every quantity in the report is zero, so it was rejected.', 'gco-stock-sync' ), $raw_row_count );
		}

		return GCO_Stock_Sync_Fetch_Result::success( $items, $raw_row_count );
	}

	/**
	 * Download the workbook.
	 *
	 * Redirects are walked by hand because the first hop sets the anonymous
	 * guest cookie that the next hop requires. Without it SharePoint answers
	 * 200 with a sign-in page, so the body is checked as well as the status.
	 *
	 * @param string $url Configured share link.
	 * @return string|WP_Error Workbook bytes, or why not.
	 */
	private function download_report( $url ) {
		$target  = add_query_arg( 'download', '1', $url );
		$cookies = array();

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$args = array(
				'timeout'             => self::REQUEST_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
				'user-agent'          => 'GCO-Stock-Sync/' . GCO_STOCK_SYNC_VERSION . '; ' . home_url(),
				'headers'             => array(),
			);

			if ( ! empty( $cookies ) ) {
				$pairs = array();
				foreach ( $cookies as $name => $value ) {
					$pairs[] = $name . '=' . $value;
				}
				$args['headers']['Cookie'] = implode( '; ', $pairs );
			}

			$response = wp_remote_get( $target, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$cookies = array_merge( $cookies, $this->read_set_cookies( $response ) );
			$code    = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 300 && $code < 400 ) {
				$next = WP_Http::make_absolute_url( (string) wp_remote_retrieve_header( $response, 'location' ), $target );

				if ( ! self::is_sharepoint_url( $next ) ) {
					return new WP_Error( 'phoenix_link_redirected', __( 'The report link redirected away from SharePoint. It has probably expired or now requires a sign-in.', 'gco-stock-sync' ) );
				}

				$target = $next;
				continue;
			}

			$body = wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				$this->store_debug_body( $body );
				/* translators: %d: HTTP status code */
				return new WP_Error( 'phoenix_http_status', sprintf( __( 'Unexpected HTTP status: %d', 'gco-stock-sync' ), $code ) );
			}

			if ( 'PK' !== substr( (string) $body, 0, 2 ) ) {
				$this->store_debug_body( $body );
				return new WP_Error( 'phoenix_not_a_workbook', __( 'The report link did not return a spreadsheet. It has probably expired or now requires a sign-in.', 'gco-stock-sync' ) );
			}

			return $body;
		}

		return new WP_Error( 'phoenix_too_many_redirects', __( 'The report link redirected too many times.', 'gco-stock-sync' ) );
	}

	/**
	 * Read cookies from a response's raw Set-Cookie headers, keeping values
	 * byte-for-byte: the guest cookie is base64 and must not be re-encoded.
	 *
	 * @param array $response wp_remote_get() response.
	 * @return array Cookie name => value.
	 */
	private function read_set_cookies( $response ) {
		$cookies = array();

		foreach ( (array) wp_remote_retrieve_header( $response, 'set-cookie' ) as $line ) {
			$pair = explode( '=', explode( ';', (string) $line, 2 )[0], 2 );

			if ( 2 === count( $pair ) && '' !== trim( $pair[0] ) ) {
				$cookies[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		return $cookies;
	}

	/**
	 * Parse the workbook into header-mapped rows plus its last-saved time.
	 *
	 * @param string $body Workbook bytes.
	 * @return array|WP_Error {
	 *     @type array    $rows     Rows of array( 'sku' => string, 'qty' => string|null ).
	 *     @type int|null $modified Unix time the workbook was last saved, if known.
	 * }
	 */
	private function parse_report( $body ) {
		// ZipArchive only opens files, and wp_tempnam() isn't loaded during cron.
		$path = tempnam( get_temp_dir(), 'gcoss' );

		if ( false === $path || false === file_put_contents( $path, $body ) ) {
			return new WP_Error( 'phoenix_temp_file', __( 'Could not write a temporary copy of the report.', 'gco-stock-sync' ) );
		}

		$zip    = new ZipArchive();
		$opened = true === $zip->open( $path );

		try {
			if ( ! $opened ) {
				return new WP_Error( 'phoenix_bad_zip', __( 'The report could not be opened as a spreadsheet.', 'gco-stock-sync' ) );
			}

			$strings = $this->read_shared_strings( $zip );
			$rows    = null;

			for ( $i = 0; $i < $zip->numFiles && null === $rows; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );

				if ( preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $name ) ) {
					$rows = $this->read_sheet_rows( $this->read_xml_part( $zip, $name ), $strings );
				}
			}

			if ( null === $rows ) {
				return new WP_Error(
					'phoenix_missing_columns',
					sprintf(
						/* translators: 1: SKU column heading, 2: quantity column heading */
						__( 'The report no longer has the expected "%1$s" and "%2$s" columns.', 'gco-stock-sync' ),
						'Item Number',
						'Qty On Hand'
					)
				);
			}

			$modified = $this->read_last_saved( $zip );
		} finally {
			if ( $opened ) {
				$zip->close();
			}
			unlink( $path );
		}

		return array(
			'rows'     => $rows,
			'modified' => $modified,
		);
	}

	/**
	 * Read and parse one XML part of the workbook.
	 *
	 * @param ZipArchive $zip  Open workbook.
	 * @param string     $name Part name.
	 * @return SimpleXMLElement|null
	 */
	private function read_xml_part( ZipArchive $zip, $name ) {
		$stat = $zip->statName( $name );

		if ( false === $stat || $stat['size'] > self::MAX_XML_BYTES ) {
			return null;
		}

		$xml = $zip->getFromName( $name );

		if ( false === $xml ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return false === $parsed ? null : $parsed;
	}

	/**
	 * Read the workbook's shared string table.
	 *
	 * @param ZipArchive $zip Open workbook.
	 * @return string[]
	 */
	private function read_shared_strings( ZipArchive $zip ) {
		$table = $this->read_xml_part( $zip, 'xl/sharedStrings.xml' );

		if ( null === $table ) {
			return array();
		}

		$strings = array();

		foreach ( $table->si as $si ) {
			$strings[] = $this->rich_text( $si );
		}

		return $strings;
	}

	/**
	 * Flatten a plain or rich-text string element.
	 *
	 * @param SimpleXMLElement $element <si> or <is> element.
	 * @return string
	 */
	private function rich_text( SimpleXMLElement $element ) {
		$text = (string) $element->t;

		foreach ( $element->r as $run ) {
			$text .= (string) $run->t;
		}

		return $text;
	}

	/**
	 * Read a cell as text. Boolean and error cells return null so they can
	 * never be mistaken for a quantity.
	 *
	 * @param SimpleXMLElement $cell    <c> element.
	 * @param string[]         $strings Shared string table.
	 * @return string|null
	 */
	private function cell_value( SimpleXMLElement $cell, array $strings ) {
		switch ( (string) $cell['t'] ) {
			case 's':
				$index = (int) $cell->v;
				return isset( $strings[ $index ] ) ? $strings[ $index ] : null;

			case 'inlineStr':
				return $this->rich_text( $cell->is );

			case 'b':
			case 'e':
				return null;

			default:
				return (string) $cell->v;
		}
	}

	/**
	 * Map a sheet's rows by header name. Empty cells can be left out of the
	 * XML entirely, so cells are placed by reference rather than position.
	 *
	 * @param SimpleXMLElement|null $sheet   Worksheet.
	 * @param string[]              $strings Shared string table.
	 * @return array|null Rows, or null if this sheet lacks the expected columns.
	 */
	private function read_sheet_rows( $sheet, array $strings ) {
		if ( null === $sheet ) {
			return null;
		}

		$sku_column = null;
		$qty_column = null;
		$rows       = array();

		foreach ( $sheet->sheetData->row as $row ) {
			$cells = array();

			foreach ( $row->c as $cell ) {
				$column = preg_replace( '/\d+/', '', (string) $cell['r'] );

				if ( '' !== $column ) {
					$cells[ $column ] = $this->cell_value( $cell, $strings );
				}
			}

			if ( null === $sku_column ) {
				foreach ( $cells as $column => $value ) {
					$heading = strtolower( trim( (string) $value ) );

					if ( self::HEADER_SKU === $heading ) {
						$sku_column = $column;
					} elseif ( self::HEADER_QTY === $heading ) {
						$qty_column = $column;
					}
				}

				// Both headings must sit on the same row.
				if ( null === $sku_column || null === $qty_column ) {
					$sku_column = null;
					$qty_column = null;
				}

				continue;
			}

			$rows[] = array(
				'sku' => isset( $cells[ $sku_column ] ) ? trim( (string) $cells[ $sku_column ] ) : '',
				'qty' => isset( $cells[ $qty_column ] ) ? $cells[ $qty_column ] : null,
			);
		}

		return null === $sku_column ? null : $rows;
	}

	/**
	 * When the workbook was last saved, from its own document properties.
	 *
	 * @param ZipArchive $zip Open workbook.
	 * @return int|null Unix time, or null if unavailable.
	 */
	private function read_last_saved( ZipArchive $zip ) {
		$core = $this->read_xml_part( $zip, 'docProps/core.xml' );

		if ( null === $core ) {
			return null;
		}

		$time = strtotime( (string) $core->children( 'http://purl.org/dc/terms/' )->modified );

		return false === $time ? null : $time;
	}

	/**
	 * Normalise rows into feed items. A row without a real number is omitted,
	 * never treated as zero, and an item listed twice with different
	 * quantities is omitted because neither figure can be trusted.
	 *
	 * @param array $rows Rows from parse_report().
	 * @return array
	 */
	private function extract_items( array $rows ) {
		$items     = array();
		$conflicts = array();

		foreach ( $rows as $row ) {
			$qty = $this->normalise_qty( $row['qty'] );

			if ( '' === $row['sku'] || null === $qty ) {
				continue;
			}

			$key = strtoupper( $row['sku'] );

			if ( isset( $items[ $key ] ) && $items[ $key ]['qty'] !== $qty ) {
				$conflicts[ $key ] = true;
			}

			$items[ $key ] = array(
				'sku'         => $row['sku'],
				'qty'         => $qty,
				'name'        => $row['sku'],
				'internal_id' => $row['sku'],
			);
		}

		return array_values( array_diff_key( $items, $conflicts ) );
	}

	/**
	 * Normalise a raw quantity to a non-negative integer, or null if it isn't
	 * a number.
	 *
	 * @param string|null $raw Raw cell value.
	 * @return int|null
	 */
	private function normalise_qty( $raw ) {
		if ( null === $raw ) {
			return null;
		}

		$value = str_replace( ',', '', trim( (string) $raw ) );

		if ( '' === $value || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			return null;
		}

		return max( 0, (int) floor( (float) $value ) );
	}
}
