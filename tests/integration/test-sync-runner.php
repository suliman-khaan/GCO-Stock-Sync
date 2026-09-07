<?php
/**
 * Test Sync Runner — Integration Tests for Sync Engine (Phase 4, CRITICAL)
 *
 * Tests P4-TC01 through P4-TC26. These protect the client's live storefront —
 * every test here must pass. All test products use a GCO-SYNC-TEST- prefix
 * and are force-deleted in tearDown so nothing real is ever touched.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Runner_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Runner_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Runner_Base_TestCase {
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
		protected function assertNotNull( $value, $message = '' ) {
			if ( null === $value ) {
				throw new Exception( $message ? $message : "Failed asserting that value is not null" );
			}
		}
		protected function assertEmpty( $value, $message = '' ) {
			if ( ! empty( $value ) ) {
				throw new Exception( $message ? $message : "Failed asserting that value is empty" );
			}
		}
		protected function assertNotEmpty( $value, $message = '' ) {
			if ( empty( $value ) ) {
				throw new Exception( $message ? $message : "Failed asserting that value is not empty" );
			}
		}
		protected function assertContains( $needle, $haystack, $message = '' ) {
			if ( is_string( $haystack ) && false === strpos( $haystack, $needle ) ) {
				throw new Exception( $message ? $message : "Failed asserting that string contains '{$needle}'" );
			}
		}
		protected function fail( $message = '' ) {
			throw new Exception( $message ? $message : 'Failed' );
		}
	}
}

/**
 * Minimal in-test supplier stub — returns a canned fetch result without any HTTP call.
 */
class GCO_Test_Stub_Supplier implements GCO_Stock_Sync_Supplier_Interface {

	/**
	 * The canned result to return from fetch().
	 *
	 * @var GCO_Stock_Sync_Fetch_Result
	 */
	private $result;

	/**
	 * Constructor.
	 *
	 * @param GCO_Stock_Sync_Fetch_Result $result Canned result.
	 */
	public function __construct( $result ) {
		$this->result = $result;
	}

	public function get_key() {
		return 'gco_test_stub';
	}

	public function get_label() {
		return 'GCO Test Stub';
	}

	public function get_settings_fields() {
		return array();
	}

	public function is_configured() {
		return true;
	}

	public function fetch() {
		return $this->result;
	}
}

/**
 * Supplier stub whose fetch() throws, to test lock release on exception.
 */
class GCO_Test_Throwing_Supplier implements GCO_Stock_Sync_Supplier_Interface {

	public function get_key() {
		return 'gco_test_throwing';
	}

	public function get_label() {
		return 'GCO Test Throwing';
	}

	public function get_settings_fields() {
		return array();
	}

	public function is_configured() {
		return true;
	}

	public function fetch() {
		throw new RuntimeException( 'Simulated fatal during fetch.' );
	}
}

/**
 * Product matcher stub that throws for one specific SKU, delegating to the
 * real matcher for everything else.
 */
class GCO_Test_Throwing_Matcher extends GCO_Stock_Sync_Product_Matcher {

	/**
	 * The SKU that should trigger an exception.
	 *
	 * @var string
	 */
	private $throw_for_sku;

	/**
	 * Constructor.
	 *
	 * @param string $throw_for_sku SKU that triggers the exception.
	 */
	public function __construct( $throw_for_sku ) {
		$this->throw_for_sku = $throw_for_sku;
	}

	public function find_by_sku( $sku ) {
		if ( $sku === $this->throw_for_sku ) {
			throw new RuntimeException( 'Simulated exception matching ' . $sku );
		}

		return parent::find_by_sku( $sku );
	}
}

/**
 * Class Test_Sync_Runner
 */
class Test_Sync_Runner extends GCO_Runner_Base_TestCase {

