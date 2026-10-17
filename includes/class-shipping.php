<?php
/**
 * Multi-address shipping: one shipping package per branch, rated by the store's own
 * WooCommerce shipping zones and methods. Optional "shipping included in contract".
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Splits the cart into packages by branch.
 */
class B2BCP_Shipping {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'split_packages' ), 20 );
		add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'package_name' ), 10, 3 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'included_rates' ), 100, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'shipping_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_cart_needs_shipping_address', array( __CLASS__, 'needs_shipping_address' ), 20 );
		add_filter( 'pre_render_block', array( __CLASS__, 'classic_checkout_for_saved_addresses' ), 10, 2 );
	}

	/**
	 * Whether any cart line already ships to a saved address.
	 *
	 * @return bool
	 */
	public static function cart_has_saved_addresses() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['b2bcp']['address'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The Checkout block always shows a shipping address form when the cart ships,
	 * and gives plugins no way to skip it. When items already ship to saved
	 * addresses, show WooCommerce's classic checkout on that page instead: it only
	 * asks for an address when something still needs one, and keeps one shipping
	 * line per address.
	 *
	 * @param string|null $pre          Pre-rendered content.
	 * @param array       $parsed_block Block.
	 * @return string|null
	 */
	public static function classic_checkout_for_saved_addresses( $pre, $parsed_block ) {
		if ( null !== $pre || 'woocommerce/checkout' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $pre;
		}
		if ( is_admin() || wp_is_json_request() || is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return $pre;
		}
		if ( 'no' === get_option( 'b2bcp_classic_checkout', 'yes' ) || ! self::cart_has_saved_addresses() ) {
			return $pre;
		}
		return '<div class="b2bcp-classic-checkout alignwide">' . do_shortcode( '[woocommerce_checkout]' ) . '</div>';
	}

	/**
	 * Don't ask for a shipping address at checkout when every item already ships to a branch.
	 *
	 * @param bool $needs Default.
	 * @return bool
	 */
	public static function needs_shipping_address( $needs ) {
		if ( ! $needs || ! WC()->cart ) {
			return $needs;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $item['data'] instanceof WC_Product && $item['data']->needs_shipping() && empty( $item['b2bcp']['address'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One package for items shipping to the checkout address, plus one per branch.
	 *
	 * @param array $packages Packages from the cart.
	 * @return array
	 */
	public static function split_packages( $packages ) {
		if ( count( $packages ) !== 1 || ! WC()->cart ) {
			return $packages; // Another extension already split the cart; leave it alone.
		}
		$base    = $packages[0];
		$by_addr = array();
		$rest    = array();

		foreach ( $base['contents'] as $key => $item ) {
			$address = $item['b2bcp']['address'] ?? null;
			if ( $address && ! empty( $address['id'] ) ) {
				$by_addr[ (int) $address['id'] ]['address']       = $address;
				$by_addr[ (int) $address['id'] ]['items'][ $key ] = $item;
			} else {
				$rest[ $key ] = $item;
			}
		}
		if ( ! $by_addr ) {
			return $packages;
		}

		$out = array();
		if ( $rest ) {
			$package                  = $base;
			$package['contents']      = $rest;
			$package['contents_cost'] = array_sum( wp_list_pluck( $rest, 'line_total' ) );
			$out[]                    = $package;
		}
		foreach ( $by_addr as $group ) {
			$address                  = $group['address'];
			$package                  = $base;
			$package['contents']      = $group['items'];
			$package['contents_cost'] = array_sum( wp_list_pluck( $group['items'], 'line_total' ) );
			$package['destination']   = array(
				'country'   => $address['country'],
				'state'     => $address['state'],
				'postcode'  => $address['postcode'],
				'city'      => $address['city'],
				'address'   => $address['address_1'],
				'address_1' => $address['address_1'],
				'address_2' => $address['address_2'],
			);
			$package['b2bcp_address'] = $address;
			$out[]                    = $package;
		}
		return $out;
	}

	/**
	 * Label each package with its branch.
	 *
	 * @param string $name    Default name.
	 * @param int    $i       Index.
	 * @param array  $package Package.
	 * @return string
	 */
	public static function package_name( $name, $i, $package ) {
		if ( ! empty( $package['b2bcp_address'] ) ) {
			/* translators: %s: branch */
			return sprintf( __( 'Shipping to %s', 'b2b-contract-portal' ), b2bcp_address_label( $package['b2bcp_address'] ) );
		}
		return $name;
	}

	/**
	 * Contract with shipping included: a single free rate per package.
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates.
	 * @param array              $package Package.
	 * @return WC_Shipping_Rate[]
	 */
	public static function included_rates( $rates, $package ) {
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		if ( ! $company || ! $company['shipping_included'] ) {
			return $rates;
		}
		$rate = new WC_Shipping_Rate( 'b2bcp_included', __( 'Shipping included in contract', 'b2b-contract-portal' ), 0, array(), 'b2bcp_included' );
		return array( $rate->get_id() => $rate );
	}

	/**
	 * Write the branch address on each order shipping line, so packing knows where each box goes.
	 *
	 * @param WC_Order_Item_Shipping $item        Shipping item.
	 * @param int                    $package_key Package index.
	 * @param array                  $package     Package.
	 * @param WC_Order               $order       Order.
	 */
	public static function shipping_item_meta( $item, $package_key, $package, $order ) {
		if ( empty( $package['b2bcp_address'] ) ) {
			return;
		}
		$address = $package['b2bcp_address'];
		$item->add_meta_data( __( 'Ship to', 'b2b-contract-portal' ), b2bcp_address_label( $address ) . ' — ' . b2bcp_address_line( $address ), true );
		$item->add_meta_data( '_b2bcp_address', $address, true );
	}
}
