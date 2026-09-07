<?php
/**
 * Sync Runner — the only code in the plugin allowed to change WooCommerce
 * stock status. Every safety rule from the master plan is enforced here.
 *
 * NON-NEGOTIABLE RULES (see development-plan/master-plan.md):
 *   1. Never write stock_quantity — only set_stock_status() via CRUD.
 *   2. A failed fetch NEVER touches any product.
 *   3. Products absent from the feed are left completely alone.
 *   4. One product's exception can't abort the run.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Sync_Runner
 */
class GCO_Stock_Sync_Sync_Runner {

	/**
	 * Transient key used as the run lock.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'gco_stock_sync_lock';

	/**
	 * Lock TTL in seconds — comfortably longer than the slowest plausible run.
	 *
	 * @var int
	 */
	const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Logger instance.
	 *
	 * @var GCO_Stock_Sync_Logger
	 */
	private $logger;

	/**
	 * Product matcher instance.
	 *
	 * @var GCO_Stock_Sync_Product_Matcher
	 */
	private $matcher;

	/**
	 * Constructor.
	 *
	 * @param GCO_Stock_Sync_Logger|null          $logger  Optional logger override (for tests).
	 * @param GCO_Stock_Sync_Product_Matcher|null $matcher Optional matcher override (for tests).
	 */
	public function __construct( $logger = null, $matcher = null ) {
		$this->logger  = $logger ?: new GCO_Stock_Sync_Logger();
		$this->matcher = $matcher ?: new GCO_Stock_Sync_Product_Matcher();
	}

