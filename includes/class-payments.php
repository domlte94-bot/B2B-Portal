<?php
/**
 * Payments per company (card, invoice with terms, or both), fixed billing address,
 * and stamping the company on each order.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invoice gateway: places the order on hold, to be paid on the company's terms.
 */
class B2BCP_Gateway_Invoice extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'b2bcp_invoice';
		$this->method_title       = __( 'Invoice (contract terms)', 'b2b-contract-portal' );
		$this->method_description = __( 'For client companies allowed to pay by invoice. Orders go on hold until you mark them paid. Shown only to companies set to "Invoice" or "Both".', 'b2b-contract-portal' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Invoice', 'b2b-contract-portal' ) );
		$this->description = $this->get_option( 'description', __( 'We will send an invoice. Payment is due on your contract terms.', 'b2b-contract-portal' ) );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'b2b-contract-portal' ),
				'type'    => 'checkbox',
				'label'   => __( 'Allow invoice payment for companies that have it', 'b2b-contract-portal' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => __( 'Title', 'b2b-contract-portal' ),
				'type'    => 'text',
				'default' => __( 'Invoice', 'b2b-contract-portal' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'b2b-contract-portal' ),
				'type'    => 'textarea',
				'default' => __( 'We will send an invoice. Payment is due on your contract terms.', 'b2b-contract-portal' ),
			),
		);
	}

	/**
	 * Title with the company's terms, e.g. "Invoice (Net 30)".
	 *
	 * @return string
	 */
	public function get_title() {
		$title   = parent::get_title();
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		if ( $company && $company['terms'] && ! is_admin() ) {
			$title .= ' (' . $company['terms'] . ')';
		}
		return $title;
	}

	/**
	 * Only for companies allowed to pay by invoice.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		return $company && in_array( $company['payment'], array( 'invoice', 'both' ), true );
	}

	/**
	 * Place the order on hold.
	 *
	 * @param int $order_id Order.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		$terms   = $company && $company['terms'] ? $company['terms'] : '';
		/* translators: %s: payment terms */
		$order->update_status( 'on-hold', $terms ? sprintf( __( 'Awaiting payment on invoice terms (%s).', 'b2b-contract-portal' ), $terms ) : __( 'Awaiting payment on invoice.', 'b2b-contract-portal' ) );
		if ( $terms ) {
			$order->update_meta_data( '_b2bcp_terms', $terms );
			$order->save();
		}
		wc_reduce_stock_levels( $order_id );
		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}

/**
 * Gateway filtering, billing lock and company stamping.
 */
