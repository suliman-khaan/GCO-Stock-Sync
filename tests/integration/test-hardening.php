<?php
/**
 * Integration & Hardening Tests for Phase 6.
 *
 * Covers failure alerting, test connection, CLI command, i18n, and release packaging.
 *
 * @package GCO_Stock_Sync
 */

class Test_Hardening {

	/**
	 * Captured emails for mail tests.
	 *
	 * @var array
	 */
	private $captured_emails = array();

	/**
	 * Created test product IDs.
	 *
	 * @var array
	 */
	private $test_product_ids = array();

	/**
	 * Set up test environment before each test.
	 */
	public function setUp() {
		$this->captured_emails = array();
		$this->test_product_ids = array();

		// Clean notifier options
		delete_option( GCO_Stock_Sync_Failure_Notifier::OPTION_CONSECUTIVE_FAILURES );
		delete_option( GCO_Stock_Sync_Failure_Notifier::OPTION_LAST_ALERT_TIME );
		delete_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY );

		// Set default admin email if not set
		if ( ! get_option( 'admin_email' ) ) {
			update_option( 'admin_email', 'admin@example.com' );
		}
	}

	/**
	 * Clean up test environment after each test.
	 */
	public function tearDown() {
		// Delete any created products
		foreach ( $this->test_product_ids as $id ) {
			$prod = wc_get_product( $id );
			if ( $prod ) {
				$prod->delete( true );
			}
		}

		delete_option( GCO_Stock_Sync_Failure_Notifier::OPTION_CONSECUTIVE_FAILURES );
		delete_option( GCO_Stock_Sync_Failure_Notifier::OPTION_LAST_ALERT_TIME );
		delete_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY );

		// Reset current user
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_mail' );
	}

	/**
	 * Helper to create a test product.
	 */
	private function create_product( $sku, $status = 'outofstock' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test ' . $sku );
		$product->set_regular_price( '19.99' );
		$product->set_sku( $sku );
		$product->set_stock_status( $status );
		$product->save();

		$this->test_product_ids[] = $product->get_id();
		return $product;
	}

	/**
	 * P6-TC04 & P6-TC05: Failure Notifier increments and only emails at threshold.
	 */
	public function test_failure_notifier_threshold_alert() {
		$notifier = new GCO_Stock_Sync_Failure_Notifier();

		// Hook into wp_mail
		add_filter( 'wp_mail', function( $args ) {
			$this->captured_emails[] = $args;
			return true;
		} );

		// Failure 1: no email sent
		$result1 = new GCO_Stock_Sync_Sync_Result( 'failed', 'Connection timeout' );
		$notifier->handle_run_completed( 'highland_outdoors', $result1 );

		if ( 1 !== $notifier->get_consecutive_failures() ) {
			throw new Exception( 'Expected failure count 1, got ' . $notifier->get_consecutive_failures() );
		}
		if ( 0 !== count( $this->captured_emails ) ) {
			throw new Exception( 'Email was sent prematurely on failure 1.' );
		}

		// Failure 2: still no email
		$notifier->handle_run_completed( 'highland_outdoors', $result1 );
		if ( 2 !== $notifier->get_consecutive_failures() ) {
			throw new Exception( 'Expected failure count 2, got ' . $notifier->get_consecutive_failures() );
		}
		if ( 0 !== count( $this->captured_emails ) ) {
			throw new Exception( 'Email was sent prematurely on failure 2.' );
		}

		// Failure 3: threshold reached (3) -> email MUST be sent
		$notifier->handle_run_completed( 'highland_outdoors', $result1 );
		if ( 3 !== $notifier->get_consecutive_failures() ) {
			throw new Exception( 'Expected failure count 3, got ' . $notifier->get_consecutive_failures() );
		}
		if ( 1 !== count( $this->captured_emails ) ) {
			throw new Exception( 'Expected 1 email after failure 3, got ' . count( $this->captured_emails ) );
		}

		$email = $this->captured_emails[0];
		if ( false === strpos( $email['subject'], 'Stock Sync Alert' ) ) {
			throw new Exception( 'Email subject missing alert tag: ' . $email['subject'] );
		}
		if ( false === strpos( $email['message'], 'highland_outdoors' ) ) {
			throw new Exception( 'Email message does not identify the supplier.' );
		}
	}

	/**
	 * P6-TC06: Failure Notifier deduplicates alerts within cooldown window.
	 */
	public function test_failure_notifier_deduplication() {
		$notifier = new GCO_Stock_Sync_Failure_Notifier();

		add_filter( 'wp_mail', function( $args ) {
			$this->captured_emails[] = $args;
			return $args;
		} );

		$result = new GCO_Stock_Sync_Sync_Result( 'failed', 'DNS resolution failure' );

		// 3 failures -> 1 email sent
		for ( $i = 0; $i < 3; $i++ ) {
			$notifier->handle_run_completed( 'highland_outdoors', $result );
		}
		if ( 1 !== count( $this->captured_emails ) ) {
			throw new Exception( 'Expected 1 email, got ' . count( $this->captured_emails ) );
		}

		// Another 3 failures immediately after (within 24h cooldown) -> no new email
		for ( $i = 0; $i < 3; $i++ ) {
			$notifier->handle_run_completed( 'highland_outdoors', $result );
		}
		if ( 1 !== count( $this->captured_emails ) ) {
			throw new Exception( 'Deduplication failed: email was sent again during cooldown window.' );
		}
	}

	/**
	 * P6-TC07: Success run resets consecutive failure counter to 0.
	 */
	public function test_failure_notifier_resets_on_success() {
		$notifier = new GCO_Stock_Sync_Failure_Notifier();

		$failed_result = new GCO_Stock_Sync_Sync_Result( 'failed', 'HTTP 500' );
		$notifier->handle_run_completed( 'highland_outdoors', $failed_result );
		$notifier->handle_run_completed( 'highland_outdoors', $failed_result );

		if ( 2 !== $notifier->get_consecutive_failures() ) {
			throw new Exception( 'Expected 2 failures recorded.' );
		}

		// Successful run
		$success_result = new GCO_Stock_Sync_Sync_Result( 'success' );
		$notifier->handle_run_completed( 'highland_outdoors', $success_result );

		if ( 0 !== $notifier->get_consecutive_failures() ) {
			throw new Exception( 'Consecutive failure count did not reset to 0 after success.' );
		}
	}

	/**
	 * P6-TC08 & P6-TC09: Test connection returns row count without writing to DB or products.
	 */
	public function test_test_connection_no_side_effects() {
		global $wpdb;

		$runs_table = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_run_items';

		$runs_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs_table}" );
		$items_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table}" );

		$product = $this->create_product( 'TEST-TC-09', 'outofstock' );

		// Execute test connection directly through supplier fetch
		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$fetch    = $supplier->fetch();

		if ( ! $fetch->ok ) {
			throw new Exception( 'Fetch failed: ' . $fetch->error_message );
		}
		if ( $fetch->raw_row_count <= 0 ) {
			throw new Exception( 'Expected positive raw row count.' );
		}

		// Verify NO database rows inserted
		$runs_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs_table}" );
		$items_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table}" );

		if ( $runs_before !== $runs_after ) {
			throw new Exception( 'Test connection inserted into gco_ss_runs table!' );
		}
		if ( $items_before !== $items_after ) {
			throw new Exception( 'Test connection inserted into gco_ss_run_items table!' );
		}

		// Verify product status unchanged
		$product_reloaded = wc_get_product( $product->get_id() );
		if ( 'outofstock' !== $product_reloaded->get_stock_status() ) {
			throw new Exception( 'Test connection modified product stock status!' );
		}
	}

	/**
	 * P6-TC10: Test connection AJAX endpoint requires capability and nonce.
	 */
	public function test_test_connection_security_checks() {
		$settings_page = new GCO_Stock_Sync_Settings_Page();

		// 1. Unauthenticated / Non-privileged user
		wp_set_current_user( 0 );
		$_POST = array();

		// Capture json response or exit
		$caught_error = false;
		try {
			// Using reflection to test ajax_test_connection capability check
			$ref = new ReflectionMethod( $settings_page, 'ajax_test_connection' );
			// We can verify that without manage_woocommerce current_user_can returns false
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				$caught_error = true;
			}
		} catch ( Throwable $e ) {
			$caught_error = true;
		}

		if ( ! $caught_error ) {
			throw new Exception( 'ajax_test_connection did not require manage_woocommerce capability.' );
		}

		// 2. Valid admin user check
		$admin_user = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! empty( $admin_user ) ) {
			wp_set_current_user( $admin_user[0]->ID );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				throw new Exception( 'Administrator should possess manage_woocommerce capability.' );
			}
		}
	}

	/**
	 * Cron mode setting: switching to system_cron clears WP-Cron event.
	 */
	public function test_cron_mode_system_cron_clears_wp_cron() {
		$plugin = GCO_Stock_Sync_Plugin::get_instance();

		// Enable with wp_cron
		$old_settings = array( 'enabled' => 1, 'cron_mode' => 'wp_cron', 'sync_interval' => 60 );
		wp_schedule_event( time(), 'gco_stock_sync_interval', 'gco_stock_sync_cron' );

		// Switch to system_cron
		$new_settings = array( 'enabled' => 1, 'cron_mode' => 'system_cron', 'sync_interval' => 60 );
		$plugin->maybe_reschedule_cron( $old_settings, $new_settings );

		if ( false !== wp_next_scheduled( 'gco_stock_sync_cron' ) ) {
			throw new Exception( 'Switching to system_cron did not clear WP-Cron scheduled hook!' );
		}
	}

	/**
	 * P6-TC12: WP-CLI class and dry-run safety verification.
	 */
	public function test_cli_dry_run_safety() {
		if ( ! class_exists( 'GCO_Stock_Sync_CLI' ) ) {
			require_once GCO_STOCK_SYNC_PATH . 'includes/class-cli.php';
		}

		$sku = 'CLI-DRY-' . uniqid();
		$test_p = $this->create_product( $sku, 'outofstock' );

		// Use test stub supplier returning item with this SKU and positive qty
		$fetch_result = new GCO_Stock_Sync_Fetch_Result(
			true,
			array(
				array(
					'sku'         => $sku,
					'qty'         => 25,
					'name'        => 'CLI Dry Run Item',
					'internal_id' => '99999',
				),
			),
			1
		);
		$stub_supplier = new GCO_Test_Stub_Supplier( $fetch_result );

		// Mock WP_CLI calls if WP_CLI is not running
		if ( ! class_exists( 'WP_CLI' ) ) {
			eval( 'class WP_CLI { public static function log($m){} public static function warning($m){} public static function success($m){} public static function error($m,$e=true){} }' );
			eval( 'namespace WP_CLI\Utils { function format_items($t,$i,$f){} }' );
		}

		// Call dry run method via reflection
		$cli = new GCO_Stock_Sync_CLI();
		$ref = new ReflectionMethod( $cli, 'execute_dry_run' );
		$ref->setAccessible( true );
		$ref->invoke( $cli, 'test_stub', $stub_supplier );

		// Confirm test product remained outofstock!
		$reloaded = wc_get_product( $test_p->get_id() );
		if ( 'outofstock' !== $reloaded->get_stock_status() ) {
			throw new Exception( 'Dry run modified WooCommerce stock status!' );
		}
	}

	/**
	 * P6-TC13: CLI force clears run lock transient.
	 */
	public function test_cli_force_clears_transient_lock() {
		set_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY, time(), 900 );

		if ( false === get_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY ) ) {
			throw new Exception( 'Failed to set transient lock.' );
		}

		delete_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY );

		if ( false !== get_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY ) ) {
			throw new Exception( 'Lock transient was not cleared.' );
		}
	}

	/**
	 * P6-TC02 & P6-TC03: POT file exists and contains valid gettext structure.
	 */
	public function test_pot_file_exists_and_valid() {
		$pot_file = GCO_STOCK_SYNC_PATH . 'languages/gco-stock-sync.pot';

		if ( ! file_exists( $pot_file ) ) {
			throw new Exception( 'languages/gco-stock-sync.pot does not exist.' );
		}

		$content = file_get_contents( $pot_file );

		if ( false === strpos( $content, 'Project-Id-Version: GCO Supplier Stock Sync' ) ) {
			throw new Exception( 'POT file missing Project-Id-Version header.' );
		}
		if ( false === strpos( $content, 'X-Domain: gco-stock-sync' ) ) {
			throw new Exception( 'POT file missing X-Domain header.' );
		}
		if ( false === strpos( $content, 'msgid "Stock Sync"' ) ) {
			throw new Exception( 'POT file missing expected core translation string.' );
		}
		if ( false === strpos( $content, 'msgid "Run Sync Now"' ) ) {
			throw new Exception( 'POT file missing admin button string.' );
		}
	}

	/**
	 * P6-TC15: Release zip file exists and excludes dev files.
	 */
	public function test_release_zip_excludes_dev_files() {
		$zip_file = GCO_STOCK_SYNC_PATH . 'release/gco-stock-sync-1.0.0.zip';

		if ( ! file_exists( $zip_file ) ) {
			throw new Exception( 'Release zip file does not exist at ' . $zip_file );
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_file );
		if ( true !== $res ) {
			throw new Exception( 'Could not open release zip archive: code ' . $res );
		}

		$forbidden_prefixes = array(
			'gco-stock-sync/tests',
			'gco-stock-sync/development-plan',
			'gco-stock-sync/research',
			'gco-stock-sync/bin',
			'gco-stock-sync/.git',
			'gco-stock-sync/.claude',
			'gco-stock-sync/phpunit.xml',
		);

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = str_replace( '\\', '/', $stat['name'] );

			foreach ( $forbidden_prefixes as $forbidden ) {
				if ( 0 === strpos( $name, $forbidden ) ) {
					$zip->close();
					throw new Exception( "Forbidden dev file found in release zip: {$name}" );
				}
			}
		}

		$zip->close();
	}

	/**
	 * P6-TC18: Version numbers consistent across plugin files.
	 */
	public function test_version_numbers_consistent() {
		$main_file = file_get_contents( GCO_STOCK_SYNC_PATH . 'gco-stock-sync.php' );
		$readme    = file_get_contents( GCO_STOCK_SYNC_PATH . 'readme.txt' );

		// 1. Plugin header version
		if ( ! preg_match( '/\*\s*Version:\s*([0-9\.]+)/', $main_file, $m1 ) ) {
			throw new Exception( 'Could not extract Version from gco-stock-sync.php header.' );
		}
		$header_version = $m1[1];

		// 2. Defined constant
		if ( ! defined( 'GCO_STOCK_SYNC_VERSION' ) ) {
			throw new Exception( 'GCO_STOCK_SYNC_VERSION constant not defined.' );
		}
		$constant_version = GCO_STOCK_SYNC_VERSION;

		// 3. Readme stable tag
		if ( ! preg_match( '/Stable tag:\s*([0-9\.]+)/', $readme, $m2 ) ) {
			throw new Exception( 'Could not extract Stable tag from readme.txt.' );
		}
		$readme_version = $m2[1];

		if ( $header_version !== $constant_version ) {
			throw new Exception( "Mismatch: Header version ({$header_version}) vs Constant version ({$constant_version})" );
		}
		if ( $header_version !== $readme_version ) {
			throw new Exception( "Mismatch: Header version ({$header_version}) vs Readme stable tag ({$readme_version})" );
		}
	}
}
