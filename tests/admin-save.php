<?php
$GLOBALS['p']=0;$GLOBALS['f']=0;
function ok($c,$m){ if($c){$GLOBALS['p']++;echo "  PASS  $m\n";} else {$GLOBALS['f']++;echo "  FAIL  $m\n";} }
add_filter('pre_wp_mail', function(){ return true; });
extract(get_option('b2bcp_test_ids'));
wp_set_current_user(1);
// New company saved from the admin form.
$id = wp_insert_post(array('post_type'=>'b2bcp_company','post_status'=>'publish','post_title'=>'Lakeview Schools'));
$_POST = array(
  'b2bcp_company_nonce' => wp_create_nonce('b2bcp_save_company'),
  'b2bcp' => array('contact_name'=>'Rosa','contact_email'=>'rosa@lakeview.test','payment'=>'invoice','terms'=>'Net 45','shipping_included'=>'yes','notify_email'=>'a@x.test, bad-email, b@x.test','billing'=>array('company'=>'District Office','country'=>'us','city'=>'Peoria'),'logo_id'=>'','color'=>'#C00'),
  'b2bcp_products' => array(array('product_id'=>$badge,'price'=>'6.50'),array('product_id'=>$mug,'price'=>''),array('product_id'=>'','price'=>'3')),
  'b2bcp_branches' => array(array('id'=>'','label'=>'North Elementary','code'=>'N1','address_1'=>'10 North Rd','city'=>'Peoria','state'=>'IL','postcode'=>'61604','country'=>''),array('id'=>'','label'=>'','address_1'=>'')),
  'b2bcp_invite' => array('email'=>'rosa@lakeview.test','first_name'=>'Rosa','last_name'=>'Diaz','admin'=>'1'),
);
B2BCP_Company_Admin::save($id, get_post($id));
$c = b2bcp_get_company($id);
ok('invoice'===$c['payment'] && 'Net 45'===$c['terms'] && $c['shipping_included'], 'Payment, terms, shipping saved');
ok('a@x.test, b@x.test'===$c['notify_email'], 'Invalid notify email dropped: '.$c['notify_email']);
ok('US'===$c['billing']['country'] && '#cc0000'===$c['color'], 'Country upper-cased, short color expanded');
$a = b2bcp_company_assignments($id);
ok(2===count($a) && 6.5===$a[$badge] && null===$a[$mug], 'Products and prices saved (empty row ignored)');
$br = b2bcp_get_addresses($id);
ok(1===count($br) && 'US'===$br[0]['country'], 'Branch saved, empty row ignored, default country');
$rosa = get_user_by('email','rosa@lakeview.test');
ok($rosa && b2bcp_user_is_company_admin($rosa->ID,$id), 'Invited user created as company admin');
// Second save: edit branch, remove user, change price.
$_POST['b2bcp_branches'] = array(array('id'=>$br[0]['id'],'label'=>'North Elem.','address_1'=>'10 North Rd','city'=>'Peoria'));
$_POST['b2bcp_products'] = array(array('product_id'=>$badge,'price'=>'7'));
$_POST['b2bcp_users'] = array($rosa->ID => array('keep'=>1,'remove'=>'1'));
$_POST['b2bcp_invite'] = array('email'=>'');
B2BCP_Company_Admin::save($id, get_post($id));
$br2 = b2bcp_get_addresses($id);
ok(1===count($br2) && 'North Elem.'===$br2[0]['label'] && $br2[0]['id']===$br[0]['id'], 'Branch edited in place (same id)');
ok(array($badge=>7.0)===b2bcp_company_assignments($id), 'Price updated, removed product unassigned');
ok(!in_array($id, b2bcp_user_companies($rosa->ID), true), 'User removed from company');
// Product tab save.
$p = wc_get_product($mug);
$_POST = array(
  'b2bcp_product_nonce'=>wp_create_nonce('b2bcp_save_product'),
  'b2bcp_visibility'=>'restricted','b2bcp_personalized'=>'yes',
  'b2bcp_fields'=>array(array('label'=>'Name','type'=>'text','required'=>'1','maxlength'=>'18','format'=>'upper'),array('label'=>'Name','type'=>'select','options'=>'A, B ,,C'),array('label'=>'','type'=>'text')),
  'b2bcp_multiaddress'=>'yes','b2bcp_min_per_address'=>'3',
  'b2bcp_kit_items'=>array(array('product_id'=>$mug,'qty'=>'2'),array('product_id'=>$plate,'qty'=>'0')),
  'b2bcp_companies'=>array(array('company_id'=>$bank,'price'=>'8,25'),array('company_id'=>999999,'price'=>'1')),
);
B2BCP_Product_Admin::save($p); $p->save();
$cfg = b2bcp_product_config($mug);
ok('restricted'===$cfg['visibility'] && $cfg['personalized'] && $cfg['multiaddress'] && 3===$cfg['min_per_address'], 'Product flags saved');
ok(2===count($cfg['fields']) && 'name'===$cfg['fields'][0]['key'] && 'name_2'===$cfg['fields'][1]['key'] && array('A','B','C')===$cfg['fields'][1]['options'], 'Field keys unique, options cleaned');
ok(1===count($cfg['kit_items']) && $plate===$cfg['kit_items'][0]['product_id'] && 1===$cfg['kit_items'][0]['qty'], 'Kit cannot include itself; qty at least 1');
ok(array($bank)===array_keys(b2bcp_product_assignments($mug)), 'Unknown company ignored');
echo 'mug bank price: '; var_dump(b2bcp_product_assignments($mug)[$bank]);
echo "RESULT: {$GLOBALS['p']} passed, {$GLOBALS['f']} failed\n";
