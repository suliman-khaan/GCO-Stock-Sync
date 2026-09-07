<?php
/**
 * Fetch Result — normalised outcome of a supplier feed fetch.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Fetch_Result
 */
class GCO_Stock_Sync_Fetch_Result {

	/**
	 * Whether the fetch succeeded.
	 *
	 * @var bool
	 */
	public $ok = false;

	/**
	 * Normalised product items: array of ['sku','qty','name','internal_id'].
	 *
	 * @var array
	 */
	public $items = array();

	/**
	 * Machine-readable error code, e.g. 'fetch_failed', 'parse_failed', 'suspiciously_empty'.
	 *
	 * @var string|null
	 */
	public $error_code = null;

	/**
	 * Human-readable error message.
	 *
	 * @var string|null
	 */
	public $error_message = null;

	/**
	 * Total raw <tr> rows seen, including header and section-header rows.
	 *
	 * @var int
	 */
	public $raw_row_count = 0;

	/**
	 * Build a success result.
	 *
	 * @param array $items         Normalised product items.
	 * @param int   $raw_row_count Total raw rows seen.
	 * @return GCO_Stock_Sync_Fetch_Result
	 */
	public static function success( $items, $raw_row_count ) {
		$result                = new self();
		$result->ok            = true;
		$result->items         = $items;
		$result->raw_row_count = $raw_row_count;

		return $result;
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $error_code    Machine-readable error code.
	 * @param string $error_message Human-readable error message.
	 * @param int    $raw_row_count Total raw rows seen (0 if fetch never returned a body).
	 * @return GCO_Stock_Sync_Fetch_Result
	 */
	public static function failure( $error_code, $error_message, $raw_row_count = 0 ) {
		$result                = new self();
		$result->ok            = false;
		$result->error_code    = $error_code;
		$result->error_message = $error_message;
		$result->raw_row_count = $raw_row_count;

		return $result;
	}
}
