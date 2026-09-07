<?php
/**
 * Supplier interface — contract every supplier connector must implement.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface GCO_Stock_Sync_Supplier_Interface
 */
interface GCO_Stock_Sync_Supplier_Interface {

	/**
	 * Unique supplier key, e.g. 'highland_outdoors'.
	 *
	 * @return string
	 */
	public function get_key();

	/**
	 * Human-readable label, e.g. 'Highland Outdoors'.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Settings fields for the admin settings screen.
	 *
	 * @return array
	 */
	public function get_settings_fields();

	/**
	 * Whether the supplier has the minimum configuration needed to fetch.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Fetch and parse the supplier feed.
	 *
	 * @return GCO_Stock_Sync_Fetch_Result
	 */
	public function fetch();
}
