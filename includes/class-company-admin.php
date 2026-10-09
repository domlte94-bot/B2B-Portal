<?php
/**
 * Client companies: post type, admin screens and saving.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Company post type with details, branding, branches, users and contract prices.
 */
class B2BCP_Company_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . B2BCP_COMPANY_CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . B2BCP_COMPANY_CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . B2BCP_COMPANY_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . B2BCP_COMPANY_CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Capabilities: only store managers handle companies.
	 *
	 * @return array
	 */
	public static function caps() {
		$cap = 'manage_woocommerce';
		return array(
			'edit_post'              => $cap,
			'read_post'              => $cap,
			'delete_post'            => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'edit_published_posts'   => $cap,
			'edit_private_posts'     => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_published_posts' => $cap,
			'delete_private_posts'   => $cap,
			'create_posts'           => $cap,
		);
	}

	/**
	 * Register the post type and the top-level menu.
	 */
	public static function register() {
		register_post_type(
			B2BCP_COMPANY_CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Client companies', 'b2b-contract-portal' ),
					'singular_name' => __( 'Client company', 'b2b-contract-portal' ),
					'menu_name'     => __( 'B2B Portal', 'b2b-contract-portal' ),
					'all_items'     => __( 'Companies', 'b2b-contract-portal' ),
					'add_new'       => __( 'Add company', 'b2b-contract-portal' ),
					'add_new_item'  => __( 'Add client company', 'b2b-contract-portal' ),
					'edit_item'     => __( 'Edit client company', 'b2b-contract-portal' ),
					'search_items'  => __( 'Search companies', 'b2b-contract-portal' ),
					'not_found'     => __( 'No client companies yet.', 'b2b-contract-portal' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-building',
				'menu_position' => 56,
				'supports'     => array( 'title' ),
				'capabilities' => self::caps(),
				'map_meta_cap' => false,
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Title field placeholder.
	 *
	 * @param string  $text Default.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		return B2BCP_COMPANY_CPT === $post->post_type ? __( 'Company name (e.g. Mechanics Bank)', 'b2b-contract-portal' ) : $text;
	}

	/**
	 * Admin scripts and styles on company and product screens.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$ours = in_array( $screen->post_type, array( B2BCP_COMPANY_CPT, B2BCP_QUOTE_CPT, 'product' ), true ) && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		if ( ! $ours ) {
			return;
		}
		if ( B2BCP_COMPANY_CPT === $screen->post_type ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'b2bcp-admin', B2BCP_URL . 'assets/admin.css', array(), B2BCP_VERSION );
		wp_enqueue_script( 'b2bcp-admin', B2BCP_URL . 'assets/admin.js', array( 'jquery', 'wc-enhanced-select' ), B2BCP_VERSION, true );
		wp_localize_script(
			'b2bcp-admin',
			'b2bcpAdmin',
			array(
				'chooseLogo' => __( 'Choose company logo', 'b2b-contract-portal' ),
				'useLogo'    => __( 'Use this logo', 'b2b-contract-portal' ),
			)
		);
	}

	/**
	 * Meta boxes on the company screen.
	 */
	public static function meta_boxes() {
		add_meta_box( 'b2bcp-company-details', __( 'Contract details', 'b2b-contract-portal' ), array( __CLASS__, 'box_details' ), B2BCP_COMPANY_CPT, 'normal', 'high' );
		add_meta_box( 'b2bcp-company-products', __( 'Products and contract prices', 'b2b-contract-portal' ), array( __CLASS__, 'box_products' ), B2BCP_COMPANY_CPT, 'normal', 'high' );
		add_meta_box( 'b2bcp-company-branches', __( 'Branches / shipping addresses', 'b2b-contract-portal' ), array( __CLASS__, 'box_branches' ), B2BCP_COMPANY_CPT, 'normal', 'default' );
		add_meta_box( 'b2bcp-company-users', __( 'Users', 'b2b-contract-portal' ), array( __CLASS__, 'box_users' ), B2BCP_COMPANY_CPT, 'normal', 'default' );
		add_meta_box( 'b2bcp-company-brand', __( 'Company branding', 'b2b-contract-portal' ), array( __CLASS__, 'box_brand' ), B2BCP_COMPANY_CPT, 'side', 'default' );
	}

	/**
	 * Contract details: contact, billing, payment, shipping, notifications.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_details( $post ) {
		wp_nonce_field( 'b2bcp_save_company', 'b2bcp_company_nonce' );
		$company = b2bcp_get_company( $post->ID );
		$billing = $company ? $company['billing'] : array();
		$payment = $company ? $company['payment'] : 'card';
		?>
		<div class="b2bcp-grid">
			<div>
				<h4><?php esc_html_e( 'Main contact', 'b2b-contract-portal' ); ?></h4>
				<p><label><?php esc_html_e( 'Name', 'b2b-contract-portal' ); ?><br><input type="text" class="widefat" name="b2bcp[contact_name]" value="<?php echo esc_attr( get_post_meta( $post->ID, '_b2bcp_contact_name', true ) ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Email', 'b2b-contract-portal' ); ?><br><input type="email" class="widefat" name="b2bcp[contact_email]" value="<?php echo esc_attr( get_post_meta( $post->ID, '_b2bcp_contact_email', true ) ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Phone', 'b2b-contract-portal' ); ?><br><input type="text" class="widefat" name="b2bcp[contact_phone]" value="<?php echo esc_attr( get_post_meta( $post->ID, '_b2bcp_contact_phone', true ) ); ?>"></label></p>

				<h4><?php esc_html_e( 'Payment', 'b2b-contract-portal' ); ?></h4>
				<p>
					<label><input type="radio" name="b2bcp[payment]" value="card" <?php checked( $payment, 'card' ); ?>> <?php esc_html_e( 'Card / online payment only', 'b2b-contract-portal' ); ?></label><br>
					<label><input type="radio" name="b2bcp[payment]" value="invoice" <?php checked( $payment, 'invoice' ); ?>> <?php esc_html_e( 'Invoice with terms only', 'b2b-contract-portal' ); ?></label><br>
					<label><input type="radio" name="b2bcp[payment]" value="both" <?php checked( $payment, 'both' ); ?>> <?php esc_html_e( 'Both', 'b2b-contract-portal' ); ?></label>
				</p>
				<p><label><?php esc_html_e( 'Invoice terms', 'b2b-contract-portal' ); ?><br><input type="text" class="widefat" name="b2bcp[terms]" placeholder="Net 30" value="<?php echo esc_attr( get_post_meta( $post->ID, '_b2bcp_terms', true ) ); ?>"></label></p>

				<h4><?php esc_html_e( 'Shipping and notifications', 'b2b-contract-portal' ); ?></h4>
				<p><label><input type="checkbox" name="b2bcp[shipping_included]" value="yes" <?php checked( $company && $company['shipping_included'] ); ?>> <?php esc_html_e( 'Shipping included in contract (shipping shows as $0)', 'b2b-contract-portal' ); ?></label></p>
				<p><label><input type="checkbox" name="b2bcp[buyers_add_addresses]" value="yes" <?php checked( ! $company || $company['buyers_add_addresses'] ); ?>> <?php esc_html_e( 'Buyers can add new shipping addresses (from their account and while ordering)', 'b2b-contract-portal' ); ?></label><br>
				<span class="description"><?php esc_html_e( 'Turn off to allow only addresses added by you or by the company admin.', 'b2b-contract-portal' ); ?></span></p>
				<p><label><?php esc_html_e( 'Extra email for new orders from this company', 'b2b-contract-portal' ); ?><br><input type="text" class="widefat" name="b2bcp[notify_email]" placeholder="cs@yourshop.com" value="<?php echo esc_attr( get_post_meta( $post->ID, '_b2bcp_notify_email', true ) ); ?>"></label>
				<span class="description"><?php esc_html_e( 'Comma separated. Gets the "New order" email in addition to the store\'s usual recipients.', 'b2b-contract-portal' ); ?></span></p>
			</div>
			<div>
				<h4><?php esc_html_e( 'Fixed billing address', 'b2b-contract-portal' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Fill this to lock checkout billing to these values for every order from this company. Leave empty to let buyers type their own.', 'b2b-contract-portal' ); ?></p>
				<?php foreach ( b2bcp_billing_keys() as $key => $label ) : ?>
					<p><label><?php echo esc_html( $label ); ?><br><input type="text" class="widefat" name="b2bcp[billing][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $billing[ $key ] ) ? $billing[ $key ] : '' ); ?>"></label></p>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Products assigned to the company and their contract price.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_products( $post ) {
		$rows = b2bcp_company_assignments( $post->ID );
		?>
		<p class="description"><?php esc_html_e( 'Products this company can order. Restricted products are only visible to companies listed here. Leave the price empty to use the product\'s normal price.', 'b2b-contract-portal' ); ?></p>
		<table class="widefat b2bcp-repeater" data-name="b2bcp_products">
			<thead><tr><th><?php esc_html_e( 'Product', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Visibility', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Regular price', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Contract price', 'b2b-contract-portal' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php
				$i = 0;
				foreach ( $rows as $product_id => $price ) {
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}
					self::product_row( (string) $i++, $product, $price );
				}
				?>
			</tbody>
			<tfoot><tr><td colspan="5"><button type="button" class="button b2bcp-add-row"><?php esc_html_e( '+ Add product', 'b2b-contract-portal' ); ?></button></td></tr></tfoot>
		</table>
		<script type="text/html" class="b2bcp-row-template"><?php self::product_row( '__i__', null, null ); ?></script>
		<?php
	}

	/**
	 * One product/price row.
	 *
	 * @param string          $i       Index.
	 * @param WC_Product|null $product Product.
	 * @param float|null      $price   Contract price.
	 */
	private static function product_row( $i, $product, $price ) {
		$base = 'b2bcp_products[' . $i . ']';
		?>
		<tr>
			<td style="width:40%">
				<select class="wc-product-search" style="width:100%" name="<?php echo esc_attr( $base ); ?>[product_id]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'b2b-contract-portal' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( (string) $product->get_id() ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				</select>
			</td>
			<td><?php echo $product ? esc_html( 'restricted' === b2bcp_product_config( $product )['visibility'] ? __( 'Exclusive', 'b2b-contract-portal' ) : __( 'Public', 'b2b-contract-portal' ) ) : '&ndash;'; ?></td>
			<td><?php echo $product ? wp_kses_post( wc_price( (float) $product->get_regular_price( 'edit' ) ) ) : '&ndash;'; ?></td>
			<td><input type="text" class="short wc_input_price" name="<?php echo esc_attr( $base ); ?>[price]" placeholder="<?php esc_attr_e( 'Regular', 'b2b-contract-portal' ); ?>" value="<?php echo null === $price ? '' : esc_attr( wc_format_localized_price( (string) $price ) ); ?>"></td>
			<td><button type="button" class="button-link b2bcp-remove-row" aria-label="<?php esc_attr_e( 'Remove', 'b2b-contract-portal' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Branches table.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_branches( $post ) {
		$keys = b2bcp_address_keys();
		?>
		<p class="description"><?php esc_html_e( 'Locations this company ships to. Buyers pick from this list when splitting an order across addresses, and can add new ones themselves unless you turn that off above.', 'b2b-contract-portal' ); ?></p>
		<div class="b2bcp-scroll">
		<table class="widefat b2bcp-repeater b2bcp-branches" data-name="b2bcp_branches">
			<thead><tr>
				<?php foreach ( $keys as $label ) : ?>
					<th><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
				<th></th>
			</tr></thead>
			<tbody>
				<?php
				foreach ( b2bcp_get_addresses( $post->ID ) as $i => $address ) {
					self::branch_row( (string) $i, $address );
				}
				?>
			</tbody>
			<tfoot><tr><td colspan="<?php echo count( $keys ) + 1; ?>"><button type="button" class="button b2bcp-add-row"><?php esc_html_e( '+ Add branch', 'b2b-contract-portal' ); ?></button></td></tr></tfoot>
		</table>
		</div>
		<script type="text/html" class="b2bcp-row-template"><?php self::branch_row( '__i__', array() ); ?></script>
		<?php
	}

	/**
	 * One branch row.
	 *
	 * @param string $i       Index.
	 * @param array  $address Address row.
	 */
	private static function branch_row( $i, array $address ) {
		$base = 'b2bcp_branches[' . $i . ']';
		echo '<tr>';
		foreach ( array_keys( b2bcp_address_keys() ) as $key ) {
			$value = isset( $address[ $key ] ) ? $address[ $key ] : '';
			$class = in_array( $key, array( 'code', 'state', 'postcode', 'country' ), true ) ? 'small-text' : 'regular-text';
			printf( '<td><input type="text" class="%1$s" name="%2$s[%3$s]" value="%4$s"></td>', esc_attr( $class ), esc_attr( $base ), esc_attr( $key ), esc_attr( $value ) );
		}
		printf( '<td><input type="hidden" name="%1$s[id]" value="%2$s"><button type="button" class="button-link b2bcp-remove-row" aria-label="%3$s">&times;</button></td>', esc_attr( $base ), esc_attr( isset( $address['id'] ) ? (string) $address['id'] : '' ), esc_attr__( 'Remove', 'b2b-contract-portal' ) );
		echo '</tr>';
	}

	/**
	 * Users linked to the company + invitation form.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_users( $post ) {
		$users = 'auto-draft' === $post->post_status ? array() : b2bcp_company_users( $post->ID );
		?>
		<?php if ( $users ) : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'User', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Email', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Company admin', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Remove from company', 'b2b-contract-portal' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $users as $user ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><input type="checkbox" name="b2bcp_users[<?php echo (int) $user->ID; ?>][admin]" value="1" <?php checked( b2bcp_user_is_company_admin( $user->ID, $post->ID ) ); ?>><input type="hidden" name="b2bcp_users[<?php echo (int) $user->ID; ?>][keep]" value="1"></td>
						<td><input type="checkbox" name="b2bcp_users[<?php echo (int) $user->ID; ?>][remove]" value="1"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No users yet.', 'b2b-contract-portal' ); ?></p>
		<?php endif; ?>

		<h4><?php esc_html_e( 'Invite a user', 'b2b-contract-portal' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Only invited users can order for this company. New people get an email to set their password; existing customers are linked and notified. One person can belong to several companies.', 'b2b-contract-portal' ); ?></p>
		<div class="b2bcp-invite">
			<input type="email" name="b2bcp_invite[email]" placeholder="<?php esc_attr_e( 'Email', 'b2b-contract-portal' ); ?>">
			<input type="text" name="b2bcp_invite[first_name]" placeholder="<?php esc_attr_e( 'First name', 'b2b-contract-portal' ); ?>">
			<input type="text" name="b2bcp_invite[last_name]" placeholder="<?php esc_attr_e( 'Last name', 'b2b-contract-portal' ); ?>">
			<label><input type="checkbox" name="b2bcp_invite[admin]" value="1"> <?php esc_html_e( 'Company admin', 'b2b-contract-portal' ); ?></label>
		</div>
		<p class="description"><?php esc_html_e( 'The invitation is sent when you click Update / Publish.', 'b2b-contract-portal' ); ?></p>
		<?php
	}

	/**
	 * Logo and accent color.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_brand( $post ) {
		$logo_id = (int) get_post_meta( $post->ID, '_b2bcp_logo_id', true );
		$color   = b2bcp_sanitize_hex( get_post_meta( $post->ID, '_b2bcp_color', true ) );
		$img     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<div class="b2bcp-logo-field">
			<div class="b2bcp-logo-preview"><?php echo $img ? '<img src="' . esc_url( $img ) . '" alt="">' : ''; ?></div>
			<input type="hidden" name="b2bcp[logo_id]" value="<?php echo $logo_id ? (int) $logo_id : ''; ?>">
			<button type="button" class="button b2bcp-logo-pick"><?php esc_html_e( 'Choose logo', 'b2b-contract-portal' ); ?></button>
			<button type="button" class="button-link b2bcp-logo-clear"><?php esc_html_e( 'Remove', 'b2b-contract-portal' ); ?></button>
		</div>
		<p><label><?php esc_html_e( 'Accent color', 'b2b-contract-portal' ); ?><br><input type="text" class="b2bcp-color" name="b2bcp[color]" value="<?php echo esc_attr( $color ); ?>"></label></p>
		<p class="description"><?php esc_html_e( 'Shown to this company\'s users on their catalog header, buttons and accents, and in their order emails. The rest of the store keeps its design. Text on the color switches to black or white automatically so it stays readable.', 'b2b-contract-portal' ); ?></p>
		<?php
	}

	/**
	 * Save everything posted from the company screen.
	 *
	 * @param int     $post_id Company id.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['b2bcp_company_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2bcp_company_nonce'] ) ), 'b2bcp_save_company' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'manage_woocommerce' ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key below.
		$data = isset( $_POST['b2bcp'] ) && is_array( $_POST['b2bcp'] ) ? wp_unslash( $_POST['b2bcp'] ) : array();

		update_post_meta( $post_id, '_b2bcp_contact_name', sanitize_text_field( $data['contact_name'] ?? '' ) );
		update_post_meta( $post_id, '_b2bcp_contact_email', sanitize_email( $data['contact_email'] ?? '' ) );
		update_post_meta( $post_id, '_b2bcp_contact_phone', sanitize_text_field( $data['contact_phone'] ?? '' ) );
		$payment = in_array( $data['payment'] ?? '', array( 'card', 'invoice', 'both' ), true ) ? $data['payment'] : 'card';
		update_post_meta( $post_id, '_b2bcp_payment', $payment );
		update_post_meta( $post_id, '_b2bcp_terms', sanitize_text_field( $data['terms'] ?? '' ) );
		update_post_meta( $post_id, '_b2bcp_shipping_included', isset( $data['shipping_included'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_b2bcp_buyers_add_addresses', isset( $data['buyers_add_addresses'] ) ? 'yes' : 'no' );

		$emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) ( $data['notify_email'] ?? '' ) ) ) ) );
		update_post_meta( $post_id, '_b2bcp_notify_email', implode( ', ', $emails ) );

		$billing = array();
		foreach ( array_keys( b2bcp_billing_keys() ) as $key ) {
			$value           = (string) ( $data['billing'][ $key ] ?? '' );
			$billing[ $key ] = 'email' === $key ? sanitize_email( $value ) : sanitize_text_field( $value );
		}
		$billing['country'] = strtoupper( substr( $billing['country'], 0, 2 ) );
		update_post_meta( $post_id, '_b2bcp_billing', $billing );

		update_post_meta( $post_id, '_b2bcp_logo_id', absint( $data['logo_id'] ?? 0 ) );
		update_post_meta( $post_id, '_b2bcp_color', b2bcp_sanitize_hex( $data['color'] ?? '' ) );

		// Products and prices.
		$products = isset( $_POST['b2bcp_products'] ) && is_array( $_POST['b2bcp_products'] ) ? wp_unslash( $_POST['b2bcp_products'] ) : array();
		$rows     = array();
		foreach ( $products as $row ) {
			$product_id = absint( $row['product_id'] ?? 0 );
			if ( $product_id && wc_get_product( $product_id ) ) {
				$rows[ $product_id ] = b2bcp_parse_price( $row['price'] ?? '' );
			}
		}
		b2bcp_replace_assignments( 'company', $post_id, $rows );

		// Branches: update existing, insert new, delete removed.
		$branches = isset( $_POST['b2bcp_branches'] ) && is_array( $_POST['b2bcp_branches'] ) ? $_POST['b2bcp_branches'] : array();
		$keep     = array();
		foreach ( $branches as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = b2bcp_sanitize_address( $row );
			if ( '' === $clean['label'] && '' === $clean['address_1'] ) {
				continue;
			}
			if ( '' === $clean['label'] ) {
				$clean['label'] = $clean['city'] ? $clean['city'] : $clean['address_1'];
			}
			$keep[] = b2bcp_save_address( $post_id, $clean, absint( $row['id'] ?? 0 ) );
		}
		foreach ( b2bcp_get_addresses( $post_id ) as $existing ) {
			if ( ! in_array( (int) $existing['id'], $keep, true ) ) {
				b2bcp_delete_address( $post_id, (int) $existing['id'] );
			}
		}

		// Existing users: admin flag / removal.
		$users = isset( $_POST['b2bcp_users'] ) && is_array( $_POST['b2bcp_users'] ) ? wp_unslash( $_POST['b2bcp_users'] ) : array();
		foreach ( $users as $user_id => $flags ) {
			$user_id = absint( $user_id );
			if ( ! $user_id ) {
				continue;
			}
			if ( ! empty( $flags['remove'] ) ) {
				b2bcp_remove_user_from_company( $user_id, $post_id );
			} else {
				b2bcp_set_company_admin( $user_id, $post_id, ! empty( $flags['admin'] ) );
			}
		}

		// Invitation.
		$invite = isset( $_POST['b2bcp_invite'] ) && is_array( $_POST['b2bcp_invite'] ) ? wp_unslash( $_POST['b2bcp_invite'] ) : array();
		// phpcs:enable
		$email = sanitize_email( $invite['email'] ?? '' );
		if ( $email && 'publish' === $post->post_status ) {
			$result = self::invite_user( $post_id, $email, sanitize_text_field( $invite['first_name'] ?? '' ), sanitize_text_field( $invite['last_name'] ?? '' ), ! empty( $invite['admin'] ) );
			set_transient( 'b2bcp_notice_' . get_current_user_id(), $result, 60 );
		} elseif ( $email ) {
			set_transient( 'b2bcp_notice_' . get_current_user_id(), array( 'error', __( 'Publish the company before inviting users.', 'b2b-contract-portal' ) ), 60 );
		}
	}

	/**
	 * Create or link a user and send the invitation.
	 *
	 * @param int    $company_id Company.
	 * @param string $email      Email.
	 * @param string $first      First name.
	 * @param string $last       Last name.
	 * @param bool   $is_admin   Company admin.
	 * @return array{0:string,1:string} [ type, message ]
	 */
	public static function invite_user( $company_id, $email, $first, $last, $is_admin ) {
		$user   = get_user_by( 'email', $email );
		$is_new = false;
		if ( ! $user ) {
			$login = sanitize_user( current( explode( '@', $email ) ), true );
			$base  = $login ? $login : 'buyer';
			$n     = 1;
			while ( username_exists( $login ) || '' === $login ) {
				$login = $base . $n++;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $login,
					'role'         => 'customer',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return array( 'error', $user_id->get_error_message() );
			}
			$user   = get_user_by( 'id', $user_id );
			$is_new = true;
		}
		b2bcp_add_user_to_company( $user->ID, $company_id, $is_admin );
		B2BCP_Emails::send_invite( $user, $company_id, $is_new );

		return array(
			'success',
			$is_new
				/* translators: %s: email */
				? sprintf( __( 'Invitation sent to %s. They will set their password from the email.', 'b2b-contract-portal' ), $email )
				/* translators: %s: email */
				: sprintf( __( '%s already had an account; they were added to this company and notified.', 'b2b-contract-portal' ), $email ),
		);
	}

	/**
	 * Show the invitation result after saving.
	 */
	public static function notices() {
		$notice = get_transient( 'b2bcp_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'b2bcp_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}

	/**
	 * Admin list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['b2bcp_products'] = __( 'Products', 'b2b-contract-portal' );
		$columns['b2bcp_users']    = __( 'Users', 'b2b-contract-portal' );
		$columns['b2bcp_branches'] = __( 'Branches', 'b2b-contract-portal' );
		$columns['b2bcp_payment']  = __( 'Payment', 'b2b-contract-portal' );
		$columns['date']           = $date;
		return $columns;
	}

	/**
	 * Admin list column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Company.
	 */
	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'b2bcp_products':
				echo (int) count( b2bcp_company_assignments( $post_id ) );
				break;
			case 'b2bcp_users':
				echo (int) count( b2bcp_company_users( $post_id ) );
				break;
			case 'b2bcp_branches':
				echo (int) count( b2bcp_get_addresses( $post_id ) );
				break;
			case 'b2bcp_payment':
				$labels = array(
					'card'    => __( 'Card', 'b2b-contract-portal' ),
					'invoice' => __( 'Invoice', 'b2b-contract-portal' ),
					'both'    => __( 'Card + invoice', 'b2b-contract-portal' ),
				);
				$company = b2bcp_get_company( $post_id );
				echo esc_html( $company ? $labels[ $company['payment'] ] : '' );
				break;
		}
	}
}
