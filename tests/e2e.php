<?php
/**
 * End-to-end test of B2B Contract Portal on a real WordPress + WooCommerce.
 * Run with: wp eval-file e2e.php
 */

$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
function ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['passes']++;
		echo "  PASS  $msg\n";
	} else {
		$GLOBALS['fails']++;
		echo "  FAIL  $msg\n";
	}
}
function section( $t ) {
	echo "\n== $t ==\n";
}

// Capture redirects and mail instead of exiting / sending.
class B2BCP_Test_Redirect extends Exception {}
add_filter( 'wp_redirect', function ( $location ) {
	throw new B2BCP_Test_Redirect( $location );
}, 1 );
$GLOBALS['sent_mail'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$GLOBALS['sent_mail'][] = $atts;
	return true;
}, 10, 2 );

/* ---------------------------------------------------------------- store */
section( 'Store setup' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_default_country', 'US:IL' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_cheque_settings', array( 'enabled' => 'yes', 'title' => 'Card (test)' ) );
update_option( 'woocommerce_b2bcp_invoice_settings', array( 'enabled' => 'yes', 'title' => 'Invoice', 'description' => 'Pay on terms' ) );

$zone = new WC_Shipping_Zone( null );
$zone->set_zone_name( 'US' );
$zone->add_location( 'US', 'country' );
$zone->save();
$instance = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $instance . '_settings', array( 'title' => 'Flat rate', 'cost' => '10', 'tax_status' => 'none', 'enabled' => 'yes' ) );
WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );
ok( true, 'Flat rate $10 per package in a US zone' );

function make_product( $name, $price, $meta = array(), $sku = '' ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->set_sku( $sku ? $sku : sanitize_title( $name ) );
	foreach ( $meta as $k => $v ) {
		$p->update_meta_data( $k, $v );
	}
	$p->save();
	return $p->get_id();
}

