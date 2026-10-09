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
