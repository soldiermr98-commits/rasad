<?php
/** Isolated million-hit SQL benchmark. Does not add fixtures to live Rasad tables. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { exit( 1 ); }
$_SERVER['HTTP_HOST']='localhost';$_SERVER['REQUEST_URI']='/sks/';$_SERVER['SERVER_PORT']=80;
require $argv[1];
global $wpdb;
$base=$wpdb->prefix.'rsd_bench_'.bin2hex(random_bytes(8)).'_';
$tables=array();
try {
 foreach(array('hits','daily','daily_pages','pages','daily_dim','wc_daily') as $name){
  $t=$base.$name;
  if(false===$wpdb->query("CREATE TABLE `{$t}` LIKE `".Rasad_Installer::table($name).'`'))throw new RuntimeException($wpdb->last_error);
  $tables[$name]=$t;
 }
 $digits=$base.'digits';
 if(false===$wpdb->query("CREATE TABLE `{$digits}` (n INT PRIMARY KEY) ENGINE=InnoDB"))throw new RuntimeException($wpdb->last_error);
 $tables['digits']=$digits;
 $wpdb->query("INSERT INTO `{$digits}` VALUES(0),(1),(2),(3),(4),(5),(6),(7),(8),(9)");
 $hits=$tables['hits'];
 $n='a.n+10*b.n+100*c.n+1000*d.n+10000*e.n+100000*f.n';
 $sql="INSERT INTO `{$hits}`(session_id,page_id,ts) SELECT FLOOR(({$n})/3)+1,MOD(({$n}),1000)+1,1700000000+MOD(({$n}),2592000) FROM `{$digits}` a CROSS JOIN `{$digits}` b CROSS JOIN `{$digits}` c CROSS JOIN `{$digits}` d CROSS JOIN `{$digits}` e CROSS JOIN `{$digits}` f";
 $start=microtime(true);if(false===$wpdb->query($sql))throw new RuntimeException($wpdb->last_error);
 echo 'raw_rows='.$wpdb->get_var("SELECT COUNT(*) FROM `{$hits}`").' seed_seconds='.round(microtime(true)-$start,2).PHP_EOL;
 foreach(array('daily','daily_pages','pages','daily_dim','wc_daily') as $name){$wpdb->query("INSERT INTO `{$tables[$name]}` SELECT * FROM `".Rasad_Installer::table($name).'`');}
 $queries=array(
 'overview'=>"SELECT SUM(views),SUM(visitors),SUM(sessions) FROM `{$tables['daily']}` WHERE day BETWEEN '2000-01-01' AND '2100-01-01'",
 'pages'=>"SELECT d.page_id,SUM(d.views) views FROM `{$tables['daily_pages']}` d JOIN `{$tables['pages']}` p ON p.id=d.page_id WHERE day BETWEEN '2000-01-01' AND '2100-01-01' GROUP BY d.page_id ORDER BY views DESC LIMIT 20",
 'audience'=>"SELECT dim,dim_id,SUM(views) views FROM `{$tables['daily_dim']}` WHERE day BETWEEN '2000-01-01' AND '2100-01-01' GROUP BY dim,dim_id ORDER BY views DESC LIMIT 100",
 'commerce'=>"SELECT currency,SUM(revenue),SUM(orders) FROM `{$tables['wc_daily']}` WHERE scope=0 AND day BETWEEN '2000-01-01' AND '2100-01-01' GROUP BY currency"
 );
 foreach($queries as $label=>$query){$times=array();for($i=0;$i<10;$i++){$s=microtime(true);$wpdb->get_results($query);if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);$times[]=(microtime(true)-$s)*1000;}sort($times);echo $label.' median_ms='.round($times[5],3).' max_ms='.round(max($times),3).PHP_EOL;}
}finally{foreach(array_reverse($tables) as $t){$wpdb->query("DROP TABLE IF EXISTS `{$t}`");}}
