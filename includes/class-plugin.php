<?php
/**
 * Plugin singleton — central bootstrap and hook registration.
 *
 * Loads all dependencies, registers hooks and filters, and acts as
 * the single entry point for the plugin's runtime behaviour.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Plugin
 */
class GCO_Stock_Sync_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var GCO_Stock_Sync_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var GCO_Stock_Sync_Logger|null
	 */
	private $logger = null;

	/**
	 * Admin controller instance.
	 *
	 * @var GCO_Stock_Sync_Admin|null
	 */
	private $admin = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return GCO_Stock_Sync_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Load required class files.
	 */
	private function load_dependencies() {
		$path = GCO_STOCK_SYNC_PATH . 'includes/';

		require_once $path . 'class-installer.php';
		require_once $path . 'class-activator.php';
		require_once $path . 'class-deactivator.php';
		require_once $path . 'class-logger.php';

		require_once $path . 'suppliers/interface-supplier.php';
		require_once $path . 'suppliers/class-fetch-result.php';
		require_once $path . 'suppliers/abstract-supplier.php';
		require_once $path . 'suppliers/class-highland-outdoors.php';

		require_once $path . 'sync/class-sync-result.php';
		require_once $path . 'sync/class-product-matcher.php';
		require_once $path . 'sync/class-sync-runner.php';

		$admin_path = GCO_STOCK_SYNC_PATH . 'admin/';
		require_once $admin_path . 'class-settings-page.php';
		require_once $admin_path . 'class-log-list-table.php';
		require_once $admin_path . 'class-log-page.php';
		require_once $admin_path . 'class-product-meta-box.php';
		require_once $admin_path . 'class-admin.php';
	}

	/**
	 * Register all hooks and filters.
	 */
	private function register_hooks() {
		// Register custom cron interval.
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );

		// Check if DB needs upgrading (covers manual file updates).
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );

		add_action( 'gco_stock_sync_cron', array( $this, 'handle_cron' ) );

		// Register the built-in suppliers. Third parties can add more via this filter.
		add_filter( 'gco_stock_sync_suppliers', array( $this, 'register_builtin_suppliers' ) );

		// Reschedule cron cleanly when the sync_interval setting changes.
		add_action( 'update_option_gco_stock_sync_settings', array( $this, 'maybe_reschedule_cron' ), 10, 2 );

		// Warn if WP-Cron is disabled, since scheduled syncs won't run without an external trigger.
		add_action( 'admin_notices', array( $this, 'maybe_warn_disable_wp_cron' ) );

		// Initialize admin interface when in WordPress admin.
		if ( is_admin() ) {
			$this->admin = new GCO_Stock_Sync_Admin();
		}
	}

	/**
	 * Register the built-in supplier connectors.
	 *
	 * @param array $suppliers Existing suppliers keyed by supplier key.
	 * @return array Modified suppliers array.
	 */
	public function register_builtin_suppliers( $suppliers ) {
		$highland = new GCO_Stock_Sync_Highland_Outdoors();
		$suppliers[ $highland->get_key() ] = $highland;

		return $suppliers;
	}

	/**
	 * Get all registered suppliers.
	 *
	 * @return GCO_Stock_Sync_Supplier_Interface[]
	 */
	public function get_suppliers() {
		return apply_filters( 'gco_stock_sync_suppliers', array() );
	}

	/**
	 * Register the custom cron interval based on the sync_interval setting.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function register_cron_interval( $schedules ) {
		$settings = get_option( 'gco_stock_sync_settings', array() );
		$interval = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 60;

		// Ensure interval is one of the allowed values.
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
	 * Check if the database needs upgrading.
	 *
	 * Runs on admin_init so upgrades happen even after a manual file update
	 * (not just activation).
	 */
	public function maybe_upgrade_db() {
		$installed = get_option( GCO_Stock_Sync_Installer::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( $installed, GCO_Stock_Sync_Installer::DB_VERSION, '<' ) ) {
			GCO_Stock_Sync_Installer::install();
		}
	}

	/**
	 * Handle the scheduled cron event — run a sync for every registered,
	 * configured supplier.
	 */
	public function handle_cron() {
		$settings = get_option( 'gco_stock_sync_settings', array() );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$runner = new GCO_Stock_Sync_Sync_Runner();

		foreach ( $this->get_suppliers() as $key => $supplier ) {
			if ( ! $supplier->is_configured() ) {
				continue;
			}

			$runner->run( $key, $supplier );
		}
	}

	/**
	 * Reschedule the cron event when the sync_interval setting changes, so a
	 * saved settings change takes effect immediately rather than waiting for
	 * the previous interval to elapse.
	 *
	 * @param array $old_value Previous settings.
	 * @param array $new_value New settings.
	 */
	public function maybe_reschedule_cron( $old_value, $new_value ) {
		$old_interval = isset( $old_value['sync_interval'] ) ? absint( $old_value['sync_interval'] ) : null;
		$new_interval = isset( $new_value['sync_interval'] ) ? absint( $new_value['sync_interval'] ) : null;

		if ( $old_interval === $new_interval ) {
			return;
		}

		wp_clear_scheduled_hook( 'gco_stock_sync_cron' );
		wp_schedule_event( time(), 'gco_stock_sync_interval', 'gco_stock_sync_cron' );
	}

	/**
	 * Show an admin notice if DISABLE_WP_CRON is set, since scheduled syncs
	 * silently won't run without an external cron hitting wp-cron.php.
	 */
	public function maybe_warn_disable_wp_cron() {
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return;
		}

		$settings = get_option( 'gco_stock_sync_settings', array() );
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'GCO Supplier Stock Sync', 'gco-stock-sync' ); ?>:</strong>
				<?php esc_html_e( 'DISABLE_WP_CRON is set. Scheduled stock syncs will not run unless an external cron job hits wp-cron.php on schedule.', 'gco-stock-sync' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get the logger instance.
	 *
	 * @return GCO_Stock_Sync_Logger
	 */
	public function get_logger() {
		if ( null === $this->logger ) {
			$this->logger = new GCO_Stock_Sync_Logger();
		}
		return $this->logger;
	}

	/**
	 * Get the admin controller instance.
	 *
	 * @return GCO_Stock_Sync_Admin|null
	 */
	public function get_admin() {
		return $this->admin;
	}
}
