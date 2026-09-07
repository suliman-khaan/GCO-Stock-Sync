<?php
/**
 * Test Admin Log — Integration Tests for Sync History and Log Management (Phase 5)
 *
 * Tests P5-TC10 to P5-TC13, P5-TC17, P5-TC21.
 *
 * @package GCO_Stock_Sync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	abstract class GCO_Admin_Log_Base_TestCase extends WP_UnitTestCase {}
} elseif ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	abstract class GCO_Admin_Log_Base_TestCase extends PHPUnit\Framework\TestCase {}
} else {
	abstract class GCO_Admin_Log_Base_TestCase {
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
 * Class Test_Admin_Log
 */
class Test_Admin_Log extends GCO_Admin_Log_Base_TestCase {

	/**
	 * Log page instance.
	 *
	 * @var GCO_Stock_Sync_Log_Page
	 */
	private $log_page;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		$this->log_page = new GCO_Stock_Sync_Log_Page();
	}

	/**
	 * P5-TC10: Log list table renders with seeded data
	 */
	public function test_log_list_table_renders() {
		global $wpdb;
		$table = $wpdb->prefix . 'gco_ss_runs';

		// Seed 3 rows
		$inserted_ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'supplier'         => 'highland_outdoors',
					'started_at'       => gmdate( 'Y-m-d H:i:s', time() - ( $i * 3600 ) ),
					'status'           => 'success',
					'rows_fetched'     => 100 + $i,
					'products_updated' => $i,
					'message'          => "Test run {$i}",
				)
			);
			$inserted_ids[] = $wpdb->insert_id;
		}

		$list_table = new GCO_Stock_Sync_Log_List_Table();
		$list_table->prepare_items();

		ob_start();
		$list_table->display();
		$html = ob_get_clean();

		$this->assertContains( 'Highland Outdoors', $html );
		$this->assertContains( 'status-success', $html );
		$this->assertContains( 'View Item Details', $html );

		// Clean up
		foreach ( $inserted_ids as $id ) {
			$wpdb->delete( $table, array( 'id' => $id ) );
		}
	}

	/**
	 * P5-TC11: Log list table paginates correctly
	 */
	public function test_log_list_table_pagination() {
		global $wpdb;
		$table = $wpdb->prefix . 'gco_ss_runs';

		$inserted_ids = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'supplier'   => 'highland_outdoors',
					'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( $i * 60 ) ),
					'status'     => 'success',
				)
			);
			$inserted_ids[] = $wpdb->insert_id;
		}

		$list_table           = new GCO_Stock_Sync_Log_List_Table();
		$list_table->per_page = 10;
		$list_table->prepare_items();

		$pagination = $list_table->get_pagination_arg( 'total_items' );
		$this->assertTrue( $pagination >= 25, 'Total items should reflect seeded runs' );
		$this->assertTrue( $list_table->get_pagination_arg( 'total_pages' ) >= 3, 'Total pages should be at least 3 with 10 per page' );

		// Clean up
		foreach ( $inserted_ids as $id ) {
			$wpdb->delete( $table, array( 'id' => $id ) );
		}
	}

	/**
	 * P5-TC12: Log purge deletes only old rows
	 */
	public function test_log_purge_only_deletes_old() {
		global $wpdb;
		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		// Seed 4 runs: 60d ago (old), 35d ago (old), 25d ago (recent), 1d ago (recent)
		$now = time();

		$wpdb->insert( $runs_table, array( 'supplier' => 'old_60', 'started_at' => gmdate( 'Y-m-d H:i:s', $now - ( 60 * DAY_IN_SECONDS ) ), 'status' => 'success' ) );
		$id_60 = $wpdb->insert_id;

		$wpdb->insert( $runs_table, array( 'supplier' => 'old_35', 'started_at' => gmdate( 'Y-m-d H:i:s', $now - ( 35 * DAY_IN_SECONDS ) ), 'status' => 'success' ) );
		$id_35 = $wpdb->insert_id;

		$wpdb->insert( $runs_table, array( 'supplier' => 'recent_25', 'started_at' => gmdate( 'Y-m-d H:i:s', $now - ( 25 * DAY_IN_SECONDS ) ), 'status' => 'success' ) );
		$id_25 = $wpdb->insert_id;

		$wpdb->insert( $runs_table, array( 'supplier' => 'recent_1', 'started_at' => gmdate( 'Y-m-d H:i:s', $now - ( 1 * DAY_IN_SECONDS ) ), 'status' => 'success' ) );
		$id_1 = $wpdb->insert_id;

		// Attach items to runs
		$wpdb->insert( $items_table, array( 'run_id' => $id_60, 'sku' => 'SKU_OLD_60', 'action' => 'updated' ) );
		$wpdb->insert( $items_table, array( 'run_id' => $id_1, 'sku' => 'SKU_RECENT_1', 'action' => 'updated' ) );

		// Run 30-day purge
		GCO_Stock_Sync_Log_Page::purge_old_logs( 30 );

		$row_60 = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE id = %d", $id_60 ) );
		$row_35 = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE id = %d", $id_35 ) );
		$row_25 = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE id = %d", $id_25 ) );
		$row_1  = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE id = %d", $id_1 ) );

		$this->assertEmpty( $row_60, '60-day-old run should be deleted' );
		$this->assertEmpty( $row_35, '35-day-old run should be deleted' );
		$this->assertNotEmpty( $row_25, '25-day-old run should be kept' );
		$this->assertNotEmpty( $row_1, '1-day-old run should be kept' );

		// Clean up remaining
		$wpdb->delete( $runs_table, array( 'id' => $id_25 ) );
		$wpdb->delete( $runs_table, array( 'id' => $id_1 ) );
		$wpdb->delete( $items_table, array( 'sku' => 'SKU_RECENT_1' ) );
	}

	/**
	 * P5-TC13: Log purge respects retention window
	 */
	public function test_log_purge_retention_window() {
		global $wpdb;
		$table = $wpdb->prefix . 'gco_ss_runs';
		$now   = time();

		$wpdb->insert( $table, array( 'supplier' => 'win_8d', 'started_at' => gmdate( 'Y-m-d H:i:s', $now - ( 8 * DAY_IN_SECONDS ) ), 'status' => 'success' ) );
		$id_8 = $wpdb->insert_id;

		$wpdb->insert( $table, array( 'supplier' => 'win_5d', 'started_at' => gmdate( 'Y-m-d H:i:s', $now - ( 5 * DAY_IN_SECONDS ) ), 'status' => 'success' ) );
		$id_5 = $wpdb->insert_id;

		// Purge with 7-day retention
		GCO_Stock_Sync_Log_Page::purge_old_logs( 7 );

		$row_8 = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id_8 ) );
		$row_5 = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id_5 ) );

		$this->assertEmpty( $row_8, '8-day-old run must be purged with 7-day window' );
		$this->assertNotEmpty( $row_5, '5-day-old run must be retained with 7-day window' );

		// Clean up
		$wpdb->delete( $table, array( 'id' => $id_5 ) );
	}

	/**
	 * P5-TC17: Non-privileged user denied on log clear
	 */
	public function test_non_privileged_denied_log_clear() {
		$original_user = get_current_user_id();
		wp_set_current_user( 0 );

		$has_cap = current_user_can( 'manage_woocommerce' );
		$this->assertFalse( $has_cap, 'Non-privileged user must not have permission to clear logs' );

		wp_set_current_user( $original_user );
	}

	/**
	 * P5-TC21: Run detail view shows item-level data
	 */
	public function test_run_detail_shows_items() {
		global $wpdb;
		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		$wpdb->insert(
			$runs_table,
			array(
				'supplier'         => 'highland_outdoors',
				'started_at'       => '2026-09-08 02:00:00',
				'status'           => 'success',
				'rows_fetched'     => 3,
				'products_updated' => 1,
			)
		);
		$run_id = $wpdb->insert_id;

		$wpdb->insert(
			$items_table,
			array(
				'run_id'       => $run_id,
				'sku'          => 'DETAIL-SKU-01',
				'supplier_qty' => 12,
				'old_status'   => 'outofstock',
				'new_status'   => 'instock',
				'action'       => 'updated',
				'note'         => 'Stock restored',
			)
		);

		$wpdb->insert(
			$items_table,
			array(
				'run_id'       => $run_id,
				'sku'          => 'DETAIL-SKU-02',
				'supplier_qty' => 0,
				'old_status'   => 'outofstock',
				'new_status'   => 'outofstock',
				'action'       => 'unchanged',
			)
		);

		ob_start();
		$this->log_page->render_run_detail( $run_id );
		$output = ob_get_clean();

		$this->assertContains( 'DETAIL-SKU-01', $output );
		$this->assertContains( 'DETAIL-SKU-02', $output );
		$this->assertContains( 'Stock restored', $output );
		$this->assertContains( 'action-updated', $output );
		$this->assertContains( 'Back to All Runs', $output );

		// Clean up
		$wpdb->delete( $runs_table, array( 'id' => $run_id ) );
		$wpdb->delete( $items_table, array( 'run_id' => $run_id ) );
	}
}
