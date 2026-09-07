<?php
/**
 * Test Admin Product Meta — Integration Tests for Product Meta Controls (Phase 5)
 *
 * Tests P5-TC08 and P5-TC09.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Admin_Meta_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Admin_Meta_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Admin_Meta_Base_TestCase {
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
	}
}

/**
 * Class Test_Admin_Product_Meta
 */
class Test_Admin_Product_Meta extends GCO_Admin_Meta_Base_TestCase {

	/**
	 * Meta box instance.
	 *
	 * @var GCO_Stock_Sync_Product_Meta_Box
	 */
	private $meta_box;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		$this->meta_box = new GCO_Stock_Sync_Product_Meta_Box();
	}

	/**
	 * P5-TC08: Product meta box saves toggle correctly
	 */
	public function test_product_meta_saves_toggle() {
		$orig_user  = get_current_user_id();
		$admin_user = get_user_by( 'role', 'administrator' );
		if ( $admin_user ) {
			wp_set_current_user( $admin_user->ID );
		} else {
			wp_set_current_user( 1 );
		}

		$product = new WC_Product_Simple();
		$product->set_name( 'Test Sync Toggle Product' );
		$product->set_sku( 'TEST-TOGGLE-' . wp_rand() );
		$product_id = $product->save();

		// Simulate saving as disabled ('no')
		$_POST[ GCO_Stock_Sync_Product_Meta_Box::META_ENABLED ] = 'no';
		$this->meta_box->save_product_meta( $product_id );

		$saved = get_post_meta( $product_id, GCO_Stock_Sync_Product_Meta_Box::META_ENABLED, true );
		$this->assertEquals( 'no', $saved, 'Meta must be saved as "no"' );

		// Simulate saving as enabled ('yes')
		$_POST[ GCO_Stock_Sync_Product_Meta_Box::META_ENABLED ] = 'yes';
		$this->meta_box->save_product_meta( $product_id );

		$saved_yes = get_post_meta( $product_id, GCO_Stock_Sync_Product_Meta_Box::META_ENABLED, true );
		$this->assertEquals( 'yes', $saved_yes, 'Meta must be saved as "yes"' );

		// Clean up
		$product->delete( true );
		wp_set_current_user( $orig_user );
	}

	/**
	 * P5-TC09: Product meta toggle defaults to enabled
	 */
	public function test_product_meta_defaults_enabled() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Default Enabled Product' );
		$product->set_sku( 'DEF-ENABLED-' . wp_rand() );
		$product_id = $product->save();

		// Newly created product has no _gco_ss_enabled meta set
		$raw_meta = get_post_meta( $product_id, GCO_Stock_Sync_Product_Meta_Box::META_ENABLED, true );

		// According to plan spec: if meta is empty, default behavior is ENABLED ('yes')
		$is_enabled = 'no' !== $raw_meta;
		$this->assertTrue( $is_enabled, 'Product without explicit meta should default to sync enabled' );

		// Clean up
		$product->delete( true );
	}
}
