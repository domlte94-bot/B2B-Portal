<?php
/**
 * Data helpers: companies, users, prices, addresses, product settings.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

const B2BCP_COMPANY_CPT = 'b2bcp_company';
const B2BCP_QUOTE_CPT   = 'b2bcp_quote';

/* -------------------------------------------------------------------------
 * Request cache (cleared whenever assignments or product settings change)
 * ---------------------------------------------------------------------- */

/**
 * Current cache generation.
 *
 * @return int
 */
function b2bcp_cache_gen() {
	return isset( $GLOBALS['b2bcp_cache_gen'] ) ? (int) $GLOBALS['b2bcp_cache_gen'] : 0;
}

/**
 * Invalidate the in-request caches.
 */
function b2bcp_cache_bust() {
	$GLOBALS['b2bcp_cache_gen'] = b2bcp_cache_gen() + 1;
}

/* -------------------------------------------------------------------------
 * Companies
 * ---------------------------------------------------------------------- */

/**
 * All published companies, id => name, sorted by name.
 *
 * @return array<int,string>
 */
function b2bcp_get_companies() {
	$posts = get_posts(
		array(
			'post_type'      => B2BCP_COMPANY_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		)
	);
	$out   = array();
	foreach ( $posts as $id ) {
		$out[ (int) $id ] = get_the_title( $id );
	}
	return $out;
}

/**
 * Whether an id is a published company.
 *
 * @param int $company_id Company post id.
 * @return bool
 */
function b2bcp_is_company( $company_id ) {
	$post = get_post( (int) $company_id );
	return $post && B2BCP_COMPANY_CPT === $post->post_type && 'publish' === $post->post_status;
}

/**
 * Company record with all settings.
 *
 * @param int $company_id Company post id.
 * @return array|null
 */
function b2bcp_get_company( $company_id ) {
	$company_id = (int) $company_id;
	if ( ! $company_id || ! b2bcp_is_company( $company_id ) ) {
		return null;
	}
	$billing = get_post_meta( $company_id, '_b2bcp_billing', true );
	$color   = b2bcp_sanitize_hex( get_post_meta( $company_id, '_b2bcp_color', true ) );
	$payment = get_post_meta( $company_id, '_b2bcp_payment', true );

	return array(
		'id'                => $company_id,
		'name'              => get_the_title( $company_id ),
		'billing'           => is_array( $billing ) ? $billing : array(),
		'payment'           => in_array( $payment, array( 'card', 'invoice', 'both' ), true ) ? $payment : 'card',
		'terms'             => (string) get_post_meta( $company_id, '_b2bcp_terms', true ),
		'shipping_included' => 'yes' === get_post_meta( $company_id, '_b2bcp_shipping_included', true ),
		'notify_email'      => (string) get_post_meta( $company_id, '_b2bcp_notify_email', true ),
		'logo_id'           => (int) get_post_meta( $company_id, '_b2bcp_logo_id', true ),
		'color'             => $color,
		'contact_name'      => (string) get_post_meta( $company_id, '_b2bcp_contact_name', true ),
		'contact_email'     => (string) get_post_meta( $company_id, '_b2bcp_contact_email', true ),
		'contact_phone'     => (string) get_post_meta( $company_id, '_b2bcp_contact_phone', true ),
	);
}

/**
 * Billing fields a company can store, in WooCommerce field names (without the billing_ prefix).
 *
 * @return array<string,string> key => label
 */
function b2bcp_billing_keys() {
	return array(
		'company'   => __( 'Billing name / department', 'b2b-contract-portal' ),
		'address_1' => __( 'Address line 1', 'b2b-contract-portal' ),
		'address_2' => __( 'Address line 2', 'b2b-contract-portal' ),
		'city'      => __( 'City', 'b2b-contract-portal' ),
		'state'     => __( 'State', 'b2b-contract-portal' ),
		'postcode'  => __( 'ZIP / Postcode', 'b2b-contract-portal' ),
		'country'   => __( 'Country code (US, CA, MX…)', 'b2b-contract-portal' ),
		'email'     => __( 'Billing email (invoices)', 'b2b-contract-portal' ),
		'phone'     => __( 'Billing phone', 'b2b-contract-portal' ),
	);
}

/* -------------------------------------------------------------------------
 * Users ↔ companies
 * ---------------------------------------------------------------------- */

