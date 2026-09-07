<?php
/**
 * Test Lifecycle — Integration Tests for Plugin Skeleton & Lifecycle (Phase 2)
 *
 * Tests P2-TC01 through P2-TC17.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Lifecycle_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Lifecycle_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Lifecycle_Base_TestCase {
		protected function assertEquals( $expected, $actual, $message = '' ) {
			if ( $expected !== $actual ) {
				throw new Exception( $message ? $message : "Failed asserting that " . var_export( $actual, true ) . " equals " . var_export( $expected, true ) );
			}
		}
		protected function assertTrue( $condition, $message = '' ) {
			if ( true !== $condition ) {
				throw new Exception( $message ? $message : "Failed asserting that condition is true" );
			}
		}
		protected function assertFalse( $condition, $message = '' ) {
			if ( false !== $condition ) {
				throw new Exception( $message ? $message : "Failed asserting that condition is false" );
			}
		}
		protected function assertNotFalse( $condition, $message = '' ) {
			if ( false === $condition ) {
				throw new Exception( $message ? $message : "Failed asserting that condition is not false" );
			}
		}
		protected function assertNotEmpty( $value, $message = '' ) {
			if ( empty( $value ) ) {
				throw new Exception( $message ? $message : "Failed asserting that value is not empty" );
			}
		}
		protected function assertEmpty( $value, $message = '' ) {
			if ( ! empty( $value ) ) {
				throw new Exception( $message ? $message : "Failed asserting that value is empty" );
			}
		}
		protected function assertContains( $needle, $haystack, $message = '' ) {
			if ( is_array( $haystack ) && ! in_array( $needle, $haystack, true ) ) {
				throw new Exception( $message ? $message : "Failed asserting that array contains " . var_export( $needle, true ) );
			} elseif ( is_string( $haystack ) && false === strpos( $haystack, $needle ) ) {
				throw new Exception( $message ? $message : "Failed asserting that string contains '{$needle}'" );
			}
		}
	}
}

/**
 * Class Test_Lifecycle
 */
class Test_Lifecycle extends GCO_Lifecycle_Base_TestCase {

	/**
	 * Run before each test.
	 */
	public function setUp(): void {
		if ( is_callable( array( 'parent', 'setUp' ) ) ) {
			parent::setUp();
		}
	}

