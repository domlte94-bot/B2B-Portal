<?php
/**
 * "B2B Portal" tab on the WooCommerce product screen.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-product settings: visibility, company prices, personalization, kits, multi-address, quotes.
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
					<p class="description"><?php esc_html_e( 'Fields with the same label (e.g. "Name") are shared across the pieces of a kit, so buyers type each name once.', 'b2b-contract-portal' ); ?></p>
				</div>

				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_kit" value="yes" data-b2bcp-toggle="#b2bcp-kit" <?php checked( $config['kit'] ); ?>> <strong><?php esc_html_e( 'Kit', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'this product groups several pieces', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-kit">
					<table class="widefat b2bcp-repeater" data-name="b2bcp_kit_items">
						<thead><tr><th><?php esc_html_e( 'Piece (product)', 'b2b-contract-portal' ); ?></th><th><?php esc_html_e( 'Qty in kit', 'b2b-contract-portal' ); ?></th><th></th></tr></thead>
						<tbody>
							<?php
							foreach ( $config['kit_items'] as $i => $item ) {
								self::kit_row( (string) $i, (int) $item['product_id'], (int) $item['qty'] );
							}
							?>
						</tbody>
						<tfoot><tr><td colspan="3"><button type="button" class="button b2bcp-add-row"><?php esc_html_e( '+ Add piece', 'b2b-contract-portal' ); ?></button></td></tr></tfoot>
					</table>
					<script type="text/html" class="b2bcp-row-template"><?php self::kit_row( '__i__', 0, 1 ); ?></script>
					<p class="b2bcp-kit-mode">
						<label><input type="radio" name="b2bcp_kit_mode" value="full" <?php checked( $config['kit_mode'], 'full' ); ?>> <?php esc_html_e( 'Full kit only', 'b2b-contract-portal' ); ?></label>
						<label><input type="radio" name="b2bcp_kit_mode" value="full_or_pieces" <?php checked( $config['kit_mode'], 'full_or_pieces' ); ?>> <?php esc_html_e( 'Full kit or loose pieces', 'b2b-contract-portal' ); ?></label>
					</p>
					<p class="description"><?php esc_html_e( 'Full kit price = this product\'s price (or the company\'s contract price for it). Loose pieces are charged at each piece\'s own price, so give pieces their contract prices too. Each piece\'s personalization comes from that piece\'s own settings.', 'b2b-contract-portal' ); ?></p>
				</div>

				<p class="b2bcp-row"><label class="b2bcp-toggle"><input type="checkbox" name="b2bcp_multiaddress" value="yes" data-b2bcp-toggle="#b2bcp-multi" <?php checked( $config['multiaddress'] ); ?>> <strong><?php esc_html_e( 'Ship to several addresses', 'b2b-contract-portal' ); ?></strong> &mdash; <?php esc_html_e( 'buyers split quantities across their company\'s branches in one order and one invoice', 'b2b-contract-portal' ); ?></label></p>
				<div class="b2bcp-inner" id="b2bcp-multi">
					<p class="b2bcp-row"><label for="b2bcp_min_per_address"><?php esc_html_e( 'Minimum units per address', 'b2b-contract-portal' ); ?></label>
					<input type="number" min="0" class="short" id="b2bcp_min_per_address" name="b2bcp_min_per_address" value="<?php echo $config['min_per_address'] ? (int) $config['min_per_address'] : ''; ?>"></p>
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
	 * Kit piece row.
	 *
	 * @param string $i          Index.
	 * @param int    $product_id Piece.
	 * @param int    $qty        Qty in kit.
	 */
	private static function kit_row( $i, $product_id, $qty ) {
		$base    = 'b2bcp_kit_items[' . $i . ']';
		$product = $product_id ? wc_get_product( $product_id ) : null;
		?>
		<tr>
			<td style="width:70%">
				<select class="wc-product-search" style="width:100%" name="<?php echo esc_attr( $base ); ?>[product_id]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'b2b-contract-portal' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
					<?php if ( $product ) : ?>
						<option value="<?php echo (int) $product_id; ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				</select>
			</td>
			<td><input type="number" min="1" class="small-text" name="<?php echo esc_attr( $base ); ?>[qty]" value="<?php echo (int) max( 1, $qty ); ?>"></td>
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

		$product->update_meta_data( '_b2bcp_visibility', isset( $_POST['b2bcp_visibility'] ) && 'restricted' === $_POST['b2bcp_visibility'] ? 'restricted' : 'public' );
		$product->update_meta_data( '_b2bcp_personalized', isset( $_POST['b2bcp_personalized'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_fields', B2BCP_Fields::sanitize_definitions( $_POST['b2bcp_fields'] ?? array(), 'person' ) );
		$product->update_meta_data( '_b2bcp_multiaddress', isset( $_POST['b2bcp_multiaddress'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_min_per_address', absint( $_POST['b2bcp_min_per_address'] ?? 0 ) );
		$product->update_meta_data( '_b2bcp_kit', isset( $_POST['b2bcp_kit'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_kit_mode', isset( $_POST['b2bcp_kit_mode'] ) && 'full_or_pieces' === $_POST['b2bcp_kit_mode'] ? 'full_or_pieces' : 'full' );
		$product->update_meta_data( '_b2bcp_quote', isset( $_POST['b2bcp_quote'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_b2bcp_quote_fields', B2BCP_Fields::sanitize_definitions( $_POST['b2bcp_quote_fields'] ?? array(), 'quote' ) );

		$items = array();
		foreach ( (array) ( $_POST['b2bcp_kit_items'] ?? array() ) as $row ) {
			$piece = absint( $row['product_id'] ?? 0 );
			if ( $piece && $piece !== $id && wc_get_product( $piece ) ) {
				$items[] = array(
					'product_id' => $piece,
					'qty'        => max( 1, absint( $row['qty'] ?? 1 ) ),
				);
			}
		}
		$product->update_meta_data( '_b2bcp_kit_items', $items );

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
		if ( 'restricted' === $config['visibility'] ) {
			/* translators: %d: number of companies */
			$tags[] = sprintf( _n( 'Exclusive (%d company)', 'Exclusive (%d companies)', count( b2bcp_product_assignments( $post_id ) ), 'b2b-contract-portal' ), count( b2bcp_product_assignments( $post_id ) ) );
		}
		if ( $config['kit'] ) {
			$tags[] = __( 'Kit', 'b2b-contract-portal' );
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
