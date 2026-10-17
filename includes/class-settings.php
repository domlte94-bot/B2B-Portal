<?php
/**
 * B2B Portal → Settings.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings.
 */
class B2BCP_Settings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'b2bcp_show_company_bar', array( __CLASS__, 'show_bar' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( B2BCP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Submenu page.
	 */
	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . B2BCP_COMPANY_CPT, __( 'B2B Portal settings', 'b2b-contract-portal' ), __( 'Settings', 'b2b-contract-portal' ), 'manage_woocommerce', 'b2bcp-settings', array( __CLASS__, 'page' ) );
	}

	/**
	 * Settings fields.
	 */
	public static function register() {
		register_setting(
			'b2bcp_settings',
			'b2bcp_quote_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return implode( ', ', array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $value ) ) ) ) );
				},
			)
		);
		register_setting(
			'b2bcp_settings',
			'b2bcp_classic_checkout',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return 'no' === $value ? 'no' : 'yes';
				},
			)
		);
		register_setting(
			'b2bcp_settings',
			'b2bcp_home_is_account',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return 'no' === $value ? 'no' : 'yes';
				},
			)
		);
		register_setting(
			'b2bcp_settings',
			'b2bcp_welcome_message',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		register_setting(
			'b2bcp_settings',
			'b2bcp_company_bar',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return 'no' === $value ? 'no' : 'yes';
				},
			)
		);
	}

	/**
	 * Where quote requests go.
	 *
	 * @return string
	 */
	public static function quote_recipients() {
		$value = (string) get_option( 'b2bcp_quote_email', '' );
		return '' !== $value ? $value : (string) get_option( 'admin_email' );
	}

	/**
	 * Company bar on/off.
	 *
	 * @param bool $show Default.
	 * @return bool
	 */
	public static function show_bar( $show ) {
		return 'no' === get_option( 'b2bcp_company_bar', 'yes' ) ? false : $show;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . B2BCP_COMPANY_CPT . '&page=b2bcp-settings' ) ) . '">' . esc_html__( 'Settings', 'b2b-contract-portal' ) . '</a>' );
		return $links;
	}

	/**
	 * Page.
	 */
	public static function page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'B2B Portal settings', 'b2b-contract-portal' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'b2bcp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="b2bcp_quote_email"><?php esc_html_e( 'Quote requests go to', 'b2b-contract-portal' ); ?></label></th>
						<td><input type="text" class="regular-text" id="b2bcp_quote_email" name="b2bcp_quote_email" value="<?php echo esc_attr( (string) get_option( 'b2bcp_quote_email', '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Comma separated. Empty = the site admin email.', 'b2b-contract-portal' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Company bar', 'b2b-contract-portal' ); ?></th>
						<td><input type="hidden" name="b2bcp_company_bar" value="no">
						<label><input type="checkbox" name="b2bcp_company_bar" value="yes" <?php checked( 'no' !== get_option( 'b2bcp_company_bar', 'yes' ) ); ?>> <?php esc_html_e( 'Show the company logo bar at the top of every page for company users', 'b2b-contract-portal' ); ?></label>
						<p class="description"><?php esc_html_e( 'If your theme does not show it, place the [b2bcp_company_bar] shortcode where you want it.', 'b2b-contract-portal' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Home for company users', 'b2b-contract-portal' ); ?></th>
						<td><input type="hidden" name="b2bcp_home_is_account" value="no">
						<label><input type="checkbox" name="b2bcp_home_is_account" value="yes" <?php checked( 'no' !== get_option( 'b2bcp_home_is_account', 'yes' ) ); ?>> <?php esc_html_e( 'Send company users to My account when they open the site\'s home page', 'b2b-contract-portal' ); ?></label>
						<p class="description"><?php esc_html_e( 'Visitors and store staff still see the normal home page.', 'b2b-contract-portal' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="b2bcp_welcome_message"><?php esc_html_e( 'Welcome message', 'b2b-contract-portal' ); ?></label></th>
						<td><textarea class="large-text" rows="4" id="b2bcp_welcome_message" name="b2bcp_welcome_message" placeholder="<?php echo esc_attr( B2BCP_Welcome::default_message() ); ?>"><?php echo esc_textarea( (string) get_option( 'b2bcp_welcome_message', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown under "Welcome, name" when an invited user chooses their password and on their account home. You can use {name}, {company} and {store}. Empty = the text shown in grey.', 'b2b-contract-portal' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Checkout', 'b2b-contract-portal' ); ?></th>
						<td><input type="hidden" name="b2bcp_classic_checkout" value="no">
						<label><input type="checkbox" name="b2bcp_classic_checkout" value="yes" <?php checked( 'no' !== get_option( 'b2bcp_classic_checkout', 'yes' ) ); ?>> <?php esc_html_e( 'Use the classic checkout when items already ship to saved addresses', 'b2b-contract-portal' ); ?></label>
						<p class="description"><?php esc_html_e( 'The block Checkout always asks for a shipping address, even when every item already has one. With this on, those carts get the classic checkout, which skips it and shows one shipping line per address. Carts without saved addresses keep the block Checkout.', 'b2b-contract-portal' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Invoice payment', 'b2b-contract-portal' ); ?></th>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=b2bcp_invoice' ) ); ?>"><?php esc_html_e( 'Edit the invoice payment method', 'b2b-contract-portal' ); ?></a>
						<p class="description"><?php esc_html_e( 'Which companies may pay by invoice is set on each company.', 'b2b-contract-portal' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