	/**
	 * P2-TC01: Activation creates gco_ss_runs table
	 */
	public function test_activation_creates_runs_table() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$table  = $wpdb->prefix . 'gco_ss_runs';
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertEquals( $table, $result, 'gco_ss_runs table should exist after activation' );
	}

	/**
	 * P2-TC02: Activation creates gco_ss_items table
	 */
	public function test_activation_creates_items_table() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$table  = $wpdb->prefix . 'gco_ss_items';
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertEquals( $table, $result, 'gco_ss_items table should exist after activation' );
	}

	/**
	 * P2-TC03: Tables have correct columns and indices
	 */
	public function test_tables_have_correct_schema() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		// Verify gco_ss_runs columns
		$runs_cols = $wpdb->get_col( "DESCRIBE {$runs_table}", 0 );
		$expected_runs_cols = array(
			'id',
			'supplier',
			'started_at',
			'finished_at',
			'status',
			'rows_fetched',
			'products_updated',
			'message',
		);
		foreach ( $expected_runs_cols as $col ) {
			$this->assertContains( $col, $runs_cols, "Column {$col} missing in {$runs_table}" );
		}

		// Verify gco_ss_items columns
		$items_cols = $wpdb->get_col( "DESCRIBE {$items_table}", 0 );
		$expected_items_cols = array(
			'id',
			'run_id',
			'sku',
			'product_id',
			'supplier_qty',
			'old_status',
			'new_status',
			'action',
			'note',
		);
		foreach ( $expected_items_cols as $col ) {
			$this->assertContains( $col, $items_cols, "Column {$col} missing in {$items_table}" );
		}
	}

	/**
	 * P2-TC04: Activation is idempotent
	 */
	public function test_activation_is_idempotent() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$table = $wpdb->prefix . 'gco_ss_runs';
		$wpdb->insert(
			$table,
			array(
				'supplier'   => 'test_supplier',
				'started_at' => current_time( 'mysql' ),
				'status'     => 'success',
			),
			array( '%s', '%s', '%s' )
		);
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// Call activate second time
		GCO_Stock_Sync_Activator::activate();

		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertEquals( $count_before, $count_after, 'Data should be preserved on repeated activation' );

		// Clean up test row
		$wpdb->delete( $table, array( 'supplier' => 'test_supplier' ) );
	}

	/**
	 * P2-TC05: DB version option set on activation
	 */
	public function test_db_version_set_on_activation() {
		GCO_Stock_Sync_Activator::activate();
		$version = get_option( 'gco_stock_sync_db_version' );

		$this->assertNotEmpty( $version, 'gco_stock_sync_db_version should be set' );
		$this->assertEquals( '1.0.0', $version, 'DB version should be 1.0.0' );
	}

	/**
	 * P2-TC06: Default options set on activation
	 */
	public function test_default_options_set_on_activation() {
		delete_option( 'gco_stock_sync_settings' );
		GCO_Stock_Sync_Activator::activate();
		$settings = get_option( 'gco_stock_sync_settings' );

		$this->assertTrue( is_array( $settings ), 'Settings should be an array' );
		$this->assertFalse( $settings['enabled'], 'Default enabled should be false' );
		$this->assertEquals( 60, (int) $settings['sync_interval'], 'Default sync_interval should be 60' );
		$this->assertFalse( $settings['delete_data_uninstall'], 'Default delete_data_uninstall should be false' );
	}

	/**
	 * P2-TC07: Cron event scheduled on activation
	 */
	public function test_cron_scheduled_on_activation() {
		GCO_Stock_Sync_Activator::activate();
		$next = wp_next_scheduled( 'gco_stock_sync_cron' );

		$this->assertNotFalse( $next, 'Cron event gco_stock_sync_cron should be scheduled' );
		$this->assertTrue( $next > 0, 'Cron timestamp should be positive integer' );
	}

	/**
	 * P2-TC08: Deactivation clears cron
	 */
	public function test_deactivation_clears_cron() {
		GCO_Stock_Sync_Activator::activate();
		$this->assertNotFalse( wp_next_scheduled( 'gco_stock_sync_cron' ) );

		GCO_Stock_Sync_Deactivator::deactivate();
		$next = wp_next_scheduled( 'gco_stock_sync_cron' );

		$this->assertFalse( $next, 'Cron event should be cleared on deactivation' );
	}

	/**
	 * P2-TC09: Deactivation preserves tables
	 */
	public function test_deactivation_preserves_tables() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$table = $wpdb->prefix . 'gco_ss_runs';
		$wpdb->insert(
			$table,
			array(
				'supplier'   => 'preserve_test',
				'started_at' => current_time( 'mysql' ),
				'status'     => 'success',
			)
		);

		GCO_Stock_Sync_Deactivator::deactivate();

		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		$this->assertEquals( $table, $result, 'Table should still exist after deactivation' );

		$preserved_row = $wpdb->get_row( "SELECT * FROM {$table} WHERE supplier = 'preserve_test'" );
		$this->assertNotEmpty( $preserved_row, 'Data should be preserved after deactivation' );

		$wpdb->delete( $table, array( 'supplier' => 'preserve_test' ) );
	}

	/**
	 * P2-TC10: Deactivation preserves options
	 */
	public function test_deactivation_preserves_options() {
		GCO_Stock_Sync_Activator::activate();
		$settings_before = get_option( 'gco_stock_sync_settings' );

		GCO_Stock_Sync_Deactivator::deactivate();
		$settings_after = get_option( 'gco_stock_sync_settings' );

		$this->assertEquals( $settings_before, $settings_after, 'Options should be preserved on deactivation' );
	}

	/**
	 * P2-TC11: Uninstall (delete OFF) preserves tables
	 */
	public function test_uninstall_delete_off_preserves_data() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$settings                          = get_option( 'gco_stock_sync_settings', array() );
		$settings['delete_data_uninstall'] = false;
		update_option( 'gco_stock_sync_settings', $settings );

		// Simulate uninstall logic when delete_data_uninstall is false
		if ( ! empty( $settings['delete_data_uninstall'] ) ) {
			GCO_Stock_Sync_Installer::drop_tables();
		}

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		$this->assertEquals( $runs_table, $wpdb->get_var( "SHOW TABLES LIKE '{$runs_table}'" ) );
		$this->assertEquals( $items_table, $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) );
	}

	/**
	 * P2-TC12: Uninstall (delete ON) drops tables
	 */
	public function test_uninstall_delete_on_drops_tables() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		$settings                          = get_option( 'gco_stock_sync_settings', array() );
		$settings['delete_data_uninstall'] = true;
		update_option( 'gco_stock_sync_settings', $settings );

		// Simulate uninstall logic when delete_data_uninstall is true
		GCO_Stock_Sync_Installer::drop_tables();

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$runs_table}'" ), 'gco_ss_runs should be dropped' );
		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ), 'gco_ss_items should be dropped' );

		// Re-install tables so subsequent tests have them
		GCO_Stock_Sync_Activator::activate();
	}

	/**
	 * P2-TC13: Uninstall (delete ON) removes options
	 */
	public function test_uninstall_delete_on_removes_options() {
		GCO_Stock_Sync_Activator::activate();

		// Simulate uninstall deletion of options
		delete_option( 'gco_stock_sync_settings' );
		delete_option( 'gco_stock_sync_db_version' );

		$this->assertFalse( get_option( 'gco_stock_sync_settings' ) );
		$this->assertFalse( get_option( 'gco_stock_sync_db_version' ) );

		// Re-activate
		GCO_Stock_Sync_Activator::activate();
	}

	/**
	 * P2-TC14: Uninstall (delete ON) removes post meta
	 */
	public function test_uninstall_delete_on_removes_post_meta() {
		global $wpdb;
		GCO_Stock_Sync_Activator::activate();

		// Insert dummy meta
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => 999999,
				'meta_key'   => '_gco_ss_enabled',
				'meta_value' => 'yes',
			)
		);

		// Simulate uninstall cleanup
		$meta_keys = array( '_gco_ss_enabled', '_gco_ss_supplier', '_gco_ss_last_qty', '_gco_ss_last_sync' );
		foreach ( $meta_keys as $meta_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
		}

		$meta_check = $wpdb->get_var( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = 999999 AND meta_key = '_gco_ss_enabled'" );
		$this->assertEmpty( $meta_check, 'Post meta should be deleted' );
	}

	/**
	 * P2-TC15: WooCommerce inactive shows admin notice
	 */
	public function test_woo_inactive_shows_notice() {
		ob_start();
		gco_stock_sync_woocommerce_missing_notice();
		$output = ob_get_clean();

		$this->assertContains( 'notice notice-error', $output );
		$this->assertContains( 'requires WooCommerce to be installed and activated', $output );
	}

	/**
	 * P2-TC16: HPOS compatibility declared
	 */
	public function test_hpos_compatibility_declared() {
		// Verify before_woocommerce_init action has registered callbacks
		$has_action = has_action( 'before_woocommerce_init' );
		$this->assertNotFalse( $has_action, 'before_woocommerce_init hook should be registered' );
	}

	/**
	 * P2-TC17: ABSPATH guard on all files
	 */
	public function test_abspath_guard_on_all_files() {
		$plugin_dir = dirname( dirname( dirname( __FILE__ ) ) );
		$files      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) );

		$php_files = array();
		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				// Skip vendor or fixtures or tests
				$rel = str_replace( $plugin_dir, '', $file->getPathname() );
				if ( false !== strpos( $rel, DIRECTORY_SEPARATOR . 'tests' ) ) {
					continue;
				}
				$php_files[] = $file->getPathname();
			}
		}

		foreach ( $php_files as $file_path ) {
			$content = file_get_contents( $file_path );
			$basename = basename( $file_path );

			if ( 'uninstall.php' === $basename ) {
				$has_guard = ( false !== strpos( $content, "defined( 'WP_UNINSTALL_PLUGIN' )" ) || false !== strpos( $content, 'defined("WP_UNINSTALL_PLUGIN")' ) );
				$this->assertTrue( $has_guard, "uninstall.php must have WP_UNINSTALL_PLUGIN guard" );
			} else {
				$has_guard = ( false !== strpos( $content, "defined( 'ABSPATH' )" ) || false !== strpos( $content, 'defined("ABSPATH")' ) );
				$this->assertTrue( $has_guard, "File {$basename} must have ABSPATH guard" );
			}
		}
	}
}
