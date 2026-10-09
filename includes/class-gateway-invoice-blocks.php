<?php
/**
 * Invoice gateway for the block-based Checkout.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the invoice option with the Checkout block.
 */
class B2BCP_Gateway_Invoice_Blocks extends AbstractPaymentMethodType {

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	protected $name = 'b2bcp_invoice';

	/**
	 * Load settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_b2bcp_invoice_settings', array() );
	}

	/**
	 * Active when the gateway is available to the current shopper.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['b2bcp_invoice'] ) && $gateways['b2bcp_invoice']->is_available();
	}

	/**
	 * Front-end script.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'b2bcp-invoice-blocks',
			B2BCP_URL . 'assets/invoice-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			B2BCP_VERSION,
			true
		);
		return array( 'b2bcp-invoice-blocks' );
	}

	/**
	 * Data passed to the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways['b2bcp_invoice'] ?? null;
		return array(
			'title'       => $gateway ? $gateway->get_title() : __( 'Invoice', 'b2b-contract-portal' ),
			'description' => $gateway ? $gateway->get_description() : '',
			'supports'    => array( 'products' ),
		);
	}
}
