<?php
/**
 * Plugin Name:       B2B Contract Portal for WooCommerce
 * Description:       Contract customers for print and promo shops: client companies with exclusive catalogs and contract prices, products with parts (complete or single parts), per-person personalization (names, titles), multi-address shipping on one order, invoice terms, quote requests and production sheets.
 * Version:           0.3.0
 * Author:            Muvves Studio
 * Text Domain:       b2b-contract-portal
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * License:           GPLv2 or later
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

define( 'B2BCP_VERSION', '0.3.0' );
define( 'B2BCP_FILE', __FILE__ );
define( 'B2BCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'B2BCP_URL', plugin_dir_url( __FILE__ ) );

require_once B2BCP_DIR . 'includes/class-install.php';

register_activation_hook( __FILE__, array( 'B2BCP_Install', 'activate' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'b2bcp_boot', 20 );

/**
 * Load the plugin once WooCommerce is available.
 */
function b2bcp_boot() {
	load_plugin_textdomain( 'b2b-contract-portal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'B2B Contract Portal needs WooCommerce to be installed and active.', 'b2b-contract-portal' ) . '</p></div>';
			}
		);
		return;
	}

	B2BCP_Install::maybe_upgrade();

	$files = array(
		'helpers.php',
		'class-fields.php',
		'class-company-admin.php',
		'class-product-admin.php',
		'class-catalog.php',
		'class-order-form.php',
		'class-shipping.php',
		'class-payments.php',
		'class-branding.php',
		'class-account.php',
		'class-quotes.php',
		'class-emails.php',
		'class-export.php',
		'class-settings.php',
	);
	foreach ( $files as $file ) {
		require_once B2BCP_DIR . 'includes/' . $file;
	}

	B2BCP_Company_Admin::init();
	B2BCP_Product_Admin::init();
	B2BCP_Catalog::init();
	B2BCP_Order_Form::init();
	B2BCP_Shipping::init();
	B2BCP_Payments::init();
	B2BCP_Branding::init();
	B2BCP_Account::init();
	B2BCP_Quotes::init();
	B2BCP_Emails::init();
	B2BCP_Export::init();
	B2BCP_Settings::init();
}
