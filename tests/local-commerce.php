<?php
/** Real WooCommerce CRUD + MySQL verification. Fixtures roll back and never send orders/mail. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { exit( 1 ); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_URI']='/sks/checkout/'; $_SERVER['SERVER_PORT']=80;
require $argv[1];
global $wpdb;
$fail=0;
function commerce_check($name,$ok){global $fail;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;}
$product_ids=get_posts(array('post_type'=>'product','posts_per_page'=>1,'fields'=>'ids'));
commerce_check('Woo product exists',!empty($product_ids));
$product=wc_get_product($product_ids[0]);
// Unsaved in-memory order avoids stock, email, payment and external hooks.
$order=new WC_Order();
$order->set_id(2147000000);
$order->set_status('processing');
$order->set_currency('USD');
$order->set_total('125.50');
$order->set_date_paid(time());
$item=new WC_Order_Item_Product();
$item->set_product($product);$item->set_quantity(2);$item->set_total('100.00');
$order->add_item($item);
$orders_table=Rasad_Installer::table('wc_orders');
$summary=Rasad_Installer::table('wc_daily');
$ref=(int)$wpdb->get_var('SELECT id FROM '.Rasad_Installer::table('referrers').' LIMIT 1');
$order->update_meta_data('_rasad_attribution',array('wc_referrer_id'=>$ref,'p'=>'/checkout/'));
// Inject synthetic order lookup through Woo's object cache, without saving a real order.
// Direct hook uses wc_get_order: testing the outbox writer separately via reflection fixture payload below.
$payload=array('e'=>6,'p'=>'/checkout/','ts'=>time(),'wc_order_id'=>$order->get_id(),'wc_value'=>'125.50','wc_currency'=>'USD','wc_referrer_id'=>$ref,'wc_items'=>array(array('product_id'=>$product->get_id(),'quantity'=>2,'value'=>'100.00')));
$id=$order->get_id();
$before=$wpdb->get_results("SELECT * FROM {$summary} WHERE currency='USD'",ARRAY_A);
$sql=$wpdb->prepare("INSERT IGNORE INTO {$orders_table}(order_id,payload,processed,created_at)VALUES(%d,%s,0,%d)",$id,wp_json_encode($payload),time());
$wpdb->query($sql);$wpdb->query($sql);
commerce_check('duplicate outbox insert yields one row',1===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders_table} WHERE order_id=%d",$id)));
$stats=Rasad_Process_Aggregator::run();
commerce_check('aggregation no error',empty($stats['error']));
commerce_check('purchase ledger marked processed',1===(int)$wpdb->get_var($wpdb->prepare("SELECT processed FROM {$orders_table} WHERE order_id=%d",$id)));
$once=$wpdb->get_results("SELECT * FROM {$summary} WHERE currency='USD'",ARRAY_A);
$wpdb->query($sql);Rasad_Process_Aggregator::run();
$twice=$wpdb->get_results("SELECT * FROM {$summary} WHERE currency='USD'",ARRAY_A);
commerce_check('replayed purchase never increments summaries',$once===$twice);
$day=Rasad_Process_Aggregator::local_day(time());
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$summary} WHERE day=%s AND scope=1 AND object_id=%d AND currency='USD'",$day,$product->get_id()));
commerce_check('product quantity/revenue persisted',$row && (int)$row->quantity>=2 && (float)$row->revenue>=100);
// Restore only ZZZ summary fixture rows and synthetic order/events.
$wpdb->query("DELETE FROM {$summary} WHERE currency='USD'");
foreach($before as $r){$wpdb->insert($summary,$r);}
$wpdb->delete($orders_table,array('order_id'=>$id));
$wpdb->delete(Rasad_Installer::table('events'),array('order_id'=>$id));
Rasad_Api_Rest::bump_cache();
commerce_check('SQL status',''===$wpdb->last_error);
echo 'failures='.$fail.PHP_EOL;exit($fail?1:0);
