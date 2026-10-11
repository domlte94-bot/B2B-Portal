<?php
/**
 * Welcome flow for invited users: the invitation link opens a welcome page to
 * choose a password (not a bare "Lost password" screen) and then logs them in.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dresses WooCommerce's set-password screen for invitations. WooCommerce still
 * checks the key and saves the password; this class only adds the welcome.
 */
class B2BCP_Welcome {

	const COOKIE  = 'b2bcp_invite';
	const PENDING = '_b2bcp_invite_pending';

	/**
	 * Hooks.
	 */
	public static function init() {
		// Before WooCommerce turns the emailed link into its own cookie + redirect (priority 10).
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 5 );
		add_filter( 'password_reset_expiration', array( __CLASS__, 'expiration' ) );
		add_filter( 'woocommerce_endpoint_lost-password_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_before_reset_password_form', array( __CLASS__, 'intro' ) );
		add_action( 'woocommerce_before_lost_password_form', array( __CLASS__, 'expired' ) );
		add_action( 'woocommerce_customer_reset_password', array( __CLASS__, 'finished' ) );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'first_visit' ), 5 );
	}

	/**
	 * Mark a new user as invited to a company until they choose a password.
	 *
	 * @param int $user_id    User.
	 * @param int $company_id Company.
	 */
	public static function mark_pending( $user_id, $company_id ) {
		update_user_meta( $user_id, self::PENDING, (int) $company_id );
	}

	/**
	 * Query args that make an emailed set-password link an invitation link.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	public static function link_args( $company_id ) {
		return array( 'b2bcp_invite' => (int) $company_id );
	}

	/**
	 * The invitation being answered in this request, if any.
	 * Only counts while the user still has to choose their first password.
	 *
	 * @return array{company:array,user:WP_User}|null
	 */
	private static function context() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only; the reset key is what WooCommerce verifies.
		if ( isset( $_GET['b2bcp_invite'], $_GET['id'] ) ) {
			$company_id = absint( $_GET['b2bcp_invite'] );
			$user_id    = absint( $_GET['id'] );
		} elseif ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$parts      = array_map( 'absint', explode( ':', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) );
			$company_id = $parts[0] ?? 0;
			$user_id    = $parts[1] ?? 0;
		} else {
			return null;
		}
		// phpcs:enable
		if ( ! $company_id || ! $user_id || (int) get_user_meta( $user_id, self::PENDING, true ) !== $company_id ) {
			return null;
		}
		$company = b2bcp_get_company( $company_id );
		$user    = get_userdata( $user_id );
		return $company && $user ? compact( 'company', 'user' ) : null;
	}

	/**
	 * Set or clear the cookie that carries the invitation across WooCommerce's redirects.
	 *
	 * @param string $value Value, '' to clear.
	 */
	private static function cookie( $value ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => '' === $value ? time() - HOUR_IN_SECONDS : time() + HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Remember that the visitor arrived from an invitation email.
	 */
	public static function capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['b2bcp_invite'], $_GET['key'], $_GET['id'] ) || ! is_account_page() ) {
			return;
		}
		$context = self::context();
		if ( $context ) {
			self::cookie( $context['company']['id'] . ':' . $context['user']->ID );
		}
	}

	/**
	 * Invitations stay valid for a week instead of WordPress's one day:
	 * a new buyer often opens the email days later.
	 *
	 * @param int $seconds Default lifetime.
	 * @return int
	 */
	public static function expiration( $seconds ) {
		return self::context() ? max( (int) $seconds, WEEK_IN_SECONDS ) : $seconds;
	}

	/**
	 * Page title instead of "Lost password".
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function title( $title ) {
		return self::context() ? __( 'Welcome', 'b2b-contract-portal' ) : $title;
	}

	/**
	 * The store's welcome text with the names filled in.
	 *
	 * @param array   $company Company.
	 * @param WP_User $user    User.
	 * @return string Plain text, may have line breaks.
	 */
	public static function message( array $company, $user ) {
		$text = trim( (string) get_option( 'b2bcp_welcome_message', '' ) );
		if ( '' === $text ) {
			$text = self::default_message();
		}
		return strtr(
			$text,
			array(
				'{name}'    => $user->first_name ? $user->first_name : $user->display_name,
				'{company}' => $company['name'],
				'{store}'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
	}

	/**
	 * Welcome text used when the store has not written its own.
	 *
	 * @return string
	 */
	public static function default_message() {
		return __( 'Welcome, {name}. {store} set up an ordering account for {company}. Here you will find your company\'s products at your contract prices.', 'b2b-contract-portal' );
	}

	/**
	 * Company logo and welcome text.
	 *
	 * @param array   $company Company.
	 * @param WP_User $user    User.
	 * @param string  $after   Extra line (plain text).
	 */
	public static function block( array $company, $user, $after ) {
		$logo = $company['logo_id'] ? wp_get_attachment_image_url( $company['logo_id'], 'medium' ) : '';
		wp_enqueue_style( 'b2bcp-front' );
		echo '<div class="b2bcp-welcome">';
		if ( $logo ) {
			echo '<img class="b2bcp-welcome-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $company['name'] ) . '">';
		}
		echo wp_kses_post( wpautop( esc_html( self::message( $company, $user ) ) ) );
		if ( '' !== $after ) {
			echo '<p><strong>' . esc_html( $after ) . '</strong></p>';
		}
		echo '</div>';
	}

	/**
	 * Above the "new password" form.
	 */
	public static function intro() {
		$context = self::context();
		if ( $context ) {
			/* translators: %s: username */
			self::block( $context['company'], $context['user'], sprintf( __( 'Your username is %s. Choose a password to start ordering.', 'b2b-contract-portal' ), $context['user']->user_login ) );
		}
	}

	/**
	 * The link no longer works (expired, or the password was already set): say so
	 * above WooCommerce's "send me a link" form instead of leaving a bare error.
	 */
	public static function expired() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['show-reset-form'] ) || ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		wc_print_notice( __( 'This invitation link has expired or was already used. If you already chose a password, log in from My account. Otherwise enter your email below and we will send you a new link.', 'b2b-contract-portal' ), 'notice' );
	}

	/**
	 * The password was saved: log the new buyer in and take them to their account.
	 *
	 * @param WP_User $user User.
	 */
	public static function finished( $user ) {
		$context    = self::context();
		$company_id = (int) get_user_meta( $user->ID, self::PENDING, true );
		delete_user_meta( $user->ID, self::PENDING );
		self::cookie( '' );

		// Only when this browser came from that user's invitation and nobody else is logged in here.
		if ( ! $company_id || ! $context || $context['user']->ID !== $user->ID || ( is_user_logged_in() && get_current_user_id() !== $user->ID ) ) {
			return;
		}
		update_user_meta( $user->ID, '_b2bcp_active_company', $company_id );
		wc_set_customer_auth_cookie( $user->ID );
		wp_safe_redirect( add_query_arg( 'b2bcp_welcome', '1', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/**
	 * First screen after choosing the password: the company home (B2BCP_Dashboard) carries the welcome.
	 */
	public static function first_visit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['b2bcp_welcome'] ) && b2bcp_active_company_id() ) {
			wc_print_notice( __( 'Your password is saved and you are logged in.', 'b2b-contract-portal' ), 'success' );
		}
	}
}
