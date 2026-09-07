<?php
/**
 * Logger — writes sync events to the custom database tables.
 *
 * Provides methods to create sync run records and log per-product outcomes.
 * All data goes to {prefix}gco_ss_runs and {prefix}gco_ss_items tables.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Logger
 */
class GCO_Stock_Sync_Logger {

	/**
	 * Start a new sync run and return the run ID.
	 *
	 * @param string $supplier Supplier key (e.g. 'highland_outdoors').
	 * @return int|false The run ID, or false on failure.
	 */
	public function start_run( $supplier ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'gco_ss_runs';
		$result = $wpdb->insert(
			$table,
			array(
				'supplier'   => $supplier,
				'started_at' => current_time( 'mysql' ),
				'status'     => 'running',
			),
			array( '%s', '%s', '%s' )
		);

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Close a sync run with final status and counts.
	 *
	 * @param int    $run_id           The run ID.
	 * @param string $status           Final status: success|failed|partial|skipped.
	 * @param int    $rows_fetched     Number of rows fetched from the feed.
	 * @param int    $products_updated Number of products whose status changed.
	 * @param string $message          Optional message (error details, etc.).
	 */
	public function finish_run( $run_id, $status, $rows_fetched = 0, $products_updated = 0, $message = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'gco_ss_runs';

		$wpdb->update(
			$table,
			array(
				'finished_at'      => current_time( 'mysql' ),
				'status'           => $status,
				'rows_fetched'     => $rows_fetched,
				'products_updated' => $products_updated,
				'message'          => $message,
			),
			array( 'id' => $run_id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Log a per-product sync outcome.
	 *
	 * @param int         $run_id       The run ID.
	 * @param string      $sku          Product SKU from the feed.
	 * @param int|null    $product_id   WooCommerce product ID (null if unmatched).
	 * @param int|null    $supplier_qty Supplier quantity (null if unavailable).
	 * @param string|null $old_status   Previous stock status.
	 * @param string|null $new_status   New stock status.
	 * @param string      $action       Action taken: updated|unchanged|skipped|unmatched.
	 * @param string      $note         Optional note (warning, error detail).
	 */
	public function log_item( $run_id, $sku, $product_id, $supplier_qty, $old_status, $new_status, $action, $note = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'gco_ss_items';

		$wpdb->insert(
			$table,
			array(
				'run_id'       => $run_id,
				'sku'          => $sku,
				'product_id'   => $product_id,
				'supplier_qty' => $supplier_qty,
				'old_status'   => $old_status,
				'new_status'   => $new_status,
				'action'       => $action,
				'note'         => $note,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