	/**
	 * Run a sync for a single supplier.
	 *
	 * @param string                            $supplier_key Supplier key, e.g. 'highland_outdoors'.
	 * @param GCO_Stock_Sync_Supplier_Interface|null $supplier Optional supplier instance override (for tests).
	 * @return GCO_Stock_Sync_Sync_Result
	 */
	public function run( $supplier_key, $supplier = null ) {
		if ( ! $this->acquire_lock() ) {
			$message = __( 'Another sync run is already in progress.', 'gco-stock-sync' );
			$run_id  = $this->logger->start_run( $supplier_key );
			$this->logger->finish_run( $run_id, 'skipped', 0, 0, $message );

			$result         = new GCO_Stock_Sync_Sync_Result( 'skipped', $message );
			$result->run_id = $run_id;

			return $result;
		}

		try {
			return $this->do_run( $supplier_key, $supplier );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * The actual run logic, executed while the lock is held.
	 *
	 * @param string                            $supplier_key Supplier key.
	 * @param GCO_Stock_Sync_Supplier_Interface|null $supplier Optional supplier instance override.
	 * @return GCO_Stock_Sync_Sync_Result
	 */
	private function do_run( $supplier_key, $supplier = null ) {
		if ( null === $supplier ) {
			$suppliers = apply_filters( 'gco_stock_sync_suppliers', array() );
			$supplier  = isset( $suppliers[ $supplier_key ] ) ? $suppliers[ $supplier_key ] : null;
		}

		if ( null === $supplier ) {
			return new GCO_Stock_Sync_Sync_Result( 'failed', __( 'Unknown supplier.', 'gco-stock-sync' ) );
		}

		$run_id = $this->logger->start_run( $supplier_key );

		$fetch = $supplier->fetch();

		if ( ! $fetch->ok ) {
			// Safety rule #2: a failed fetch NEVER touches any product.
			$this->logger->finish_run( $run_id, 'failed', 0, 0, (string) $fetch->error_message );

			$result             = new GCO_Stock_Sync_Sync_Result( 'failed', (string) $fetch->error_message );
			$result->run_id     = $run_id;
			return $result;
		}

		$products_updated = 0;
		$items            = array();

		foreach ( $fetch->items as $item ) {
			// Safety rule #4: one product's exception can't abort the run.
			try {
				$outcome = $this->sync_one_item( $run_id, $supplier_key, $item );
			} catch ( Throwable $e ) {
				$outcome = array(
					'sku'          => isset( $item['sku'] ) ? $item['sku'] : '',
					'product_id'   => null,
					'supplier_qty' => isset( $item['qty'] ) ? $item['qty'] : null,
					'old_status'   => null,
					'new_status'   => null,
					'action'       => 'skipped',
					/* translators: %s: exception message */
					'note'         => sprintf( __( 'Exception while processing this item: %s', 'gco-stock-sync' ), $e->getMessage() ),
				);
				$this->logger->log_item( $run_id, $outcome['sku'], null, $outcome['supplier_qty'], null, null, 'skipped', $outcome['note'] );
			}

			if ( 'updated' === $outcome['action'] ) {
				$products_updated++;
			}

			$items[] = $outcome;
		}

		$this->logger->finish_run( $run_id, 'success', $fetch->raw_row_count, $products_updated, '' );

		$result                   = new GCO_Stock_Sync_Sync_Result( 'success' );
		$result->run_id           = $run_id;
		$result->rows_fetched     = $fetch->raw_row_count;
		$result->products_updated = $products_updated;
		$result->items            = $items;

		return $result;
	}

	/**
	 * Process a single feed item against the matching WooCommerce product.
	 *
	 * Logs the outcome itself (so the exception-catching caller doesn't have
	 * to duplicate logging) and returns the outcome array for the result object.
	 *
	 * @param int    $run_id       The run ID.
	 * @param string $supplier_key Supplier key, stored on first sync of a product.
	 * @param array  $item         Normalised feed item: sku, qty, name, internal_id.
	 * @return array Outcome array (sku, product_id, supplier_qty, old_status, new_status, action, note).
	 */
	private function sync_one_item( $run_id, $supplier_key, $item ) {
		$sku = isset( $item['sku'] ) ? $item['sku'] : '';
		$qty = isset( $item['qty'] ) ? (int) $item['qty'] : 0;

		$match      = $this->matcher->find_by_sku( $sku );
		$product_id = $match['product_id'];

		if ( null === $product_id ) {
			$this->logger->log_item( $run_id, $sku, null, $qty, null, null, 'unmatched', '' );

			return array(
				'sku'          => $sku,
				'product_id'   => null,
				'supplier_qty' => $qty,
				'old_status'   => null,
				'new_status'   => null,
				'action'       => 'unmatched',
				'note'         => '',
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			$this->logger->log_item( $run_id, $sku, $product_id, $qty, null, null, 'unmatched', __( 'Matched post is not a loadable WooCommerce product.', 'gco-stock-sync' ) );

			return array(
				'sku'          => $sku,
				'product_id'   => $product_id,
				'supplier_qty' => $qty,
				'old_status'   => null,
				'new_status'   => null,
				'action'       => 'unmatched',
				'note'         => __( 'Matched post is not a loadable WooCommerce product.', 'gco-stock-sync' ),
			);
		}

		$enabled_meta = get_post_meta( $product_id, '_gco_ss_enabled', true );
		$enabled      = ( '' === $enabled_meta ) ? true : ( 'yes' === $enabled_meta );

		if ( ! $enabled ) {
			$this->logger->log_item( $run_id, $sku, $product_id, $qty, $product->get_stock_status(), null, 'skipped', __( 'Sync disabled for this product.', 'gco-stock-sync' ) );

			return array(
				'sku'          => $sku,
				'product_id'   => $product_id,
				'supplier_qty' => $qty,
				'old_status'   => $product->get_stock_status(),
				'new_status'   => null,
				'action'       => 'skipped',
				'note'         => __( 'Sync disabled for this product.', 'gco-stock-sync' ),
			);
		}

		$note = '';
		if ( $product->get_manage_stock() ) {
			$note = __( 'Warning: manage_stock is enabled for this product; only stock status is updated, quantity is left untouched.', 'gco-stock-sync' );
		}
		if ( ! empty( $match['note'] ) ) {
			$note = trim( $note . ' ' . $match['note'] );
		}

		$old_status = $product->get_stock_status();
		$new_status = $qty > 0 ? 'instock' : 'outofstock';

		if ( $new_status === $old_status ) {
			update_post_meta( $product_id, '_gco_ss_last_qty', $qty );
			update_post_meta( $product_id, '_gco_ss_last_sync', current_time( 'mysql' ) );

			$this->logger->log_item( $run_id, $sku, $product_id, $qty, $old_status, $new_status, 'unchanged', $note );

			return array(
				'sku'          => $sku,
				'product_id'   => $product_id,
				'supplier_qty' => $qty,
				'old_status'   => $old_status,
				'new_status'   => $new_status,
				'action'       => 'unchanged',
				'note'         => $note,
			);
		}

		// Safety rule #1: only ever set_stock_status(). Never touch stock_quantity/_stock.
		$product->set_stock_status( $new_status );
		$product->save();

		update_post_meta( $product_id, '_gco_ss_supplier', $supplier_key );
		update_post_meta( $product_id, '_gco_ss_last_qty', $qty );
		update_post_meta( $product_id, '_gco_ss_last_sync', current_time( 'mysql' ) );

		$this->logger->log_item( $run_id, $sku, $product_id, $qty, $old_status, $new_status, 'updated', $note );

		return array(
			'sku'          => $sku,
			'product_id'   => $product_id,
			'supplier_qty' => $qty,
			'old_status'   => $old_status,
			'new_status'   => $new_status,
			'action'       => 'updated',
			'note'         => $note,
		);
	}

	/**
	 * Attempt to acquire the run lock.
	 *
	 * @return bool True if the lock was acquired, false if already held.
	 */
	private function acquire_lock() {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

		return true;
	}

	/**
	 * Release the run lock.
	 */
	private function release_lock() {
		delete_transient( self::LOCK_KEY );
	}
}
