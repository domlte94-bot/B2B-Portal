<?php
/**
 * Company look for its users: logo bar and accent color.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies the active company's logo and color on the front end.
 */
class B2BCP_Branding {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'css' ), 50 );
		add_action( 'wp_body_open', array( __CLASS__, 'bar' ) );
		add_shortcode( 'b2bcp_company_bar', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Active company, or null.
	 *
	 * @return array|null
	 */
	private static function company() {
		return is_user_logged_in() ? b2bcp_get_company( b2bcp_active_company_id() ) : null;
	}

	/**
	 * Body classes for company users.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		$company = self::company();
		if ( $company ) {
			$classes[] = 'b2bcp-company';
			if ( $company['color'] ) {
				$classes[] = 'b2bcp-branded';
			}
		}
		return $classes;
	}

	/**
	 * Accent color variables and the elements that use them.
	 */
	public static function css() {
		$company = self::company();
		if ( ! $company || ! $company['color'] ) {
			return;
		}
		$accent = $company['color'];
		$ink    = b2bcp_text_on( $accent );
		// For links and borders on a white page, darken colors that would be too light to read.
		$on_white = b2bcp_contrast( $accent, '#ffffff' ) >= 3 ? $accent : '#333333';
		?>
		<style id="b2bcp-company-colors">
			body.b2bcp-branded {
				--b2bcp-accent: <?php echo esc_html( $accent ); ?>;
				--b2bcp-accent-ink: <?php echo esc_html( $ink ); ?>;
				--b2bcp-accent-line: <?php echo esc_html( $on_white ); ?>;
			}
			body.b2bcp-branded .single_add_to_cart_button,
			body.b2bcp-branded .checkout-button,
			body.b2bcp-branded #place_order,
			body.b2bcp-branded .b2bcp-button-accent,
			body.b2bcp-branded .wc-block-components-checkout-place-order-button,
			body.b2bcp-branded .wc-block-cart__submit-button {
				background-color: var(--b2bcp-accent) !important;
				border-color: var(--b2bcp-accent) !important;
				color: var(--b2bcp-accent-ink) !important;
			}
		</style>
		<?php
	}

	/**
	 * Markup of the company bar.
	 *
	 * @return string
	 */
	public static function bar_html() {
		$company = self::company();
		if ( ! $company ) {
			return '';
		}
		$logo      = $company['logo_id'] ? wp_get_attachment_image( $company['logo_id'], 'medium', false, array( 'class' => 'b2bcp-bar-logo', 'alt' => $company['name'] ) ) : '';
		$companies = b2bcp_user_companies( get_current_user_id() );
		$link      = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'company' ) : '';

		ob_start();
		?>
		<div class="b2bcp-bar" role="region" aria-label="<?php esc_attr_e( 'Company portal', 'b2b-contract-portal' ); ?>">
			<div class="b2bcp-bar-inner">
				<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image output. ?>
				<span class="b2bcp-bar-text">
					<?php
					/* translators: %s: company name */
					echo esc_html( sprintf( __( 'Ordering for %s', 'b2b-contract-portal' ), $company['name'] ) );
					?>
				</span>
				<?php if ( $link ) : ?>
					<a class="b2bcp-bar-link" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( count( $companies ) > 1 ? __( 'Switch company', 'b2b-contract-portal' ) : __( 'Company account', 'b2b-contract-portal' ) ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Print the bar at the top of the page.
	 */
	public static function bar() {
		if ( apply_filters( 'b2bcp_show_company_bar', true ) ) {
			wp_enqueue_style( 'b2bcp-front' );
			echo self::bar_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
	}

	/**
	 * [b2bcp_company_bar] for themes without wp_body_open.
	 *
	 * @return string
	 */
	public static function shortcode() {
		wp_enqueue_style( 'b2bcp-front' );
		return self::bar_html();
	}
}
