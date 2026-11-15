<?php
/**
 * Rasad beacon endpoint — standalone, minimal bootstrap (SHORTINIT).
 *
 * This file is intentionally hardened on its own: it does NOT rely on the
 * plugin being active (it lives inside the plugin folder, but only needs
 * WordPress core files loaded by wp-load.php), never echoes output, never
 * leaks errors, and answers 204 for every silently-dropped request.
 *
 * If SHORTINIT bootstrap is impossible on a host, the tracker can be pointed
 * at the REST fallback (/wp-json/rasad/v1/hit) from the settings screen.
 *
 * @package Rasad
 */

// Errors must never leak into a beacon response.
ini_set( 'display_errors', '0' );

/**
 * Prints standard no-store headers and exits with $code.
 *
 * @param int $code HTTP status code.
 * @return void
 */
function rasad_collect_exit( $code ) {
	$code = (int) $code;

	if ( ! headers_sent() ) {
		http_response_code( $code );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
	}

	// Non-zero exit status keeps the code observable (web SAPI response code
	// was already set above via http_response_code()).
	exit( $code );
}

// SHORTINIT must be defined before wp-load.php pulls in wp-settings.php.
if ( ! defined( 'SHORTINIT' ) ) {
	define( 'SHORTINIT', true );
}

// Locate wp-load.php upward from the plugin directory (max 6 levels).
$rasad_wp_load = '';
$rasad_dir     = __DIR__;

for ( $rasad_i = 0; $rasad_i < 6; $rasad_i++ ) {
	if ( is_readable( $rasad_dir . '/wp-load.php' ) ) {
		$rasad_wp_load = $rasad_dir . '/wp-load.php';
		break;
	}

		$rasad_parent = dirname( $rasad_dir );

		if ( $rasad_parent === $rasad_dir ) {
			break;
		}

		$rasad_dir = $rasad_parent;
	}

if ( '' === $rasad_wp_load ) {
	// No WordPress reachable: stay silent. The tracker keeps a 204 contract;
	// admins switch to the REST route in settings when SHORTINIT is broken.
	rasad_collect_exit( 204 );
}

require $rasad_wp_load;

// The host must have reached wp-settings far enough to load plugin.php.
if ( ! function_exists( 'add_action' ) ) {
	rasad_collect_exit( 204 );
}

// Pull in constants + the lazy autoloader (no plugins_loaded fires here).
require_once dirname( __FILE__ ) . '/rasad.php';

// Raw body for sendBeacon/fetch; GET fallback carries the JSON in ?b=.
$rasad_raw = '';

if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$rasad_body = file_get_contents( 'php://input' );

	if ( false !== $rasad_body ) {
		$rasad_raw = $rasad_body;
	}
}

// Note: SHORTINIT returns before wp_magic_quotes(), so $_GET/$_SERVER are
// NOT slashed here — the pixel payload must be used as-is.
if ( '' === $rasad_raw && ! empty( $_GET['b'] ) && is_string( $_GET['b'] ) ) {
	$rasad_raw = $_GET['b'];
}

if ( '' === $rasad_raw ) {
	rasad_collect_exit( 204 );
}

try {
	$rasad_code = Rasad_Collect_Request::process( $rasad_raw );
} catch ( Throwable $rasad_error ) { // phpcs:ignore -- never leak internals of a beacon request.
	$rasad_code = 204;
}

rasad_collect_exit( $rasad_code );
