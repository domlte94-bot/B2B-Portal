<?php
/**
 * Product page order form: people rows, products with parts (complete or single parts), multi-address quantities;
 * turning one submission into cart lines; showing it in the cart and saving it on the order.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order form and cart/order data.
 */
class B2BCP_Order_Form {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render' ), 5 );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_submit' ), 19 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'lock_quantity' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'validate_quantity_update' ), 10, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'store_api_editable' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_line_item' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'display_meta_key' ), 10, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 20, 2 );
	}

	/**
	 * Front-end assets on product pages.
	 */
	public static function assets() {
		wp_register_style( 'b2bcp-front', B2BCP_URL . 'assets/front.css', array(), B2BCP_VERSION );
		wp_register_script( 'b2bcp-front', B2BCP_URL . 'assets/front.js', array(), B2BCP_VERSION, true );
		wp_localize_script(
			'b2bcp-front',
			'b2bcpFront',
			array(
				'person' => __( 'person', 'b2b-contract-portal' ),
				'people' => __( 'people', 'b2b-contract-portal' ),
				'unit'   => __( 'unit', 'b2b-contract-portal' ),
				'units'  => __( 'units', 'b2b-contract-portal' ),
				'personLabel' => __( 'Person', 'b2b-contract-portal' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'addressNonce' => wp_create_nonce( 'b2bcp_address' ),
				'saving'      => __( 'Saving…', 'b2b-contract-portal' ),
				'saved'       => __( 'Saved. It is now in the address lists.', 'b2b-contract-portal' ),
				'required'    => __( 'Name, address and city are required.', 'b2b-contract-portal' ),
				'failed'      => __( 'The address could not be saved. Try again.', 'b2b-contract-portal' ),
			)
		);
		if ( b2bcp_active_company_id() ) {
			wp_enqueue_style( 'b2bcp-front' ); // Company bar on every page.
		}
		if ( is_product() || is_account_page() || is_cart() || is_checkout() ) {
			wp_enqueue_style( 'b2bcp-front' );
			wp_enqueue_script( 'b2bcp-front' );
		}
	}

	/**
	 * Shop loop: products with the order form must be opened to be ordered.
	 *
	 * @param string     $html    Button HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function loop_button( $html, $product ) {
		$config = b2bcp_product_config( $product );
		if ( $config['quote'] || ! b2bcp_uses_order_form( $config ) || ! $product->is_purchasable() ) {
			return $html;
		}
		return sprintf( '<a href="%s" class="button">%s</a>', esc_url( $product->get_permalink() ), esc_html__( 'Customize & order', 'b2b-contract-portal' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Render the form inside the add-to-cart form.
	 */
	public static function render() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$config = b2bcp_product_config( $product );
		if ( ! b2bcp_uses_order_form( $config ) ) {
			return;
		}

		$company_id = b2bcp_active_company_id();
		$addresses  = ( $config['multiaddress'] && $company_id ) ? b2bcp_get_addresses( $company_id ) : array();
		$fields     = b2bcp_person_fields( $config );
		$parts      = $config['has_parts'] ? self::parts_display( $config ) : array();
		$can_add    = $config['multiaddress'] && $company_id && b2bcp_user_can_add_address( get_current_user_id(), $company_id );
		$is_split   = $config['multiaddress'] && $company_id && ( $addresses || $can_add );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- repopulating the form after a failed add to cart.
		$posted      = isset( $_POST['b2bcp_form'] ) && (int) ( $_POST['add-to-cart'] ?? 0 ) === $product->get_id();
		$post_people = $posted && isset( $_POST['b2bcp_people'] ) && is_array( $_POST['b2bcp_people'] ) ? wp_unslash( $_POST['b2bcp_people'] ) : array();
		$post_qty    = $posted && isset( $_POST['b2bcp_qty'] ) && is_array( $_POST['b2bcp_qty'] ) ? array_map( 'absint', wp_unslash( $_POST['b2bcp_qty'] ) ) : array();
		$post_mode   = $posted && isset( $_POST['b2bcp_parts_mode'] ) ? sanitize_key( wp_unslash( $_POST['b2bcp_parts_mode'] ) ) : 'complete';
		$post_chosen = $posted && isset( $_POST['b2bcp_parts_chosen'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['b2bcp_parts_chosen'] ) ) : array();
		// phpcs:enable

		$controls_qty = $fields || $is_split;
		?>
		<div class="b2bcp-order" data-controls-qty="<?php echo $controls_qty ? '1' : '0'; ?>">
			<input type="hidden" name="b2bcp_form" value="1">

			<?php if ( $parts ) : ?>
				<fieldset class="b2bcp-parts-box">
					<legend><?php esc_html_e( 'Includes', 'b2b-contract-portal' ); ?></legend>
					<?php if ( $config['parts_alone'] ) : ?>
						<p class="b2bcp-parts-modes">
							<label><input type="radio" name="b2bcp_parts_mode" value="complete" <?php checked( 'parts' !== $post_mode ); ?>> <?php esc_html_e( 'Complete product', 'b2b-contract-portal' ); ?></label>
							<label><input type="radio" name="b2bcp_parts_mode" value="parts" <?php checked( 'parts' === $post_mode ); ?>> <?php esc_html_e( 'Only some parts (replacements)', 'b2b-contract-portal' ); ?></label>
						</p>
					<?php endif; ?>
					<ul class="b2bcp-part-list">
						<?php foreach ( $parts as $part ) : ?>
							<li class="<?php echo $part['alone'] ? 'is-alone' : 'is-fixed'; ?>" data-keys="<?php echo esc_attr( implode( ' ', $part['keys'] ) ); ?>">
								<?php if ( $part['alone'] ) : ?>
									<label>
										<input type="checkbox" name="b2bcp_parts_chosen[]" value="<?php echo (int) $part['product_id']; ?>" <?php checked( in_array( (int) $part['product_id'], $post_chosen, true ) ); ?>>
										<?php echo esc_html( $part['qty'] . ' × ' . $part['name'] ); ?>
										<span class="b2bcp-part-price">
											<?php
											/* translators: %s: price of one part */
											echo wp_kses_post( sprintf( __( '%s each', 'b2b-contract-portal' ), $part['price_html'] ) );
											?>
										</span>
									</label>
								<?php else : ?>
									<span class="b2bcp-part-name"><?php echo esc_html( $part['qty'] . ' × ' . $part['name'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $config['parts_alone'] ) : ?>
						<p class="b2bcp-hint b2bcp-parts-hint"><?php esc_html_e( 'Parts ordered alone are charged at each part\'s price.', 'b2b-contract-portal' ); ?></p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( $fields ) : ?>
				<?php
				$own_keys = $config['personalized'] ? wp_list_pluck( $config['fields'], 'key' ) : array();
				$rows     = $post_people ? $post_people : array( array() );
				?>
				<?php
				// One address for everyone (the per-person "Ship to" is hidden and follows it) or one per person.
				$ship_choice = $is_split && ( count( $addresses ) > 1 || $can_add );
				$row_ids     = array_values( array_filter( array_map( 'absint', wp_list_pluck( array_filter( $rows, 'is_array' ), '_address' ) ) ) );
				$ship_each   = count( array_unique( $row_ids ) ) > 1;
				$ship_one    = $is_split && ! $ship_each;
				?>
				<fieldset class="b2bcp-people<?php echo $ship_one ? ' b2bcp-ship-one' : ''; ?>" data-own-keys="<?php echo esc_attr( implode( ' ', $own_keys ) ); ?>">
					<legend><?php esc_html_e( 'One row per person', 'b2b-contract-portal' ); ?></legend>
					<?php if ( $ship_choice ) : ?>
						<div class="b2bcp-ship-mode">
							<label class="b2bcp-ship-mode-option"><input type="radio" name="b2bcp_ship_mode" value="one" <?php checked( ! $ship_each ); ?>> <?php esc_html_e( 'Ship everyone to', 'b2b-contract-portal' ); ?></label>
							<?php self::address_select( $addresses, '', $row_ids ? (string) $row_ids[0] : ( 1 === count( $addresses ) ? (string) $addresses[0]['id'] : '' ), 'b2bcp-all-address' ); ?>
							<label class="b2bcp-ship-mode-option"><input type="radio" name="b2bcp_ship_mode" value="each" <?php checked( $ship_each ); ?>> <?php esc_html_e( 'Choose an address for each person', 'b2b-contract-portal' ); ?></label>
						</div>
					<?php elseif ( $is_split && $addresses ) : ?>
						<?php /* translators: %s: address label */ ?>
						<p class="b2bcp-hint"><?php echo esc_html( sprintf( __( 'Ships to %s', 'b2b-contract-portal' ), b2bcp_address_label( $addresses[0] ) ) ); ?></p>
					<?php endif; ?>
					<div class="b2bcp-people-list">
						<?php
						foreach ( array_values( $rows ) as $i => $row ) {
							self::person_row( (string) $i, $fields, $addresses, is_array( $row ) ? $row : array(), $is_split );
						}
						?>
					</div>
					<script type="text/html" class="b2bcp-person-template"><?php self::person_row( '__i__', $fields, $addresses, array(), $is_split ); ?></script>
					<p class="b2bcp-hint b2bcp-paste-hint"><?php esc_html_e( 'Tip: paste a list copied from Excel into the first box (one person per line) to fill many people at once.', 'b2b-contract-portal' ); ?></p>
					<p class="b2bcp-people-actions">
						<button type="button" class="button b2bcp-add-person"><?php esc_html_e( '+ Add person', 'b2b-contract-portal' ); ?></button>
						<span class="b2bcp-count" aria-live="polite"></span>
					</p>
				</fieldset>
			<?php elseif ( $is_split ) : ?>
				<fieldset class="b2bcp-split">
					<legend><?php esc_html_e( 'Quantity per address', 'b2b-contract-portal' ); ?></legend>
					<p class="b2bcp-apply-all">
						<label><?php esc_html_e( 'Every address gets', 'b2b-contract-portal' ); ?>
							<input type="number" min="0" class="b2bcp-all-qty" inputmode="numeric">
						</label>
						<button type="button" class="button b2bcp-apply-qty"><?php esc_html_e( 'Apply', 'b2b-contract-portal' ); ?></button>
					</p>
					<div class="b2bcp-table-wrap">
						<table class="b2bcp-split-table">
							<thead><tr><th><?php esc_html_e( 'Ship to', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Address', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Qty', 'b2b-contract-portal' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $addresses as $address ) : ?>
									<tr>
										<td><?php echo esc_html( b2bcp_address_label( $address ) ); ?></td>
										<td class="b2bcp-muted"><?php echo esc_html( b2bcp_address_line( $address ) ); ?></td>
										<td><input type="number" min="0" inputmode="numeric" class="b2bcp-branch-qty" name="b2bcp_qty[<?php echo (int) $address['id']; ?>]" value="<?php echo isset( $post_qty[ $address['id'] ] ) && $post_qty[ $address['id'] ] ? (int) $post_qty[ $address['id'] ] : ''; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: branch */ __( 'Quantity for %s', 'b2b-contract-portal' ), b2bcp_address_label( $address ) ) ); ?>"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="b2bcp-count" aria-live="polite"></p>
					<?php if ( $config['min_per_address'] ) : ?>
						<p class="b2bcp-hint">
							<?php
							/* translators: %d: minimum units */
							echo esc_html( sprintf( __( 'Minimum %d per address that receives this product.', 'b2b-contract-portal' ), $config['min_per_address'] ) );
							?>
						</p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( $can_add ) : ?>
				<details class="b2bcp-new-address"<?php echo $addresses ? '' : ' open'; ?>>
					<summary><?php esc_html_e( '+ Add a new address', 'b2b-contract-portal' ); ?></summary>
					<div class="b2bcp-form-grid">
						<?php foreach ( b2bcp_address_keys() as $key => $label ) : ?>
							<?php $req = in_array( $key, array( 'label', 'address_1', 'city' ), true ); ?>
							<label class="b2bcp-field">
								<span class="b2bcp-field-label"><?php echo esc_html( $label ); ?><?php echo $req ? ' <abbr class="required">*</abbr>' : ''; ?></span>
								<input type="text" data-address-key="<?php echo esc_attr( $key ); ?>" value="<?php echo 'country' === $key ? esc_attr( WC()->countries->get_base_country() ) : ''; ?>"<?php echo $req ? ' data-required="1"' : ''; ?>>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="b2bcp-new-address-actions">
						<button type="button" class="button b2bcp-save-address"><?php esc_html_e( 'Save address', 'b2b-contract-portal' ); ?></button>
						<span class="b2bcp-new-address-status" role="status" aria-live="polite"></span>
					</p>
					<p class="b2bcp-hint"><?php esc_html_e( 'It is saved to your company\'s address book, so everyone in your company can use it next time.', 'b2b-contract-portal' ); ?></p>
				</details>
			<?php endif; ?>

			<?php if ( $config['multiaddress'] && ! $company_id ) : ?>
				<p class="b2bcp-hint"><?php esc_html_e( 'Contract customers can log in to ship this to several addresses in one order.', 'b2b-contract-portal' ); ?></p>
			<?php elseif ( $config['multiaddress'] && ! $is_split ) : ?>
				<p class="b2bcp-hint"><?php esc_html_e( 'Your company has no shipping addresses saved yet, so this ships to the checkout address. Your company admin can add addresses.', 'b2b-contract-portal' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One person row.
	 *
	 * @param string $i         Index.
	 * @param array  $fields    Fields.
	 * @param array  $addresses Addresses (empty when not splitting).
	 * @param array  $values    Values.
	 * @param bool   $split     Whether each person picks an address.
	 */
	private static function person_row( $i, array $fields, array $addresses, array $values, $split = false ) {
		$required = ' <abbr class="required" title="' . esc_attr__( 'required', 'b2b-contract-portal' ) . '">*</abbr>';
		echo '<div class="b2bcp-person">';
		echo '<div class="b2bcp-person-head"><span class="b2bcp-num"></span>';
		echo '<button type="button" class="b2bcp-remove-person" aria-label="' . esc_attr__( 'Remove person', 'b2b-contract-portal' ) . '">&times;</button></div>';
		echo '<div class="b2bcp-person-fields">';
		foreach ( $fields as $field ) {
			$value = isset( $values[ $field['key'] ] ) ? sanitize_text_field( (string) $values[ $field['key'] ] ) : '';
			echo '<label class="b2bcp-field" data-key="' . esc_attr( $field['key'] ) . '"><span class="b2bcp-field-label">' . esc_html( $field['label'] ) . ( $field['required'] ? $required : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $required is escaped above.
			// Required is enforced on the server (parts not ordered do not need their fields).
			B2BCP_Fields::render_input( array_merge( $field, array( 'required' => false ) ), 'b2bcp_people[' . $i . '][' . $field['key'] . ']', $value );
			echo '</label>';
		}
		if ( $split ) {
			echo '<label class="b2bcp-field b2bcp-field-address"><span class="b2bcp-field-label">' . esc_html__( 'Ship to', 'b2b-contract-portal' ) . $required . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			$selected = isset( $values['_address'] ) ? (string) absint( $values['_address'] ) : ( 1 === count( $addresses ) ? (string) $addresses[0]['id'] : '' );
			self::address_select( $addresses, 'b2bcp_people[' . $i . '][_address]', $selected, 'b2bcp-person-address' );
			echo '</label>';
		}
		echo '</div></div>';
	}

	/**
	 * Branch dropdown.
	 *
	 * @param array  $addresses Addresses.
	 * @param string $name      Name attribute ('' for none).
	 * @param string $selected  Selected id.
	 * @param string $class     CSS class.
	 */
	private static function address_select( array $addresses, $name, $selected, $class ) {
		printf( '<select class="%s"%s aria-label="%s">', esc_attr( $class ), $name ? ' name="' . esc_attr( $name ) . '"' : '', esc_attr__( 'Ship to address', 'b2b-contract-portal' ) );
		echo '<option value="">' . esc_html__( 'Choose address…', 'b2b-contract-portal' ) . '</option>';
		foreach ( $addresses as $address ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $address['id'], selected( $selected, (string) $address['id'], false ), esc_html( b2bcp_address_label( $address ) ) );
		}
		echo '</select>';
	}

	/**
	 * Parts with display data.
	 *
	 * @param array $config Product config.
	 * @return array[]
	 */
	public static function parts_display( array $config ) {
		$out = array();
		foreach ( $config['parts'] as $part ) {
			$product = $part['product_id'] ? wc_get_product( (int) $part['product_id'] ) : null;
			$out[]   = array(
				'product_id' => $product ? $product->get_id() : 0,
				'qty'        => (int) $part['qty'],
				'name'       => $part['name'],
				'alone'      => $part['alone'] && $product && $product->is_purchasable(),
				'price_html' => $product ? $product->get_price_html() : '',
				'keys'       => wp_list_pluck( b2bcp_part_fields( $part, $config ), 'key' ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Submission → cart lines
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the product form before WooCommerce's own add-to-cart handler (priority 20).
	 */
	public static function handle_submit() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- add to cart is a public, non-destructive action, as in WooCommerce core.
		if ( ! isset( $_POST['b2bcp_form'], $_REQUEST['add-to-cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$product_id = absint( wp_unslash( $_REQUEST['add-to-cart'] ) );
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$config = b2bcp_product_config( $product );
		if ( ! b2bcp_uses_order_form( $config ) ) {
			return;
		}

		// From here on this request is ours: stop WooCommerce from adding the product again.
		unset( $_REQUEST['add-to-cart'], $_GET['add-to-cart'] );

		$result = self::build_lines( $product, $config, wp_unslash( $_POST ) );
		// phpcs:enable

		if ( $result['errors'] ) {
			foreach ( $result['errors'] as $error ) {
				wc_add_notice( $error, 'error' );
			}
			return; // The product page re-renders with the posted values.
		}

		$added = 0;
		foreach ( $result['lines'] as $line ) {
			$key = WC()->cart->add_to_cart( $line['product_id'], $line['qty'], 0, array(), array( 'b2bcp' => $line['data'] ) );
			if ( $key ) {
				$added += $line['qty'];
			}
		}
		if ( ! $added ) {
			return; // WooCommerce already added a notice explaining why (stock, etc.).
		}

		wc_add_notice(
			sprintf(
				'<a href="%1$s" tabindex="1" class="button wc-forward">%2$s</a> %3$s',
				esc_url( wc_get_cart_url() ),
				esc_html__( 'View cart', 'b2b-contract-portal' ),
				/* translators: 1: units, 2: product */
				esc_html( sprintf( _n( '%1$d unit of "%2$s" added to your cart.', '%1$d units of "%2$s" added to your cart.', $added, 'b2b-contract-portal' ), $added, $product->get_name() ) )
			),
			'success'
		);

		$target = 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ? wc_get_cart_url() : $product->get_permalink();
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Validate a submission and turn it into cart lines. Pure on its inputs so it can be tested.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $config  Product config.
	 * @param array      $post    Unslashed POST data.
	 * @return array{errors:string[],lines:array[]}
	 */
	public static function build_lines( WC_Product $product, array $config, array $post ) {
		$errors     = array();
		$company_id = b2bcp_active_company_id();

		if ( ! $product->is_purchasable() ) {
			return array(
				'errors' => array( __( 'This product cannot be ordered.', 'b2b-contract-portal' ) ),
				'lines'  => array(),
			);
		}

		// What is being ordered: the product (complete), or only some of its parts.
		$targets = array();
		if ( $config['parts_alone'] && isset( $post['b2bcp_parts_mode'] ) && 'parts' === $post['b2bcp_parts_mode'] ) {
			$chosen = array_map( 'absint', (array) ( $post['b2bcp_parts_chosen'] ?? array() ) );
			foreach ( $config['parts'] as $part ) {
				if ( $part['alone'] && in_array( (int) $part['product_id'], $chosen, true ) ) {
					$targets[] = array(
						'product_id' => (int) $part['product_id'],
						'per_unit'   => max( 1, (int) $part['qty'] ),
						'fields'     => b2bcp_part_fields( $part, $config ),
						'part_of'    => $product->get_id(),
						'complete'   => false,
					);
				}
			}
			if ( ! $targets ) {
				$errors[] = __( 'Choose at least one part.', 'b2b-contract-portal' );
			}
		} else {
			$targets[] = array(
				'product_id' => $product->get_id(),
				'per_unit'   => 1,
				'fields'     => b2bcp_person_fields( $config ),
				'part_of'    => 0,
				'complete'   => (bool) $config['has_parts'],
			);
		}

		// Every field any target needs, keyed.
		$needed = array();
		foreach ( $targets as $target ) {
			foreach ( $target['fields'] as $field ) {
				if ( ! isset( $needed[ $field['key'] ] ) ) {
					$needed[ $field['key'] ] = $field;
				}
			}
		}

		$addresses = array();
		if ( $config['multiaddress'] && $company_id ) {
			foreach ( b2bcp_get_addresses( $company_id ) as $address ) {
				$addresses[ (int) $address['id'] ] = $address;
			}
		}
		$split = (bool) $addresses;

		// Groups: address_id => [ 'qty' => n, 'people' => [...] ].
		$groups = array();

		if ( $needed ) {
			$rows   = isset( $post['b2bcp_people'] ) && is_array( $post['b2bcp_people'] ) ? array_values( $post['b2bcp_people'] ) : array();
			$number = 0;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$typed = false;
				foreach ( array_keys( $needed ) as $key ) {
					if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
						$typed = true;
						break;
					}
				}
				if ( ! $typed ) {
					continue; // Blank row.
				}
				++$number;
				$person = array();
				foreach ( $needed as $key => $field ) {
					list( $value, $error ) = B2BCP_Fields::clean_answer( $row[ $key ] ?? '', $field );
					if ( $error ) {
						/* translators: 1: row number, 2: error */
						$errors[] = sprintf( __( 'Person %1$d: %2$s', 'b2b-contract-portal' ), $number, $error );
					}
					$person[ $key ] = $value;
				}
				$address_id = 0;
				if ( $split ) {
					$address_id = absint( $row['_address'] ?? 0 );
					if ( ! isset( $addresses[ $address_id ] ) ) {
						/* translators: %d: row number */
						$errors[]   = sprintf( __( 'Person %d: choose the address it ships to.', 'b2b-contract-portal' ), $number );
						$address_id = 0;
					}
				}
				$groups[ $address_id ]['people'][] = $person;
			}
			if ( ! $number ) {
				$errors[] = __( 'Add at least one person.', 'b2b-contract-portal' );
			}
			foreach ( $groups as $address_id => $group ) {
				$groups[ $address_id ]['qty'] = count( $group['people'] );
			}
		} elseif ( $split ) {
			$qtys = isset( $post['b2bcp_qty'] ) && is_array( $post['b2bcp_qty'] ) ? $post['b2bcp_qty'] : array();
			foreach ( $qtys as $address_id => $qty ) {
				$address_id = absint( $address_id );
				$qty        = absint( $qty );
				if ( $qty && isset( $addresses[ $address_id ] ) ) {
					$groups[ $address_id ] = array(
						'qty'    => $qty,
						'people' => array(),
					);
				}
			}
			if ( ! $groups ) {
				$errors[] = __( 'Enter a quantity for at least one address.', 'b2b-contract-portal' );
			}
		} else {
			$qty      = max( 1, absint( $post['quantity'] ?? 1 ) );
			$groups[0] = array(
				'qty'    => $qty,
				'people' => array(),
			);
		}

		if ( $split && $config['min_per_address'] ) {
			foreach ( $groups as $address_id => $group ) {
				if ( $address_id && $group['qty'] < $config['min_per_address'] ) {
					/* translators: 1: branch, 2: minimum */
					$errors[] = sprintf( __( '%1$s: the minimum per address is %2$d.', 'b2b-contract-portal' ), b2bcp_address_label( $addresses[ $address_id ] ), $config['min_per_address'] );
				}
			}
		}

		if ( $errors ) {
			return array(
				'errors' => array_values( array_unique( $errors ) ),
				'lines'  => array(),
			);
		}

		// Snapshot of the parts, so production sheets stay right if the product is edited later.
		$components = array();
		foreach ( $config['parts'] as $part ) {
			$piece        = $part['product_id'] ? wc_get_product( (int) $part['product_id'] ) : null;
			$components[] = array(
				'product_id' => $piece ? $piece->get_id() : 0,
				'name'       => $part['name'],
				'sku'        => $piece ? $piece->get_sku() : '',
				'qty'        => (int) $part['qty'],
				'keys'       => wp_list_pluck( b2bcp_part_fields( $part, $config ), 'key' ),
			);
		}

		$lines = array();
		foreach ( $targets as $target ) {
			$labels = array();
			foreach ( $target['fields'] as $field ) {
				$labels[ $field['key'] ] = $field['label'];
			}
			foreach ( $groups as $address_id => $group ) {
				$people = array();
				foreach ( $group['people'] as $person ) {
					$people[] = array_intersect_key( $person, $labels );
				}
				$lines[] = array(
					'product_id' => $target['product_id'],
					'qty'        => $group['qty'] * $target['per_unit'],
					'data'       => array(
						'company_id' => $company_id,
						'address_id' => (int) $address_id,
						'address'    => $address_id ? $addresses[ $address_id ] : null,
						'people'     => $labels ? $people : array(),
						'labels'     => $labels,
						'per_person' => $target['per_unit'],
						'part_of'    => $target['part_of'],
						'complete'   => $target['complete'],
						'components' => $target['complete'] ? $components : array(),
						// Per-person lines must never merge with an identical earlier line:
						// WooCommerce would add the quantities but keep one people list.
						'line_id'    => $labels ? wp_generate_uuid4() : '',
					),
				);
			}
		}

		return array(
			'errors' => array(),
			'lines'  => $lines,
		);
	}

	/* ---------------------------------------------------------------------
	 * Cart display and quantity lock
	 * ------------------------------------------------------------------ */

	/**
	 * Human-readable lines for a line's data (cart, checkout, order).
	 *
	 * @param array      $data    Line data.
	 * @param WC_Product $product Product.
	 * @return array[] Each: [ 'key' => label, 'value' => text ].
	 */
	public static function describe( array $data, $product ) {
		$out = array();
		if ( ! empty( $data['address'] ) ) {
			$out[] = array(
				'key'   => __( 'Ship to', 'b2b-contract-portal' ),
				'value' => b2bcp_address_label( $data['address'] ) . ' — ' . b2bcp_address_line( $data['address'] ),
			);
		}
		if ( ! empty( $data['complete'] ) ) {
			$names = array();
			foreach ( (array) ( $data['components'] ?? array() ) as $component ) {
				$names[] = (int) $component['qty'] . ' × ' . $component['name'];
			}
			if ( $names ) {
				$out[] = array(
					'key'   => __( 'Includes', 'b2b-contract-portal' ),
					'value' => implode( ', ', $names ),
				);
			}
		}
		if ( ! empty( $data['part_of'] ) ) {
			$main  = wc_get_product( (int) $data['part_of'] );
			$out[] = array(
				'key'   => __( 'Part of', 'b2b-contract-portal' ),
				'value' => $main ? $main->get_name() : '#' . (int) $data['part_of'],
			);
		}
		if ( ! empty( $data['people'] ) ) {
			foreach ( $data['people'] as $n => $person ) {
				$parts = array_filter( array_map( 'strval', $person ), 'strlen' );
				$out[] = array(
					/* translators: %d: person number */
					'key'   => sprintf( __( 'Person %d', 'b2b-contract-portal' ), $n + 1 ),
					'value' => implode( ' · ', $parts ),
				);
			}
			if ( ! empty( $data['per_person'] ) && $data['per_person'] > 1 ) {
				$out[] = array(
					'key'   => __( 'Per person', 'b2b-contract-portal' ),
					'value' => (string) (int) $data['per_person'],
				);
			}
		}
		return $out;
	}

	/**
	 * Show the line details in cart and checkout.
	 *
	 * @param array $item_data Existing rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['b2bcp'] ) ) {
			return $item_data;
		}
		foreach ( self::describe( $cart_item['b2bcp'], $cart_item['data'] ) as $row ) {
			$item_data[] = array(
				'key'   => $row['key'],
				'value' => $row['value'],
			);
		}
		return $item_data;
	}

	/**
	 * Whether a cart line's quantity is fixed by its people list.
	 *
	 * @param array|null $cart_item Cart item.
	 * @return bool
	 */
	private static function is_locked( $cart_item ) {
		return is_array( $cart_item ) && ! empty( $cart_item['b2bcp']['people'] );
	}

	/**
	 * Classic cart: show the quantity as text for per-person lines.
	 *
	 * @param string $html          Quantity input.
	 * @param string $cart_item_key Key.
	 * @param array  $cart_item     Item.
	 * @return string
	 */
	public static function lock_quantity( $html, $cart_item_key, $cart_item = null ) {
		if ( null === $cart_item && WC()->cart ) {
			$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		}
		if ( ! self::is_locked( $cart_item ) ) {
			return $html;
		}
		return sprintf( '<span class="b2bcp-fixed-qty">%1$d</span><input type="hidden" name="cart[%2$s][qty]" value="%1$d">', (int) $cart_item['quantity'], esc_attr( $cart_item_key ) );
	}

	/**
	 * Refuse quantity changes on per-person lines.
	 *
	 * @param bool   $passed        Passed.
	 * @param string $cart_item_key Key.
	 * @param array  $values        Item.
	 * @param int    $quantity      New qty.
	 * @return bool
	 */
	public static function validate_quantity_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( self::is_locked( $values ) && (int) $quantity !== (int) $values['quantity'] && $quantity > 0 ) {
			wc_add_notice( __( 'This line has one unit per person. To change it, remove it and add it again with the right people.', 'b2b-contract-portal' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Block cart: per-person lines are not editable.
	 *
	 * @param bool       $editable  Editable.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Item.
	 * @return bool
	 */
	public static function store_api_editable( $editable, $product, $cart_item ) {
		return self::is_locked( $cart_item ) ? false : $editable;
	}

	/* ---------------------------------------------------------------------
	 * Order lines
	 * ------------------------------------------------------------------ */

	/**
	 * Copy line data onto the order item: a hidden structured copy, plus readable rows.
	 *
	 * @param WC_Order_Item_Product $item          Item.
	 * @param string                $cart_item_key Key.
	 * @param array                 $values        Cart item.
	 * @param WC_Order              $order         Order.
	 */
	public static function save_line_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['b2bcp'] ) ) {
			return;
		}
		$data = $values['b2bcp'];
		$item->add_meta_data( '_b2bcp', $data, true );
		foreach ( self::describe( $data, $values['data'] ) as $row ) {
			$item->add_meta_data( $row['key'], $row['value'] );
		}
	}

	/**
	 * Keep meta keys readable (no-op hook point for translations of stored keys).
	 *
	 * @param string        $display_key Key.
	 * @param WC_Meta_Data  $meta        Meta.
	 * @return string
	 */
	public static function display_meta_key( $display_key, $meta ) {
		return $display_key;
	}
}
