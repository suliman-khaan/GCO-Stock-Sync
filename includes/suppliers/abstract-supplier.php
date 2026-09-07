<?php
/**
 * Abstract Supplier — shared settings storage and error-wrapping helpers.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Abstract_Supplier
 */
abstract class GCO_Stock_Sync_Abstract_Supplier implements GCO_Stock_Sync_Supplier_Interface {

	/**
	 * Option name holding this supplier's settings.
	 *
	 * @return string
	 */
	protected function get_option_name() {
		return 'gco_stock_sync_supplier_' . $this->get_key();
	}

	/**
	 * Get all settings for this supplier, merged over defaults.
	 *
	 * @return array
	 */
	protected function get_settings() {
		$stored = get_option( $this->get_option_name(), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, $this->get_default_settings() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if unset.
	 * @return mixed
	 */
	protected function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Persist settings for this supplier.
	 *
	 * @param array $settings Settings to store.
	 */
	protected function update_settings( $settings ) {
		update_option( $this->get_option_name(), $settings );
	}

	/**
	 * Default settings values. Override in the concrete supplier.
	 *
	 * @return array
	 */
	protected function get_default_settings() {
		return array();
	}

	/**
	 * Store the last failed fetch's raw body for admin debugging.
	 *
	 * Truncated to ~10KB, kept for 24 hours.
	 *
	 * @param string $body Raw response body.
	 */
	protected function store_debug_body( $body ) {
		$truncated = substr( (string) $body, 0, 10240 );
		set_transient( 'gco_stock_sync_last_error_body', $truncated, DAY_IN_SECONDS );
	}
}