/**
 * Companies a user belongs to (published only).
 *
 * @param int $user_id User id.
 * @return int[]
 */
function b2bcp_user_companies( $user_id ) {
	$ids = get_user_meta( (int) $user_id, '_b2bcp_companies', true );
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	return array_values( array_filter( array_unique( $ids ), 'b2bcp_is_company' ) );
}

/**
 * Whether a user manages a company (company admin role).
 *
 * @param int $user_id    User id.
 * @param int $company_id Company id.
 * @return bool
 */
function b2bcp_user_is_company_admin( $user_id, $company_id ) {
	$ids = get_user_meta( (int) $user_id, '_b2bcp_company_admin', true );
	return is_array( $ids ) && in_array( (int) $company_id, array_map( 'intval', $ids ), true );
}

/**
 * Link a user to a company.
 *
 * @param int  $user_id    User id.
 * @param int  $company_id Company id.
 * @param bool $is_admin   Company admin flag.
 */
function b2bcp_add_user_to_company( $user_id, $company_id, $is_admin = false ) {
	$ids   = get_user_meta( $user_id, '_b2bcp_companies', true );
	$ids   = is_array( $ids ) ? $ids : array();
	$ids[] = (int) $company_id;
	update_user_meta( $user_id, '_b2bcp_companies', array_values( array_unique( array_map( 'intval', $ids ) ) ) );
	b2bcp_set_company_admin( $user_id, $company_id, $is_admin );
}

/**
 * Remove a user from a company.
 *
 * @param int $user_id    User id.
 * @param int $company_id Company id.
 */
function b2bcp_remove_user_from_company( $user_id, $company_id ) {
	$ids = get_user_meta( $user_id, '_b2bcp_companies', true );
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	update_user_meta( $user_id, '_b2bcp_companies', array_values( array_diff( $ids, array( (int) $company_id ) ) ) );
	b2bcp_set_company_admin( $user_id, $company_id, false );
	if ( (int) get_user_meta( $user_id, '_b2bcp_active_company', true ) === (int) $company_id ) {
		delete_user_meta( $user_id, '_b2bcp_active_company' );
	}
}

/**
 * Set or clear the company-admin flag.
 *
 * @param int  $user_id    User id.
 * @param int  $company_id Company id.
 * @param bool $is_admin   Flag.
 */
function b2bcp_set_company_admin( $user_id, $company_id, $is_admin ) {
	$ids = get_user_meta( $user_id, '_b2bcp_company_admin', true );
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	$ids = array_diff( $ids, array( (int) $company_id ) );
	if ( $is_admin ) {
		$ids[] = (int) $company_id;
	}
	update_user_meta( $user_id, '_b2bcp_company_admin', array_values( $ids ) );
}

/**
 * Users linked to a company.
 *
 * @param int $company_id Company id.
 * @return WP_User[]
 */
function b2bcp_company_users( $company_id ) {
	// Serialized arrays of ints: narrow with LIKE on the "i:<id>;" token, then confirm in PHP
	// (array indexes are serialized the same way, so LIKE alone can over-match).
	$candidates = get_users(
		array(
			'meta_key'     => '_b2bcp_companies', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'   => sprintf( 'i:%d;', (int) $company_id ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_compare' => 'LIKE',
			'orderby'      => 'display_name',
		)
	);
	return array_values(
		array_filter(
			$candidates,
			function ( $user ) use ( $company_id ) {
				return in_array( (int) $company_id, b2bcp_user_companies( $user->ID ), true );
			}
		)
	);
}

/**
 * The company the current shopper is buying for, or 0.
 *
 * @return int
 */
function b2bcp_active_company_id() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return 0;
	}
	// User meta is cached by WordPress, so this stays cheap and follows logins and switches.
	$companies = b2bcp_user_companies( $user_id );
	if ( ! $companies ) {
		return 0;
	}
	$active = (int) get_user_meta( $user_id, '_b2bcp_active_company', true );
	return in_array( $active, $companies, true ) ? $active : $companies[0];
}

/* -------------------------------------------------------------------------
 * Contract prices / product assignment
 * ---------------------------------------------------------------------- */

/**
 * Assignment rows for a company: product_id => price (null = use the product's own price).
 *
 * @param int $company_id Company id.
 * @return array<int,float|null>
 */
