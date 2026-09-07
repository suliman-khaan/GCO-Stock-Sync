<?php
/**
 * Standalone Test Runner for GCO Stock Sync
 *
 * Runs test cases directly against the environment.
 *
 * @package GCO_Stock_Sync
 */

echo "========================================================\n";
echo "  GCO Stock Sync — Test Runner\n";
echo "========================================================\n\n";

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/integration/test-lifecycle.php';
require_once __DIR__ . '/integration/test-supplier-highland.php';
require_once __DIR__ . '/integration/test-product-matcher.php';
require_once __DIR__ . '/integration/test-sync-runner.php';
require_once __DIR__ . '/integration/test-admin-settings.php';
require_once __DIR__ . '/integration/test-admin-log.php';
require_once __DIR__ . '/integration/test-admin-product-meta.php';
require_once __DIR__ . '/integration/test-hardening.php';

$test_classes = array(
	'Test_Lifecycle',
	'Test_Supplier_Highland',
	'Test_Product_Matcher',
	'Test_Sync_Runner',
	'Test_Admin_Settings',
	'Test_Admin_Log',
	'Test_Admin_Product_Meta',
	'Test_Hardening',
);

$total_passed = 0;
$total_failed = 0;
$start_all    = microtime( true );

foreach ( $test_classes as $class_name ) {
	if ( ! class_exists( $class_name ) ) {
		echo "Class {$class_name} not found.\n";
		continue;
	}

	echo "Running {$class_name}...\n";
	$reflection = new ReflectionClass( $class_name );
	$methods    = $reflection->getMethods( ReflectionMethod::IS_PUBLIC );

	$test_instance = new $class_name();

	foreach ( $methods as $method ) {
		$method_name = $method->getName();
		if ( 0 !== strpos( $method_name, 'test_' ) ) {
			continue;
		}

		$start = microtime( true );
		try {
			if ( method_exists( $test_instance, 'setUp' ) ) {
				$test_instance->setUp();
			}

			try {
				$test_instance->$method_name();
			} finally {
				// Always run tearDown, even on failure — test data (e.g. WooCommerce
				// test products) must never be left behind on a live install.
				if ( method_exists( $test_instance, 'tearDown' ) ) {
					$test_instance->tearDown();
				}
			}

			$duration = round( ( microtime( true ) - $start ) * 1000, 2 );
			echo "  [PASS] {$method_name} ({$duration}ms)\n";
			$total_passed++;
		} catch ( Throwable $e ) {
			$duration = round( ( microtime( true ) - $start ) * 1000, 2 );
			echo "  [FAIL] {$method_name} ({$duration}ms)\n";
			echo "         Error: " . $e->getMessage() . "\n";
			echo "         File:  " . $e->getFile() . ':' . $e->getLine() . "\n";
			$total_failed++;
		}
	}
	echo "\n";
}

$total_time = round( ( microtime( true ) - $start_all ), 3 );
echo "--------------------------------------------------------\n";
echo "Summary: " . ( $total_passed + $total_failed ) . " tests, {$total_passed} passed, {$total_failed} failed in {$total_time}s\n";
echo "========================================================\n";

if ( $total_failed > 0 ) {
	exit( 1 );
}
exit( 0 );
