<?php
/**
 * Activator — runs on plugin activation.
 *
 * Creates tables, sets default options, and schedules the sync cron event.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Activator
 */
class GCO_Stock_Sync_Activator {

	/**
	 * Default plugin settings.
	 *
	 * @var array
	 */
	private static $default_settings = array(
		'enabled'               => false,
		'sync_interval'         => 60,
		'delete_data_uninstall' => false,
	);

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		self::install_database();
		self::set_default_options();
		self::schedule_cron();
	}

	/**
	 * Create / update database tables.
	 */
	private static function install_database() {
		if ( ! class_exists( 'GCO_Stock_Sync_Installer' ) ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-installer.php';
		}
		GCO_Stock_Sync_Installer::install();
	}

	/**
	 * Set default options if they don't already exist.
	 *
	 * Uses add_option to avoid overwriting user-modified settings
	 * on re-activation.
	 */
	private static function set_default_options() {
		add_option( 'gco_stock_sync_settings', self::$default_settings );
	}

	/**
	 * Schedule the sync cron event if not already scheduled.
	 */
	private static function schedule_cron() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );

		if ( ! wp_next_scheduled( 'gco_stock_sync_cron' ) ) {
			wp_schedule_event( time(), 'gco_stock_sync_interval', 'gco_stock_sync_cron' );
		}

		if ( ! wp_next_scheduled( 'gco_stock_sync_log_purge' ) ) {
			wp_schedule_event( time(), 'daily', 'gco_stock_sync_log_purge' );
		}
	}

	/**
	 * Register the custom cron interval so WordPress recognises it during activation.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_interval( $schedules ) {
		$settings = get_option( 'gco_stock_sync_settings', self::$default_settings );
		$interval = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 60;
		if ( ! in_array( $interval, array( 30, 60, 120 ), true ) ) {
			$interval = 60;
		}
		$schedules['gco_stock_sync_interval'] = array(
			'interval' => $interval * MINUTE_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d: number of minutes */
				__( 'Every %d minutes (GCO Stock Sync)', 'gco-stock-sync' ),
				$interval
			),
		);
		return $schedules;
	}

	/**
	 * Get the default settings array.
	 *
	 * @return array Default settings.
	 */
	public static function get_default_settings() {
		return self::$default_settings;
	}
}
