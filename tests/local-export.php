<?php
/** CLI harness for real capability-gated CSV execution. */
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(1);
$_SERVER['HTTP_HOST']='localhost';$_SERVER['REQUEST_URI']='/sks/';$_SERVER['SERVER_PORT']=80;
require $argv[1];
$ids=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
wp_set_current_user((int)$ids[0]);
$_GET=array('preset'=>'7d','report'=>isset($argv[2])?$argv[2]:'pages','_wpnonce'=>wp_create_nonce('rasad_csv'));
$_REQUEST=$_GET;
Rasad_Admin_Export::handle();
