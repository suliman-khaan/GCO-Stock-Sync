<?php
/**
 * WP-CLI Commands for GCO Supplier Stock Sync.
 *
 * Provides command line control for stock synchronization, dry-runs,
 * and automation via server crontab.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage GCO Supplier Stock Sync operations.
 */
class GCO_Stock_Sync_CLI {

	/**
	 * Run supplier stock synchronization.
	 *
	 * ## OPTIONS
	 *
	 * [--supplier=<key>]
	 * : Key of the supplier to sync (e.g. highland_outdoors). Defaults to all configured suppliers.
	 *
	 * [--dry-run]
	 * : Fetch and match products without saving any stock changes or writing run logs.
	 *
	 * [--force]
	 * : Bypass and clear any active sync run lock.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run sync for all configured suppliers
	 *     wp gco-stock-sync run
	 *
	 *     # Dry-run for Highland Outdoors without modifying products
	 *     wp gco-stock-sync run --supplier=highland_outdoors --dry-run
	 *
	 *     # Force run even if previous run crashed and lock remains
	 *     wp gco-stock-sync run --force
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments / flags.
	 */
	public function run( $args, $assoc_args ) {
		$target_supplier = isset( $assoc_args['supplier'] ) ? sanitize_key( $assoc_args['supplier'] ) : '';
		$dry_run         = ! empty( $assoc_args['dry-run'] );
		$force           = ! empty( $assoc_args['force'] );

		if ( $force ) {
			delete_transient( GCO_Stock_Sync_Sync_Runner::LOCK_KEY );
			WP_CLI::log( __( 'Sync lock cleared by --force.', 'gco-stock-sync' ) );
		}

		$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();

		if ( ! empty( $target_supplier ) ) {
			if ( ! isset( $suppliers[ $target_supplier ] ) ) {
				WP_CLI::error(
					sprintf(
						/* translators: %s: supplier key */
						__( "Supplier '%s' is not registered.", 'gco-stock-sync' ),
						$target_supplier
					)
				);
				return;
			}
			$suppliers = array( $target_supplier => $suppliers[ $target_supplier ] );
		}

		if ( empty( $suppliers ) ) {
			WP_CLI::error( __( 'No suppliers registered.', 'gco-stock-sync' ) );
			return;
		}

		foreach ( $suppliers as $key => $supplier ) {
			if ( ! $supplier->is_configured() ) {
				WP_CLI::warning(
					sprintf(
						/* translators: %s: supplier label */
						__( "Supplier '%s' is not configured (missing feed URL). Skipping.", 'gco-stock-sync' ),
						$supplier->get_label()
					)
				);
				continue;
			}

			WP_CLI::log(
				sprintf(
					/* translators: 1: supplier label, 2: mode */
					__( "Starting sync for '%1\$s' (Mode: %2\$s)...", 'gco-stock-sync' ),
					$supplier->get_label(),
					$dry_run ? 'DRY-RUN' : 'LIVE'
				)
			);

			if ( $dry_run ) {
				$this->execute_dry_run( $key, $supplier );
			} else {
				$this->execute_live_run( $key, $supplier );
			}
		}
	}

