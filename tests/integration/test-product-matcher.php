<?php
/**
 * Test Product Matcher — Integration Tests for Product Matcher (Phase 4)
 *
 * Tests P4-TC19, P4-TC20, P4-TC21, plus unmatched/variation coverage that
 * overlaps with class-product-matcher.php specifically.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Matcher_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Matcher_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Matcher_Base_TestCase {
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
		protected function assertNull( $value, $message = '' ) {
			if ( null !== $value ) {
				throw new Exception( $message ? $message : "Failed asserting that value is null" );
			}
		}
		protected function assertNotEmpty( $value, $message = '' ) {
			if ( empty( $value ) ) {
				throw new Exception( $message ? $message : "Failed asserting that value is not empty" );
			}
		}
	}
}

/**
 * Class Test_Product_Matcher
 */
class Test_Product_Matcher extends GCO_Matcher_Base_TestCase {

	/**
	 * IDs of products created during the test, cleaned up in tearDown.
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	/**
	 * Run before each test.
	 */
	public function setUp(): void {
		if ( is_callable( array( 'parent', 'setUp' ) ) ) {
			parent::setUp();
		}
		$this->created_ids = array();
	}

	/**
	 * Run after each test — force-delete every product/variation created.
	 */
	public function tearDown(): void {
		foreach ( $this->created_ids as $id ) {
			wp_delete_post( $id, true );
		}
		if ( is_callable( array( 'parent', 'tearDown' ) ) ) {
			parent::tearDown();
		}
	}

	/**
	 * Create a simple WooCommerce product with a given SKU.
	 *
	 * @param string $sku SKU to assign.
	 * @return WC_Product_Simple
	 */
	private function create_product( $sku ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'GCO Matcher Test — ' . $sku );
		$product->set_sku( $sku );
		$product->set_stock_status( 'instock' );
		$product->set_manage_stock( false );
		$product->save();

		$this->created_ids[] = $product->get_id();

		return $product;
	}

	/**
	 * P4-TC19: SKU matching is case-insensitive.
	 */
	public function test_sku_matching_case_insensitive() {
		$product = $this->create_product( 'GCO-MATCH-ABC-123' );

		$matcher = new GCO_Stock_Sync_Product_Matcher();
		$result  = $matcher->find_by_sku( 'gco-match-abc-123' );

		$this->assertEquals( $product->get_id(), $result['product_id'] );
	}

	/**
	 * P4-TC20: SKU matching trims whitespace.
	 */
	public function test_sku_matching_trims_whitespace() {
		$product = $this->create_product( 'GCO-MATCH-XYZ-789' );

		$matcher = new GCO_Stock_Sync_Product_Matcher();
		$result  = $matcher->find_by_sku( '  GCO-MATCH-XYZ-789  ' );

		$this->assertEquals( $product->get_id(), $result['product_id'] );
		$this->assertTrue( $result['normalized'] );
		$this->assertNotEmpty( $result['note'] );
	}

	/**
	 * P4-TC21: Match that only succeeds after case+whitespace normalisation logs a note.
	 */
	public function test_normalised_match_logs_note() {
		$product = $this->create_product( 'GCO-MATCH-Mixed-Case' );

		$matcher = new GCO_Stock_Sync_Product_Matcher();
		$result  = $matcher->find_by_sku( '  gco-match-mixed-case  ' );

		$this->assertEquals( $product->get_id(), $result['product_id'] );
		$this->assertTrue( $result['normalized'] );
		$this->assertNotEmpty( $result['note'] );
	}

	/**
	 * Unmatched SKU returns null product_id with no error.
	 */
	public function test_unmatched_sku_returns_null() {
		$matcher = new GCO_Stock_Sync_Product_Matcher();
		$result  = $matcher->find_by_sku( 'GCO-DOES-NOT-EXIST-999' );

		$this->assertNull( $result['product_id'] );
	}

	/**
	 * Variation SKU resolves to the variation ID, not the parent.
	 */
	public function test_variation_sku_resolves_to_variation() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'GCO Matcher Test — Variable Parent' );
		$parent->save();
		$this->created_ids[] = $parent->get_id();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_sku( 'GCO-MATCH-VAR-SKU-1' );
		$variation->set_stock_status( 'outofstock' );
		$variation->save();
		$this->created_ids[] = $variation->get_id();

		$matcher = new GCO_Stock_Sync_Product_Matcher();
		$result  = $matcher->find_by_sku( 'GCO-MATCH-VAR-SKU-1' );

		$this->assertEquals( $variation->get_id(), $result['product_id'] );
		$this->assertFalse( $result['product_id'] === $parent->get_id() );
	}
}