	/**
	 * IDs of products/variations created during the test.
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
		delete_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY );
	}

	/**
	 * Run after each test — force-delete every product created and clear the lock.
	 */
	public function tearDown(): void {
		foreach ( $this->created_ids as $id ) {
			wp_delete_post( $id, true );
		}
		delete_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY );
		if ( is_callable( array( 'parent', 'tearDown' ) ) ) {
			parent::tearDown();
		}
	}

	/**
	 * Create a test WooCommerce product. All test SKUs are prefixed
	 * GCO-SYNC-TEST- so they can never collide with real store data.
	 *
	 * @param string $sku          SKU suffix (prefixed automatically).
	 * @param string $stock_status Initial stock status.
	 * @param string $enabled      '_gco_ss_enabled' meta value.
	 * @return WC_Product_Simple
	 */
	private function create_test_product( $sku, $stock_status = 'instock', $enabled = 'yes' ) {
		$full_sku = 'GCO-SYNC-TEST-' . $sku;

		$product = new WC_Product_Simple();
		$product->set_name( 'GCO Sync Test — ' . $full_sku );
		$product->set_sku( $full_sku );
		$product->set_stock_status( $stock_status );
		$product->set_manage_stock( false );
		$product->save();

		update_post_meta( $product->get_id(), '_gco_ss_enabled', $enabled );
		update_post_meta( $product->get_id(), '_gco_ss_supplier', 'gco_test_stub' );

		$this->created_ids[] = $product->get_id();

		return array( $full_sku, $product );
	}

	/**
	 * Build a successful fetch result from a simple sku => qty map.
	 *
	 * @param array $sku_qty_map Map of full SKU to quantity.
	 * @return GCO_Stock_Sync_Fetch_Result
	 */
	private function fetch_result( $sku_qty_map ) {
		$items = array();
		foreach ( $sku_qty_map as $sku => $qty ) {
			$items[] = array(
				'sku'         => $sku,
				'qty'         => $qty,
				'name'        => $sku,
				'internal_id' => '0',
			);
		}

		return GCO_Stock_Sync_Fetch_Result::success( $items, count( $items ) + 5 );
	}

	// -----------------------------------------------------------------
	// P4-TC01–05: A failed fetch NEVER touches any product.
	// -----------------------------------------------------------------

	/**
	 * Shared assertion for all "failed fetch touches nothing" cases.
	 *
	 * @param GCO_Stock_Sync_Fetch_Result $failure Failure fetch result to test against.
	 */
	private function assert_failed_fetch_touches_nothing( $failure ) {
		global $wpdb;

		list( , $products ) = $this->seed_five_instock_products();

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $failure ) );

		foreach ( $products as $product ) {
			$refreshed = wc_get_product( $product->get_id() );
			$this->assertEquals( 'instock', $refreshed->get_stock_status(), 'Product was modified during a failed sync!' );
		}

		$this->assertEquals( 'failed', $result->status );

		$items_table = $wpdb->prefix . 'gco_ss_items';
		$item_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items_table} WHERE run_id = %d", $result->run_id ) );
		$this->assertEquals( 0, $item_count, 'No item rows should be recorded for a failed fetch.' );
	}

	/**
	 * Seed 5 instock test products with sequential SKUs.
	 *
	 * @return array [skus[], products[]]
	 */
	private function seed_five_instock_products() {
		$skus     = array();
		$products = array();
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $letter ) {
			list( $sku, $product ) = $this->create_test_product( 'FAIL-' . $letter, 'instock' );
			$skus[]     = $sku;
			$products[] = $product;
		}

		return array( $skus, $products );
	}

	/**
	 * P4-TC01: WP_Error fetch leaves all stock untouched.
	 */
	public function test_wp_error_leaves_all_stock_untouched() {
		$this->assert_failed_fetch_touches_nothing( GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', 'Connection timed out' ) );
	}

	/**
	 * P4-TC02: HTTP 500 fetch leaves all stock untouched.
	 */
	public function test_http_500_leaves_all_stock_untouched() {
		$this->assert_failed_fetch_touches_nothing( GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', 'Unexpected HTTP status: 500' ) );
	}

	/**
	 * P4-TC03: Empty body fetch leaves all stock untouched.
	 */
	public function test_empty_body_leaves_all_stock_untouched() {
		$this->assert_failed_fetch_touches_nothing( GCO_Stock_Sync_Fetch_Result::failure( 'fetch_failed', 'Empty response body.' ) );
	}

	/**
	 * P4-TC04: Garbage body (parse_failed) fetch leaves all stock untouched.
	 */
	public function test_garbage_body_leaves_all_stock_untouched() {
		$this->assert_failed_fetch_touches_nothing( GCO_Stock_Sync_Fetch_Result::failure( 'parse_failed', 'Unable to parse the feed table.' ) );
	}

	/**
	 * P4-TC05: Suspiciously empty fetch leaves all stock untouched.
	 */
	public function test_suspiciously_empty_leaves_all_stock_untouched() {
		$this->assert_failed_fetch_touches_nothing( GCO_Stock_Sync_Fetch_Result::failure( 'suspiciously_empty', 'Feed returned fewer product rows than the configured minimum.' ) );
	}

	// -----------------------------------------------------------------
	// Core stock-status logic.
	// -----------------------------------------------------------------

	/**
	 * P4-TC06: qty > 0 sets instock.
	 */
	public function test_qty_positive_sets_instock() {
		list( $sku, $product ) = $this->create_test_product( 'POS-A', 'outofstock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 5 ) ) ) );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertEquals( 'instock', $refreshed->get_stock_status() );
		$this->assertEquals( 'updated', $result->items[0]['action'] );
		$this->assertEquals( 'outofstock', $result->items[0]['old_status'] );
		$this->assertEquals( 'instock', $result->items[0]['new_status'] );
	}

	/**
	 * P4-TC07: qty = 0 sets outofstock.
	 */
	public function test_qty_zero_sets_outofstock() {
		list( $sku, $product ) = $this->create_test_product( 'ZERO-B', 'instock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 0 ) ) ) );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertEquals( 'outofstock', $refreshed->get_stock_status() );
		$this->assertEquals( 'updated', $result->items[0]['action'] );
	}

	/**
	 * P4-TC08: qty = 1 is instock — no safety buffer.
	 */
	public function test_qty_one_is_instock_no_buffer() {
		list( $sku, $product ) = $this->create_test_product( 'ONE-C', 'outofstock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 1 ) ) ) );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertEquals( 'instock', $refreshed->get_stock_status() );
	}

	/**
	 * P4-TC09: Disabled product is never touched.
	 */
	public function test_disabled_product_never_touched() {
		list( $sku, $product ) = $this->create_test_product( 'DISABLED-D', 'instock', 'no' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 0 ) ) ) );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertEquals( 'instock', $refreshed->get_stock_status(), 'Disabled product must never change status' );
		$this->assertEquals( 'skipped', $result->items[0]['action'] );
		$this->assertEmpty( get_post_meta( $product->get_id(), '_gco_ss_last_qty', true ) );
		$this->assertEmpty( get_post_meta( $product->get_id(), '_gco_ss_last_sync', true ) );
	}

	/**
	 * P4-TC10: Unmatched SKU is recorded but the run still succeeds.
	 */
	public function test_unmatched_sku_recorded_run_succeeds() {
		list( $valid_sku, $valid_product ) = $this->create_test_product( 'VALID-E', 'outofstock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run(
			'gco_test_stub',
			new GCO_Test_Stub_Supplier( $this->fetch_result(
				array(
					'GCO-SYNC-TEST-DOES-NOT-EXIST' => 5,
					$valid_sku                      => 5,
				)
			) )
		);

		$this->assertEquals( 'success', $result->status );
		$this->assertEquals( 'unmatched', $result->items[0]['action'] );
		$this->assertNull( $result->items[0]['product_id'] );
		$this->assertEquals( 'instock', wc_get_product( $valid_product->get_id() )->get_stock_status() );
	}

	/**
	 * P4-TC11: Product on site but not in feed is left completely alone.
	 */
	public function test_product_not_in_feed_untouched() {
		list( , $product ) = $this->create_test_product( 'ABSENT-F', 'instock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( 'GCO-SYNC-TEST-SOMETHING-ELSE' => 5 ) ) ) );

		$this->assertEquals( 'instock', wc_get_product( $product->get_id() )->get_stock_status() );
		$this->assertEquals( 1, count( $result->items ), 'Only the fed SKU should produce an item row.' );
	}

	/**
	 * P4-TC12: stock_quantity is never written.
	 *
	 * Uses manage_stock=true with a non-zero quantity so WooCommerce's own
	 * auto-status logic (which forces outofstock when quantity <= 0 for a
	 * managed product) doesn't interfere with the assertion — the point of
	 * this test is that the plugin itself never touches quantity/_stock,
	 * only set_stock_status().
	 */
	public function test_stock_quantity_never_written() {
		list( $sku, $product ) = $this->create_test_product( 'QTY-G', 'outofstock' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 42 );
		$product->save();

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 5 ) ) ) );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertEquals( 'instock', $refreshed->get_stock_status() );
		$this->assertEquals( '42', get_post_meta( $product->get_id(), '_stock', true ) );
	}

	/**
	 * P4-TC13: Already-correct status is recorded unchanged, meta still updated.
	 */
	public function test_already_correct_status_unchanged() {
		list( $sku, $product ) = $this->create_test_product( 'SAME-H', 'instock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 5 ) ) ) );

		$this->assertEquals( 'unchanged', $result->items[0]['action'] );
		$this->assertEquals( '5', get_post_meta( $product->get_id(), '_gco_ss_last_qty', true ) );
		$this->assertNotEmpty( get_post_meta( $product->get_id(), '_gco_ss_last_sync', true ) );
	}

	/**
	 * P4-TC14: Variation SKU updates the variation, not the parent.
	 *
	 * Note: WooCommerce's own variable-product stock status is computed
	 * independently of a single variation's status (there's no automatic
	 * parent resync outside the admin variations UI), so this test captures
	 * the parent's status before the sync and asserts it is byte-for-byte
	 * unchanged afterwards, rather than assuming a specific value.
	 */
	public function test_variation_sku_updates_variation() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'GCO Sync Test — Variable Parent' );
		$parent->save();
		$this->created_ids[] = $parent->get_id();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_sku( 'GCO-SYNC-TEST-VAR-SKU-1' );
		$variation->set_stock_status( 'outofstock' );
		$variation->set_manage_stock( false );
		$variation->save();
		$this->created_ids[] = $variation->get_id();

		$parent_status_before = wc_get_product( $parent->get_id() )->get_stock_status();

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( 'GCO-SYNC-TEST-VAR-SKU-1' => 3 ) ) ) );

		$refreshed_variation = wc_get_product( $variation->get_id() );
		$refreshed_parent     = wc_get_product( $parent->get_id() );

		$this->assertEquals( 'instock', $refreshed_variation->get_stock_status() );
		$this->assertEquals( $parent_status_before, $refreshed_parent->get_stock_status(), 'Parent status must be untouched by a variation sync.' );
	}

	// -----------------------------------------------------------------
	// Locking.
	// -----------------------------------------------------------------

	/**
	 * P4-TC15: A held lock causes the run to be skipped with no processing.
	 */
	public function test_lock_prevents_concurrent_run() {
		global $wpdb;

		set_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY, time(), 900 );

		list( $sku, $product ) = $this->create_test_product( 'LOCKED-I', 'instock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 0 ) ) ) );

		$this->assertEquals( 'skipped', $result->status );
		$this->assertEquals( 'instock', wc_get_product( $product->get_id() )->get_stock_status(), 'Locked run must not touch products.' );
		$this->assertNotNull( get_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY ), 'Lock must remain intact.' );

		$runs_table = $wpdb->prefix . 'gco_ss_runs';
		$status     = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$runs_table} WHERE id = %d", $result->run_id ) );
		$this->assertEquals( 'skipped', $status );
	}

	/**
	 * P4-TC23: Lock is released even when an exception escapes the run.
	 */
	public function test_lock_released_on_exception() {
		$runner = new GCO_Stock_Sync_Sync_Runner();

		$threw = false;
		try {
			$runner->run( 'gco_test_throwing', new GCO_Test_Throwing_Supplier() );
		} catch ( Throwable $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'Expected the simulated fetch exception to propagate.' );
		$this->assertFalse( get_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY ), 'Lock must be released even after an exception.' );
	}

	// -----------------------------------------------------------------
	// Logging / DB rows.
	// -----------------------------------------------------------------

	/**
	 * P4-TC16: Run row and item rows are written with correct data.
	 */
	public function test_run_and_item_rows_correct() {
		global $wpdb;

		list( $update_sku, $update_product ) = $this->create_test_product( 'ROWS-UPDATE', 'outofstock' );
		list( $same_sku, $same_product )     = $this->create_test_product( 'ROWS-SAME', 'instock' );
		list( $disabled_sku, $disabled_product ) = $this->create_test_product( 'ROWS-DISABLED', 'instock', 'no' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run(
			'gco_test_stub',
			new GCO_Test_Stub_Supplier( $this->fetch_result(
				array(
					$update_sku   => 5,
					$same_sku     => 5,
					$disabled_sku => 0,
				)
			) )
		);

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		$run_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs_table} WHERE id = %d", $result->run_id ), ARRAY_A );
		$this->assertEquals( 'gco_test_stub', $run_row['supplier'] );
		$this->assertEquals( 'success', $run_row['status'] );
		$this->assertNotEmpty( $run_row['started_at'] );
		$this->assertNotEmpty( $run_row['finished_at'] );
		$this->assertEquals( 1, (int) $run_row['products_updated'] );

		$item_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE run_id = %d", $result->run_id ), ARRAY_A );
		$this->assertEquals( 3, count( $item_rows ) );
	}

	/**
	 * P4-TC17: An exception on one product doesn't abort the others.
	 */
	public function test_exception_on_product_doesnt_abort_run() {
		list( $sku_a, $product_a ) = $this->create_test_product( 'EXC-A', 'outofstock' );
		list( $sku_b, $product_b ) = $this->create_test_product( 'EXC-B', 'outofstock' );
		list( $sku_c, $product_c ) = $this->create_test_product( 'EXC-C', 'outofstock' );

		$matcher = new GCO_Test_Throwing_Matcher( $sku_b );
		$runner  = new GCO_Stock_Sync_Sync_Runner( null, $matcher );

		$result = $runner->run(
			'gco_test_stub',
			new GCO_Test_Stub_Supplier( $this->fetch_result(
				array(
					$sku_a => 5,
					$sku_b => 5,
					$sku_c => 5,
				)
			) )
		);

		$this->assertEquals( 'instock', wc_get_product( $product_a->get_id() )->get_stock_status(), 'SKU-A must still be processed.' );
		$this->assertEquals( 'outofstock', wc_get_product( $product_b->get_id() )->get_stock_status(), 'SKU-B failed and must be left alone.' );
		$this->assertEquals( 'instock', wc_get_product( $product_c->get_id() )->get_stock_status(), 'SKU-C must still be processed despite SKU-B failing.' );
		$this->assertEquals( 'success', $result->status );
		$this->assertEquals( 'skipped', $result->items[1]['action'] );
	}

	/**
	 * P4-TC18: manage_stock enabled triggers a warning note.
	 *
	 * With manage_stock on and quantity > 0, WooCommerce's own CRUD layer
	 * forces stock_status to 'instock' on every save() regardless of what
	 * set_stock_status() was called with — that's WooCommerce's behaviour,
	 * not this plugin's, and is exactly why 4.4.2 calls for a warning rather
	 * than a guaranteed status change. This test only asserts what's actually
	 * guaranteed: the warning fires, action is a recognised outcome, and the
	 * plugin itself never touches the quantity/_stock value.
	 */
	public function test_manage_stock_on_triggers_warning() {
		list( $sku, $product ) = $this->create_test_product( 'MANAGED-J', 'outofstock' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 5 ) ) ) );

		$this->assertContains( 'manage_stock', $result->items[0]['note'] );
		$this->assertTrue( in_array( $result->items[0]['action'], array( 'updated', 'unchanged' ), true ) );
		$this->assertEquals( '10', get_post_meta( $product->get_id(), '_stock', true ), 'Quantity must be untouched by the plugin.' );
	}

	/**
	 * P4-TC22: Custom cron interval is registered.
	 */
	public function test_cron_interval_registered() {
		$schedules = wp_get_schedules();
		$this->assertTrue( isset( $schedules['gco_stock_sync_interval'] ) );
	}

	/**
	 * P4-TC24: Post meta updated on unchanged status (diagnostics).
	 */
	public function test_post_meta_updated_on_unchanged() {
		list( $sku, $product ) = $this->create_test_product( 'META-K', 'instock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 7 ) ) ) );

		$this->assertEquals( '7', get_post_meta( $product->get_id(), '_gco_ss_last_qty', true ) );
		$this->assertNotEmpty( get_post_meta( $product->get_id(), '_gco_ss_last_sync', true ) );
	}

	/**
	 * P4-TC25: instock → outofstock transition.
	 */
	public function test_instock_to_outofstock_transition() {
		list( $sku, $product ) = $this->create_test_product( 'TRANS-1', 'instock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 0 ) ) ) );

		$this->assertEquals( 'outofstock', wc_get_product( $product->get_id() )->get_stock_status() );
		$this->assertEquals( 'updated', $result->items[0]['action'] );
		$this->assertEquals( 'instock', $result->items[0]['old_status'] );
		$this->assertEquals( 'outofstock', $result->items[0]['new_status'] );
	}

	/**
	 * P4-TC26: outofstock → instock transition.
	 */
	public function test_outofstock_to_instock_transition() {
		list( $sku, $product ) = $this->create_test_product( 'TRANS-2', 'outofstock' );

		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( 'gco_test_stub', new GCO_Test_Stub_Supplier( $this->fetch_result( array( $sku => 3 ) ) ) );

		$this->assertEquals( 'instock', wc_get_product( $product->get_id() )->get_stock_status() );
		$this->assertEquals( 'updated', $result->items[0]['action'] );
		$this->assertEquals( 'outofstock', $result->items[0]['old_status'] );
		$this->assertEquals( 'instock', $result->items[0]['new_status'] );
	}
}
