<?php
/**
 * Installer — database schema creation and upgrades.
 *
 * Uses dbDelta for idempotent table creation. Stores a db_version option
 * to support future schema migrations.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Installer
 *
 * Handles database schema creation and version-based upgrades.
 */
class GCO_Stock_Sync_Installer {

	/**
	 * Current database schema version.
	 *
	 * Bump this when the schema changes to trigger an upgrade.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key that stores the installed db version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'gco_stock_sync_db_version';

	/**
	 * Run the installer.
	 *
	 * Creates tables if they don't exist, then runs any pending upgrades.
	 * Safe to call multiple times (idempotent via dbDelta).
	 */
	public static function install() {
		self::create_tables();
		self::maybe_upgrade();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create custom database tables using dbDelta.
	 *
	 * dbDelta is idempotent — it only adds columns/indices that are missing,
	 * so calling this on every activation is safe.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$runs_table      = $wpdb->prefix . 'gco_ss_runs';
		$items_table     = $wpdb->prefix . 'gco_ss_items';

		/*
		 * dbDelta is very picky about SQL formatting:
		 * - Each field on its own line
		 * - Two spaces after PRIMARY KEY
		 * - KEY (not INDEX) for secondary indices
		 * - Must use the exact table name, not a placeholder
		 */
		$sql = "CREATE TABLE {$runs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			supplier varchar(64) NOT NULL,
			started_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL,
			rows_fetched int(10) unsigned NOT NULL DEFAULT 0,
			products_updated int(10) unsigned NOT NULL DEFAULT 0,
			message text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY supplier_started (supplier, started_at)
		) {$charset_collate};

		CREATE TABLE {$items_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			sku varchar(100) NOT NULL,
			product_id bigint(20) unsigned DEFAULT NULL,
			supplier_qty int(11) DEFAULT NULL,
			old_status varchar(20) DEFAULT NULL,
			new_status varchar(20) DEFAULT NULL,
			action varchar(20) NOT NULL,
			note varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY sku (sku)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run upgrade routines if the installed version is behind the current.
	 *
	 * Add new upgrade steps here when bumping DB_VERSION. Each step should
	 * check against the installed version and only run if needed.
	 */
	private static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '>=' ) ) {
			return; // Already up to date.
		}

		// Example future upgrade:
		// if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
		//     self::upgrade_to_1_1_0();
		// }
	}

	/**
	 * Drop all custom tables.
	 *
	 * Called only during uninstall when the "delete data" setting is enabled.
	 */
	public static function drop_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gco_ss_items" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gco_ss_runs" );
	}
}
