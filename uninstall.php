<?php
/**
 * Uninstall — clean up all plugin data.
 *
 * This file runs when the plugin is deleted via wp-admin. It respects the
 * "delete data on uninstall" setting — if off (the default), nothing is
 * deleted so the client can reinstall without data loss.
 *
 * @package GCO_Stock_Sync
 */

// Security: only run during a genuine WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Check whether the user opted into data deletion.
 */
$settings = get_option( 'gco_stock_sync_settings', array() );

if ( empty( $settings['delete_data_uninstall'] ) ) {
	// User chose to keep data. Nothing to do.
	return;
}

/*
 * =========================================================================
 * The user opted to delete all data. Remove everything.
 * =========================================================================
 */

global $wpdb;

/**
 * 1. Drop custom tables (items first due to run_id reference).
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gco_ss_items" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gco_ss_runs" );

/**
 * 2. Delete all plugin options.
 */
delete_option( 'gco_stock_sync_settings' );
delete_option( 'gco_stock_sync_db_version' );

/**
 * 3. Delete all plugin-specific post meta from all posts.
 *
 * Uses direct SQL because there's no WP function to delete meta by key
 * across all posts efficiently.
 */
$meta_keys = array(
	'_gco_ss_enabled',
	'_gco_ss_supplier',
	'_gco_ss_last_qty',
	'_gco_ss_last_sync',
);

foreach ( $meta_keys as $meta_key ) {
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => $meta_key ),
		array( '%s' )
	);
}

/**
 * 4. Delete any transients the plugin may have set.
 */
delete_transient( 'gco_stock_sync_lock' );
delete_transient( 'gco_stock_sync_last_error_body' );

/**
 * 5. Clear any remaining scheduled events.
 */
wp_clear_scheduled_hook( 'gco_stock_sync_cron' );
wp_clear_scheduled_hook( 'gco_stock_sync_log_purge' );
