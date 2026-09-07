<?php
/**
 * Sync Result — value object carrying the outcome of a single sync run.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Sync_Result
 */
class GCO_Stock_Sync_Sync_Result {

	/**
	 * Run status: success|failed|partial|skipped.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * The run ID in {prefix}gco_ss_runs, if a row was created.
	 *
	 * @var int|null
	 */
	public $run_id = null;

	/**
	 * Number of raw rows fetched from the supplier feed.
	 *
	 * @var int
	 */
	public $rows_fetched = 0;

	/**
	 * Number of products whose stock status actually changed.
	 *
	 * @var int
	 */
	public $products_updated = 0;

	/**
	 * Human-readable message (error detail, skip reason, etc.).
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Per-item outcomes: array of ['sku','product_id','action',...].
	 *
	 * @var array
	 */
	public $items = array();

	/**
	 * Constructor.
	 *
	 * @param string $status  Run status.
	 * @param string $message Optional message.
	 */
	public function __construct( $status, $message = '' ) {
		$this->status  = $status;
		$this->message = $message;
	}
}
