<?php
/**
 * Rasad beacon endpoint — standalone, minimal bootstrap (SHORTINIT).
 *
 * This file is intentionally hardened on its own: it does NOT rely on the
 * plugin being active (it lives inside the plugin folder, but only needs
 * WordPress core files loaded by wp-load.php), never echoes output, never
 * leaks errors, and answers 204 for every silently-dropped request.
 *
 * Hardening (phase 4.5):
 * - CONTENT_LENGTH > RASAD_MAX_PAYLOAD rejected BEFORE reading the body;
 *   php://input is then read with a hard byte cap.
 * - The wp-load.php path saved on activation (wp-load-path.php) is tried
 *   first; the upward directory search is only a fallback.
 * - Optional X-Rasad-Status: ok|dropped|error header for diagnostics,
 *   emitted ONLY when WP_DEBUG is on or a valid self-test key is sent
 *   (?rasad_test= / X-Rasad-Test-Key). Otherwise responses stay plain.
 *
 * If SHORTINIT bootstrap is impossible on a host, the tracker can be pointed
 * at the REST fallback (/wp-json/rasad/v1/hit) from the settings screen.
 *
 * @package Rasad
 */

// Errors must never leak into a beacon response.
ini_set( 'display_errors', '0' );

/**
 * Whether diagnostic headers are allowed: WP_DEBUG, or a valid self-test
 * key (written on activation as selftest-key.php) was supplied.
 *
 * @return bool
 */
function rasad_collect_diag() {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		return true;
	}

	$given = '';

	if ( isset( $_GET['rasad_test'] ) && is_string( $_GET['rasad_test'] ) ) {
		$given = $_GET['rasad_test'];
	} elseif ( isset( $_SERVER['HTTP_X_RASAD_TEST_KEY'] ) && is_string( $_SERVER['HTTP_X_RASAD_TEST_KEY'] ) ) {
		$given = $_SERVER['HTTP_X_RASAD_TEST_KEY'];
	}

	if ( '' === $given || strlen( $given ) > 128 ) {
		return false;
	}

	$stored   = '';
	$key_file = __DIR__ . '/selftest-key.php';

	if ( is_readable( $key_file ) ) {
		try {
			$stored = include $key_file;
		} catch ( Throwable $rasad_key_error ) {
			$stored = '';
		}
	}

	return is_string( $stored ) && '' !== $stored && hash_equals( $stored, $given );
}

/**
 * Prints standard no-store headers (plus an optional X-Rasad-Status
 * diagnostic) and exits with $code.
 *
 * @param int    $code   HTTP status code.
 * @param string $status Diagnostic status: ok|dropped|error. Emitted only
 *                       when diagnostics are enabled; '' sends nothing.
 * @return void
 */
function rasad_collect_exit( $code, $status = '' ) {
	$code = (int) $code;

	if ( ! headers_sent() ) {
		http_response_code( $code );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );

		if ( is_string( $status )
			&& in_array( $status, array( 'ok', 'dropped', 'error' ), true )
			&& rasad_collect_diag() ) {
			header( 'X-Rasad-Status: ' . $status );
		}
	}

	// Non-zero exit status keeps the code observable (web SAPI response code
	// was already set above via http_response_code()).
	exit( $code );
}

// SHORTINIT must be defined before wp-load.php pulls in wp-settings.php.
if ( ! defined( 'SHORTINIT' ) ) {
	define( 'SHORTINIT', true );
}

// 1) Bootstrap pointer written on activation — fastest and survives odd
//    layouts; validated here (must be a readable path).
$rasad_wp_load = '';
$rasad_hint    = __DIR__ . '/wp-load-path.php';

if ( is_readable( $rasad_hint ) ) {
	try {
		$rasad_candidate = include $rasad_hint;
	} catch ( Throwable $rasad_hint_error ) {
		$rasad_candidate = false;
	}

	if ( is_string( $rasad_candidate ) && '' !== $rasad_candidate && is_readable( $rasad_candidate ) ) {
		$rasad_wp_load = $rasad_candidate;
	}
}

// 2) Fallback: locate wp-load.php upward from the plugin directory (max 6 levels).
if ( '' === $rasad_wp_load ) {
	$rasad_dir = __DIR__;

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
}

if ( '' === $rasad_wp_load ) {
	// No WordPress reachable: stay silent. The self-test key still gets an
	// honest "error" header here — this is exactly the broken-bootstrap
	// case admins need to diagnose; the tracker keeps a 204 contract.
	rasad_collect_exit( 204, 'error' );
}

require $rasad_wp_load;

// The host must have reached wp-settings far enough to load plugin.php.
if ( ! function_exists( 'add_action' ) ) {
	rasad_collect_exit( 204, 'error' );
}

// Pull in constants + the lazy autoloader (no plugins_loaded fires here).
require_once dirname( __FILE__ ) . '/rasad.php';

$rasad_raw = '';

if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	// Reject oversized declared bodies BEFORE reading a single byte.
	if ( isset( $_SERVER['CONTENT_LENGTH'] )
		&& (int) $_SERVER['CONTENT_LENGTH'] > RASAD_MAX_PAYLOAD ) {
		rasad_collect_exit( 204, 'error' );
	}

	// Hard cap on the read itself; one extra byte detects bodies whose
	// Content-Length is missing, chunked or lying.
	$rasad_body = file_get_contents( 'php://input', false, null, 0, RASAD_MAX_PAYLOAD + 1 );

	if ( false !== $rasad_body ) {
		if ( strlen( $rasad_body ) > RASAD_MAX_PAYLOAD ) {
			rasad_collect_exit( 204, 'error' );
		}

		$rasad_raw = $rasad_body;
	}
}

// Note: SHORTINIT returns before wp_magic_quotes(), so $_GET/$_SERVER are
// NOT slashed here — the pixel payload must be used as-is.
//
// GET pixel fallback: strictly ?d=1&b=<json>, byte-capped.
if ( '' === $rasad_raw && isset( $_GET['b'] ) && is_string( $_GET['b'] ) ) {
	if ( ! isset( $_GET['d'] ) || '1' !== (string) $_GET['d'] ) {
		rasad_collect_exit( 204, 'dropped' );
	}

	if ( strlen( $_GET['b'] ) > RASAD_MAX_PAYLOAD ) {
		rasad_collect_exit( 204, 'error' );
	}

	$rasad_raw = $_GET['b'];
}

if ( '' === $rasad_raw ) {
	rasad_collect_exit( 204, 'dropped' );
}

try {
	$rasad_code   = Rasad_Collect_Request::process( $rasad_raw );
	$rasad_status = Rasad_Collect_Request::last_status();
} catch ( Throwable $rasad_error ) { // phpcs:ignore -- never leak internals of a beacon request.
	$rasad_code   = 204;
	$rasad_status = 'error';
}

rasad_collect_exit( $rasad_code, $rasad_status );
