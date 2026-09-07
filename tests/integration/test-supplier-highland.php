<?php
/**
 * Test Supplier Highland — Integration Tests for Supplier Connector (Phase 3)
 *
 * Tests P3-TC01 through P3-TC16.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Supplier_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Supplier_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Supplier_Base_TestCase {
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
		protected function assertGreaterThan( $expected, $actual, $message = '' ) {
			if ( ! ( $actual > $expected ) ) {
				throw new Exception( $message ? $message : "Failed asserting that {$actual} is greater than {$expected}" );
			}
		}
		protected function assertNotContains( $needle, $haystack, $message = '' ) {
			if ( is_array( $haystack ) && in_array( $needle, $haystack, true ) ) {
				throw new Exception( $message ? $message : "Failed asserting that array does not contain " . var_export( $needle, true ) );
			}
		}
		protected function assertIsInt( $value, $message = '' ) {
			if ( ! is_int( $value ) ) {
				throw new Exception( $message ? $message : "Failed asserting that value is an integer" );
			}
		}
	}
}

/**
 * Class Test_Supplier_Highland
 */
class Test_Supplier_Highland extends GCO_Supplier_Base_TestCase {

	/**
	 * Fixture directory.
	 *
	 * @var string
	 */
	private $fixtures_dir;

	/**
	 * Currently active pre_http_request callback, so it can be removed in tearDown.
	 *
	 * @var callable|null
	 */
	private $http_filter;

	/**
	 * Run before each test.
	 */
	public function setUp(): void {
		if ( is_callable( array( 'parent', 'setUp' ) ) ) {
			parent::setUp();
		}
		$this->fixtures_dir = dirname( dirname( __FILE__ ) ) . '/fixtures/';
		delete_transient( 'gco_stock_sync_last_error_body' );
	}

	/**
	 * Run after each test.
	 */
	public function tearDown(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		if ( is_callable( array( 'parent', 'tearDown' ) ) ) {
			parent::tearDown();
		}
	}

