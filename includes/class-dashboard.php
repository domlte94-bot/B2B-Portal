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

	const PRODUCTS = 6;
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
		$user = wp_get_current_user();
		wp_enqueue_style( 'b2bcp-front' );

		echo '<div class="b2bcp-dashboard">';
		B2BCP_Welcome::block( $company, $user, '' );
		self::products( $company );
		self::orders( $user->ID );
		self::shortcuts( $company );
		echo '</div>';
	}

	/**
	 * The company's products, as the theme's own product grid.
	 *
	 * @param array $company Company.
	 */
	private static function products( array $company ) {
		$ids  = array_keys( b2bcp_company_assignments( $company['id'] ) );
		$shop = wc_get_page_permalink( 'shop' );
		$grid = '';
		if ( $ids ) {
			// The shortcode applies the catalog rules and contract prices like any other product list.
			$grid = trim( do_shortcode( sprintf( '[products ids="%s" limit="%d" columns="3" orderby="title" order="ASC"]', implode( ',', array_map( 'intval', $ids ) ), self::PRODUCTS ) ) );
		}

		echo '<section class="b2bcp-dashboard-section">';
		echo '<h2>' . esc_html__( 'Your products', 'b2b-contract-portal' ) . '</h2>';
		if ( '' !== $grid ) {
			echo $grid; // phpcs:ignore WordPress.Security.EscapeOutput -- WooCommerce shortcode output.
			if ( count( $ids ) > self::PRODUCTS ) {
				echo '<p><a class="button" href="' . esc_url( $shop ) . '">' . esc_html__( 'See all products', 'b2b-contract-portal' ) . '</a></p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Your company\'s products will appear here as soon as they are ready.', 'b2b-contract-portal' ) . ' <a href="' . esc_url( $shop ) . '">' . esc_html__( 'Go to the shop', 'b2b-contract-portal' ) . '</a></p>';
		}
		echo '</section>';
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
		echo '<h2>' . esc_html__( 'Recent orders', 'b2b-contract-portal' ) . '</h2>';
		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'You have not placed any orders yet.', 'b2b-contract-portal' ) . '</p></section>';
			return;
		}
		echo '<table class="shop_table shop_table_responsive b2bcp-dashboard-orders"><thead><tr>';
		foreach ( array( __( 'Order', 'b2b-contract-portal' ), __( 'Date', 'b2b-contract-portal' ), __( 'Status', 'b2b-contract-portal' ), __( 'Total', 'b2b-contract-portal' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$date = $order->get_date_created();
			echo '<tr>';
			echo '<td data-title="' . esc_attr__( 'Order', 'b2b-contract-portal' ) . '"><a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td data-title="' . esc_attr__( 'Date', 'b2b-contract-portal' ) . '">' . esc_html( $date ? wc_format_datetime( $date ) : '' ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Status', 'b2b-contract-portal' ) . '">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Total', 'b2b-contract-portal' ) . '">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( $order->get_view_order_url() ) . '">' . esc_html__( 'View', 'b2b-contract-portal' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( wc_get_account_endpoint_url( 'orders' ) ) . '">' . esc_html__( 'All orders', 'b2b-contract-portal' ) . '</a></p>';
		echo '</section>';
	}

	/**
	 * Shortcuts to the company's addresses and the buyer's own details.
	 *
	 * @param array $company Company.
	 */
	private static function shortcuts( array $company ) {
		$addresses = count( b2bcp_get_addresses( $company['id'] ) );
		$links     = array(
			array(
				wc_get_account_endpoint_url( B2BCP_Account::ENDPOINT ),
				__( 'Shipping addresses', 'b2b-contract-portal' ),
				/* translators: %d: number of addresses */
				sprintf( _n( '%d address saved for your company', '%d addresses saved for your company', $addresses, 'b2b-contract-portal' ), $addresses ),
			),
			array(
				wc_get_account_endpoint_url( 'edit-account' ),
				__( 'Account details', 'b2b-contract-portal' ),
				__( 'Your name, email and password', 'b2b-contract-portal' ),
			),
		);

		echo '<section class="b2bcp-dashboard-section">';
		echo '<h2>' . esc_html__( 'Quick links', 'b2b-contract-portal' ) . '</h2>';
		echo '<div class="b2bcp-dashboard-links">';
		foreach ( $links as $link ) {
			echo '<a class="b2bcp-dashboard-link" href="' . esc_url( $link[0] ) . '"><strong>' . esc_html( $link[1] ) . '</strong><span>' . esc_html( $link[2] ) . '</span></a>';
		}
		echo '</div></section>';
	}
}
