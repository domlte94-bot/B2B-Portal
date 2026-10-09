<?php
$GLOBALS['p']=0;$GLOBALS['f']=0;
function ok($c,$m){ if($c){$GLOBALS['p']++;echo "  PASS  $m\n";} else {$GLOBALS['f']++;echo "  FAIL  $m\n";} }
extract(get_option('b2bcp_test_ids'));
$who = getenv('B2BCP_WHO');
if ($who === 'guest') {
  wp_set_current_user(0);
  $q = new WP_Query(array('post_type'=>'product','posts_per_page'=>-1,'fields'=>'ids'));
  ok(!in_array($kit,$q->posts,true) && !in_array($secret,$q->posts,true) && in_array($mug,$q->posts,true), 'Guest shop: only public products');
  ok(!in_array($quote,b2bcp_hidden_product_ids(0),true), 'Guest sees the public quote product');
} elseif ($who === 'bank') {
  update_user_meta($ana,'_b2bcp_active_company',$bank); wp_set_current_user($ana);
  $q = new WP_Query(array('post_type'=>'product','posts_per_page'=>-1,'fields'=>'ids'));
  ok(in_array($kit,$q->posts,true) && in_array($badge,$q->posts,true) && !in_array($secret,$q->posts,true), 'Bank shop: kit and pieces visible, other bank plaque hidden');
  $s = new WP_Query(array('s'=>'Plaque','post_type'=>'product','fields'=>'ids'));
  ok(!in_array($secret,$s->posts,true), 'Bank search cannot find the other company\'s product');
  $gw = WC()->payment_gateways()->get_available_payment_gateways();
  ok(isset($gw['b2bcp_invoice'],$gw['cheque']), 'Bank (both): card + invoice: '.implode(',',array_keys($gw)));
  wc_load_cart(); WC()->cart->empty_cart();
  $r = B2BCP_Order_Form::build_lines(wc_get_product($sign), b2bcp_product_config($sign), array('b2bcp_qty'=>array($a1=>2,$a2=>2)));
  foreach ($r['lines'] as $l) WC()->cart->add_to_cart($l['product_id'], $l['qty'], 0, array(), array('b2bcp'=>$l['data']));
  ok(false === WC()->cart->needs_shipping_address(), 'Only branch items: checkout does not ask for a shipping address');
  WC()->cart->add_to_cart($mug, 1);
  ok(true === WC()->cart->needs_shipping_address(), 'With an item for the checkout address: shipping address asked');
  WC()->cart->empty_cart();
} elseif ($who === 'coop') {
  update_user_meta($ana,'_b2bcp_active_company',$coop); wp_set_current_user($ana);
  ok($coop === b2bcp_active_company_id(), 'Ana switched to the credit union');
  $q = new WP_Query(array('post_type'=>'product','posts_per_page'=>-1,'fields'=>'ids'));
  ok(!in_array($kit,$q->posts,true) && in_array($secret,$q->posts,true), 'Credit union: sees its plaque, not the bank kit');
  ok('7.5' === wc_get_product($mug)->get_price(), 'Credit union mug price 7.50: '.wc_get_product($mug)->get_price());
  $gw = WC()->payment_gateways()->get_available_payment_gateways();
  ok(!isset($gw['b2bcp_invoice']) && isset($gw['cheque']), 'Card-only company: no invoice option');
  wc_load_cart(); WC()->cart->empty_cart(); WC()->cart->add_to_cart($mug,2);
  WC()->customer->set_shipping_country('US'); WC()->customer->set_shipping_state('IL'); WC()->customer->set_shipping_postcode('62701');
  WC()->cart->calculate_totals();
  ok(0.0 === (float) WC()->cart->get_shipping_total() && 'b2bcp_included' === current(current(WC()->shipping()->get_packages())['rates'])->get_method_id(), 'Shipping included in contract → $0 rate');
  update_user_meta($ana,'_b2bcp_active_company',$bank);
}
echo "RESULT: {$GLOBALS['p']} passed, {$GLOBALS['f']} failed\n";
