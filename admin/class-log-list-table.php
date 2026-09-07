<?php
/**
 * Log List Table — WP_List_Table implementation for sync run history.
 *
 * Displays paginated sync run history with status filters.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class GCO_Stock_Sync_Log_List_Table
 */
class GCO_Stock_Sync_Log_List_Table extends WP_List_Table {

	/**
	 * Per page count.
	 *
	 * @var int
	 */
	public $per_page = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sync_run',
				'plural'   => 'sync_runs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'started_at'       => __( 'Started At', 'gco-stock-sync' ),
			'supplier'         => __( 'Supplier', 'gco-stock-sync' ),
			'status'           => __( 'Status', 'gco-stock-sync' ),
			'rows_fetched'     => __( 'Rows Fetched', 'gco-stock-sync' ),
			'products_updated' => __( 'Products Updated', 'gco-stock-sync' ),
			'message'          => __( 'Message', 'gco-stock-sync' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'started_at' => array( 'started_at', true ),
			'status'     => array( 'status', false ),
		);
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		global $wpdb;

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$table_name   = $wpdb->prefix . 'gco_ss_runs';
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $this->per_page;

		// Filter by status
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';

		$where_clause = '1=1';
		$params       = array();

		if ( ! empty( $status_filter ) ) {
			$where_clause .= ' AND status = %s';
			$params[]      = $status_filter;
		}

		// Count total rows
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}", $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}" );
		}

		// Fetch items
		$order_by = 'id';
		$order    = 'DESC';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'started_at', 'status' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_by = sanitize_key( $_GET['orderby'] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = strtoupper( sanitize_key( $_GET['order'] ) );
		}

		$query_params   = $params;
		$query_params[] = $this->per_page;
		$query_params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d",
				$query_params
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $this->per_page,
				'total_pages' => ceil( $total_items / $this->per_page ),
			)
		);
	}

	/**
	 * Default column output.
	 *
	 * @param object $item        Run record.
	 * @param string $column_name Column name.
	 * @return string HTML output.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'supplier':
				return esc_html( ucwords( str_replace( '_', ' ', $item->supplier ) ) );

			case 'status':
				$status_class = sanitize_html_class( $item->status );
				return sprintf(
					'<span class="gco-ss-badge status-%s">%s</span>',
					esc_attr( $status_class ),
					esc_html( $item->status )
				);

			case 'rows_fetched':
				return esc_html( number_format_i18n( (int) $item->rows_fetched ) );

			case 'products_updated':
				return esc_html( number_format_i18n( (int) $item->products_updated ) );

			case 'message':
				return ! empty( $item->message ) ? esc_html( $item->message ) : '—';

			default:
				return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
		}
	}

	/**
	 * Started At column output with link to detail view.
	 *
	 * @param object $item Run record.
	 * @return string HTML.
	 */
	public function column_started_at( $item ) {
		$detail_url = add_query_arg(
			array(
				'page'   => 'gco-stock-sync',
				'tab'    => 'logs',
				'run_id' => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $detail_url ),
				__( 'View Item Details', 'gco-stock-sync' )
			),
		);

		$date_display = esc_html( $item->started_at );

		return sprintf(
			'<strong><a href="%s">%s</a></strong> %s',
			esc_url( $detail_url ),
			$date_display,
			$this->row_actions( $actions )
		);
	}

	/**
	 * Extra table navigation filters (status dropdown).
	 *
	 * @param string $which Table nav position ('top' or 'bottom').
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';
		$statuses       = array( 'success', 'failed', 'partial', 'skipped', 'running' );
		?>
		<div class="alignleft actions">
			<select name="status_filter" id="filter-by-status">
				<option value=""><?php esc_html_e( 'All Statuses', 'gco-stock-sync' ); ?></option>
				<?php foreach ( $statuses as $status ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current_status, $status ); ?>>
						<?php echo esc_html( ucfirst( $status ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'gco-stock-sync' ), 'button', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Message to show when no items exist.
	 */
	public function no_items() {
		esc_html_e( 'No sync runs recorded yet.', 'gco-stock-sync' );
	}
}