function b2bcp_company_assignments( $company_id ) {
	global $wpdb;
	static $cache = array();
	$company_id = (int) $company_id;
	$key        = b2bcp_cache_gen() . ':' . $company_id;
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, price FROM {$wpdb->prefix}b2bcp_prices WHERE company_id = %d", $company_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row->product_id ] = null === $row->price ? null : (float) $row->price;
	}
	$cache[ $key ] = $out;
	return $out;
}

/**
 * Assignment rows for a product: company_id => price.
 *
 * @param int $product_id Product id.
 * @return array<int,float|null>
 */
function b2bcp_product_assignments( $product_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT company_id, price FROM {$wpdb->prefix}b2bcp_prices WHERE product_id = %d", (int) $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row->company_id ] = null === $row->price ? null : (float) $row->price;
	}
	return $out;
}

/**
 * Replace every assignment for one side (all products of a company, or all companies of a product).
 *
 * @param string $side  'company' or 'product'.
 * @param int    $id    The fixed id.
 * @param array  $rows  other_id => price|null.
 */
function b2bcp_replace_assignments( $side, $id, array $rows ) {
	global $wpdb;
	$table = $wpdb->prefix . 'b2bcp_prices';
	$col   = 'company' === $side ? 'company_id' : 'product_id';
	$other = 'company' === $side ? 'product_id' : 'company_id';

	b2bcp_cache_bust();
	$wpdb->delete( $table, array( $col => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rows as $other_id => $price ) {
		$other_id = (int) $other_id;
		if ( ! $other_id ) {
			continue;
		}
		$data = array(
			$col   => (int) $id,
			$other => $other_id,
			'price' => null === $price ? null : (float) $price,
		);
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Parse a price typed in an admin field: '' means "use the product price".
 *
 * @param mixed $raw Raw input.
 * @return float|null
 */
function b2bcp_parse_price( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}
	$value = wc_format_decimal( $raw );
	return '' === $value ? null : max( 0, (float) $value );
}

/* -------------------------------------------------------------------------
 * Product settings
 * ---------------------------------------------------------------------- */

/**
 * Normalized plugin settings for a product.
 *
 * @param int|WC_Product $product Product or id.
 * @return array
 */
function b2bcp_product_config( $product ) {
	$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
	if ( $product instanceof WC_Product && $product->is_type( 'variation' ) ) {
		$id = $product->get_parent_id();
	}
	static $cache = array();
	$key = b2bcp_cache_gen() . ':' . $id;
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$fields       = get_post_meta( $id, '_b2bcp_fields', true );
	$quote_fields = get_post_meta( $id, '_b2bcp_quote_fields', true );
	$kit_items    = get_post_meta( $id, '_b2bcp_kit_items', true );
	$visibility   = get_post_meta( $id, '_b2bcp_visibility', true );

	$config = array(
		'id'                => $id,
		'visibility'        => 'restricted' === $visibility ? 'restricted' : 'public',
		'personalized'      => 'yes' === get_post_meta( $id, '_b2bcp_personalized', true ),
		'fields'            => is_array( $fields ) ? $fields : array(),
		'multiaddress'      => 'yes' === get_post_meta( $id, '_b2bcp_multiaddress', true ),
		'min_per_address'   => max( 0, (int) get_post_meta( $id, '_b2bcp_min_per_address', true ) ),
		'kit'               => 'yes' === get_post_meta( $id, '_b2bcp_kit', true ),
		'kit_mode'          => 'full_or_pieces' === get_post_meta( $id, '_b2bcp_kit_mode', true ) ? 'full_or_pieces' : 'full',
		'kit_items'         => is_array( $kit_items ) ? $kit_items : array(),
		'quote'             => 'yes' === get_post_meta( $id, '_b2bcp_quote', true ),
		'quote_fields'      => is_array( $quote_fields ) ? $quote_fields : array(),
	);

	$cache[ $key ] = $config;
	return $config;
}

/**
 * Whether a product needs the plugin's order form on the product page.
 *
 * @param array $config Product config.
 * @return bool
 */
function b2bcp_uses_order_form( array $config ) {
	return ! $config['quote'] && ( $config['personalized'] || $config['multiaddress'] || $config['kit'] || b2bcp_kit_is_personalized( $config ) );
}

/**
 * Whether any piece of a kit is personalized.
 *
 * @param array $config Kit config.
 * @return bool
 */
function b2bcp_kit_is_personalized( array $config ) {
	if ( ! $config['kit'] ) {
		return false;
	}
	foreach ( $config['kit_items'] as $item ) {
		$piece = b2bcp_product_config( (int) $item['product_id'] );
		if ( $piece['personalized'] && $piece['fields'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Personalization fields shown when ordering a product.
 * For a kit: the kit's own fields plus every piece's fields, merged by key,
 * so a name typed once fills every piece that uses "name".
 *
 * @param array $config Product config.
 * @return array
 */
function b2bcp_person_fields( array $config ) {
	$fields = array();
	if ( $config['personalized'] ) {
		foreach ( $config['fields'] as $field ) {
			$fields[ $field['key'] ] = $field;
		}
	}
	if ( $config['kit'] ) {
		foreach ( $config['kit_items'] as $item ) {
			$piece = b2bcp_product_config( (int) $item['product_id'] );
			if ( ! $piece['personalized'] ) {
				continue;
			}
			foreach ( $piece['fields'] as $field ) {
				if ( ! isset( $fields[ $field['key'] ] ) ) {
					$fields[ $field['key'] ] = $field;
				}
			}
		}
	}
	return array_values( $fields );
}

/**
 * Whether a company may see and buy a product.
 * Public products: everyone. Restricted: companies it is assigned to,
 * plus pieces of a kit assigned to that company.
 *
 * @param int $product_id Product id (parent id for variations).
 * @param int $company_id Company id (0 = guest / no company).
 * @return bool
 */
function b2bcp_company_can_access( $product_id, $company_id ) {
	$config = b2bcp_product_config( (int) $product_id );
	if ( 'public' === $config['visibility'] ) {
		return true;
	}
	if ( ! $company_id ) {
		return false;
	}
	$assigned = b2bcp_company_assignments( $company_id );
	if ( array_key_exists( (int) $product_id, $assigned ) ) {
		return true;
	}
	foreach ( array_keys( $assigned ) as $assigned_id ) {
		$kit = b2bcp_product_config( $assigned_id );
		if ( $kit['kit'] && 'full_or_pieces' === $kit['kit_mode'] ) {
			foreach ( $kit['kit_items'] as $item ) {
				if ( (int) $item['product_id'] === (int) $product_id ) {
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * Ids of restricted products the given company cannot see (for catalog queries).
 *
 * @param int $company_id Company id.
 * @return int[]
 */
function b2bcp_hidden_product_ids( $company_id ) {
	static $cache = array();
	$key = b2bcp_cache_gen() . ':' . (int) $company_id;
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$restricted = get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => '_b2bcp_visibility', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => 'restricted', // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => true,
			'b2bcp_skip'       => true, // Not filtered by our own catalog rules.
		)
	);
	$hidden = array();
	foreach ( $restricted as $id ) {
		if ( ! b2bcp_company_can_access( (int) $id, $company_id ) ) {
			$hidden[] = (int) $id;
		}
	}
	$cache[ $key ] = $hidden;
	return $hidden;
}

/* -------------------------------------------------------------------------
 * Addresses (company branches)
 * ---------------------------------------------------------------------- */

/**
 * Address fields stored for each branch.
 *
 * @return array<string,string>
 */
function b2bcp_address_keys() {
	return array(
		'label'     => __( 'Branch name', 'b2b-contract-portal' ),
		'code'      => __( 'Branch #', 'b2b-contract-portal' ),
		'attention' => __( 'Attention', 'b2b-contract-portal' ),
		'address_1' => __( 'Address', 'b2b-contract-portal' ),
		'address_2' => __( 'Address 2', 'b2b-contract-portal' ),
		'city'      => __( 'City', 'b2b-contract-portal' ),
		'state'     => __( 'State', 'b2b-contract-portal' ),
		'postcode'  => __( 'ZIP', 'b2b-contract-portal' ),
		'country'   => __( 'Country', 'b2b-contract-portal' ),
		'phone'     => __( 'Phone', 'b2b-contract-portal' ),
	);
}

/**
 * All branches of a company.
 *
 * @param int $company_id Company id.
 * @return array[] Each row has id + address keys.
 */
function b2bcp_get_addresses( $company_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}b2bcp_addresses WHERE company_id = %d ORDER BY label ASC, id ASC", (int) $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return is_array( $rows ) ? $rows : array();
}

/**
 * One branch.
 *
 * @param int $address_id Address id.
 * @return array|null
 */
function b2bcp_get_address( $address_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}b2bcp_addresses WHERE id = %d", (int) $address_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $row ? $row : null;
}

/**
 * Sanitize a posted branch row.
 *
 * @param array $raw Raw values.
 * @return array
 */
function b2bcp_sanitize_address( array $raw ) {
	$clean = array();
	foreach ( array_keys( b2bcp_address_keys() ) as $key ) {
		$clean[ $key ] = isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '';
	}
	$clean['country'] = strtoupper( substr( $clean['country'], 0, 2 ) );
	if ( '' === $clean['country'] ) {
		$clean['country'] = WC()->countries->get_base_country();
	}
	return $clean;
}

/**
 * Insert or update a branch.
 *
 * @param int   $company_id Company id.
 * @param array $data       Sanitized fields.
 * @param int   $address_id Existing id to update, or 0.
 * @return int Address id.
 */
function b2bcp_save_address( $company_id, array $data, $address_id = 0 ) {
	global $wpdb;
	$table              = $wpdb->prefix . 'b2bcp_addresses';
	$data['company_id'] = (int) $company_id;
	if ( $address_id ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $address_id, 'company_id' => (int) $company_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $address_id;
	}
	$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->insert_id;
}

/**
 * Delete a branch of a company.
 *
 * @param int $company_id Company id.
 * @param int $address_id Address id.
 */
function b2bcp_delete_address( $company_id, $address_id ) {
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'b2bcp_addresses', array( 'id' => (int) $address_id, 'company_id' => (int) $company_id ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Short one-line label: "Downtown (#014)".
 *
 * @param array $address Address row.
 * @return string
 */
function b2bcp_address_label( array $address ) {
	$label = $address['label'];
	if ( '' !== $address['code'] ) {
		$label .= ' (#' . $address['code'] . ')';
	}
	return $label;
}

/**
 * Full single-line address.
 *
 * @param array $address Address row.
 * @return string
 */
function b2bcp_address_line( array $address ) {
	$parts = array_filter(
		array(
			$address['attention'] ? 'Attn: ' . $address['attention'] : '',
			$address['address_1'],
			$address['address_2'],
			trim( $address['city'] . ', ' . $address['state'] . ' ' . $address['postcode'], ', ' ),
			$address['country'],
		)
	);
	return implode( ', ', $parts );
}

/* -------------------------------------------------------------------------
 * Colors
 * ---------------------------------------------------------------------- */

/**
 * Keep a #rrggbb color or return ''.
 *
 * @param mixed $color Input.
 * @return string
 */
function b2bcp_sanitize_hex( $color ) {
	$color = sanitize_hex_color( (string) $color );
	if ( ! $color ) {
		return '';
	}
	if ( 4 === strlen( $color ) ) {
		$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
	}
	return strtolower( $color );
}

/**
 * WCAG relative luminance of a #rrggbb color.
 *
 * @param string $hex Color.
 * @return float
 */
function b2bcp_luminance( $hex ) {
	$hex = ltrim( $hex, '#' );
	$rgb = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	foreach ( $rgb as $i => $c ) {
		$c         = $c / 255;
		$rgb[ $i ] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
}

/**
 * Contrast ratio between two colors.
 *
 * @param string $a Color.
 * @param string $b Color.
 * @return float
 */
function b2bcp_contrast( $a, $b ) {
	$la = b2bcp_luminance( $a );
	$lb = b2bcp_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/**
 * Black or white, whichever reads better on the given background.
 *
 * @param string $bg Background color.
 * @return string
 */
function b2bcp_text_on( $bg ) {
	return b2bcp_contrast( $bg, '#ffffff' ) >= b2bcp_contrast( $bg, '#111111' ) ? '#ffffff' : '#111111';
}

/**
 * Clear caches when a product's plugin settings change.
 *
 * @param int    $meta_id  Meta id.
 * @param int    $object_id Post id.
 * @param string $meta_key Key.
 */
function b2bcp_maybe_bust_on_meta( $meta_id, $object_id, $meta_key ) {
	if ( 0 === strpos( (string) $meta_key, '_b2bcp_' ) ) {
		b2bcp_cache_bust();
	}
}
add_action( 'added_post_meta', 'b2bcp_maybe_bust_on_meta', 10, 3 );
add_action( 'updated_post_meta', 'b2bcp_maybe_bust_on_meta', 10, 3 );
add_action( 'deleted_post_meta', 'b2bcp_maybe_bust_on_meta', 10, 3 );