	/**
	 * Mock the next wp_remote_get() call to return a canned HTTP response.
	 *
	 * @param int    $code Response code.
	 * @param string $body Response body.
	 */
	private function mock_http_response( $code, $body ) {
		$this->http_filter = function () use ( $code, $body ) {
			return array(
				'response' => array( 'code' => $code, 'message' => '' ),
				'body'     => $body,
				'headers'  => array( 'content-type' => 'text/html' ),
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Mock the next wp_remote_get() call to return a WP_Error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	private function mock_http_error( $code, $message ) {
		$this->http_filter = function () use ( $code, $message ) {
			return new WP_Error( $code, $message );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Load a fixture file's contents.
	 *
	 * @param string $name Fixture filename.
	 * @return string
	 */
	private function fixture( $name ) {
		return file_get_contents( $this->fixtures_dir . $name );
	}

	/**
	 * P3-TC01: Valid feed → correct SKU count.
	 */
	public function test_valid_feed_returns_correct_sku_count() {
		$this->mock_http_response( 200, $this->fixture( 'feed-valid.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertTrue( $result->ok );
		$this->assertEquals( 11, count( $result->items ), 'Fixture has 11 real product rows' );
	}

	/**
	 * P3-TC02: Valid feed → qty values correct.
	 */
	public function test_valid_feed_qty_values_match() {
		$this->mock_http_response( 200, $this->fixture( 'feed-valid.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$items_by_sku = array();
		foreach ( $result->items as $item ) {
			$items_by_sku[ $item['sku'] ] = $item;
		}

		$this->assertIsInt( $items_by_sku['BSEC10']['qty'] );
		$this->assertEquals( 8, $items_by_sku['BSEC10']['qty'] );
		$this->assertEquals( 101, $items_by_sku['BRASL']['qty'], 'Empty price + non-zero qty is a real product' );
		$this->assertEquals( 0, $items_by_sku['BRKGOO']['qty'], 'Priced product with zero qty is still a product' );
	}

	/**
	 * P3-TC03: Valid feed → section-header rows excluded.
	 */
	public function test_section_header_rows_excluded() {
		$this->mock_http_response( 200, $this->fixture( 'feed-valid.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$skus = array();
		foreach ( $result->items as $item ) {
			$skus[] = $item['sku'];
		}

		$this->assertNotContains( 'Boston Security', $skus );
		$this->assertNotContains( 'Cabinets', $skus );
		$this->assertNotContains( 'Gun Bags & Accessories', $skus );
		$this->assertGreaterThan( count( $result->items ), $result->raw_row_count );
	}

	/**
	 * P3-TC04: Empty feed → ok=false, suspiciously_empty.
	 */
	public function test_empty_feed_returns_suspiciously_empty() {
		$this->mock_http_response( 200, $this->fixture( 'feed-empty.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertFalse( $result->ok );
		$this->assertEquals( 'suspiciously_empty', $result->error_code );
		$this->assertEmpty( $result->items );
	}

	/**
	 * P3-TC05: Garbage feed → ok=false, parse_failed.
	 */
	public function test_garbage_feed_returns_parse_failed() {
		$this->mock_http_response( 200, $this->fixture( 'feed-garbage.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertFalse( $result->ok );
		$this->assertEquals( 'parse_failed', $result->error_code );
	}

	/**
	 * P3-TC06: WP_Error from HTTP → ok=false, fetch_failed.
	 */
	public function test_wp_error_returns_fetch_failed() {
		$this->mock_http_error( 'http_request_failed', 'Connection refused' );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertFalse( $result->ok );
		$this->assertEquals( 'fetch_failed', $result->error_code );
		$this->assertNotEmpty( $result->error_message );
	}

	/**
	 * P3-TC07: HTTP 500 → ok=false.
	 */
	public function test_http_500_returns_error() {
		$this->mock_http_response( 500, 'Server Error' );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertFalse( $result->ok );
		$this->assertEquals( 'fetch_failed', $result->error_code );
	}

	/**
	 * P3-TC08: HTTP 200 empty body → ok=false.
	 */
	public function test_http_200_empty_body_returns_error() {
		$this->mock_http_response( 200, '' );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertFalse( $result->ok );
	}

	/**
	 * P3-TC09/10/11: Qty normalisation via reflection on the private method.
	 */
	public function test_qty_normalisation_rules() {
		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$method   = new ReflectionMethod( $supplier, 'normalise_qty' );
		$method->setAccessible( true );

		$this->assertEquals( 1234, $method->invoke( $supplier, '=1,234' ), 'Commas stripped' );
		$this->assertEquals( 0, $method->invoke( $supplier, '=-5' ), 'Negative clamped to zero' );
		$this->assertEquals( null, $method->invoke( $supplier, '' ), 'Empty string is unparseable' );
	}

	/**
	 * P3-TC12: Second supplier discoverable via filter.
	 */
	public function test_second_supplier_discoverable() {
		$dummy = new class extends GCO_Stock_Sync_Abstract_Supplier {
			public function get_key() {
				return 'dummy';
			}
			public function get_label() {
				return 'Dummy';
			}
			public function get_settings_fields() {
				return array();
			}
			public function is_configured() {
				return true;
			}
			public function fetch() {
				return GCO_Stock_Sync_Fetch_Result::success( array(), 0 );
			}
		};

		$add_dummy = function ( $suppliers ) use ( $dummy ) {
			$suppliers['dummy'] = $dummy;
			return $suppliers;
		};
		add_filter( 'gco_stock_sync_suppliers', $add_dummy );

		$suppliers = apply_filters( 'gco_stock_sync_suppliers', array() );

		remove_filter( 'gco_stock_sync_suppliers', $add_dummy );

		$this->assertNotEmpty( $suppliers['highland_outdoors'] );
		$this->assertNotEmpty( $suppliers['dummy'] );
		$this->assertTrue( $suppliers['highland_outdoors'] instanceof GCO_Stock_Sync_Supplier_Interface );
		$this->assertTrue( $suppliers['dummy'] instanceof GCO_Stock_Sync_Supplier_Interface );
	}

	/**
	 * P3-TC13: Feed URL is a setting, not hardcoded.
	 */
	public function test_feed_url_is_configurable() {
		update_option(
			'gco_stock_sync_supplier_highland_outdoors',
			array( 'feed_url' => 'https://example.test/custom-feed', 'min_rows' => 10 )
		);

		$captured_url = null;
		$capture      = function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200, 'message' => '' ),
				'body'     => '<table></table>',
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$supplier->fetch();

		remove_filter( 'pre_http_request', $capture, 10 );
		delete_option( 'gco_stock_sync_supplier_highland_outdoors' );

		$this->assertEquals( 'https://example.test/custom-feed', $captured_url );
	}

	/**
	 * SSRF hardening: http:// (non-https) feed URLs are rejected.
	 */
	public function test_non_https_feed_url_rejected() {
		update_option(
			'gco_stock_sync_supplier_highland_outdoors',
			array( 'feed_url' => 'http://169.254.169.254/latest/meta-data/', 'min_rows' => 10 )
		);

		$called = false;
		$capture = function () use ( &$called ) {
			$called = true;
			return array( 'response' => array( 'code' => 200 ), 'body' => '' );
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		remove_filter( 'pre_http_request', $capture, 10 );
		delete_option( 'gco_stock_sync_supplier_highland_outdoors' );

		$this->assertFalse( $called, 'wp_remote_get() must never be called for a non-https feed URL' );
		$this->assertFalse( $result->ok );
		$this->assertEquals( 'fetch_failed', $result->error_code );
	}

	/**
	 * P3-TC14: Below minimum rows triggers sanity guard.
	 */
	public function test_below_minimum_rows_triggers_guard() {
		$small_feed = '<html><body><table>'
			. '<tr><td>Qty Available</td><td>Trade Price</td><td>Name</td><td>Brand Name</td><td>Description</td><td>Internal ID</td></tr>'
			. '<tr><td>=5</td><td>=10</td><td>SKU1</td><td>Brand</td><td>Desc</td><td>1</td></tr>'
			. '<tr><td>=5</td><td>=10</td><td>SKU2</td><td>Brand</td><td>Desc</td><td>2</td></tr>'
			. '<tr><td>=5</td><td>=10</td><td>SKU3</td><td>Brand</td><td>Desc</td><td>3</td></tr>'
			. '</table></body></html>';

		$this->mock_http_response( 200, $small_feed );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		$this->assertFalse( $result->ok );
		$this->assertEquals( 'suspiciously_empty', $result->error_code );
	}

	/**
	 * P3-TC15: Fetch result item structure is correct.
	 */
	public function test_fetch_result_structure() {
		$this->mock_http_response( 200, $this->fixture( 'feed-valid.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$result   = $supplier->fetch();

		foreach ( $result->items as $item ) {
			$this->assertNotEmpty( $item['sku'] );
			$this->assertIsInt( $item['qty'] );
			$this->assertTrue( $item['qty'] >= 0 );
			$this->assertTrue( array_key_exists( 'name', $item ) );
			$this->assertTrue( array_key_exists( 'internal_id', $item ) );
		}
	}

	/**
	 * P3-TC16: Failed fetch stores debug transient.
	 */
	public function test_failed_fetch_stores_debug_transient() {
		$this->mock_http_response( 200, $this->fixture( 'feed-garbage.html' ) );

		$supplier = new GCO_Stock_Sync_Highland_Outdoors();
		$supplier->fetch();

		$debug = get_transient( 'gco_stock_sync_last_error_body' );

		$this->assertNotEmpty( $debug );
		$this->assertTrue( strlen( $debug ) <= 10240 );
	}
}
