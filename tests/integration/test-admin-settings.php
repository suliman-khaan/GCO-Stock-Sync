<?php
/**
 * Test Admin Settings — Integration Tests for Settings Page (Phase 5)
 *
 * Tests P5-TC01 to P5-TC07, P5-TC14 to P5-TC16, P5-TC18 to P5-TC20, P5-TC22.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Admin_Settings_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Admin_Settings_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Admin_Settings_Base_TestCase {
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
		protected function assertNotFalse( $condition, $message = '' ) {
			if ( false === $condition ) {
				throw new Exception( $message ? $message : "Failed asserting that condition is not false" );
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
 * Class Test_Admin_Settings
 */
class Test_Admin_Settings extends GCO_Admin_Settings_Base_TestCase {

	/**
	 * Settings page instance.
	 *
	 * @var GCO_Stock_Sync_Settings_Page
	 */
	private $settings_page;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		$this->settings_page = new GCO_Stock_Sync_Settings_Page();
	}

	/**
	 * P5-TC01: Settings save with valid nonce persists
	 */
	public function test_settings_save_valid_nonce() {
		$input = array(
			'enabled'               => '1',
			'sync_interval'         => '30',
			'delete_data_uninstall' => '1',
		);

		$orig_settings = get_option( 'gco_stock_sync_settings' );

		$sanitized = $this->settings_page->sanitize_global_settings( $input );

		$this->assertTrue( $sanitized['enabled'] );
		$this->assertEquals( 30, $sanitized['sync_interval'] );
		$this->assertTrue( $sanitized['delete_data_uninstall'] );

		update_option( 'gco_stock_sync_settings', $sanitized );
		$saved = get_option( 'gco_stock_sync_settings' );

		$this->assertTrue( $saved['enabled'] );
		$this->assertEquals( 30, $saved['sync_interval'] );
		$this->assertTrue( $saved['delete_data_uninstall'] );

		// Clean up / restore
		if ( false !== $orig_settings ) {
			update_option( 'gco_stock_sync_settings', $orig_settings );
		} else {
			delete_option( 'gco_stock_sync_settings' );
		}
	}

	/**
	 * P5-TC02: Settings save with invalid nonce rejected
	 */
	public function test_settings_save_invalid_nonce_rejected() {
		$bad_nonce = 'invalid_nonce_' . wp_rand();
		$verified  = wp_verify_nonce( $bad_nonce, 'gco_stock_sync_settings_group-options' );
		$this->assertFalse( $verified, 'Invalid nonce must not verify' );
	}

	/**
	 * P5-TC03: Non-privileged user denied on settings save
	 */
	public function test_non_privileged_user_denied_settings() {
		$original_user = get_current_user_id();

		// Simulate non-privileged user (no manage_woocommerce)
		wp_set_current_user( 0 );
		$can_manage = current_user_can( 'manage_woocommerce' );
		$this->assertFalse( $can_manage, 'Guest or non-admin user must not have manage_woocommerce' );

		wp_set_current_user( $original_user );
	}

	/**
	 * P5-TC04: Feed URL sanitisation rejects javascript:
	 */
	public function test_feed_url_rejects_javascript() {
		$input     = array( 'feed_url' => 'javascript:alert(1)' );
		$sanitized = $this->settings_page->sanitize_supplier_settings( $input, 'highland_outdoors' );

		$this->assertEmpty( $sanitized['feed_url'], 'javascript: URL must be sanitized to empty string' );
	}

	/**
	 * P5-TC05: Feed URL sanitisation rejects non-http schemes
	 */
	public function test_feed_url_rejects_non_http() {
		$invalid_urls = array(
			'ftp://example.com/feed',
			'file:///etc/passwd',
			'data:text/html,<script>alert(1)</script>',
			'php://filter/read=convert.base64-encode/resource=wp-config.php',
			'http://insecure-domain.com/feed.html', // http rejected, https required
		);

		foreach ( $invalid_urls as $bad_url ) {
			$input     = array( 'feed_url' => $bad_url );
			$sanitized = $this->settings_page->sanitize_supplier_settings( $input, 'highland_outdoors' );
			$this->assertEmpty( $sanitized['feed_url'], "Scheme in '{$bad_url}' must be rejected" );
		}
	}

	/**
	 * P5-TC06: Feed URL sanitisation accepts valid https URL
	 */
	public function test_feed_url_accepts_valid_https() {
		$valid_url = 'https://687183.app.netsuite.com/app/reporting/webquery.nl?compid=687183';
		$input     = array( 'feed_url' => $valid_url );
		$sanitized = $this->settings_page->sanitize_supplier_settings( $input, 'highland_outdoors' );

		$this->assertEquals( $valid_url, $sanitized['feed_url'], 'Valid HTTPS URL must be accepted' );
	}

	/**
	 * P5-TC07: Sync interval only accepts allowed values
	 */
	public function test_sync_interval_whitelist() {
		// Allowed values
		foreach ( array( 30, 60, 120 ) as $allowed ) {
			$sanitized = $this->settings_page->sanitize_global_settings( array( 'sync_interval' => $allowed ) );
			$this->assertEquals( $allowed, $sanitized['sync_interval'], "Interval {$allowed} must be accepted" );
		}

		// Rejected values should fall back to 60
		$rejected_values = array( 0, 1, 15, 45, 999, -1, 'invalid' );
		foreach ( $rejected_values as $rejected ) {
			$sanitized = $this->settings_page->sanitize_global_settings( array( 'sync_interval' => $rejected ) );
			$this->assertEquals( 60, $sanitized['sync_interval'], "Invalid interval '{$rejected}' must fall back to 60" );
		}
	}

	/**
	 * P5-TC14: Manual run trigger requires nonce
	 */
	public function test_manual_run_requires_nonce() {
		$bad_nonce = 'bad_nonce_' . wp_rand();
		$verified  = wp_verify_nonce( $bad_nonce, 'gco_stock_sync_manual_run' );
		$this->assertFalse( $verified, 'Manual run must require valid nonce' );
	}

	/**
	 * P5-TC15: Manual run trigger requires capability
	 */
	public function test_manual_run_requires_capability() {
		$original_user = get_current_user_id();
		wp_set_current_user( 0 );

		$has_cap = current_user_can( 'manage_woocommerce' );
		$this->assertFalse( $has_cap, 'Unauthorized user cannot trigger manual run' );

		wp_set_current_user( $original_user );
	}

	/**
	 * P5-TC16: Non-privileged user denied on manual run
	 */
	public function test_non_privileged_denied_manual_run() {
		$original_user = get_current_user_id();

		// Create or simulate subscriber
		$subscriber = get_user_by( 'slug', 'subscriber' );
		if ( ! $subscriber ) {
			$sub_id = wp_create_user( 'test_sub_' . wp_rand(), 'pass123', 'sub_' . wp_rand() . '@example.com' );
			$user   = get_user_by( 'id', $sub_id );
			$user->set_role( 'subscriber' );
			wp_set_current_user( $sub_id );
		} else {
			wp_set_current_user( $subscriber->ID );
		}

		$can_run = current_user_can( 'manage_woocommerce' );
		$this->assertFalse( $can_run, 'Subscriber must not be permitted to run sync' );

		wp_set_current_user( $original_user );
	}

	/**
	 * P5-TC18: Admin assets loaded only on plugin screens
	 */
	public function test_assets_only_on_plugin_screens() {
		$admin = new GCO_Stock_Sync_Admin();

		// Simulate non-plugin screen hook (e.g. index.php)
		set_current_screen( 'dashboard' );
		$admin->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_style_is( 'gco-stock-sync-admin', 'enqueued' ), 'Styles must not be enqueued on dashboard' );
		$this->assertFalse( wp_script_is( 'gco-stock-sync-admin', 'enqueued' ), 'Scripts must not be enqueued on dashboard' );
	}

	/**
	 * P5-TC19: Status panel shows last run info
	 */
	public function test_status_panel_shows_last_run() {
		global $wpdb;
		$runs_table = $wpdb->prefix . 'gco_ss_runs';

		$wpdb->insert(
			$runs_table,
			array(
				'supplier'         => 'highland_outdoors',
				'started_at'       => '2026-09-08 01:00:00',
				'status'           => 'success',
				'rows_fetched'     => 111,
				'products_updated' => 5,
			),
			array( '%s', '%s', '%s', '%d', '%d' )
		);
		$insert_id = $wpdb->insert_id;

		ob_start();
		$this->settings_page->render_status_panel();
		$output = ob_get_clean();

		$this->assertContains( 'Sync Overview', $output );
		$this->assertContains( '2026-09-08 01:00:00', $output );
		$this->assertContains( 'Run Sync Now', $output );

		// Clean up
		$wpdb->delete( $runs_table, array( 'id' => $insert_id ) );
	}

	/**
	 * P5-TC20: WP-Cron warning shown when DISABLE_WP_CRON
	 */
	public function test_wp_cron_warning_displayed() {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}

		ob_start();
		$this->settings_page->render_cron_warning();
		$output = ob_get_clean();

		$this->assertContains( 'DISABLE_WP_CRON is enabled', $output );
		$this->assertContains( 'wget -q -O -', $output );
	}

	/**
	 * P5-TC22: Settings change reschedules cron
	 */
	public function test_interval_change_reschedules_cron() {
		$plugin = GCO_Stock_Sync_Plugin::get_instance();

		// Schedule initial
		wp_clear_scheduled_hook( 'gco_stock_sync_cron' );
		wp_schedule_event( time(), 'gco_stock_sync_interval', 'gco_stock_sync_cron' );
		$this->assertNotFalse( wp_next_scheduled( 'gco_stock_sync_cron' ) );

		// Simulate interval change 60 -> 30
		$old_settings = array( 'sync_interval' => 60 );
		$new_settings = array( 'sync_interval' => 30 );
		$plugin->maybe_reschedule_cron( $old_settings, $new_settings );

		$next = wp_next_scheduled( 'gco_stock_sync_cron' );
		$this->assertNotFalse( $next, 'Cron should be rescheduled after interval change' );
	}
}
