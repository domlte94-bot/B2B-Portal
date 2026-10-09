<?php
/**
 * My Account → Company: switch company, see contract info, manage branches (company admins).
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Company page in My Account.
 */
class B2BCP_Account {

	const ENDPOINT = 'company';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Register the endpoint; flush once after activation.
	 */
	public static function endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		if ( 'yes' === get_option( 'b2bcp_flush_rewrites' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'b2bcp_flush_rewrites' );
		}
	}

	/**
	 * Let WooCommerce know about the endpoint.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu item for company users.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		if ( ! b2bcp_active_company_id() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Company', 'b2b-contract-portal' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Company', 'b2b-contract-portal' );
		}
		return $out;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Company', 'b2b-contract-portal' );
	}

	/**
	 * Page content.
	 */
	public static function content() {
		$user_id    = get_current_user_id();
		$company_id = b2bcp_active_company_id();
		$company    = b2bcp_get_company( $company_id );
		if ( ! $company ) {
			echo '<p>' . esc_html__( 'Your account is not linked to a company.', 'b2b-contract-portal' ) . '</p>';
			return;
		}
		$companies = b2bcp_user_companies( $user_id );
		$is_admin  = b2bcp_user_is_company_admin( $user_id, $company_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which branch to edit.
		$editing   = isset( $_GET['edit_branch'] ) ? b2bcp_get_address( absint( $_GET['edit_branch'] ) ) : null;
		if ( $editing && (int) $editing['company_id'] !== $company_id ) {
			$editing = null;
		}
		$payments = array(
			'card'    => __( 'Card', 'b2b-contract-portal' ),
			'invoice' => __( 'Invoice', 'b2b-contract-portal' ),
			'both'    => __( 'Card or invoice', 'b2b-contract-portal' ),
		);
		?>
		<div class="b2bcp-account">
			<?php if ( count( $companies ) > 1 ) : ?>
				<form method="post" class="b2bcp-switch">
					<?php wp_nonce_field( 'b2bcp_switch', 'b2bcp_switch_nonce' ); ?>
					<label for="b2bcp-switch-company"><?php esc_html_e( 'You are ordering for', 'b2b-contract-portal' ); ?></label>
					<select id="b2bcp-switch-company" name="b2bcp_company">
						<?php foreach ( $companies as $id ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( $id, $company_id ); ?>><?php echo esc_html( get_the_title( $id ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Switch', 'b2b-contract-portal' ); ?></button>
				</form>
				<p class="b2bcp-hint"><?php esc_html_e( 'Your cart, catalog and prices follow the company you choose.', 'b2b-contract-portal' ); ?></p>
			<?php endif; ?>

			<div class="b2bcp-company-head">
				<?php echo $company['logo_id'] ? wp_get_attachment_image( $company['logo_id'], 'medium', false, array( 'class' => 'b2bcp-company-logo', 'alt' => $company['name'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2><?php echo esc_html( $company['name'] ); ?></h2>
			</div>

			<ul class="b2bcp-facts">
				<li><strong><?php esc_html_e( 'Payment', 'b2b-contract-portal' ); ?>:</strong> <?php echo esc_html( $payments[ $company['payment'] ] . ( $company['terms'] && 'card' !== $company['payment'] ? ' (' . $company['terms'] . ')' : '' ) ); ?></li>
				<?php if ( $company['shipping_included'] ) : ?>
					<li><strong><?php esc_html_e( 'Shipping', 'b2b-contract-portal' ); ?>:</strong> <?php esc_html_e( 'Included in your contract', 'b2b-contract-portal' ); ?></li>
				<?php endif; ?>
				<?php if ( $company['contact_name'] ) : ?>
					<li><strong><?php esc_html_e( 'Main contact', 'b2b-contract-portal' ); ?>:</strong> <?php echo esc_html( $company['contact_name'] ); ?></li>
				<?php endif; ?>
			</ul>

			<h3><?php esc_html_e( 'Branches', 'b2b-contract-portal' ); ?></h3>
			<?php $addresses = b2bcp_get_addresses( $company_id ); ?>
			<?php if ( $addresses ) : ?>
				<table class="shop_table b2bcp-branch-list">
					<thead><tr><th><?php esc_html_e( 'Branch', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Address', 'b2b-contract-portal' ); ?></th><?php echo $is_admin ? '<th></th>' : ''; ?></tr></thead>
					<tbody>
					<?php foreach ( $addresses as $address ) : ?>
						<tr>
							<td><?php echo esc_html( b2bcp_address_label( $address ) ); ?></td>
							<td><?php echo esc_html( b2bcp_address_line( $address ) ); ?></td>
							<?php if ( $is_admin ) : ?>
								<td class="b2bcp-actions">
									<a href="<?php echo esc_url( add_query_arg( 'edit_branch', (int) $address['id'], wc_get_account_endpoint_url( self::ENDPOINT ) ) . '#b2bcp-branch-form' ); ?>"><?php esc_html_e( 'Edit', 'b2b-contract-portal' ); ?></a>
									<form method="post" class="b2bcp-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this branch?', 'b2b-contract-portal' ) ); ?>');">
										<?php wp_nonce_field( 'b2bcp_branch', 'b2bcp_branch_nonce' ); ?>
										<input type="hidden" name="b2bcp_branch_action" value="delete">
										<input type="hidden" name="b2bcp_branch_id" value="<?php echo (int) $address['id']; ?>">
										<button type="submit" class="b2bcp-link-button"><?php esc_html_e( 'Delete', 'b2b-contract-portal' ); ?></button>
									</form>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No branches saved yet.', 'b2b-contract-portal' ); ?></p>
			<?php endif; ?>

			<?php if ( $is_admin ) : ?>
				<h3 id="b2bcp-branch-form"><?php echo esc_html( $editing ? __( 'Edit branch', 'b2b-contract-portal' ) : __( 'Add a branch', 'b2b-contract-portal' ) ); ?></h3>
				<form method="post" class="b2bcp-branch-form">
					<?php wp_nonce_field( 'b2bcp_branch', 'b2bcp_branch_nonce' ); ?>
					<input type="hidden" name="b2bcp_branch_action" value="save">
					<input type="hidden" name="b2bcp_branch_id" value="<?php echo $editing ? (int) $editing['id'] : 0; ?>">
					<div class="b2bcp-form-grid">
						<?php foreach ( b2bcp_address_keys() as $key => $label ) : ?>
							<p class="form-row">
								<label for="b2bcp-branch-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?><?php echo in_array( $key, array( 'label', 'address_1', 'city' ), true ) ? ' <abbr class="required">*</abbr>' : ''; ?></label>
								<input type="text" class="input-text" id="b2bcp-branch-<?php echo esc_attr( $key ); ?>" name="b2bcp_branch[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $editing ? $editing[ $key ] : ( 'country' === $key ? WC()->countries->get_base_country() : '' ) ); ?>" <?php echo in_array( $key, array( 'label', 'address_1', 'city' ), true ) ? 'required' : ''; ?>>
							</p>
						<?php endforeach; ?>
					</div>
					<p><button type="submit" class="button b2bcp-button-accent"><?php echo esc_html( $editing ? __( 'Save branch', 'b2b-contract-portal' ) : __( 'Add branch', 'b2b-contract-portal' ) ); ?></button>
					<?php if ( $editing ) : ?>
						<a href="<?php echo esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ); ?>"><?php esc_html_e( 'Cancel', 'b2b-contract-portal' ); ?></a>
					<?php endif; ?>
					</p>
				</form>

				<h3><?php esc_html_e( 'People in your company', 'b2b-contract-portal' ); ?></h3>
				<ul class="b2bcp-people-list">
					<?php foreach ( b2bcp_company_users( $company_id ) as $user ) : ?>
						<li><?php echo esc_html( $user->display_name . ' — ' . $user->user_email ); ?><?php echo b2bcp_user_is_company_admin( $user->ID, $company_id ) ? ' <em>(' . esc_html__( 'admin', 'b2b-contract-portal' ) . ')</em>' : ''; ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="b2bcp-hint"><?php esc_html_e( 'To add or remove people, contact the store.', 'b2b-contract-portal' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Company switch and branch edits.
	 */
	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();

		if ( isset( $_POST['b2bcp_switch_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2bcp_switch_nonce'] ) ), 'b2bcp_switch' ) ) {
			$target = absint( $_POST['b2bcp_company'] ?? 0 );
			if ( in_array( $target, b2bcp_user_companies( $user_id ), true ) ) {
				update_user_meta( $user_id, '_b2bcp_active_company', $target );
				// Prices and catalog change with the company: start a clean cart.
				if ( WC()->cart ) {
					WC()->cart->empty_cart();
				}
				/* translators: %s: company */
				wc_add_notice( sprintf( __( 'You are now ordering for %s.', 'b2b-contract-portal' ), get_the_title( $target ) ) );
			}
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		if ( isset( $_POST['b2bcp_branch_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2bcp_branch_nonce'] ) ), 'b2bcp_branch' ) ) {
			$company_id = b2bcp_active_company_id();
			if ( ! $company_id || ! b2bcp_user_is_company_admin( $user_id, $company_id ) ) {
				return;
			}
			$action     = sanitize_key( wp_unslash( $_POST['b2bcp_branch_action'] ?? '' ) );
			$address_id = absint( $_POST['b2bcp_branch_id'] ?? 0 );
			if ( $address_id ) {
				$existing = b2bcp_get_address( $address_id );
				if ( ! $existing || (int) $existing['company_id'] !== $company_id ) {
					return;
				}
			}
			if ( 'delete' === $action && $address_id ) {
				b2bcp_delete_address( $company_id, $address_id );
				wc_add_notice( __( 'Branch deleted.', 'b2b-contract-portal' ) );
			} elseif ( 'save' === $action ) {
				$raw   = isset( $_POST['b2bcp_branch'] ) && is_array( $_POST['b2bcp_branch'] ) ? $_POST['b2bcp_branch'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in b2bcp_sanitize_address.
				$clean = b2bcp_sanitize_address( $raw );
				if ( '' === $clean['label'] || '' === $clean['address_1'] || '' === $clean['city'] ) {
					wc_add_notice( __( 'Branch name, address and city are required.', 'b2b-contract-portal' ), 'error' );
					return;
				}
				b2bcp_save_address( $company_id, $clean, $address_id );
				wc_add_notice( $address_id ? __( 'Branch updated.', 'b2b-contract-portal' ) : __( 'Branch added.', 'b2b-contract-portal' ) );
			}
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}
	}
}
