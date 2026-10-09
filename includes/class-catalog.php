<?php
/**
 * Front-end catalog: who sees which product, and contract prices.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hides exclusive products from everyone except their companies and swaps in contract prices.
 */
class B2BCP_Catalog {

	/**
	 * Re-entrancy guard for the product query filter.
	 *
	 * @var bool
	 */
	private static $querying = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_queries' ), 20 );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'is_visible' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'is_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'filter_ids' ), 20 );
		add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'filter_ids' ), 20 );
		add_filter( 'woocommerce_product_get_cross_sell_ids', array( __CLASS__, 'filter_ids' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'block_single' ) );

		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'contract_price' ), 50, 2 );
		}
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'contract_sale_price' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'contract_sale_price' ), 50, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'variation_range_price' ), 50, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'variation_range_price' ), 50, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'variation_range_price' ), 50, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ), 50 );
	}

	/**
	 * Store staff see everything.
	 *
	 * @return bool
	 */
	public static function bypass() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Whether the current shopper may access a product.
	 *
	 * @param WC_Product|int $product Product.
	 * @return bool
	 */
	public static function can_access( $product ) {
		if ( self::bypass() ) {
			return true;
		}
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
		if ( ! $product ) {
			return false;
		}
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return b2bcp_company_can_access( $id, b2bcp_active_company_id() );
	}

	/**
	 * Remove hidden products from front-end product queries (shop, search, blocks, Store API).
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_queries( $query ) {
		if ( self::$querying || $query->get( 'b2bcp_skip' ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$types   = (array) $query->get( 'post_type' );
		$is_prod = in_array( 'product', $types, true ) || $query->is_tax( get_object_taxonomies( 'product' ) ) || ( $query->is_search() && $query->is_main_query() );
		if ( ! $is_prod || ! did_action( 'set_current_user' ) || self::bypass() ) {
			return;
		}
		self::$querying = true;
		$hidden         = b2bcp_hidden_product_ids( b2bcp_active_company_id() );
		self::$querying = false;
		if ( $hidden ) {
			$query->set( 'post__not_in', array_values( array_unique( array_merge( (array) $query->get( 'post__not_in' ), $hidden ) ) ) );
		}
	}

	/**
	 * Loop visibility.
	 *
	 * @param bool $visible    Visible.
	 * @param int  $product_id Product.
	 * @return bool
	 */
	public static function is_visible( $visible, $product_id ) {
		return $visible && self::can_access( (int) $product_id );
	}

	/**
	 * Purchasability.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function is_purchasable( $purchasable, $product ) {
		return $purchasable && self::can_access( $product );
	}

	/**
	 * Drop hidden ids from related / upsell / cross-sell lists.
	 *
	 * @param int[] $ids Ids.
	 * @return int[]
	 */
	public static function filter_ids( $ids ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $ids;
		}
		return array_values( array_filter( (array) $ids, array( __CLASS__, 'can_access' ) ) );
	}

	/**
	 * 404 for a hidden product's page.
	 */
	public static function block_single() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		if ( ! self::can_access( get_queried_object_id() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Contract price for the active company, or null.
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	public static function company_price( $product ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return null;
		}
		$company_id = b2bcp_active_company_id();
		if ( ! $company_id ) {
			return null;
		}
		$id       = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$assigned = b2bcp_company_assignments( $company_id );
		return isset( $assigned[ $id ] ) ? $assigned[ $id ] : null;
	}

	/**
	 * Price / regular price filter.
	 *
	 * @param mixed      $price   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function contract_price( $price, $product ) {
		$contract = self::company_price( $product );
		return null === $contract ? $price : wc_format_decimal( $contract );
	}

	/**
	 * A contract price is never shown as a sale.
	 *
	 * @param mixed      $price   Sale price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function contract_sale_price( $price, $product ) {
		return null === self::company_price( $product ) ? $price : '';
	}

	/**
	 * Variation price ranges on variable products.
	 *
	 * @param mixed      $price     Price.
	 * @param WC_Product $variation Variation.
	 * @param WC_Product $product   Parent.
	 * @return mixed
	 */
	public static function variation_range_price( $price, $variation, $product ) {
		$contract = self::company_price( $product );
		if ( null === $contract ) {
			return $price;
		}
		return 'woocommerce_variation_prices_sale_price' === current_filter() ? '' : wc_format_decimal( $contract );
	}

	/**
	 * Cache variation prices per company.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public static function prices_hash( $hash ) {
		$hash[] = 'b2bcp-' . b2bcp_active_company_id();
		return $hash;
	}
}
