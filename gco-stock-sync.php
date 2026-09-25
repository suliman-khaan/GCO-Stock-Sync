<?php
/**
 * GCO Supplier Stock Sync
 *
 * Pulls live stock data from supplier feeds and updates WooCommerce product
 * stock status (never quantity) on a configurable schedule.
 *
 * @package     GCO_Stock_Sync
 * @author      Suliman K.
 * @copyright   2026 Suliman K.
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       GCO Supplier Stock Sync
 * Plugin URI:        https://sulimankhan.pro
 * Description:       Pulls live stock data from supplier feeds and updates WooCommerce product stock status on a schedule. Suppliers: Highland Outdoors (NetSuite), Ladds Guns (INFAC), Browning Gun Safes.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Suliman K.
 * Author URI:        https://sulimankhan.pro
 * Text Domain:       gco-stock-sync
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'GCO_STOCK_SYNC_VERSION', '1.3.0' );
define( 'GCO_STOCK_SYNC_FILE', __FILE__ );
define( 'GCO_STOCK_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'GCO_STOCK_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'GCO_STOCK_SYNC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * Not strictly needed since the plugin doesn't touch orders,
 * but WooCommerce will warn in the admin without it.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Load plugin textdomain for translations.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'gco-stock-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Load the plugin after all plugins are loaded, so WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	function () {
		// Bail if WooCommerce is not active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'gco_stock_sync_woocommerce_missing_notice' );
			return;
		}

		require_once GCO_STOCK_SYNC_PATH . 'includes/class-plugin.php';
		GCO_Stock_Sync_Plugin::get_instance();
	}
);

/**
 * Display admin notice when WooCommerce is not active.
 */
function gco_stock_sync_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'GCO Supplier Stock Sync', 'gco-stock-sync' ); ?>:</strong>
			<?php esc_html_e( 'This plugin requires WooCommerce to be installed and activated.', 'gco-stock-sync' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Activation hook.
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once GCO_STOCK_SYNC_PATH . 'includes/class-installer.php';
		require_once GCO_STOCK_SYNC_PATH . 'includes/class-activator.php';
		GCO_Stock_Sync_Activator::activate();
	}
);

/**
 * Deactivation hook.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		require_once GCO_STOCK_SYNC_PATH . 'includes/class-deactivator.php';
		GCO_Stock_Sync_Deactivator::deactivate();
	}
);
