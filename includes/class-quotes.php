<?php
/**
 * Quote-request products: no price or cart, a form whose options the store defines,
 * and a list of requests in the admin. Pricing and follow-up happen outside the plugin.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote requests.
 */
class B2BCP_Quotes {

	const MAX_FILE_BYTES = 10485760; // 10 MB.

	/**
	 * Whether the form was printed on this page (classic + block templates can both try).
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'not_purchasable' ), 30, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 30, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 30, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_form' ), 30 );
		add_filter( 'render_block', array( __CLASS__, 'render_in_block' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_submit' ), 5 );

		add_action( 'add_meta_boxes_' . B2BCP_QUOTE_CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . B2BCP_QUOTE_CPT, array( __CLASS__, 'save_status' ) );
		add_filter( 'manage_' . B2BCP_QUOTE_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . B2BCP_QUOTE_CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	/**
	 * Statuses of a request.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'new'         => __( 'New', 'b2b-contract-portal' ),
			'in_progress' => __( 'In progress', 'b2b-contract-portal' ),
			'closed'      => __( 'Closed', 'b2b-contract-portal' ),
		);
	}

	/**
	 * Register the request post type under the B2B Portal menu.
	 */
	public static function register() {
		$caps                 = B2BCP_Company_Admin::caps();
		$caps['create_posts'] = 'do_not_allow';
		register_post_type(
			B2BCP_QUOTE_CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Quote requests', 'b2b-contract-portal' ),
					'singular_name' => __( 'Quote request', 'b2b-contract-portal' ),
					'all_items'     => __( 'Quote requests', 'b2b-contract-portal' ),
					'edit_item'     => __( 'Quote request', 'b2b-contract-portal' ),
					'search_items'  => __( 'Search requests', 'b2b-contract-portal' ),
					'not_found'     => __( 'No quote requests yet.', 'b2b-contract-portal' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . B2BCP_COMPANY_CPT,
				'supports'     => array( 'title' ),
				'capabilities' => $caps,
				'map_meta_cap' => false,
				'rewrite'      => false,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Store front
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a product is quote-only.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_quote( $product ) {
		return $product instanceof WC_Product && b2bcp_product_config( $product )['quote'];
	}

	/**
	 * Quote products cannot be bought.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function not_purchasable( $purchasable, $product ) {
		return self::is_quote( $product ) ? false : $purchasable;
	}

	/**
	 * No price for quote products.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		return self::is_quote( $product ) ? '<span class="b2bcp-quote-price">' . esc_html__( 'Price on quote', 'b2b-contract-portal' ) . '</span>' : $html;
	}

	/**
	 * Shop loop button.
	 *
	 * @param string     $html    Button.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function loop_button( $html, $product ) {
		if ( ! self::is_quote( $product ) ) {
			return $html;
		}
		return sprintf( '<a href="%s#b2bcp-quote" class="button">%s</a>', esc_url( $product->get_permalink() ), esc_html__( 'Request a quote', 'b2b-contract-portal' ) );
	}

	/**
	 * Block themes: add the form after the add-to-cart block of a quote product.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function render_in_block( $content, $block ) {
		if ( self::$rendered || ! in_array( $block['blockName'] ?? '', array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ), true ) || ! is_product() ) {
			return $content;
		}
		ob_start();
		self::render_form();
		return $content . ob_get_clean();
	}

	/**
	 * The request form.
	 */
	public static function render_form() {
		global $product;
		if ( self::$rendered ) {
			return;
		}
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_queried_object_id() );
		}
		if ( ! self::is_quote( $product ) || ! B2BCP_Catalog::can_access( $product ) ) {
			return;
		}
		self::$rendered = true;
		wp_enqueue_style( 'b2bcp-front' );

		$config  = b2bcp_product_config( $product );
		$user    = wp_get_current_user();
		$company = b2bcp_get_company( b2bcp_active_company_id() );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- repopulate after a failed submission.
		$posted  = isset( $_POST['b2bcp_quote_nonce'] ) ? wp_unslash( $_POST ) : array();
		// phpcs:enable
		$contact = array(
			'name'    => $posted['b2bcp_q_contact_name'] ?? ( $user->exists() ? $user->display_name : '' ),
			'company' => $posted['b2bcp_q_contact_company'] ?? ( $company ? $company['name'] : '' ),
			'email'   => $posted['b2bcp_q_contact_email'] ?? ( $user->exists() ? $user->user_email : '' ),
			'phone'   => $posted['b2bcp_q_contact_phone'] ?? '',
		);
		?>
		<form id="b2bcp-quote" class="b2bcp-quote" method="post" enctype="multipart/form-data" action="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>#b2bcp-quote">
			<h3><?php esc_html_e( 'Request a quote', 'b2b-contract-portal' ); ?></h3>
			<?php wp_nonce_field( 'b2bcp_quote_' . $product->get_id(), 'b2bcp_quote_nonce' ); ?>
			<input type="hidden" name="b2bcp_quote_product" value="<?php echo (int) $product->get_id(); ?>">
			<p class="b2bcp-hp" aria-hidden="true"><label>Website <input type="text" name="b2bcp_website" tabindex="-1" autocomplete="off"></label></p>

			<?php foreach ( $config['quote_fields'] as $field ) : ?>
				<?php
				$name  = 'b2bcp_q_' . $field['key'];
				$id    = 'b2bcp-q-' . $field['key'];
				$value = isset( $posted[ $name ] ) && ! is_array( $posted[ $name ] ) ? (string) $posted[ $name ] : '';
				?>
				<p class="form-row b2bcp-q-<?php echo esc_attr( $field['type'] ); ?>">
					<?php if ( 'checkbox' === $field['type'] ) : ?>
						<label for="<?php echo esc_attr( $id ); ?>"><?php B2BCP_Fields::render_input( $field, $name, $value, $id ); ?> <?php echo esc_html( $field['label'] ); ?></label>
					<?php else : ?>
						<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' <abbr class="required">*</abbr>' : ''; ?></label>
						<?php B2BCP_Fields::render_input( $field, $name, $value, $id ); ?>
						<?php if ( 'file' === $field['type'] ) : ?>
							<span class="b2bcp-hint"><?php esc_html_e( 'JPG, PNG, PDF, AI or EPS, up to 10 MB.', 'b2b-contract-portal' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>

			<div class="b2bcp-form-grid">
				<p class="form-row"><label for="b2bcp-q-name"><?php esc_html_e( 'Your name', 'b2b-contract-portal' ); ?> <abbr class="required">*</abbr></label><input type="text" class="input-text" id="b2bcp-q-name" name="b2bcp_q_contact_name" required value="<?php echo esc_attr( $contact['name'] ); ?>"></p>
				<p class="form-row"><label for="b2bcp-q-company"><?php esc_html_e( 'Company', 'b2b-contract-portal' ); ?></label><input type="text" class="input-text" id="b2bcp-q-company" name="b2bcp_q_contact_company" value="<?php echo esc_attr( $contact['company'] ); ?>"></p>
				<p class="form-row"><label for="b2bcp-q-email"><?php esc_html_e( 'Email', 'b2b-contract-portal' ); ?> <abbr class="required">*</abbr></label><input type="email" class="input-text" id="b2bcp-q-email" name="b2bcp_q_contact_email" required value="<?php echo esc_attr( $contact['email'] ); ?>"></p>
				<p class="form-row"><label for="b2bcp-q-phone"><?php esc_html_e( 'Phone', 'b2b-contract-portal' ); ?></label><input type="tel" class="input-text" id="b2bcp-q-phone" name="b2bcp_q_contact_phone" value="<?php echo esc_attr( $contact['phone'] ); ?>"></p>
			</div>
			<p><button type="submit" class="button alt b2bcp-button-accent"><?php esc_html_e( 'Send request', 'b2b-contract-portal' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Save a submitted request.
	 */
	public static function handle_submit() {
		if ( empty( $_POST['b2bcp_quote_nonce'] ) || empty( $_POST['b2bcp_quote_product'] ) ) {
			return;
		}
		$product_id = absint( $_POST['b2bcp_quote_product'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2bcp_quote_nonce'] ) ), 'b2bcp_quote_' . $product_id ) ) {
			wc_add_notice( __( 'The form expired. Please send it again.', 'b2b-contract-portal' ), 'error' );
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! self::is_quote( $product ) || ! B2BCP_Catalog::can_access( $product ) ) {
			return;
		}
		if ( ! empty( $_POST['b2bcp_website'] ) ) {
			// Honeypot filled: pretend success, store nothing.
			wc_add_notice( __( 'Thanks! Your request was sent.', 'b2b-contract-portal' ) );
			wp_safe_redirect( get_permalink( $product_id ) );
			exit;
		}

		$errors  = array();
		$contact = array(
			'name'    => sanitize_text_field( wp_unslash( $_POST['b2bcp_q_contact_name'] ?? '' ) ),
			'company' => sanitize_text_field( wp_unslash( $_POST['b2bcp_q_contact_company'] ?? '' ) ),
			'email'   => sanitize_email( wp_unslash( $_POST['b2bcp_q_contact_email'] ?? '' ) ),
			'phone'   => sanitize_text_field( wp_unslash( $_POST['b2bcp_q_contact_phone'] ?? '' ) ),
		);
		if ( '' === $contact['name'] ) {
			$errors[] = __( 'Your name is required.', 'b2b-contract-portal' );
		}
		if ( ! is_email( $contact['email'] ) ) {
			$errors[] = __( 'Enter a valid email.', 'b2b-contract-portal' );
		}

		$config  = b2bcp_product_config( $product );
		$answers = array();
		$files   = array();
		foreach ( $config['quote_fields'] as $field ) {
			$name = 'b2bcp_q_' . $field['key'];
			if ( 'file' === $field['type'] ) {
				$has_file = isset( $_FILES[ $name ]['error'] ) && UPLOAD_ERR_NO_FILE !== $_FILES[ $name ]['error'];
				if ( ! $has_file ) {
					if ( $field['required'] ) {
						/* translators: %s: field */
						$errors[] = sprintf( __( '%s is required.', 'b2b-contract-portal' ), $field['label'] );
					}
					continue;
				}
				$size = isset( $_FILES[ $name ]['size'] ) ? (int) $_FILES[ $name ]['size'] : 0;
				if ( UPLOAD_ERR_OK !== $_FILES[ $name ]['error'] || $size > self::MAX_FILE_BYTES ) {
					/* translators: %s: field */
					$errors[] = sprintf( __( '%s: the file could not be uploaded (max 10 MB).', 'b2b-contract-portal' ), $field['label'] );
					continue;
				}
				$files[ $field['key'] ] = $field;
				continue;
			}
			$raw                   = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by clean_answer.
			list( $value, $error ) = B2BCP_Fields::clean_answer( is_array( $raw ) ? '' : $raw, $field );
			if ( $error ) {
				$errors[] = $error;
			}
			$answers[] = array(
				'label' => $field['label'],
				'value' => $value,
			);
		}

		if ( $errors ) {
			foreach ( $errors as $error ) {
				wc_add_notice( $error, 'error' );
			}
			return; // Page renders again with the posted values.
		}

		$company_id = b2bcp_active_company_id();
		$title      = $product->get_name() . ' — ' . $contact['name'] . ( $contact['company'] ? ' (' . $contact['company'] . ')' : '' );
		$quote_id   = wp_insert_post(
			array(
				'post_type'   => B2BCP_QUOTE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $quote_id ) ) {
			wc_add_notice( __( 'Your request could not be saved. Please try again.', 'b2b-contract-portal' ), 'error' );
			return;
		}

		// Uploads, attached to the request.
		if ( $files ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$mimes = array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'pdf'      => 'application/pdf',
				'ai'       => 'application/postscript',
				'eps'      => 'application/postscript',
			);
			foreach ( $files as $key => $field ) {
				$attachment_id = media_handle_upload(
					'b2bcp_q_' . $key,
					$quote_id,
					array(),
					array(
						'test_form' => false,
						'mimes'     => $mimes,
					)
				);
				if ( is_wp_error( $attachment_id ) ) {
					$answers[] = array(
						'label' => $field['label'],
						/* translators: %s: error */
						'value' => sprintf( __( 'Upload failed: %s', 'b2b-contract-portal' ), $attachment_id->get_error_message() ),
					);
					continue;
				}
				$answers[] = array(
					'label'         => $field['label'],
					'value'         => wp_get_attachment_url( $attachment_id ),
					'attachment_id' => $attachment_id,
				);
			}
		}

		update_post_meta( $quote_id, '_b2bcp_product_id', $product_id );
		update_post_meta( $quote_id, '_b2bcp_contact', $contact );
		update_post_meta( $quote_id, '_b2bcp_answers', $answers );
		update_post_meta( $quote_id, '_b2bcp_company_id', $company_id );
		update_post_meta( $quote_id, '_b2bcp_user_id', get_current_user_id() );
		update_post_meta( $quote_id, '_b2bcp_status', 'new' );

		B2BCP_Emails::quote_received( $quote_id );

		wc_add_notice( __( 'Thanks! Your request was sent. We will get back to you soon.', 'b2b-contract-portal' ) );
		wp_safe_redirect( get_permalink( $product_id ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Request detail box.
	 */
	public static function meta_boxes() {
		remove_meta_box( 'submitdiv', B2BCP_QUOTE_CPT, 'side' );
		add_meta_box( 'b2bcp-quote-details', __( 'Request', 'b2b-contract-portal' ), array( __CLASS__, 'box_details' ), B2BCP_QUOTE_CPT, 'normal', 'high' );
		add_meta_box( 'b2bcp-quote-status', __( 'Status', 'b2b-contract-portal' ), array( __CLASS__, 'box_status' ), B2BCP_QUOTE_CPT, 'side', 'high' );
	}

	/**
	 * Everything the visitor sent.
	 *
	 * @param WP_Post $post Request.
	 */
	public static function box_details( $post ) {
		$data = self::get( $post->ID );
		?>
		<table class="widefat striped">
			<tbody>
				<tr><th><?php esc_html_e( 'Product', 'b2b-contract-portal' ); ?></th><td><?php echo $data['product'] ? '<a href="' . esc_url( get_edit_post_link( $data['product']->get_id() ) ) . '">' . esc_html( $data['product']->get_name() ) . '</a>' : '&ndash;'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Name', 'b2b-contract-portal' ); ?></th><td><?php echo esc_html( $data['contact']['name'] ?? '' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Company', 'b2b-contract-portal' ); ?></th><td><?php echo esc_html( $data['contact']['company'] ?? '' ); ?><?php echo $data['company_id'] ? ' — <a href="' . esc_url( get_edit_post_link( $data['company_id'] ) ) . '">' . esc_html__( 'existing client', 'b2b-contract-portal' ) . '</a>' : ' — ' . esc_html__( 'no contract yet', 'b2b-contract-portal' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Email', 'b2b-contract-portal' ); ?></th><td><a href="mailto:<?php echo esc_attr( $data['contact']['email'] ?? '' ); ?>"><?php echo esc_html( $data['contact']['email'] ?? '' ); ?></a></td></tr>
				<tr><th><?php esc_html_e( 'Phone', 'b2b-contract-portal' ); ?></th><td><?php echo esc_html( $data['contact']['phone'] ?? '' ); ?></td></tr>
				<?php foreach ( $data['answers'] as $answer ) : ?>
					<tr>
						<th><?php echo esc_html( $answer['label'] ); ?></th>
						<td><?php echo ! empty( $answer['attachment_id'] ) ? '<a href="' . esc_url( $answer['value'] ) . '" target="_blank" rel="noopener">' . esc_html( basename( (string) $answer['value'] ) ) . '</a>' : nl2br( esc_html( $answer['value'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr><th><?php esc_html_e( 'Received', 'b2b-contract-portal' ); ?></th><td><?php echo esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Price and contract are handled outside the store. When the client signs, create their company, invite them and assign the product with its price.', 'b2b-contract-portal' ); ?></p>
		<?php
	}

	/**
	 * Status box with save button.
	 *
	 * @param WP_Post $post Request.
	 */
	public static function box_status( $post ) {
		$status = get_post_meta( $post->ID, '_b2bcp_status', true );
		wp_nonce_field( 'b2bcp_quote_status', 'b2bcp_quote_status_nonce' );
		?>
		<select name="b2bcp_quote_status" class="widefat">
			<?php foreach ( self::statuses() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'b2b-contract-portal' ); ?></button>
		<a class="submitdelete" style="float:right" href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Move to Trash', 'b2b-contract-portal' ); ?></a></p>
		<input type="hidden" name="post_status" value="publish">
		<?php
	}

	/**
	 * Save the status.
	 *
	 * @param int $post_id Request.
	 */
	public static function save_status( $post_id ) {
		if ( ! isset( $_POST['b2bcp_quote_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2bcp_quote_status_nonce'] ) ), 'b2bcp_quote_status' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_POST['b2bcp_quote_status'] ?? 'new' ) );
		if ( isset( self::statuses()[ $status ] ) ) {
			update_post_meta( $post_id, '_b2bcp_status', $status );
		}
	}

	/**
	 * A request's data.
	 *
	 * @param int $quote_id Request.
	 * @return array
	 */
	public static function get( $quote_id ) {
		$contact = get_post_meta( $quote_id, '_b2bcp_contact', true );
		$answers = get_post_meta( $quote_id, '_b2bcp_answers', true );
		return array(
			'product'    => wc_get_product( (int) get_post_meta( $quote_id, '_b2bcp_product_id', true ) ),
			'contact'    => is_array( $contact ) ? $contact : array(),
			'answers'    => is_array( $answers ) ? $answers : array(),
			'company_id' => (int) get_post_meta( $quote_id, '_b2bcp_company_id', true ),
			'status'     => (string) get_post_meta( $quote_id, '_b2bcp_status', true ),
		);
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => __( 'Request', 'b2b-contract-portal' ),
			'b2bcp_email'  => __( 'Email', 'b2b-contract-portal' ),
			'b2bcp_client' => __( 'Client', 'b2b-contract-portal' ),
			'b2bcp_status' => __( 'Status', 'b2b-contract-portal' ),
			'date'         => $columns['date'],
		);
	}

	/**
	 * List column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Request.
	 */
	public static function column( $column, $post_id ) {
		$data = self::get( $post_id );
		switch ( $column ) {
			case 'b2bcp_email':
				echo esc_html( $data['contact']['email'] ?? '' );
				break;
			case 'b2bcp_client':
				echo $data['company_id'] ? esc_html( get_the_title( $data['company_id'] ) ) : esc_html__( 'New (no contract)', 'b2b-contract-portal' );
				break;
			case 'b2bcp_status':
				$statuses = self::statuses();
				$status   = isset( $statuses[ $data['status'] ] ) ? $data['status'] : 'new';
				printf( '<mark class="b2bcp-status b2bcp-status-%1$s">%2$s</mark>', esc_attr( $status ), esc_html( $statuses[ $status ] ) );
				break;
		}
	}

	/**
	 * Hide "Quick edit" on requests.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( B2BCP_QUOTE_CPT === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}
}
