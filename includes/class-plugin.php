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
	 * Failure notifier instance.
	 *
	 * @var GCO_Stock_Sync_Failure_Notifier|null
	 */
	private $failure_notifier = null;

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
		require_once $path . 'class-failure-notifier.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $path . 'class-cli.php';
		}

		require_once $path . 'suppliers/interface-supplier.php';
		require_once $path . 'suppliers/class-fetch-result.php';
		require_once $path . 'suppliers/abstract-supplier.php';
		require_once $path . 'suppliers/class-highland-outdoors.php';
		require_once $path . 'suppliers/class-odoo-session.php';
		require_once $path . 'suppliers/class-ladds-infac.php';
		require_once $path . 'suppliers/class-browning-auth-session.php';
		require_once $path . 'suppliers/class-browning.php';

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
		// Initialize failure notifier.
		$this->failure_notifier = new GCO_Stock_Sync_Failure_Notifier();
		$this->failure_notifier->init();

		// Register custom cron interval.
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );

		// Check if DB needs upgrading (covers manual file updates).
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );

		add_action( 'gco_stock_sync_cron', array( $this, 'handle_cron' ) );

		// Register the built-in suppliers. Third parties can add more via this filter.
		add_filter( 'gco_stock_sync_suppliers', array( $this, 'register_builtin_suppliers' ) );

		// Reschedule cron cleanly when the settings change.
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

		$ladds_infac = new GCO_Stock_Sync_Ladds_Infac();
		$suppliers[ $ladds_infac->get_key() ] = $ladds_infac;

		$browning = new GCO_Stock_Sync_Browning();
		$suppliers[ $browning->get_key() ] = $browning;

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
	 * Reschedule or clear the cron event when settings change (interval, mode, or enabled state).
	 *
	 * @param array $old_value Previous settings.
	 * @param array $new_value New settings.
	 */
	public function maybe_reschedule_cron( $old_value, $new_value ) {
		$old_mode = isset( $old_value['cron_mode'] ) ? $old_value['cron_mode'] : 'wp_cron';
		$new_mode = isset( $new_value['cron_mode'] ) ? $new_value['cron_mode'] : 'wp_cron';

		$old_interval = isset( $old_value['sync_interval'] ) ? absint( $old_value['sync_interval'] ) : null;
		$new_interval = isset( $new_value['sync_interval'] ) ? absint( $new_value['sync_interval'] ) : null;

		// If explicitly disabled or switched to system_cron, ensure WP-Cron event is removed.
		if ( 'system_cron' === $new_mode || ( isset( $new_value['enabled'] ) && empty( $new_value['enabled'] ) ) ) {
			wp_clear_scheduled_hook( 'gco_stock_sync_cron' );
			return;
		}

		// If interval and mode haven't changed and hook is scheduled, keep it.
		if ( $old_interval === $new_interval && $old_mode === $new_mode && wp_next_scheduled( 'gco_stock_sync_cron' ) ) {
			return;
		}

		wp_clear_scheduled_hook( 'gco_stock_sync_cron' );
		wp_schedule_event( time(), 'gco_stock_sync_interval', 'gco_stock_sync_cron' );
	}

	/**
	 * Show an admin notice if DISABLE_WP_CRON is set and the plugin is in WP-Cron mode.
	 */
	public function maybe_warn_disable_wp_cron() {
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return;
		}

		$settings = get_option( 'gco_stock_sync_settings', array() );
		$mode     = isset( $settings['cron_mode'] ) ? $settings['cron_mode'] : 'wp_cron';

		// If system_cron is chosen, DISABLE_WP_CRON is intentional and expected.
		if ( empty( $settings['enabled'] ) || 'system_cron' === $mode ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'GCO Supplier Stock Sync', 'gco-stock-sync' ); ?>:</strong>
				<?php esc_html_e( 'DISABLE_WP_CRON is set. Scheduled stock syncs will not run unless an external cron job hits wp-cron.php on schedule, or you switch to Server Cron mode.', 'gco-stock-sync' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get the failure notifier instance.
	 *
	 * @return GCO_Stock_Sync_Failure_Notifier|null
	 */
	public function get_failure_notifier() {
		return $this->failure_notifier;
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
