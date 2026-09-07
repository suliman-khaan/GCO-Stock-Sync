<?php
/**
 * PHPUnit Bootstrap for GCO Stock Sync
 *
 * Supports both standard WordPress test suite (wp-env / WP_TESTS_DIR)
 * and direct execution against the local WordPress environment.
 *
 * @package GCO_Stock_Sync
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// Standard WordPress test suite environment
	require_once $_tests_dir . '/includes/functions.php';

	function _manually_load_plugin() {
		require_once dirname( dirname( __FILE__ ) ) . '/gco-stock-sync.php';
	}
	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

	require_once $_tests_dir . '/includes/bootstrap.php';
} else {
	// Local WordPress environment fallback
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( file_exists( $wp_load ) ) {
		// Define ABSPATH if not defined
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', dirname( $wp_load ) . '/' );
		}
		require_once $wp_load;
	}

	// Ensure plugin constants and classes are loaded
	require_once dirname( dirname( __FILE__ ) ) . '/gco-stock-sync.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/class-installer.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/class-activator.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/class-deactivator.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/class-logger.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/suppliers/interface-supplier.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/suppliers/class-fetch-result.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/suppliers/abstract-supplier.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/suppliers/class-highland-outdoors.php';
	require_once dirname( dirname( __FILE__ ) ) . '/includes/class-plugin.php';
}
