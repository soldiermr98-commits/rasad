<?php
/** Local WordPress integration smoke checks. Run: php tests/local-smoke.php C:/.../wp-load.php */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { exit( 1 ); }
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/sks/';
$_SERVER['SERVER_PORT'] = 80;
require $argv[1];
$failures = 0;
function rsd_check( $label, $condition ) {
	global $failures;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $condition ) { $failures++; }
}
rsd_check( 'Rasad loaded', defined( 'RASAD_VERSION' ) );
Rasad_Installer::maybe_upgrade();
global $wpdb;
foreach ( Rasad_Installer::table_names() as $table ) {
	rsd_check( 'schema ' . $table, $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
}
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
rsd_check( 'administrator exists', ! empty( $admin ) );
wp_set_current_user( (int) $admin[0] );
$request = new WP_REST_Request( 'GET', '/rasad/v1/overview' );
$request->set_param( 'range', 'today' );
$response = rest_do_request( $request );
rsd_check( 'authorized overview HTTP 200', 200 === $response->get_status() );
foreach ( array( 'series', 'pages', 'referrers', 'audience', 'woo', 'live' ) as $route ) {
	$wpdb->last_error = '';
	$req = new WP_REST_Request( 'GET', '/rasad/v1/' . $route );
	$req->set_param( 'preset', '7d' );
	$start = microtime( true );
	$res = rest_do_request( $req );
	rsd_check( $route . ' HTTP 200', 200 === $res->get_status() );
	rsd_check( $route . ' SQL clean', '' === $wpdb->last_error );
	echo 'elapsed_ms ' . $route . '=' . round( ( microtime( true ) - $start ) * 1000, 2 ) . PHP_EOL;
}
wp_set_current_user( 0 );
rsd_check( 'anonymous overview forbidden', 403 === rest_do_request( $request )->get_status() || 401 === rest_do_request( $request )->get_status() );
rsd_check( 'Nowruz 1403', array( 'jy' => 1403, 'jm' => 1, 'jd' => 1 ) === Rasad_Jalali::gregorian_to_jalali( 2024, 3, 20 ) );
rsd_check( 'DB query status', '' === $wpdb->last_error );
echo 'failures=' . $failures . PHP_EOL;
exit( $failures ? 1 : 0 );
