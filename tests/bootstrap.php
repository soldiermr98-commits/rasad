<?php
/**
 * Test bootstrap: minimal WordPress stubs so the plugin's pure-logic classes
 * can be exercised from the CLI without a running WordPress or database.
 *
 * @package Rasad
 */

// rasad.php guards on ABSPATH; storage_dir() resolves against WP_CONTENT_DIR.
// Keep it workspace-local so the file-buffer driver can write under the
// test sandbox (system temp is not guaranteed writable there).
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/tests/.wp-content' );

$GLOBALS['rasad_test_failures'] = 0;

// --- WordPress function stubs (never override the real ones). ---

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://example.test/wp-content/plugins/rasad/';
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null ) {
		return date( $format, null === $ts ? time() : (int) $ts );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
		$string = strip_tags( $string );

		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}

		return trim( $string );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		$str = preg_replace( '/[\r\n\t]+/', ' ', $str );

		return trim( preg_replace( '/ {2,}/', ' ', $str ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . (string) $path;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

// Stub the settings surface used by the pure classifiers: the real class
// pulls in options + home_url, which we do not need in a unit test.
if ( ! class_exists( 'Rasad_Settings', false ) ) {
	class Rasad_Settings {
		public static function site_host() {
			return 'example.test';
		}

		public static function get( $key, $default = null ) {
			return $default;
		}

		public static function all() {
			return array();
		}
	}
}

// Load the plugin skeleton (autoloader + constants). The real Rasad_Plugin
// and every tested class are then pulled in lazily by the autoloader.
require_once dirname( __DIR__ ) . '/rasad.php';

/**
 * Records one assertion and reports the result.
 *
 * @param string $name      Assertion name.
 * @param bool   $condition Condition.
 * @return void
 */
function test( $name, $condition ) {
	if ( $condition ) {
		echo "PASS  {$name}\n";
	} else {
		echo "FAIL  {$name}\n";
		$GLOBALS['rasad_test_failures']++;
	}
}