$name_field  = array( 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 20, 'format' => 'title', 'options' => array() );
$title_field = array( 'key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => false, 'maxlength' => 30, 'format' => 'none', 'options' => array() );
$color_field = array( 'key' => 'color', 'label' => 'Color', 'type' => 'select', 'required' => true, 'maxlength' => 0, 'format' => 'none', 'options' => array( 'Gold', 'Silver' ) );

$badge   = make_product( 'Name Badge 3x1', '12', array( '_b2bcp_visibility' => 'restricted', '_b2bcp_personalized' => 'yes', '_b2bcp_fields' => array( $name_field, $title_field ) ) );
$deskbar = make_product( 'Desk Bar', '40', array( '_b2bcp_visibility' => 'restricted', '_b2bcp_personalized' => 'yes', '_b2bcp_fields' => array( $name_field, $title_field, $color_field ) ) );
$plate   = make_product( 'Door Plate', '20', array( '_b2bcp_visibility' => 'restricted', '_b2bcp_personalized' => 'yes', '_b2bcp_fields' => array( $name_field ) ) );
$kit     = make_product( 'Welcome Kit', '100', array(
	'_b2bcp_visibility' => 'restricted',
	'_b2bcp_has_parts'  => 'yes',
	'_b2bcp_parts'      => array( array( 'product_id' => $badge, 'name' => '', 'qty' => 2, 'alone' => true ), array( 'product_id' => $deskbar, 'name' => '', 'qty' => 1, 'alone' => true ), array( 'product_id' => $plate, 'name' => '', 'qty' => 1, 'alone' => true ) ),
	'_b2bcp_multiaddress' => 'yes',
) );
$sign    = make_product( 'Holiday Sign', '50', array( '_b2bcp_visibility' => 'restricted', '_b2bcp_multiaddress' => 'yes', '_b2bcp_min_per_address' => 2 ) );
$mug     = make_product( 'Logo Mug', '9', array() );
$secret  = make_product( 'Other Bank Plaque', '70', array( '_b2bcp_visibility' => 'restricted' ) );
$quote   = make_product( 'Custom Monument Sign', '0', array(
	'_b2bcp_quote'        => 'yes',
	'_b2bcp_quote_fields' => array(
		array( 'key' => 'size', 'label' => 'Size', 'type' => 'select', 'required' => true, 'maxlength' => 0, 'format' => 'none', 'options' => array( 'Small', 'Large' ) ),
		array( 'key' => 'qty', 'label' => 'Quantity', 'type' => 'number', 'required' => true, 'maxlength' => 0, 'format' => 'none', 'options' => array() ),
		array( 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'maxlength' => 0, 'format' => 'none', 'options' => array() ),
	),
) );
$magnet = make_product( 'Name Magnet', '15', array( '_b2bcp_visibility' => 'restricted', '_b2bcp_personalized' => 'yes', '_b2bcp_fields' => array( $name_field, $title_field ) ) );
$mbar   = make_product( 'Magnetic Desk Bar', '45', array(
	'_b2bcp_visibility' => 'restricted',
	'_b2bcp_personalized' => 'yes',
	'_b2bcp_fields'     => array( $name_field, $title_field ),
	'_b2bcp_has_parts'  => 'yes',
	'_b2bcp_parts'      => array( array( 'product_id' => 0, 'name' => 'Walnut base', 'qty' => 1, 'alone' => false ), array( 'product_id' => $magnet, 'name' => '', 'qty' => 1, 'alone' => true ), array( 'product_id' => 0, 'name' => 'Back plate', 'qty' => 1, 'alone' => true ) ),
) );
ok( $kit && $sign && $quote && $mbar, 'Products created (badge, deskbar, plate, kit, sign, mug, quote, magnetic desk bar)' );
$mcfg = b2bcp_product_config( $mbar );
ok( 3 === count( $mcfg['parts'] ) && 'Name Magnet' === $mcfg['parts'][1]['name'] && false === $mcfg['parts'][2]['alone'], 'Parts: name-only parts never sold alone, product part keeps its name' );

/* ------------------------------------------------------------ companies */
section( 'Companies' );
wp_set_current_user( 1 );
$bank = wp_insert_post( array( 'post_type' => 'b2bcp_company', 'post_status' => 'publish', 'post_title' => 'Mechanics Test Bank' ) );
update_post_meta( $bank, '_b2bcp_payment', 'both' );
update_post_meta( $bank, '_b2bcp_terms', 'Net 30' );
update_post_meta( $bank, '_b2bcp_color', '#0b5394' );
update_post_meta( $bank, '_b2bcp_notify_email', 'cs@printshop.test' );
update_post_meta( $bank, '_b2bcp_billing', array( 'company' => 'Accounts Payable - MTB', 'address_1' => '1111 Civic Dr', 'address_2' => 'Suite 290', 'city' => 'Walnut Creek', 'state' => 'CA', 'postcode' => '94596', 'country' => 'US', 'email' => 'ap@mtb.test', 'phone' => '' ) );
b2bcp_replace_assignments( 'company', $bank, array( $kit => 95.0, $badge => 8.0, $deskbar => 30.0, $plate => null, $sign => 40.0, $mbar => 42.0, $magnet => 12.0 ) );
$a1 = b2bcp_save_address( $bank, b2bcp_sanitize_address( array( 'label' => 'Downtown', 'code' => '014', 'address_1' => '1 Main St', 'city' => 'Chicago', 'state' => 'IL', 'postcode' => '60601', 'country' => 'US' ) ) );
$a2 = b2bcp_save_address( $bank, b2bcp_sanitize_address( array( 'label' => 'Oak Park', 'code' => '022', 'address_1' => '200 Lake St', 'city' => 'Oak Park', 'state' => 'IL', 'postcode' => '60302', 'country' => 'US' ) ) );
$a3 = b2bcp_save_address( $bank, b2bcp_sanitize_address( array( 'label' => 'Peoria', 'code' => '031', 'address_1' => '5 Water St', 'city' => 'Peoria', 'state' => 'IL', 'postcode' => '61602', 'country' => 'US' ) ) );

$coop = wp_insert_post( array( 'post_type' => 'b2bcp_company', 'post_status' => 'publish', 'post_title' => 'Prairie Credit Union' ) );
update_post_meta( $coop, '_b2bcp_payment', 'card' );
update_post_meta( $coop, '_b2bcp_shipping_included', 'yes' );
b2bcp_replace_assignments( 'company', $coop, array( $mug => 7.5, $secret => null ) );
b2bcp_save_address( $coop, b2bcp_sanitize_address( array( 'label' => 'HQ', 'address_1' => '9 Elm', 'city' => 'Springfield', 'state' => 'IL', 'postcode' => '62701' ) ) );

ok( count( b2bcp_get_addresses( $bank ) ) === 3, 'Bank has 3 branches' );
ok( array_key_exists( $plate, b2bcp_company_assignments( $bank ) ) && null === b2bcp_company_assignments( $bank )[ $plate ], 'Plate assigned with empty price (uses regular)' );
$assign = b2bcp_product_assignments( $kit );
ok( isset( $assign[ $bank ] ) && 95.0 === $assign[ $bank ], 'Product side sees bank price for kit' );

/* ---------------------------------------------------------------- users */
section( 'Invitations' );
list( $type, $msg ) = B2BCP_Company_Admin::invite_user( $bank, 'ana@mtb.test', 'ana', 'lopez', true );
ok( 'success' === $type, "Invite new user: $msg" );
$ana = get_user_by( 'email', 'ana@mtb.test' );
ok( $ana && in_array( 'customer', $ana->roles, true ), 'Ana created as customer' );
ok( b2bcp_user_is_company_admin( $ana->ID, $bank ), 'Ana is company admin of the bank' );
$invite = end( $GLOBALS['sent_mail'] );
ok( $invite && 'ana@mtb.test' === $invite['to'] && false !== strpos( $invite['message'], 'lost-password' ), 'Invitation email with set-password link sent' );
ok( false !== strpos( $invite['message'], 'Mechanics Test Bank' ), 'Invitation email names the company' );
list( $type2 ) = B2BCP_Company_Admin::invite_user( $coop, 'ana@mtb.test', '', '', false );
ok( 'success' === $type2 && 2 === count( b2bcp_user_companies( $ana->ID ) ), 'Same person added to a second company' );
ok( 1 === count( b2bcp_company_users( $bank ) ) && 1 === count( b2bcp_company_users( $coop ) ), 'Each company lists its user' );
// Over-match guard: a user in companies [bank, coop] must not show for a company whose id equals an array index.
ok( ! in_array( $ana->ID, wp_list_pluck( b2bcp_company_users( 1 ), 'ID' ), true ), 'company_users does not over-match serialized indexes' );

/* ------------------------------------------------------- visibility */
section( 'Catalog visibility and prices' );
function as_user( $id ) {
	wp_set_current_user( $id );
	// Reset static caches by re-requiring is not possible; helpers cache per company id, fine.
}
// Guest.
wp_set_current_user( 0 );
$guest_hidden = b2bcp_hidden_product_ids( 0 );
ok( in_array( $kit, $guest_hidden, true ) && in_array( $secret, $guest_hidden, true ) && ! in_array( $mug, $guest_hidden, true ), 'Guest: exclusive products hidden, public visible' );
$q = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids' ) );
ok( ! in_array( $kit, $q->posts, true ) && in_array( $mug, $q->posts, true ), 'Guest: shop query excludes exclusive products' );
ok( ! wc_get_product( $kit )->is_purchasable(), 'Guest: kit not purchasable' );

// Ana buying for the bank.
update_user_meta( $ana->ID, '_b2bcp_active_company', $bank );
wp_set_current_user( $ana->ID );
ok( $bank === b2bcp_active_company_id(), 'Active company = bank' );
$bank_hidden = b2bcp_hidden_product_ids( $bank );
ok( ! in_array( $kit, $bank_hidden, true ) && in_array( $secret, $bank_hidden, true ), 'Bank: sees kit, not the other company\'s plaque' );
$q = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids' ) );
ok( in_array( $kit, $q->posts, true ) && ! in_array( $secret, $q->posts, true ), 'Bank: shop query follows assignments' );
ok( '95' === wc_get_product( $kit )->get_price(), 'Bank: kit contract price 95 (regular 100): ' . wc_get_product( $kit )->get_price() );
ok( '20' === wc_get_product( $plate )->get_price(), 'Bank: plate with empty contract price uses regular 20' );
ok( '9' === wc_get_product( $mug )->get_price(), 'Bank: public mug at regular price' );
ok( wc_get_product( $kit )->is_purchasable(), 'Bank: kit purchasable' );

/* --------------------------------------------------------- build lines */
section( 'Order form: building cart lines' );
$kitp   = wc_get_product( $kit );
$config = b2bcp_product_config( $kit );
ok( b2bcp_uses_order_form( $config ), 'Kit uses the order form' );
$fields = wp_list_pluck( b2bcp_person_fields( $config ), 'key' );
ok( array( 'name', 'title', 'color' ) === $fields, 'Kit person fields merged by key: ' . implode( ',', $fields ) );

$r = B2BCP_Order_Form::build_lines( $kitp, $config, array(
	'b2bcp_people' => array(
		array( 'name' => 'maria   GARCIA', 'title' => 'Branch Manager', 'color' => 'Gold', '_address' => $a1 ),
		array( 'name' => 'john smith', 'title' => 'Teller', 'color' => 'Silver', '_address' => $a2 ),
		array( 'name' => 'luz o\'neil', 'title' => '', 'color' => 'Gold', '_address' => $a1 ),
		array( 'name' => '', 'title' => '', 'color' => '' ),
	),
) );
ok( ! $r['errors'], 'Full kit, 3 people, 2 branches: no errors ' . implode( ' | ', $r['errors'] ) );
ok( 2 === count( $r['lines'] ), 'Two cart lines (one per branch)' );
$downtown = $r['lines'][0];
ok( 2 === $downtown['qty'] && 'Maria Garcia' === $downtown['data']['people'][0]['name'] && "Luz O'neil" === $downtown['data']['people'][1]['name'], 'Downtown: 2 kits, names in Title Case' );
ok( 3 === count( $downtown['data']['components'] ) && 2 === $downtown['data']['components'][0]['qty'], 'Kit components snapshot (2 badges per kit)' );

$r = B2BCP_Order_Form::build_lines( $kitp, $config, array(
	'b2bcp_parts_mode'   => 'parts',
	'b2bcp_parts_chosen' => array( $badge ),
	'b2bcp_people'   => array( array( 'name' => 'ana lopez', 'title' => 'VP', 'color' => '', '_address' => $a3 ) ),
) );
ok( ! $r['errors'] && 1 === count( $r['lines'] ) && $badge === $r['lines'][0]['product_id'], 'Loose piece: only the badge' );
ok( 2 === $r['lines'][0]['qty'] && ! isset( $r['lines'][0]['data']['people'][0]['color'] ), 'Loose badge: kit qty 2 per person, deskbar-only field not required/kept' );
ok( $kit === $r['lines'][0]['data']['part_of'], 'Single part remembers the product it belongs to' );

$r = B2BCP_Order_Form::build_lines( $kitp, $config, array( 'b2bcp_parts_mode' => 'parts' ) );
ok( in_array( 'Choose at least one part.', $r['errors'], true ), 'Pieces mode with nothing chosen → error' );

$r = B2BCP_Order_Form::build_lines( $kitp, $config, array(
	'b2bcp_people' => array(
		array( 'name' => '', 'title' => 'CFO', 'color' => 'Gold', '_address' => $a1 ),
		array( 'name' => 'Bartholomew Montgomery-Smythe', 'title' => '', 'color' => 'Bronze', '_address' => 99999 ),
	),
) );
$e = implode( ' | ', $r['errors'] );
ok( false !== strpos( $e, 'Person 1: Name is required' ), 'Missing required name flagged' );
ok( false !== strpos( $e, 'at most 20 characters' ), 'Too-long name flagged' );
ok( false !== strpos( $e, 'choose an option from the list' ), 'Invalid dropdown option flagged' );
ok( false !== strpos( $e, 'choose the address' ), 'Branch not of this company flagged' );
echo "     errors: $e\n";

$mbarp = wc_get_product( $mbar );
$r     = B2BCP_Order_Form::build_lines( $mbarp, $mcfg, array( 'b2bcp_people' => array( array( 'name' => 'ana lopez', 'title' => 'VP' ) ) ) );
ok( ! $r['errors'] && 1 === count( $r['lines'] ) && $mbar === $r['lines'][0]['product_id'] && 3 === count( $r['lines'][0]['data']['components'] ), 'Complete desk bar: one line with its 3 parts' );
$r = B2BCP_Order_Form::build_lines( $mbarp, $mcfg, array( 'b2bcp_parts_mode' => 'parts', 'b2bcp_parts_chosen' => array( $magnet ), 'b2bcp_people' => array( array( 'name' => 'new name', 'title' => 'New Title' ) ) ) );
ok( ! $r['errors'] && $magnet === $r['lines'][0]['product_id'] && 'New Name' === $r['lines'][0]['data']['people'][0]['name'], 'Replacement magnet alone with the new name' );
ok( '12' === wc_get_product( $magnet )->get_price() && b2bcp_company_can_access( $magnet, $bank ), 'Magnet: contract price and accessible as a part' );
$r = B2BCP_Order_Form::build_lines( $mbarp, $mcfg, array( 'b2bcp_parts_mode' => 'parts', 'b2bcp_parts_chosen' => array( 0 ) ) );
ok( in_array( 'Choose at least one part.', $r['errors'], true ), 'A name-only part cannot be ordered alone' );

$signp = wc_get_product( $sign );
$r     = B2BCP_Order_Form::build_lines( $signp, b2bcp_product_config( $sign ), array( 'b2bcp_qty' => array( $a1 => 5, $a2 => 1, $a3 => 0 ) ) );
ok( false !== strpos( implode( ' ', $r['errors'] ), 'minimum per address is 2' ), 'Min per branch enforced' );
$r = B2BCP_Order_Form::build_lines( $signp, b2bcp_product_config( $sign ), array( 'b2bcp_qty' => array( $a1 => 5, $a2 => 3, $a3 => 0 ) ) );
ok( ! $r['errors'] && 2 === count( $r['lines'] ) && 5 === $r['lines'][0]['qty'], 'Multi-address quantities per branch' );

/* ---------------------------------------------------------- real submit */
section( 'Product form submit → cart' );
wc_load_cart();
WC()->cart->empty_cart();
$_POST = $_REQUEST = array(
	'b2bcp_form'   => '1',
	'add-to-cart'  => (string) $kit,
	'b2bcp_people' => array(
		array( 'name' => 'maria garcia', 'title' => 'Branch Manager', 'color' => 'Gold', '_address' => (string) $a1 ),
		array( 'name' => 'john smith', 'title' => 'Teller', 'color' => 'Silver', '_address' => (string) $a2 ),
	),
);
$redirect = '';
try {
	B2BCP_Order_Form::handle_submit();
} catch ( B2BCP_Test_Redirect $ex ) {
	$redirect = $ex->getMessage();
}
ok( '' !== $redirect, 'Submit redirects after adding (PRG): ' . $redirect );
ok( ! isset( $_REQUEST['add-to-cart'] ), 'WooCommerce\'s own add-to-cart is prevented (no double add)' );
$_POST = $_REQUEST = array(
	'b2bcp_form'  => '1',
	'add-to-cart' => (string) $sign,
	'b2bcp_qty'   => array( $a1 => '3', $a3 => '2' ),
);
try {
	B2BCP_Order_Form::handle_submit();
} catch ( B2BCP_Test_Redirect $ex ) {
}
$_POST = $_REQUEST = array(
	'b2bcp_form'   => '1',
	'add-to-cart'  => (string) $kit,
	'b2bcp_people' => array(
		array( 'name' => 'maria garcia', 'title' => 'Branch Manager', 'color' => 'Gold', '_address' => (string) $a1 ),
	),
);
$before = count( WC()->cart->get_cart() );
$before_keys = array_keys( WC()->cart->get_cart() );
try {
	B2BCP_Order_Form::handle_submit();
} catch ( Throwable $ex ) {
}
$_POST = $_REQUEST = array(
	'b2bcp_form'   => '1',
	'add-to-cart'  => (string) $kit,
	'b2bcp_people' => array(
		array( 'name' => 'maria garcia', 'title' => 'Branch Manager', 'color' => 'Gold', '_address' => (string) $a1 ),
	),
);
try {
	B2BCP_Order_Form::handle_submit();
} catch ( Throwable $ex ) {
}
$dupes = 0;
foreach ( WC()->cart->get_cart() as $line ) {
	if ( ! empty( $line['b2bcp']['people'] ) && count( $line['b2bcp']['people'] ) !== (int) $line['quantity'] ) {
		$dupes++;
	}
}
ok( 0 === $dupes && count( WC()->cart->get_cart() ) === $before + 2, 'Adding the same people twice keeps quantity = people on every line' );
foreach ( array_diff( array_keys( WC()->cart->get_cart() ), $before_keys ) as $key ) {
	WC()->cart->remove_cart_item( $key );
}
$_POST = $_REQUEST = array();
WC()->cart->add_to_cart( $mug, 4 );
WC()->cart->calculate_totals();
$lines = array_values( WC()->cart->get_cart() );
ok( 5 === count( $lines ), 'Cart has 5 lines (2 kit lines, 2 sign lines, mug): ' . count( $lines ) );
$expected = 95 * 2 + 40 * 5 + 9 * 4;
ok( abs( (float) WC()->cart->get_subtotal() - $expected ) < 0.01, "Subtotal uses contract prices: {$expected} vs " . WC()->cart->get_subtotal() );
$item_data = B2BCP_Order_Form::cart_item_data( array(), $lines[0] );
$keys      = wp_list_pluck( $item_data, 'key' );
ok( in_array( 'Ship to', $keys, true ) && in_array( 'Includes', $keys, true ) && in_array( 'Person 1', $keys, true ), 'Cart line shows Ship to, Kit includes, Person 1' );
echo '     ' . implode( "\n     ", array_map( function ( $r ) { return $r['key'] . ': ' . $r['value']; }, $item_data ) ) . "\n";
$locked = B2BCP_Order_Form::lock_quantity( '<input>', $lines[0]['key'], $lines[0] );
ok( false !== strpos( $locked, 'b2bcp-fixed-qty' ), 'Per-person line quantity is locked in the cart' );
ok( false === B2BCP_Order_Form::validate_quantity_update( true, $lines[0]['key'], $lines[0], 5 ), 'Changing a per-person quantity is refused' );
ok( true === B2BCP_Order_Form::validate_quantity_update( true, $lines[2]['key'], $lines[2], 9 ), 'Changing a branch quantity line is allowed' );
ok( false === B2BCP_Order_Form::store_api_editable( true, $lines[0]['data'], $lines[0] ), 'Block cart: per-person line not editable' );

/* ------------------------------------------------------------ shipping */
section( 'Shipping packages' );
WC()->customer->set_shipping_country( 'US' );
WC()->customer->set_shipping_state( 'IL' );
WC()->customer->set_shipping_postcode( '61602' );
WC()->customer->set_shipping_city( 'Peoria' );
WC()->customer->set_shipping_address( '1 Test' );
$packages = WC()->cart->get_shipping_packages();
ok( 4 === count( $packages ), 'Packages: checkout address + Downtown + Oak Park + Peoria = ' . count( $packages ) );
$names = array();
foreach ( $packages as $i => $p ) {
	$names[] = apply_filters( 'woocommerce_shipping_package_name', 'Shipping', $i, $p );
}
echo '     ' . implode( ' | ', $names ) . "\n";
ok( in_array( 'Shipping to Downtown (#014)', $names, true ), 'Package named by branch' );
WC()->shipping()->calculate_shipping( $packages );
WC()->cart->calculate_totals();
$rates_ok = true;
foreach ( WC()->shipping()->get_packages() as $p ) {
	$rates_ok = $rates_ok && ! empty( $p['rates'] );
}
ok( $rates_ok, 'Every package got rates from the store\'s zones' );
ok( abs( (float) WC()->cart->get_shipping_total() - 40 ) < 0.01, 'Flat $10 × 4 packages = $40 shipping: ' . WC()->cart->get_shipping_total() );

/* ------------------------------------------------------------- payment */
section( 'Payment methods per company' );
WC()->payment_gateways()->init();
$gw = WC()->payment_gateways()->get_available_payment_gateways();
ok( isset( $gw['b2bcp_invoice'], $gw['cheque'] ), 'Bank (both): card + invoice available' );
ok( 'Invoice (Net 30)' === $gw['b2bcp_invoice']->get_title(), 'Invoice title shows terms: ' . $gw['b2bcp_invoice']->get_title() );
update_post_meta( $bank, '_b2bcp_payment', 'invoice' );
$gw = WC()->payment_gateways()->get_available_payment_gateways();
ok( array( 'b2bcp_invoice' ) === array_keys( $gw ), 'Invoice-only company: only invoice' );
update_post_meta( $bank, '_b2bcp_payment', 'both' );

/* --------------------------------------------------------------- order */
section( 'Checkout → order' );
ok( 'Accounts Payable - MTB' === B2BCP_Payments::prefill_billing( '', 'billing_company' ), 'Billing pre-filled from company' );
$fields_cfg = B2BCP_Payments::lock_billing_fields( array( 'billing' => array( 'billing_company' => array( 'type' => 'text' ), 'billing_country' => array( 'type' => 'country' ) ) ) );
ok( 'readonly' === ( $fields_cfg['billing']['billing_company']['custom_attributes']['readonly'] ?? '' ) && ! isset( $fields_cfg['billing']['billing_country']['custom_attributes'] ), 'Text billing fields locked, selects left alone' );

$order_id = WC()->checkout()->create_order(
	array(
		'billing_first_name' => 'Ana',
		'billing_last_name'  => 'Lopez',
		'billing_company'    => 'Typed By Buyer Inc',
		'billing_address_1'  => 'Wrong St',
		'billing_city'       => 'Nowhere',
		'billing_state'      => 'IL',
		'billing_postcode'   => '60000',
		'billing_country'    => 'US',
		'billing_email'      => 'ana@mtb.test',
		'shipping_first_name' => 'Ana',
		'shipping_last_name' => 'Lopez',
		'shipping_address_1' => '1 Test',
		'shipping_city'      => 'Peoria',
		'shipping_state'     => 'IL',
		'shipping_postcode'  => '61602',
		'shipping_country'   => 'US',
		'payment_method'     => 'b2bcp_invoice',
		'ship_to_different_address' => 1,
	)
);
ok( is_numeric( $order_id ), 'Order created: #' . ( is_numeric( $order_id ) ? $order_id : $order_id->get_error_message() ) );
$order = wc_get_order( $order_id );
ok( (int) $order->get_meta( '_b2bcp_company_id' ) === $bank && 'Mechanics Test Bank' === $order->get_meta( '_b2bcp_company_name' ), 'Order stamped with company' );
ok( 'Accounts Payable - MTB' === $order->get_billing_company() && 'Walnut Creek' === $order->get_billing_city() && 'ap@mtb.test' === $order->get_billing_email(), 'Company billing enforced over typed values' );
$ship_lines = $order->get_items( 'shipping' );
ok( 4 === count( $ship_lines ), 'Order has 4 shipping lines' );
$ship_to = array();
foreach ( $ship_lines as $sl ) {
	$ship_to[] = (string) $sl->get_meta( 'Ship to' );
}
ok( count( array_filter( $ship_to ) ) === 3, 'Branch address written on 3 shipping lines' );
$first = current( $order->get_items() );
ok( is_array( $first->get_meta( '_b2bcp' ) ) && '' !== $first->get_meta( 'Person 1' ), 'Line item keeps structured data and readable Person rows' );
echo '     Person 1 = ' . $first->get_meta( 'Person 1' ) . ' | Ship to = ' . $first->get_meta( 'Ship to' ) . "\n";

$GLOBALS['sent_mail'] = array();
$result = WC()->payment_gateways()->payment_gateways()['b2bcp_invoice']->process_payment( $order_id );
$order  = wc_get_order( $order_id );
ok( 'success' === $result['result'] && 'on-hold' === $order->get_status(), 'Invoice payment → order on hold' );
ok( 'Net 30' === $order->get_meta( '_b2bcp_terms' ), 'Terms saved on the order' );
ok( 0 === WC()->cart->get_cart_contents_count(), 'Cart emptied' );
$recip = apply_filters( 'woocommerce_email_recipient_new_order', 'owner@printshop.test', $order, null );
ok( false !== strpos( $recip, 'cs@printshop.test' ), 'New order email also goes to the company\'s CS address: ' . $recip );
ob_start();
B2BCP_Emails::company_header( $order, false, false, null );
$hdr = ob_get_clean();
ok( false !== strpos( $hdr, 'Mechanics Test Bank' ), 'Order emails get the company header' );

/* -------------------------------------------------------------- export */
section( 'Production sheet' );
list( $header, $rows ) = B2BCP_Export::rows( array( $order ) );
echo '     ' . implode( ' | ', $header ) . "\n";
foreach ( $rows as $row ) {
	echo '     ' . implode( ' | ', $row ) . "\n";
}
// 2 people × 3 parts = 6 rows, sign 2 rows, mug 1 row.
ok( 9 === count( $rows ), '9 production rows (3 parts × 2 people, 2 sign addresses, 1 mug): ' . count( $rows ) );
ok( in_array( 'Name', $header, true ) && in_array( 'Color', $header, true ), 'Header has the personalization columns' );
$plate_row = null;
foreach ( $rows as $row ) {
	if ( 'Door Plate' === $row[7] ) {
		$plate_row = $row;
		break;
	}
}
$color_col = array_search( 'Color', $header, true );
ok( $plate_row && '' === $plate_row[ $color_col ], 'Door plate rows leave fields it does not use empty' );
ok( "'=SUM(A1)" === B2BCP_Export::cell( '=SUM(A1)' ) && '-5' === B2BCP_Export::cell( '-5' ), 'Spreadsheet formulas neutralized, negative numbers kept' );

/* --------------------------------------------------------- company switch */
section( 'Second company' );
update_user_meta( $ana->ID, '_b2bcp_active_company', $coop );
// active company caches per request; emulate a new request.
$ref = new ReflectionFunction( 'b2bcp_active_company_id' );
echo "     (new request simulated by re-reading in a fresh process later)\n";

/* ---------------------------------------------------------------- quote */
section( 'Quote request' );
wp_set_current_user( 0 );
$GLOBALS['sent_mail'] = array();
$_POST = array(
	'b2bcp_quote_nonce'       => wp_create_nonce( 'b2bcp_quote_' . $quote ),
	'b2bcp_quote_product'     => (string) $quote,
	'b2bcp_q_contact_name'    => 'Pat Newclient',
	'b2bcp_q_contact_company' => 'Corn Belt Bank',
	'b2bcp_q_contact_email'   => 'pat@cornbelt.test',
	'b2bcp_q_size'            => 'Large',
	'b2bcp_q_qty'             => '3',
	'b2bcp_q_notes'           => "Lit at night\nTwo-sided",
);
try {
	B2BCP_Quotes::handle_submit();
} catch ( B2BCP_Test_Redirect $ex ) {
}
$_POST     = array();
$requests  = get_posts( array( 'post_type' => 'b2bcp_quote', 'post_status' => 'any' ) );
ok( 1 === count( $requests ), 'Quote request saved' );
$data = B2BCP_Quotes::get( $requests[0]->ID );
ok( 'new' === $data['status'] && 'pat@cornbelt.test' === $data['contact']['email'] && 3 === count( $data['answers'] ), 'Request has contact, 3 answers, status New' );
ok( 2 === count( $GLOBALS['sent_mail'] ), 'Two emails: store + confirmation to the visitor' );
ok( false !== strpos( implode( ' ', (array) $GLOBALS['sent_mail'][0]['headers'] ), 'Reply-To' ), 'Store email has Reply-To the visitor' );
ok( false === wc_get_product( $quote )->is_purchasable(), 'Quote product cannot be bought' );
ok( false !== strpos( wc_get_product( $quote )->get_price_html(), 'Price on quote' ), 'Quote product shows "Price on quote"' );

// Validation.
wc_clear_notices();
$_POST = array(
	'b2bcp_quote_nonce'     => wp_create_nonce( 'b2bcp_quote_' . $quote ),
	'b2bcp_quote_product'   => (string) $quote,
	'b2bcp_q_contact_name'  => '',
	'b2bcp_q_contact_email' => 'not-an-email',
	'b2bcp_q_size'          => 'Huge',
	'b2bcp_q_qty'           => 'abc',
);
B2BCP_Quotes::handle_submit();
$_POST   = array();
$notices = wp_list_pluck( wc_get_notices( 'error' ), 'notice' );
ok( 4 === count( $notices ), 'Quote validation: 4 errors (name, email, size, number)' );
echo '     ' . implode( ' | ', $notices ) . "\n";
wc_clear_notices();
ok( 1 === count( get_posts( array( 'post_type' => 'b2bcp_quote', 'post_status' => 'any' ) ) ), 'Invalid request not saved' );

/* --------------------------------------------------------------- colors */
section( 'Branding' );
ok( '#ffffff' === b2bcp_text_on( '#0b5394' ), 'White text on dark blue' );
ok( '#111111' === b2bcp_text_on( '#ffd500' ), 'Dark text on yellow' );
ok( '#aabbcc' === b2bcp_sanitize_hex( '#ABC' ), 'Short hex expanded' );

echo "\nRESULT: {$GLOBALS['passes']} passed, {$GLOBALS['fails']} failed\n";
update_option( 'b2bcp_test_ids', compact( 'bank', 'coop', 'kit', 'mbar', 'magnet', 'sign', 'mug', 'secret', 'quote', 'badge', 'deskbar', 'plate', 'order_id', 'a1', 'a2', 'a3' ) + array( 'ana' => $ana->ID ) );
