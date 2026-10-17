<?php
/**
 * My Account → Home for company users (replaces WooCommerce's myaccount/dashboard.php).
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

B2BCP_Dashboard::render();

// Keep the hook other plugins use to add to the dashboard.
do_action( 'woocommerce_account_dashboard' );