class B2BCP_Payments {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ), 50 );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_block_gateway' ) );

		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_billing' ), 20, 2 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'lock_billing_fields' ), 50 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'summary_fields' ), 60 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'use_billing_summary' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'stamp_order' ), 20 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_company' ) );
	}

	/**
	 * Register the invoice gateway.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		$gateways[] = 'B2BCP_Gateway_Invoice';
		return $gateways;
	}

	/**
	 * Card only, invoice only, or both — per company. Shoppers without a company never see the invoice option.
	 *
	 * @param WC_Payment_Gateway[] $gateways Available gateways.
	 * @return WC_Payment_Gateway[]
	 */
	public static function filter_gateways( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		if ( ! $company || 'card' === $company['payment'] ) {
			unset( $gateways['b2bcp_invoice'] );
			return $gateways;
		}
		if ( 'invoice' === $company['payment'] && isset( $gateways['b2bcp_invoice'] ) ) {
			return array( 'b2bcp_invoice' => $gateways['b2bcp_invoice'] );
		}
		return $gateways;
	}

	/**
	 * Make the invoice option appear in the block-based Checkout too.
	 *
	 * @param object $registry Payment method registry.
	 */
	public static function register_block_gateway( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once B2BCP_DIR . 'includes/class-gateway-invoice-blocks.php';
		$registry->register( new B2BCP_Gateway_Invoice_Blocks() );
	}

	/**
	 * Company billing values to enforce (only the keys the store filled in).
	 *
	 * @return array<string,string> billing_<key> => value
	 */
	public static function locked_billing() {
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		if ( ! $company ) {
			return array();
		}
		$out = array();
		foreach ( $company['billing'] as $key => $value ) {
			if ( '' !== (string) $value ) {
				$out[ 'billing_' . $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Classic checkout: pre-fill billing with the company's.
	 *
	 * @param mixed  $value Value.
	 * @param string $input Field.
	 * @return mixed
	 */
	public static function prefill_billing( $value, $input ) {
		$locked = self::locked_billing();
		return isset( $locked[ $input ] ) ? $locked[ $input ] : $value;
	}

	/**
	 * Classic checkout: make locked text fields read-only.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function lock_billing_fields( $fields ) {
		foreach ( array_keys( self::locked_billing() ) as $key ) {
			if ( isset( $fields['billing'][ $key ] ) && ! in_array( $fields['billing'][ $key ]['type'] ?? 'text', array( 'country', 'state', 'select' ), true ) ) {
				$fields['billing'][ $key ]['custom_attributes']['readonly'] = 'readonly';
				$fields['billing'][ $key ]['description']                   = __( 'Set by your company contract', 'b2b-contract-portal' );
			}
		}
		return $fields;
	}

	/**
	 * Billing values for a company buyer when the company has a billing address on file:
	 * the contract's billing plus the buyer's own name and email. Null = show the normal form.
	 *
	 * @return array<string,string>|null billing_<key> => value
	 */
	public static function billing_summary_values() {
		if ( ! is_user_logged_in() || ! WC()->checkout() ) {
			return null;
		}
		$locked = self::locked_billing();
		if ( empty( $locked['billing_address_1'] ) ) {
			return null;
		}
		$user     = wp_get_current_user();
		$customer = WC()->customer;
		$names    = array(
			'billing_first_name' => $customer ? $customer->get_billing_first_name() : '',
			'billing_last_name'  => $customer ? $customer->get_billing_last_name() : '',
		);
		if ( '' === $names['billing_first_name'] ) {
			$names['billing_first_name'] = $user->first_name ? $user->first_name : $user->display_name;
		}
		if ( '' === $names['billing_last_name'] ) {
			$names['billing_last_name'] = $user->last_name;
		}
		$email = $customer && $customer->get_billing_email() ? $customer->get_billing_email() : $user->user_email;
		$phone = $customer ? $customer->get_billing_phone() : '';
		return $locked + $names + array(
			'billing_email' => $email,
			'billing_phone' => $phone,
		);
	}

	/**
	 * Classic checkout: nothing in billing is typed when the summary replaces the form.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function summary_fields( $fields ) {
		if ( null === self::billing_summary_values() ) {
			return $fields;
		}
		foreach ( array_keys( $fields['billing'] ?? array() ) as $key ) {
			$fields['billing'][ $key ]['required'] = false;
		}
		return $fields;
	}

	/**
	 * Classic checkout: swap WooCommerce's billing form for a read-only summary.
	 */
	public static function use_billing_summary() {
		$checkout = WC()->checkout();
		if ( null === self::billing_summary_values() || ! $checkout ) {
			return;
		}
		remove_action( 'woocommerce_checkout_billing', array( $checkout, 'checkout_form_billing' ) );
		add_action( 'woocommerce_checkout_billing', array( __CLASS__, 'billing_summary' ) );
	}

	/**
	 * Who orders, who is billed and where it ships, with the values posted as hidden fields.
	 */
	public static function billing_summary() {
		$values  = self::billing_summary_values();
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		if ( null === $values || ! $company ) {
			return;
		}
		$countries = WC()->countries;
		$address   = $countries->get_formatted_address(
			array(
				'company'   => $values['billing_company'] ?? $company['name'],
				'address_1' => $values['billing_address_1'] ?? '',
				'address_2' => $values['billing_address_2'] ?? '',
				'city'      => $values['billing_city'] ?? '',
				'state'     => $values['billing_state'] ?? '',
				'postcode'  => $values['billing_postcode'] ?? '',
				'country'   => $values['billing_country'] ?? '',
			)
		);
		$name = trim( $values['billing_first_name'] . ' ' . $values['billing_last_name'] );

		echo '<div class="b2bcp-checkout-summary">';
		echo '<h3>' . esc_html__( 'Order details', 'b2b-contract-portal' ) . '</h3>';
		echo '<dl>';
		echo '<dt>' . esc_html__( 'Ordering for', 'b2b-contract-portal' ) . '</dt><dd>' . esc_html( $company['name'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Ordered by', 'b2b-contract-portal' ) . '</dt><dd>' . esc_html( $name ) . '<br>' . esc_html( $values['billing_email'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Bill to', 'b2b-contract-portal' ) . '</dt><dd>' . wp_kses_post( $address ) . '</dd>';
		if ( WC()->cart && WC()->cart->needs_shipping() && ! WC()->cart->needs_shipping_address() ) {
			echo '<dt>' . esc_html__( 'Ship to', 'b2b-contract-portal' ) . '</dt><dd>' . esc_html__( 'The addresses chosen for each item (listed in your order below).', 'b2b-contract-portal' ) . '</dd>';
		}
		echo '</dl>';
		/* translators: %s: store name */
		echo '<p class="b2bcp-muted">' . esc_html( sprintf( __( 'Billing is set by your company contract. To change it, contact %s.', 'b2b-contract-portal' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ) . '</p>';
		// Posted like the normal form (checkout.js also reads #billing_country etc. to update totals).
		foreach ( $values as $key => $value ) {
			echo '<input type="hidden" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '</div>';
	}

	/**
	 * Both checkouts: save the company on the order and enforce its billing address.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function stamp_order( $order ) {
		$company_id = b2bcp_active_company_id();
		if ( ! $company_id ) {
			return;
		}
		$order->update_meta_data( '_b2bcp_company_id', $company_id );
		$order->update_meta_data( '_b2bcp_company_name', get_the_title( $company_id ) );
		foreach ( self::locked_billing() as $key => $value ) {
			$setter = 'set_' . $key;
			if ( is_callable( array( $order, $setter ) ) ) {
				$order->$setter( $value );
			}
		}
		// Everything ships to saved addresses: say so instead of an empty "N/A" shipping address.
		if ( '' === $order->get_shipping_address_1() && WC()->cart && ! WC()->cart->needs_shipping_address() && WC()->cart->needs_shipping() ) {
			$order->set_shipping_company( __( 'Several addresses — see each item', 'b2b-contract-portal' ) );
		}
	}

	/**
	 * Show the company in the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_company( $order ) {
		$company_id = (int) $order->get_meta( '_b2bcp_company_id' );
		if ( ! $company_id ) {
			return;
		}
		$name = $order->get_meta( '_b2bcp_company_name' );
		echo '<p><strong>' . esc_html__( 'Client company:', 'b2b-contract-portal' ) . '</strong><br>';
		if ( get_post( $company_id ) ) {
			echo '<a href="' . esc_url( get_edit_post_link( $company_id ) ) . '">' . esc_html( $name ) . '</a>';
		} else {
			echo esc_html( $name );
		}
		$terms = $order->get_meta( '_b2bcp_terms' );
		if ( $terms ) {
			echo '<br>' . esc_html__( 'Terms:', 'b2b-contract-portal' ) . ' ' . esc_html( $terms );
		}
		echo '</p>';
	}
}
