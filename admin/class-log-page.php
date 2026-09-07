<?php
/**
 * Log Page — displays sync run history and item-level details.
 *
 * Provides manual log clearing and scheduled retention auto-purge.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Log_Page
 */
class GCO_Stock_Sync_Log_Page {

	/**
	 * Log list table instance.
	 *
	 * @var GCO_Stock_Sync_Log_List_Table|null
	 */
	private $list_table = null;

	/**
	 * Initialize log page hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'gco_stock_sync_log_purge', array( __CLASS__, 'purge_old_logs' ) );
	}

	/**
	 * Handle admin actions like manual log clearing.
	 */
	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['gco_ss_action'] ) && 'clear_logs' === $_POST['gco_ss_action'] ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Permission denied. You must be able to manage WooCommerce.', 'gco-stock-sync' ), 403 );
			}

			check_admin_referer( 'gco_stock_sync_clear_logs', 'gco_ss_nonce' );

			self::clear_all_logs();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'gco-stock-sync',
						'tab'     => 'logs',
						'cleared' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Truncate/delete all log rows.
	 */
	public static function clear_all_logs() {
		global $wpdb;

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$items_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$runs_table}" );
	}

	/**
	 * Purge logs older than N days (default: 30 days).
	 *
	 * @param int $days Number of days to retain.
	 * @return int Number of deleted runs.
	 */
	public static function purge_old_logs( $days = 30 ) {
		global $wpdb;

		$days = max( 1, absint( $days ) );

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		// Use MySQL date comparison
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE started_at < %s", $cutoff ) );

		if ( empty( $old_ids ) ) {
			return 0;
		}

		$ids_list = implode( ',', array_map( 'absint', $old_ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$items_table} WHERE run_id IN ({$ids_list})" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( "DELETE FROM {$runs_table} WHERE id IN ({$ids_list})" );

		return (int) $deleted;
	}

	/**
	 * Render the log page or single run detail view.
	 */
	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;

		if ( $run_id > 0 ) {
			$this->render_run_detail( $run_id );
		} else {
			$this->render_list_view();
		}
	}

	/**
	 * Render the list of all sync runs.
	 */
	private function render_list_view() {
		if ( null === $this->list_table ) {
			$this->list_table = new GCO_Stock_Sync_Log_List_Table();
		}

		$this->list_table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['cleared'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Sync logs cleared successfully.', 'gco-stock-sync' ); ?></p>
			</div>
			<?php
		}
		?>
		<div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gco-stock-sync&tab=logs' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all sync run records and items?', 'gco-stock-sync' ) ); ?>');">
				<?php wp_nonce_field( 'gco_stock_sync_clear_logs', 'gco_ss_nonce' ); ?>
				<input type="hidden" name="gco_ss_action" value="clear_logs" />
				<button type="submit" class="button">
					<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 2px;"></span>
					<?php esc_html_e( 'Clear All Logs', 'gco-stock-sync' ); ?>
				</button>
			</form>
		</div>

		<form method="get">
			<input type="hidden" name="page" value="gco-stock-sync" />
			<input type="hidden" name="tab" value="logs" />
			<?php $this->list_table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Render detailed item outcomes for a single run.
	 *
	 * @param int $run_id Run record ID.
	 */
	public function render_run_detail( $run_id ) {
		global $wpdb;

		$runs_table  = $wpdb->prefix . 'gco_ss_runs';
		$items_table = $wpdb->prefix . 'gco_ss_items';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs_table} WHERE id = %d", $run_id ) );

		if ( ! $run ) {
			?>
			<div class="wrap gco-ss-wrap">
				<p><?php esc_html_e( 'Sync run not found.', 'gco-stock-sync' ); ?></p>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=gco-stock-sync&tab=logs' ) ); ?>">&larr; <?php esc_html_e( 'Back to all runs', 'gco-stock-sync' ); ?></a></p>
			</div>
			<?php
			return;
		}

		// Fetch item rows for this run
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE run_id = %d ORDER BY id ASC", $run_id ) );

		$back_url = admin_url( 'admin.php?page=gco-stock-sync&tab=logs' );
		?>
		<div class="gco-ss-detail-header">
			<a href="<?php echo esc_url( $back_url ); ?>" class="back-link">&larr; <?php esc_html_e( 'Back to All Runs', 'gco-stock-sync' ); ?></a>

				<h1>
					<?php
					printf(
						/* translators: 1: Run ID, 2: Supplier label */
						esc_html__( 'Run #%1$d — %2$s', 'gco-stock-sync' ),
						(int) $run->id,
						esc_html( ucwords( str_replace( '_', ' ', $run->supplier ) ) )
					);
					?>
				</h1>

				<div class="gco-ss-detail-meta">
					<div>
						<strong><?php esc_html_e( 'Status:', 'gco-stock-sync' ); ?></strong>
						<span class="gco-ss-badge status-<?php echo esc_attr( sanitize_html_class( $run->status ) ); ?>">
							<?php echo esc_html( $run->status ); ?>
						</span>
					</div>
					<div>
						<strong><?php esc_html_e( 'Started:', 'gco-stock-sync' ); ?></strong>
						<?php echo esc_html( $run->started_at ); ?>
					</div>
					<div>
						<strong><?php esc_html_e( 'Finished:', 'gco-stock-sync' ); ?></strong>
						<?php echo esc_html( ! empty( $run->finished_at ) ? $run->finished_at : '—' ); ?>
					</div>
					<div>
						<strong><?php esc_html_e( 'Rows Fetched:', 'gco-stock-sync' ); ?></strong>
						<?php echo esc_html( number_format_i18n( (int) $run->rows_fetched ) ); ?>
					</div>
					<div>
						<strong><?php esc_html_e( 'Products Updated:', 'gco-stock-sync' ); ?></strong>
						<?php echo esc_html( number_format_i18n( (int) $run->products_updated ) ); ?>
					</div>
				</div>

				<?php if ( ! empty( $run->message ) ) : ?>
					<p style="margin-top: 15px; margin-bottom: 0;">
						<strong><?php esc_html_e( 'Message:', 'gco-stock-sync' ); ?></strong>
						<code><?php echo esc_html( $run->message ); ?></code>
					</p>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Item-Level Outcomes', 'gco-stock-sync' ); ?></h2>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 140px;"><?php esc_html_e( 'SKU', 'gco-stock-sync' ); ?></th>
						<th><?php esc_html_e( 'Matched Product', 'gco-stock-sync' ); ?></th>
						<th style="width: 110px;"><?php esc_html_e( 'Supplier Qty', 'gco-stock-sync' ); ?></th>
						<th style="width: 180px;"><?php esc_html_e( 'Status Transition', 'gco-stock-sync' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Action', 'gco-stock-sync' ); ?></th>
						<th><?php esc_html_e( 'Note', 'gco-stock-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No items recorded for this run.', 'gco-stock-sync' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $item->sku ); ?></strong></td>
								<td>
									<?php
									if ( ! empty( $item->product_id ) ) {
										$prod_title = get_the_title( $item->product_id );
										$edit_url   = get_edit_post_link( $item->product_id );
										if ( $edit_url ) {
											printf(
												'<a href="%s">%s</a> <span class="description">(#%d)</span>',
												esc_url( $edit_url ),
												esc_html( $prod_title ? $prod_title : __( 'Product', 'gco-stock-sync' ) ),
												(int) $item->product_id
											);
										} else {
											echo esc_html( $prod_title ? $prod_title : '#' . $item->product_id );
										}
									} else {
										echo '<em>' . esc_html__( 'Unmatched', 'gco-stock-sync' ) . '</em>';
									}
									?>
								</td>
								<td>
									<?php echo null !== $item->supplier_qty ? esc_html( $item->supplier_qty ) : '—'; ?>
								</td>
								<td>
									<?php
									$old_s = ! empty( $item->old_status ) ? $item->old_status : '—';
									$new_s = ! empty( $item->new_status ) ? $item->new_status : '—';
									if ( $old_s !== $new_s ) {
										echo esc_html( $old_s ) . ' &rarr; <strong>' . esc_html( $new_s ) . '</strong>';
									} else {
										echo esc_html( $new_s );
									}
									?>
								</td>
								<td>
									<span class="gco-ss-badge action-<?php echo esc_attr( sanitize_html_class( $item->action ) ); ?>">
										<?php echo esc_html( $item->action ); ?>
									</span>
								</td>
								<td>
									<?php echo ! empty( $item->note ) ? esc_html( $item->note ) : '—'; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		<?php
	}
}
