<?php
/**
 * My Account home for company users: welcome, their products, recent orders, shortcuts.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces WooCommerce's account dashboard for shoppers who order for a company.
 * Everyone else keeps the standard dashboard.
 */
class B2BCP_Dashboard {

	const PRODUCTS = 8;
	const ORDERS   = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 10, 2 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 30 );
		add_action( 'template_redirect', array( __CLASS__, 'home_redirect' ) );
	}

	/**
	 * The site's front page sends company users to their account home (setting, on by default).
	 * Store staff are left alone so they can see and edit the public home page.
	 */
	public static function home_redirect() {
		if ( ! is_front_page() || 'no' === get_option( 'b2bcp_home_is_account', 'yes' ) || current_user_can( 'manage_woocommerce' ) || ! b2bcp_active_company_id() ) {
			return;
		}
		$account = wc_get_page_permalink( 'myaccount' );
		if ( ! $account || is_account_page() ) {
			return;
		}
		nocache_headers();
		wp_safe_redirect( $account );
		exit;
	}

	/**
	 * Swap the dashboard template.
	 *
	 * @param string $template      Path WooCommerce found.
	 * @param string $template_name Template name.
	 * @return string
	 */
	public static function template( $template, $template_name ) {
		if ( 'myaccount/dashboard.php' === $template_name && b2bcp_active_company_id() ) {
			return B2BCP_DIR . 'templates/company-dashboard.php';
		}
		return $template;
	}

	/**
	 * Account menu for company users: "Home" instead of "Dashboard", no "Downloads".
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function menu( $items ) {
		if ( ! b2bcp_active_company_id() ) {
			return $items;
		}
		unset( $items['downloads'] );
		if ( isset( $items['dashboard'] ) ) {
			$items['dashboard'] = __( 'Home', 'b2b-contract-portal' );
		}
		return $items;
	}

	/**
	 * The page.
	 */
	public static function render() {
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		if ( ! $company ) {
			return;
		}
		$user     = wp_get_current_user();
		$products = self::company_products( $company['id'] );
		$shop     = wc_get_page_permalink( 'shop' );
		wp_enqueue_style( 'b2bcp-front' );

		echo '<div class="b2bcp-dashboard">';
		self::hero( $company, $user, $shop );
		self::stats( $company, $user->ID, count( $products ), $shop );
		self::products( $products, $shop );
		self::orders( $user->ID );
		echo '</div>';
	}

	/**
	 * Published products assigned to the company that this buyer can order, by name.
	 *
	 * @param int $company_id Company.
	 * @return WC_Product[]
	 */
	private static function company_products( $company_id ) {
		$ids = array_keys( b2bcp_company_assignments( $company_id ) );
		if ( ! $ids ) {
			return array();
		}
		$products = wc_get_products(
			array(
				'include' => array_map( 'intval', $ids ),
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		return array_values( array_filter( (array) $products, array( 'B2BCP_Catalog', 'can_access' ) ) );
	}

	/**
	 * Colored band with the company logo and the welcome.
	 *
	 * @param array   $company Company.
	 * @param WP_User $user    User.
	 * @param string  $shop    Shop URL.
	 */
	private static function hero( array $company, $user, $shop ) {
		$logo = $company['logo_id'] ? wp_get_attachment_image_url( $company['logo_id'], 'medium' ) : '';
		echo '<header class="b2bcp-hero">';
		if ( $logo ) {
			echo '<div class="b2bcp-hero-logo"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $company['name'] ) . '"></div>';
		}
		echo '<div class="b2bcp-hero-text">';
		echo '<p class="b2bcp-hero-eyebrow">' . esc_html( $company['name'] ) . '</p>';
		echo '<h2 class="b2bcp-hero-title">' . esc_html( B2BCP_Welcome::greeting( $user ) ) . '</h2>';
		echo wp_kses_post( wpautop( esc_html( B2BCP_Welcome::message( $company, $user ) ) ) );
		echo '</div>';
		echo '<a class="b2bcp-hero-cta" href="' . esc_url( $shop ) . '">' . esc_html__( 'Browse products', 'b2b-contract-portal' ) . '</a>';
		echo '</header>';
	}

	/**
	 * Three numbers that double as shortcuts.
	 *
	 * @param array  $company  Company.
	 * @param int    $user_id  User.
	 * @param int    $products Number of products.
	 * @param string $shop     Shop URL.
	 */
	private static function stats( array $company, $user_id, $products, $shop ) {
		$addresses = count( b2bcp_get_addresses( $company['id'] ) );
		$orders    = (int) wc_get_customer_order_count( $user_id );
		$tiles     = array(
			/* translators: %d: number of products */
			array( $shop, $products, _n( 'Product for your company', 'Products for your company', $products, 'b2b-contract-portal' ) ),
			/* translators: %d: number of orders */
			array( wc_get_account_endpoint_url( 'orders' ), $orders, _n( 'Order placed', 'Orders placed', $orders, 'b2b-contract-portal' ) ),
			/* translators: %d: number of addresses */
			array( wc_get_account_endpoint_url( B2BCP_Account::ENDPOINT ), $addresses, _n( 'Shipping address', 'Shipping addresses', $addresses, 'b2b-contract-portal' ) ),
		);
		echo '<div class="b2bcp-stats">';
		foreach ( $tiles as $tile ) {
			echo '<a class="b2bcp-stat" href="' . esc_url( $tile[0] ) . '"><span class="b2bcp-stat-number">' . esc_html( number_format_i18n( $tile[1] ) ) . '</span><span class="b2bcp-stat-label">' . esc_html( $tile[2] ) . '</span></a>';
		}
		echo '</div>';
	}

	/**
	 * Product cards.
	 *
	 * @param WC_Product[] $products Products.
	 * @param string       $shop     Shop URL.
	 */
	private static function products( array $products, $shop ) {
		echo '<section class="b2bcp-dashboard-section">';
		echo '<div class="b2bcp-section-head"><h2>' . esc_html__( 'Your products', 'b2b-contract-portal' ) . '</h2>';
		if ( count( $products ) > self::PRODUCTS ) {
			echo '<a href="' . esc_url( $shop ) . '">' . esc_html__( 'See all products', 'b2b-contract-portal' ) . '</a>';
		}
		echo '</div>';

		if ( ! $products ) {
			echo '<p class="b2bcp-empty">' . esc_html__( 'Your company\'s products will appear here as soon as they are ready.', 'b2b-contract-portal' ) . '</p></section>';
			return;
		}
		echo '<div class="b2bcp-cards">';
		foreach ( array_slice( $products, 0, self::PRODUCTS ) as $product ) {
			$config = b2bcp_product_config( $product );
			if ( $config['quote'] ) {
				$action = __( 'Request a quote', 'b2b-contract-portal' );
			} elseif ( b2bcp_uses_order_form( $config ) ) {
				$action = __( 'Customize & order', 'b2b-contract-portal' );
			} else {
				$action = __( 'Order', 'b2b-contract-portal' );
			}
			echo '<a class="b2bcp-card" href="' . esc_url( $product->get_permalink() ) . '">';
			echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) );
			echo '<span class="b2bcp-card-body">';
			echo '<span class="b2bcp-card-name">' . esc_html( $product->get_name() ) . '</span>';
			if ( ! $config['quote'] ) {
				echo '<span class="b2bcp-card-price">' . wp_kses_post( $product->get_price_html() ) . '</span>';
			}
			echo '<span class="b2bcp-card-action">' . esc_html( $action ) . '</span>';
			echo '</span></a>';
		}
		echo '</div></section>';
	}

	/**
	 * The buyer's latest orders.
	 *
	 * @param int $user_id User.
	 */
	private static function orders( $user_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'limit'       => self::ORDERS,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);

		echo '<section class="b2bcp-dashboard-section">';
		echo '<div class="b2bcp-section-head"><h2>' . esc_html__( 'Recent orders', 'b2b-contract-portal' ) . '</h2>';
		if ( $orders ) {
			echo '<a href="' . esc_url( wc_get_account_endpoint_url( 'orders' ) ) . '">' . esc_html__( 'All orders', 'b2b-contract-portal' ) . '</a>';
		}
		echo '</div>';
		if ( ! $orders ) {
			echo '<p class="b2bcp-empty">' . esc_html__( 'You have not placed any orders yet. Your orders will show here with their status.', 'b2b-contract-portal' ) . '</p></section>';
			return;
		}
		echo '<ul class="b2bcp-order-list">';
		foreach ( $orders as $order ) {
			$date = $order->get_date_created();
			echo '<li><a href="' . esc_url( $order->get_view_order_url() ) . '">';
			echo '<span class="b2bcp-order-number">#' . esc_html( $order->get_order_number() ) . '</span>';
			echo '<span class="b2bcp-order-date">' . esc_html( $date ? wc_format_datetime( $date ) : '' ) . '</span>';
			echo '<span class="b2bcp-status b2bcp-status-' . esc_attr( $order->get_status() ) . '">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span>';
			echo '<span class="b2bcp-order-total">' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
			echo '</a></li>';
		}
		echo '</ul></section>';
	}
}
