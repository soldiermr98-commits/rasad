<?php
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(1);
$_SERVER['HTTP_HOST']='localhost';$_SERVER['REQUEST_URI']='/sks/';$_SERVER['SERVER_PORT']=80;require $argv[1];
$id=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 1");
$stats=Rasad_Process_Aggregator::run();
$day=Rasad_Process_Aggregator::local_day(time());
$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Rasad_Installer::table('wc_daily').' WHERE day=%s AND scope=1 AND object_id=%d AND currency=%s',$day,$id,get_woocommerce_currency()));
$pageViews=(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(views) FROM '.Rasad_Installer::table('daily_pages').' dp JOIN '.Rasad_Installer::table('pages').' p ON p.id=dp.page_id WHERE p.path=%s','/product/test-product/'));
echo json_encode(array('id'=>$id,'stats'=>$stats,'product_views'=>$row?(int)$row->product_views:0,'pageViews'=>$pageViews)).PHP_EOL;
