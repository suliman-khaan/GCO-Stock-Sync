<?php
/**
 * Product Meta Box & List Columns — per-product stock sync controls.
 *
 * Adds sync toggle to WooCommerce product inventory tab and variation rows,
 * plus a status column on the WooCommerce products list table.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Product_Meta_Box
 */
class GCO_Stock_Sync_Product_Meta_Box {

	/**
	 * Meta key for enabling/disabling sync on a product.
	 */
	const META_ENABLED = '_gco_ss_enabled';

	/**
	 * Meta key for supplier name.
	 */
	const META_SUPPLIER = '_gco_ss_supplier';

	/**
	 * Meta key for last supplier qty.
	 */
	const META_LAST_QTY = '_gco_ss_last_qty';

	/**
	 * Meta key for last sync timestamp.
	 */
	const META_LAST_SYNC = '_gco_ss_last_sync';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Simple & parent products (inventory tab)
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_inventory_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

		// Variations
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_meta' ), 10, 2 );

		// Products list column
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'add_column_css' ) );
	}

	/**
	 * Render fields in the Product Data -> Inventory tab.
	 */
	public function render_inventory_fields() {
		global $post;

		if ( ! $post ) {
			return;
		}

		echo '<div class="options_group gco-ss-product-options">';

		// Default is enabled ('yes') unless explicitly set to 'no'
		$raw_enabled = get_post_meta( $post->ID, self::META_ENABLED, true );
		$is_enabled  = 'no' !== $raw_enabled;

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ENABLED,
				'label'       => __( 'Supplier Stock Sync', 'gco-stock-sync' ),
				'description' => __( 'Synchronise stock status from supplier inventory feeds for this product.', 'gco-stock-sync' ),
				'desc_tip'    => false,
				'value'       => $is_enabled ? 'yes' : 'no',
			)
		);

		// Read-only diagnostic info
		$last_qty  = get_post_meta( $post->ID, self::META_LAST_QTY, true );
		$last_sync = get_post_meta( $post->ID, self::META_LAST_SYNC, true );
		$supplier  = get_post_meta( $post->ID, self::META_SUPPLIER, true );

		if ( '' !== $last_sync || '' !== $last_qty ) {
			?>
			<p class="form-field">
				<label><?php esc_html_e( 'Last Sync Info', 'gco-stock-sync' ); ?></label>
				<span class="description" style="display: inline-block; padding-top: 5px;">
					<?php
					if ( '' !== $supplier ) {
						echo '<strong>' . esc_html__( 'Supplier:', 'gco-stock-sync' ) . '</strong> ' . esc_html( ucwords( str_replace( '_', ' ', $supplier ) ) ) . ' | ';
					}
					if ( '' !== $last_qty ) {
						echo '<strong>' . esc_html__( 'Last Qty:', 'gco-stock-sync' ) . '</strong> ' . esc_html( $last_qty ) . ' | ';
					}
					if ( '' !== $last_sync ) {
						echo '<strong>' . esc_html__( 'Last Synced:', 'gco-stock-sync' ) . '</strong> ' . esc_html( $last_sync );
					}
					?>
				</span>
			</p>
			<?php
		}

		echo '</div>';
	}

	/**
	 * Save product inventory meta.
	 *
	 * @param int $post_id Product post ID.
	 */
	public function save_product_meta( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$is_enabled = ( isset( $_POST[ self::META_ENABLED ] ) && 'no' !== $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_ENABLED, $is_enabled );
	}

	/**
	 * Render fields in variation edit panel.
	 *
	 * @param int     $loop           Position in loop.
	 * @param array   $variation_data Variation post data.
	 * @param WP_Post $variation      Variation post object.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		$raw_enabled = get_post_meta( $variation->ID, self::META_ENABLED, true );
		$is_enabled  = 'no' !== $raw_enabled;
		?>
		<div class="gco-ss-variation-options" style="margin-top: 10px; margin-bottom: 10px;">
			<label>
				<input type="checkbox" class="checkbox" name="<?php echo esc_attr( self::META_ENABLED . '[' . $loop . ']' ); ?>" value="yes" <?php checked( $is_enabled, true ); ?> />
				<?php esc_html_e( 'Supplier Stock Sync enabled for this variation', 'gco-stock-sync' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Save variation meta.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $i            Index.
	 */
	public function save_variation_meta( $variation_id, $i ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$is_enabled = ( isset( $_POST[ self::META_ENABLED ][ $i ] ) && 'no' !== $_POST[ self::META_ENABLED ][ $i ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, self::META_ENABLED, $is_enabled );
	}


	/**
	 * Output column width and alignment styles on the products list table.
	 */
	public function add_column_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.widefat th.column-gco_stock_sync,
			.widefat td.column-gco_stock_sync {
				width: 100px !important;
				min-width: 90px;
				text-align: center;
				white-space: nowrap;
				vertical-align: middle;
			}
			.column-gco_stock_sync .dashicons {
				vertical-align: middle;
				margin-right: 3px;
				font-size: 17px;
				width: 17px;
				height: 17px;
			}
		</style>
		<?php
	}

	/**
	 * Add Stock Sync column to Products list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_product_column( $columns ) {
		$new_columns = array();
		$column_title = '<span style="white-space: nowrap;" title="' . esc_attr__( 'Supplier Stock Sync', 'gco-stock-sync' ) . '">' . esc_html__( 'Stock Sync', 'gco-stock-sync' ) . '</span>';

		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'is_in_stock' === $key ) {
				$new_columns['gco_stock_sync'] = $column_title;
			}
		}

		if ( ! isset( $new_columns['gco_stock_sync'] ) ) {
			$new_columns['gco_stock_sync'] = $column_title;
		}

		return $new_columns;
	}

	/**
	 * Render Stock Sync column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Product post ID.
	 */
	public function render_product_column( $column, $post_id ) {
		if ( 'gco_stock_sync' !== $column ) {
			return;
		}

		$raw_enabled = get_post_meta( $post_id, self::META_ENABLED, true );
		$is_enabled  = 'no' !== $raw_enabled;

		if ( $is_enabled ) {
			$last_sync = get_post_meta( $post_id, self::META_LAST_SYNC, true );
			$title     = ! empty( $last_sync ) ? sprintf( __( 'Active (Last synced: %s)', 'gco-stock-sync' ), $last_sync ) : __( 'Active', 'gco-stock-sync' );

			echo '<span style="white-space: nowrap; display: inline-block;" title="' . esc_attr( $title ) . '"><span class="dashicons dashicons-yes-alt" style="color:#1a7f37;"></span> <small>' . esc_html__( 'Active', 'gco-stock-sync' ) . '</small></span>';
		} else {
			echo '<span style="white-space: nowrap; display: inline-block;" title="' . esc_attr__( 'Disabled', 'gco-stock-sync' ) . '"><span class="dashicons dashicons-dismiss" style="color:#cf222e;"></span> <small style="color:#646970;">' . esc_html__( 'Disabled', 'gco-stock-sync' ) . '</small></span>';
		}
	}
}