	/**
	 * Execute a live sync run.
	 *
	 * @param string                            $key      Supplier key.
	 * @param GCO_Stock_Sync_Supplier_Interface $supplier Supplier instance.
	 */
	private function execute_live_run( $key, $supplier ) {
		$runner = new GCO_Stock_Sync_Sync_Runner();
		$result = $runner->run( $key, $supplier );

		if ( 'failed' === $result->status ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: error message */
					__( 'Sync failed: %s', 'gco-stock-sync' ),
					$result->message
				),
				false
			);
			return;
		}

		if ( 'skipped' === $result->status ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: reason */
					__( 'Sync skipped: %s', 'gco-stock-sync' ),
					$result->message
				)
			);
			return;
		}

		// Display table of item outcomes if items exist
		if ( ! empty( $result->items ) ) {
			$table_data = array();
			foreach ( $result->items as $item ) {
				$table_data[] = array(
					'SKU'          => $item['sku'],
					'Product ID'   => $item['product_id'] ? $item['product_id'] : '—',
					'Feed Qty'     => null !== $item['supplier_qty'] ? $item['supplier_qty'] : '—',
					'Old Status'   => $item['old_status'] ? $item['old_status'] : '—',
					'New Status'   => $item['new_status'] ? $item['new_status'] : '—',
					'Action'       => strtoupper( $item['action'] ),
					'Note'         => $item['note'],
				);
			}

			WP_CLI\Utils\format_items( 'table', $table_data, array( 'SKU', 'Product ID', 'Feed Qty', 'Old Status', 'New Status', 'Action', 'Note' ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: rows fetched, 2: products updated */
				__( 'Sync complete! Feed rows fetched: %1$d | Products updated: %2$d', 'gco-stock-sync' ),
				$result->rows_fetched,
				$result->products_updated
			)
		);
	}

	/**
	 * Execute a dry-run without modifying database or stock statuses.
	 *
	 * @param string                            $key      Supplier key.
	 * @param GCO_Stock_Sync_Supplier_Interface $supplier Supplier instance.
	 */
	private function execute_dry_run( $key, $supplier ) {
		$fetch = $supplier->fetch();

		if ( ! $fetch->ok ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: error message */
					__( 'Dry-run feed fetch failed: %s', 'gco-stock-sync' ),
					$fetch->error_message
				),
				false
			);
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: 1: raw rows, 2: valid items */
				__( 'Feed fetched successfully: %1$d raw rows, %2$d parsed items.', 'gco-stock-sync' ),
				$fetch->raw_row_count,
				count( $fetch->items )
			)
		);

		$matcher         = new GCO_Stock_Sync_Product_Matcher();
		$table_data      = array();
		$would_update    = 0;
		$would_unchanged = 0;
		$would_unmatched = 0;
		$would_skip      = 0;

		foreach ( $fetch->items as $item ) {
			$sku = isset( $item['sku'] ) ? $item['sku'] : '';
			$qty = isset( $item['qty'] ) ? (int) $item['qty'] : 0;

			$match      = $matcher->find_by_sku( $sku );
			$product_id = $match['product_id'];

			if ( null === $product_id ) {
				$would_unmatched++;
				$table_data[] = array(
					'SKU'          => $sku,
					'Product ID'   => '—',
					'Feed Qty'     => $qty,
					'Old Status'   => '—',
					'New Status'   => '—',
					'Action'       => 'UNMATCHED',
					'Note'         => __( 'No WooCommerce product found for this SKU', 'gco-stock-sync' ),
				);
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$would_unmatched++;
				$table_data[] = array(
					'SKU'          => $sku,
					'Product ID'   => $product_id,
					'Feed Qty'     => $qty,
					'Old Status'   => '—',
					'New Status'   => '—',
					'Action'       => 'UNMATCHED',
					'Note'         => __( 'Could not load WooCommerce product', 'gco-stock-sync' ),
				);
				continue;
			}

			$enabled_meta = get_post_meta( $product_id, '_gco_ss_enabled', true );
			$enabled      = ( '' === $enabled_meta ) ? true : ( 'yes' === $enabled_meta );

			if ( ! $enabled ) {
				$would_skip++;
				$table_data[] = array(
					'SKU'          => $sku,
					'Product ID'   => $product_id,
					'Feed Qty'     => $qty,
					'Old Status'   => $product->get_stock_status(),
					'New Status'   => '—',
					'Action'       => 'SKIPPED',
					'Note'         => __( 'Sync disabled for this product', 'gco-stock-sync' ),
				);
				continue;
			}

			$old_status = $product->get_stock_status();
			$new_status = $qty > 0 ? 'instock' : 'outofstock';
			$note       = $match['note'];

			if ( $old_status === $new_status ) {
				$would_unchanged++;
				$action = 'UNCHANGED';
			} else {
				$would_update++;
				$action = 'WOULD_UPDATE';
			}

			$table_data[] = array(
				'SKU'          => $sku,
				'Product ID'   => $product_id,
				'Feed Qty'     => $qty,
				'Old Status'   => $old_status,
				'New Status'   => $new_status,
				'Action'       => $action,
				'Note'         => $note,
			);
		}

		if ( ! empty( $table_data ) ) {
			WP_CLI\Utils\format_items( 'table', $table_data, array( 'SKU', 'Product ID', 'Feed Qty', 'Old Status', 'New Status', 'Action', 'Note' ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: would update, 2: unchanged, 3: unmatched, 4: skipped */
				__( 'Dry run complete (NO changes made). Would update: %1$d | Unchanged: %2$d | Unmatched: %3$d | Skipped: %4$d', 'gco-stock-sync' ),
				$would_update,
				$would_unchanged,
				$would_unmatched,
				$would_skip
			)
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'gco-stock-sync', 'GCO_Stock_Sync_CLI' );
}
