<?php
/**
 * "B2B Portal" tab on the WooCommerce product screen.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-product settings: visibility, company prices, personalization, parts, multi-address, quotes.
 */
class B2BCP_Product_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['b2bcp'] = array(
			'label'    => __( 'B2B Portal', 'b2b-contract-portal' ),
			'target'   => 'b2bcp_product_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Tab content.
	 */
	public static function panel() {
		global $post;
		$config    = b2bcp_product_config( $post->ID );
		$companies = b2bcp_get_companies();
		$assigned  = b2bcp_product_assignments( $post->ID );
		wp_nonce_field( 'b2bcp_save_product', 'b2bcp_product_nonce' );
		?>
		<div id="b2bcp_product_data" class="panel woocommerce_options_panel hidden b2bcp-product-panel">

			<div class="options_group">
				<?php if ( $config['owner_company'] ) : ?>
					<p class="b2bcp-row">
						<?php
						$template_name = $config['template_id'] ? get_the_title( $config['template_id'] ) : '';
						printf(
							/* translators: %s: company name (linked) */
							esc_html__( 'Made for %s: this product carries that company\'s own design.', 'b2b-contract-portal' ),
							'<a href="' . esc_url( (string) get_edit_post_link( $config['owner_company'] ) ) . '"><strong>' . esc_html( get_the_title( $config['owner_company'] ) ) . '</strong></a>'
						);
						if ( '' !== $template_name ) {
							/* translators: %s: template name */
							echo ' ' . esc_html( sprintf( __( 'Copied from the template "%s".', 'b2b-contract-portal' ), $template_name ) );
						}
						?>
					</p>
				<?php else : ?>
					<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_template" value="yes" <?php checked( $config['template'] ); ?>> <strong><?php esc_html_e( 'Template', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'not sold as it is; each company gets its own copy with its design, created from the company\'s page', 'b2b-contract-portal' ); ?></label></p>
				<?php endif; ?>
			</div>

			<div class="options_group">
				<h4><?php esc_html_e( 'Who can see this product', 'b2b-contract-portal' ); ?></h4>
				<p class="b2bcp-row">
					<label><input type="radio" name="b2bcp_visibility" value="public" <?php checked( $config['visibility'], 'public' ); ?>> <?php esc_html_e( 'Public: everyone sees it; companies below see their contract price', 'b2b-contract-portal' ); ?></label><br>
					<label><input type="radio" name="b2bcp_visibility" value="restricted" <?php checked( $config['visibility'], 'restricted' ); ?>> <?php esc_html_e( 'Exclusive: only the companies below can see and order it', 'b2b-contract-portal' ); ?></label>
				</p>
				<div class="b2bcp-inner">
					<?php if ( ! $companies ) : ?>
						<p><?php
							printf(
								/* translators: %s: link to add a company */
								esc_html__( 'No client companies yet. %s', 'b2b-contract-portal' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . B2BCP_COMPANY_CPT ) ) . '">' . esc_html__( 'Add the first one', 'b2b-contract-portal' ) . '</a>'
							);
						?></p>
					<?php else : ?>
						<table class="widefat b2bcp-repeater" data-name="b2bcp_companies">
							<thead><tr><th><?php esc_html_e( 'Company', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Contract price', 'b2b-contract-portal' ); ?></th><th></th></tr></thead>
							<tbody>
								<?php
								$i = 0;
								foreach ( $assigned as $company_id => $price ) {
									if ( isset( $companies[ $company_id ] ) ) {
										self::company_row( (string) $i++, $companies, $company_id, $price );
									}
								}
								?>
							</tbody>
							<tfoot><tr><td colspan="3"><button type="button" class="button b2bcp-add-row"><?php esc_html_e( '+ Assign to company', 'b2b-contract-portal' ); ?></button></td></tr></tfoot>
						</table>
						<script type="text/html" class="b2bcp-row-template"><?php self::company_row( '__i__', $companies, 0, null ); ?></script>
						<p class="description"><?php esc_html_e( 'Empty price = the regular price above. The same list can be edited from each company\'s page.', 'b2b-contract-portal' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="options_group">
				<h4><?php esc_html_e( 'How it is ordered', 'b2b-contract-portal' ); ?></h4>

				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_personalized" value="yes" data-b2bcp-toggle="#b2bcp-personal" <?php checked( $config['personalized'] ); ?>> <strong><?php esc_html_e( 'Personalized (custom)', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'buyers add one row per person (name, title…); each row is one unit', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-personal">
					<?php B2BCP_Fields::render_builder( 'b2bcp_fields', $config['fields'], 'person' ); ?>
					<p class="description"><?php esc_html_e( 'Fields with the same label (e.g. "Name") are shared with this product\'s parts, so buyers type each name once.', 'b2b-contract-portal' ); ?></p>
					<p class="b2bcp-row"><label><input type="checkbox" name="b2bcp_preview" value="yes" <?php checked( 'no' !== get_post_meta( $post->ID, '_b2bcp_preview', true ) ); ?>> <?php esc_html_e( 'Show a live preview to company buyers: their company\'s logo with the first person\'s first two fields (e.g. name and title)', 'b2b-contract-portal' ); ?></label></p>
				</div>

				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_has_parts" value="yes" data-b2bcp-toggle="#b2bcp-parts" <?php checked( $config['has_parts'] ); ?>> <strong><?php esc_html_e( 'This product has parts', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'e.g. a desk bar = base + name magnet, or a welcome pack = badge + desk bar + door plate', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-parts">
					<table class="widefat b2bcp-repeater b2bcp-parts" data-name="b2bcp_parts">
						<thead><tr>
							<th><?php esc_html_e( 'Part', 'b2b-contract-portal' ); ?></th>
							<th><?php esc_html_e( 'Store product (optional)', 'b2b-contract-portal' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'b2b-contract-portal' ); ?></th>
							<th><?php esc_html_e( 'Can be ordered alone', 'b2b-contract-portal' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
							<?php
							foreach ( $config['parts'] as $i => $part ) {
								self::part_row( (string) $i, $part );
							}
							?>
						</tbody>
						<tfoot><tr><td colspan="5"><button type="button" class="button b2bcp-add-row"><?php esc_html_e( '+ Add part', 'b2b-contract-portal' ); ?></button></td></tr></tfoot>
					</table>
					<script type="text/html" class="b2bcp-row-template">
						<?php
						self::part_row(
							'__i__',
							array(
								'product_id' => 0,
								'name'       => '',
								'qty'        => 1,
								'alone'      => false,
							)
						);
						?>
					</script>
					<ul class="description">
						<li><?php esc_html_e( 'Name only (e.g. "Base"): the part is for production and appears on the production sheet with this product\'s personalization.', 'b2b-contract-portal' ); ?></li>
						<li><?php esc_html_e( 'Linked to a store product (e.g. the name magnet): it can also be ordered alone as a replacement, at that product\'s price (give it contract prices too). Its personalization comes from that product. You can hide it from the shop in its Catalog visibility.', 'b2b-contract-portal' ); ?></li>
						<li><?php esc_html_e( 'The complete product is charged at this product\'s price.', 'b2b-contract-portal' ); ?></li>
					</ul>
				</div>

				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_multiaddress" value="yes" data-b2bcp-toggle="#b2bcp-multi" <?php checked( $config['multiaddress'] ); ?>> <strong><?php esc_html_e( 'Ship to several addresses', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'buyers split quantities across their company\'s branches in one order and one invoice', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-multi">
					<p class="b2bcp-row"><label for="b2bcp_min_per_address"><?php esc_html_e( 'Minimum units per address', 'b2b-contract-portal' ); ?></label>
					<input type="number" min="0" class="short" id="b2bcp_min_per_address" name="b2bcp_min_per_address" value="<?php echo $config['min_per_address'] ? (int) $config['min_per_address'] : ''; ?>"></p>
				</div>
			</div>

			<div class="options_group">
				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_group" value="yes" data-b2bcp-toggle="#b2bcp-group" <?php checked( $config['group'] ); ?>> <strong><?php esc_html_e( 'Order page for several products', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'e.g. a desk bar page where each name picks "insert only" or "desk bar + insert", plus extra items by quantity', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-group">
					<p class="b2bcp-row">
						<label for="b2bcp_group_named"><strong><?php esc_html_e( 'Products with names', 'b2b-contract-portal' ); ?></strong></label><br>
						<select id="b2bcp_group_named" class="wc-product-search" multiple="multiple" style="width:100%" name="b2bcp_group_named[]" data-placeholder="<?php esc_attr_e( 'Search for products…', 'b2b-contract-portal' ); ?>" data-action="woocommerce_json_search_products">
							<?php foreach ( $config['group_named'] as $id ) : ?>
								<?php $item = wc_get_product( $id ); ?>
								<?php if ( $item ) : ?>
									<option value="<?php echo (int) $id; ?>" selected><?php echo esc_html( wp_strip_all_tags( $item->get_formatted_name() ) ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<span class="description"><?php esc_html_e( 'Each name row picks one of these. The row asks for this product\'s personalization fields above (and each chosen product\'s own fields, if it has them).', 'b2b-contract-portal' ); ?></span>
					</p>
					<p class="b2bcp-row">
						<label for="b2bcp_group_qty"><strong><?php esc_html_e( 'Products by quantity', 'b2b-contract-portal' ); ?></strong></label><br>
						<select id="b2bcp_group_qty" class="wc-product-search" multiple="multiple" style="width:100%" name="b2bcp_group_qty[]" data-placeholder="<?php esc_attr_e( 'Search for products or variations…', 'b2b-contract-portal' ); ?>" data-action="woocommerce_json_search_products_and_variations">
							<?php foreach ( $config['group_qty'] as $id ) : ?>
								<?php $item = wc_get_product( $id ); ?>
								<?php if ( $item ) : ?>
									<option value="<?php echo (int) $id; ?>" selected><?php echo esc_html( wp_strip_all_tags( $item->get_formatted_name() ) ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<span class="description"><?php esc_html_e( 'Shown as cards with − / + (no names). Pick variations to show e.g. "one-sided" and "two-sided" as separate cards. Products that need names cannot go here.', 'b2b-contract-portal' ); ?></span>
					</p>
					<p class="b2bcp-row">
						<label for="b2bcp_group_quote"><strong><?php esc_html_e( 'Custom version (optional)', 'b2b-contract-portal' ); ?></strong></label><br>
						<select id="b2bcp_group_quote" class="wc-product-search" style="width:100%" name="b2bcp_group_quote" data-placeholder="<?php esc_attr_e( 'A quote-request product…', 'b2b-contract-portal' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
							<?php $quote_product = $config['group_quote'] ? wc_get_product( $config['group_quote'] ) : null; ?>
							<?php if ( $quote_product ) : ?>
								<option value="<?php echo (int) $quote_product->get_id(); ?>" selected><?php echo esc_html( wp_strip_all_tags( $quote_product->get_formatted_name() ) ); ?></option>
							<?php endif; ?>
						</select>
						<span class="description"><?php esc_html_e( 'Adds a choice at the top: "Standard order" (this page) or "Custom quote request" (opens that product\'s quote form).', 'b2b-contract-portal' ); ?></span>
					</p>
					<p class="description"><?php esc_html_e( 'Each product ordered here goes into the cart as its own line, at its own (contract) price. This product itself is never added to the cart and does not need a price.', 'b2b-contract-portal' ); ?></p>
				</div>
			</div>

			<div class="options_group">
				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_quote" value="yes" data-b2bcp-toggle="#b2bcp-quote" <?php checked( $config['quote'] ); ?>> <strong><?php esc_html_e( 'Quote request only', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'no price or cart; visitors fill a form and the request comes to you', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-quote">
					<?php B2BCP_Fields::render_builder( 'b2bcp_quote_fields', $config['quote_fields'], 'quote' ); ?>
					<p class="description"><?php esc_html_e( 'Contact fields (name, company, email, phone) are always included. Use "Exclusive" above to offer the quote form only to certain companies.', 'b2b-contract-portal' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Company/price row.
	 *
	 * @param string     $i          Index.
	 * @param array      $companies  All companies.
	 * @param int        $company_id Selected.
	 * @param float|null $price      Price.
	 */
	private static function company_row( $i, array $companies, $company_id, $price ) {
		$base = 'b2bcp_companies[' . $i . ']';
		?>
		<tr>
			<td>
				<select name="<?php echo esc_attr( $base ); ?>[company_id]">
					<option value=""><?php esc_html_e( 'Choose company…', 'b2b-contract-portal' ); ?></option>
					<?php foreach ( $companies as $id => $name ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $company_id, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="short wc_input_price" name="<?php echo esc_attr( $base ); ?>[price]" placeholder="<?php esc_attr_e( 'Regular', 'b2b-contract-portal' ); ?>" value="<?php echo null === $price ? '' : esc_attr( wc_format_localized_price( (string) $price ) ); ?>"></td>
			<td><button type="button" class="button-link b2bcp-remove-row" aria-label="<?php esc_attr_e( 'Remove', 'b2b-contract-portal' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Part row.
	 *
	 * @param string $i    Index.
	 * @param array  $part Part.
	 */
	private static function part_row( $i, array $part ) {
		$base    = 'b2bcp_parts[' . $i . ']';
		$product = $part['product_id'] ? wc_get_product( (int) $part['product_id'] ) : null;
		?>
		<tr>
			<td><input type="text" name="<?php echo esc_attr( $base ); ?>[name]" value="<?php echo esc_attr( $part['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Base', 'b2b-contract-portal' ); ?>"></td>
			<td style="width:40%">
				<select class="wc-product-search" style="width:100%" name="<?php echo esc_attr( $base ); ?>[product_id]" data-placeholder="<?php esc_attr_e( 'None (production only)', 'b2b-contract-portal' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
					<?php if ( $product ) : ?>
						<option value="<?php echo (int) $product->get_id(); ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				</select>
			</td>
			<td><input type="number" min="1" class="small-text" name="<?php echo esc_attr( $base ); ?>[qty]" value="<?php echo (int) max( 1, $part['qty'] ); ?>"></td>
			<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[alone]" value="1" <?php checked( $part['alone'] ); ?> aria-label="<?php esc_attr_e( 'Can be ordered alone', 'b2b-contract-portal' ); ?>"></td>
			<td><button type="button" class="button-link b2bcp-remove-row" aria-label="<?php esc_attr_e( 'Remove', 'b2b-contract-portal' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Save the tab.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save( $product ) {
		if ( ! isset( $_POST['b2bcp_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2bcp_product_nonce'] ) ), 'b2bcp_save_product' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- each value is sanitized below.
		$id = $product->get_id();

		$product->update_meta_data( '_b2bcp_template', isset( $_POST['b2bcp_template'] ) && ! (int) $product->get_meta( '_b2bcp_owner_company' ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_visibility', isset( $_POST['b2bcp_visibility'] ) && 'restricted' === $_POST['b2bcp_visibility'] ? 'restricted' : 'public' );
		$product->update_meta_data( '_b2bcp_personalized', isset( $_POST['b2bcp_personalized'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_preview', isset( $_POST['b2bcp_preview'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_fields', B2BCP_Fields::sanitize_definitions( $_POST['b2bcp_fields'] ?? array(), 'person' ) );
		$product->update_meta_data( '_b2bcp_multiaddress', isset( $_POST['b2bcp_multiaddress'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_min_per_address', absint( $_POST['b2bcp_min_per_address'] ?? 0 ) );
		$product->update_meta_data( '_b2bcp_has_parts', isset( $_POST['b2bcp_has_parts'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_quote', isset( $_POST['b2bcp_quote'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_group', isset( $_POST['b2bcp_group'] ) ? 'yes' : 'no' );
		$named = array();
		foreach ( (array) ( $_POST['b2bcp_group_named'] ?? array() ) as $item_id ) {
			$item_id = absint( $item_id );
			if ( $item_id && $item_id !== $id && wc_get_product( $item_id ) ) {
				$named[] = $item_id;
			}
		}
		$by_qty = array();
		foreach ( (array) ( $_POST['b2bcp_group_qty'] ?? array() ) as $item_id ) {
			$item_id = absint( $item_id );
			$item    = $item_id && $item_id !== $id ? wc_get_product( $item_id ) : null;
			// Products that need names or a form of their own cannot be ordered by a plain quantity.
			if ( $item && ! $item->is_type( 'variable' ) && ! b2bcp_uses_order_form( b2bcp_product_config( $item ) ) ) {
				$by_qty[] = $item_id;
			}
		}
		$product->update_meta_data( '_b2bcp_group_named', array_values( array_unique( $named ) ) );
		$product->update_meta_data( '_b2bcp_group_qty', array_values( array_unique( $by_qty ) ) );
		$product->update_meta_data( '_b2bcp_group_quote', absint( $_POST['b2bcp_group_quote'] ?? 0 ) );
		$product->update_meta_data( '_b2bcp_quote_fields', B2BCP_Fields::sanitize_definitions( $_POST['b2bcp_quote_fields'] ?? array(), 'quote' ) );

		$parts = array();
		foreach ( (array) ( $_POST['b2bcp_parts'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$parts[] = array(
				'product_id' => absint( $row['product_id'] ?? 0 ),
				'name'       => sanitize_text_field( wp_unslash( $row['name'] ?? '' ) ),
				'qty'        => absint( $row['qty'] ?? 1 ),
				'alone'      => ! empty( $row['alone'] ),
			);
		}
		$product->update_meta_data( '_b2bcp_parts', b2bcp_normalize_parts( $parts, $id ) );

		if ( isset( $_POST['b2bcp_companies'] ) || isset( $_POST['b2bcp_visibility'] ) ) {
			$rows = array();
			foreach ( (array) ( $_POST['b2bcp_companies'] ?? array() ) as $row ) {
				$company_id = absint( $row['company_id'] ?? 0 );
				if ( $company_id && b2bcp_is_company( $company_id ) ) {
					$rows[ $company_id ] = b2bcp_parse_price( wp_unslash( $row['price'] ?? '' ) );
				}
			}
			b2bcp_replace_assignments( 'product', $id, $rows );
		}
		// phpcs:enable
	}

	/**
	 * Product list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$columns['b2bcp'] = __( 'B2B', 'b2b-contract-portal' );
		return $columns;
	}

	/**
	 * Product list column value.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Product.
	 */
	public static function column( $column, $post_id ) {
		if ( 'b2bcp' !== $column ) {
			return;
		}
		$config = b2bcp_product_config( $post_id );
		$tags   = array();
		if ( $config['template'] ) {
			$tags[] = __( 'Template', 'b2b-contract-portal' );
		}
		if ( $config['owner_company'] ) {
			/* translators: %s: company name */
			$tags[] = sprintf( __( 'Made for %s', 'b2b-contract-portal' ), get_the_title( $config['owner_company'] ) );
		}
		if ( 'restricted' === $config['visibility'] && ! $config['template'] && ! $config['owner_company'] ) {
			/* translators: %d: number of companies */
			$tags[] = sprintf( _n( 'Exclusive (%d company)', 'Exclusive (%d companies)', count( b2bcp_product_assignments( $post_id ) ), 'b2b-contract-portal' ), count( b2bcp_product_assignments( $post_id ) ) );
		}
		if ( $config['group'] ) {
			$tags[] = __( 'Order page', 'b2b-contract-portal' );
		}
		if ( $config['has_parts'] ) {
			/* translators: %d: number of parts */
			$tags[] = sprintf( _n( '%d part', '%d parts', count( $config['parts'] ), 'b2b-contract-portal' ), count( $config['parts'] ) );
		}
		if ( $config['personalized'] ) {
			$tags[] = __( 'Personalized', 'b2b-contract-portal' );
		}
		if ( $config['multiaddress'] ) {
			$tags[] = __( 'Multi-address', 'b2b-contract-portal' );
		}
		if ( $config['quote'] ) {
			$tags[] = __( 'Quote', 'b2b-contract-portal' );
		}
		echo $tags ? esc_html( implode( ' · ', $tags ) ) : '&ndash;';
	}
}
