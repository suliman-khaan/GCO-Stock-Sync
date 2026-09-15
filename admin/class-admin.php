<?php
/**
 * Admin Controller — registers admin menus, manages tabs, and enqueues assets.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Admin
 */
class GCO_Stock_Sync_Admin {

	/**
	 * Settings page controller.
	 *
	 * @var GCO_Stock_Sync_Settings_Page
	 */
	private $settings_page;

	/**
	 * Log page controller.
	 *
	 * @var GCO_Stock_Sync_Log_Page
	 */
	private $log_page;

	/**
	 * Product meta box controller.
	 *
	 * @var GCO_Stock_Sync_Product_Meta_Box
	 */
	private $meta_box;

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings_page = new GCO_Stock_Sync_Settings_Page();
		$this->log_page      = new GCO_Stock_Sync_Log_Page();
		$this->meta_box      = new GCO_Stock_Sync_Product_Meta_Box();

		$this->settings_page->init();
		$this->log_page->init();
		$this->meta_box->init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu under WooCommerce.
	 */
	public function register_menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			__( 'Stock Sync', 'gco-stock-sync' ),
			__( 'Stock Sync', 'gco-stock-sync' ),
			'manage_woocommerce',
			'gco-stock-sync',
			array( $this, 'render_main_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles only on this plugin's screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Strict screen check — only enqueue on our screen
		if ( ! $screen || 'woocommerce_page_gco-stock-sync' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'gco-stock-sync-admin',
			GCO_STOCK_SYNC_URL . 'admin/assets/admin.css',
			array(),
			GCO_STOCK_SYNC_VERSION
		);

		wp_enqueue_script(
			'gco-stock-sync-admin',
			GCO_STOCK_SYNC_URL . 'admin/assets/admin.js',
			array( 'jquery' ),
			GCO_STOCK_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'gco-stock-sync-admin',
			'gco_ss_admin',
			array(
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'manual_run_nonce'      => wp_create_nonce( 'gco_stock_sync_manual_run' ),
				'test_connection_nonce' => wp_create_nonce( 'gco_stock_sync_test_connection' ),
				'strings'               => array(
					'running'        => __( 'Sync in progress... Please wait.', 'gco-stock-sync' ),
					'testing'        => __( 'Testing connection...', 'gco-stock-sync' ),
					'copied'         => __( 'Copied to clipboard!', 'gco-stock-sync' ),
					'copy_failed'    => __( 'Copy failed.', 'gco-stock-sync' ),
				),
			)
		);
	}

	/**
	 * Render the main administration page with tabbed interface.
	 */
	public function render_main_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied. You must be able to manage WooCommerce to access this page.', 'gco-stock-sync' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		// 'settings' is the pre-tabs-redesign default; keep old bookmarks working.
		if ( 'settings' === $requested_tab ) {
			$requested_tab = 'general';
		}

		$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();

		// One tab per registered supplier, built from the same template —
		// a future connector (Browning, GMK, ...) gets its own tab for free
		// the moment it's registered, with no changes needed here.
		$tabs = array( 'general' => __( 'General Settings', 'gco-stock-sync' ) );
		foreach ( $suppliers as $key => $supplier ) {
			$tabs[ 'supplier_' . $key ] = $supplier->get_label();
		}
		$tabs['logs'] = __( 'Sync History & Logs', 'gco-stock-sync' );

		if ( ! array_key_exists( $requested_tab, $tabs ) ) {
			$requested_tab = 'general';
		}
		?>
		<div class="wrap gco-ss-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'GCO Supplier Stock Sync', 'gco-stock-sync' ); ?></h1>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-top: 15px; margin-bottom: 20px;">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=gco-stock-sync&tab=' . $tab_key ) ); ?>" class="nav-tab <?php echo $tab_key === $requested_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
						<?php if ( 0 === strpos( $tab_key, 'supplier_' ) ) : ?>
							<?php $supplier_key = substr( $tab_key, strlen( 'supplier_' ) ); ?>
							<?php if ( isset( $suppliers[ $supplier_key ] ) && $suppliers[ $supplier_key ]->is_configured() ) : ?>
								<span class="gco-ss-tab-dot gco-ss-tab-dot-configured" title="<?php esc_attr_e( 'Configured', 'gco-stock-sync' ); ?>"></span>
							<?php else : ?>
								<span class="gco-ss-tab-dot gco-ss-tab-dot-unconfigured" title="<?php esc_attr_e( 'Not configured', 'gco-stock-sync' ); ?>"></span>
							<?php endif; ?>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'logs' === $requested_tab ) {
				$this->log_page->render();
			} elseif ( 'general' === $requested_tab ) {
				$this->settings_page->render_general_tab();
			} else {
				$this->settings_page->render_supplier_tab( substr( $requested_tab, strlen( 'supplier_' ) ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Get the settings page instance.
	 *
	 * @return GCO_Stock_Sync_Settings_Page
	 */
	public function get_settings_page() {
		return $this->settings_page;
	}

	/**
	 * Get the log page instance.
	 *
	 * @return GCO_Stock_Sync_Log_Page
	 */
	public function get_log_page() {
		return $this->log_page;
	}

	/**
	 * Get the product meta box instance.
	 *
	 * @return GCO_Stock_Sync_Product_Meta_Box
	 */
	public function get_meta_box() {
		return $this->meta_box;
	}
}
