<?php
/**
 * Deactivator — runs on plugin deactivation.
 *
 * Clears the cron schedule but NEVER deletes data. Tables, options,
 * and post meta all survive deactivation.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Deactivator
 */
class GCO_Stock_Sync_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * Only clears the cron schedule. Data is preserved so re-activation
	 * picks up where it left off.
	 */
	public static function deactivate() {
		self::clear_cron();
	}

	/**
	 * Remove the scheduled sync cron event.
	 */
	private static function clear_cron() {
		$timestamp = wp_next_scheduled( 'gco_stock_sync_cron' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'gco_stock_sync_cron' );
		}

		// Belt-and-suspenders: clear all events with this hook in case
		// multiple got scheduled due to a bug or race condition.
		wp_clear_scheduled_hook( 'gco_stock_sync_cron' );
		wp_clear_scheduled_hook( 'gco_stock_sync_log_purge' );
	}
}
