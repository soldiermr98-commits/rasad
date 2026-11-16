<?php
/**
 * Tests for Rasad_Collect_Ua_Parser.
 *
 * Run with: php tests/test-ua-parser.php
 * Requires WordPress bootstrap (ABSPATH defined, class loaded via autoloader).
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../rasad.php';

$failures = 0;

function test( $name, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS  $name\n";
	} else {
		echo "FAIL  $name\n";
		$failures++;
	}
}

// --- Browser detection ---
test( 'Chrome detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' )['browser'] === Rasad_Collect_Ua_Parser::BROWSER_CHROME );

test( 'Firefox detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0' )['browser'] === Rasad_Collect_Ua_Parser::BROWSER_FIREFOX );

test( 'Safari detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15' )['browser'] === Rasad_Collect_Ua_Parser::BROWSER_SAFARI );

test( 'Edge detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0' )['browser'] === Rasad_Collect_Ua_Parser::BROWSER_EDGE );

test( 'Edge Chromium fork checked before Chrome', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0' )['browser'] !== Rasad_Collect_Ua_Parser::BROWSER_CHROME );

test( 'Unknown browser', Rasad_Collect_Ua_Parser::parse( 'SomeUnknownBrowser/1.0' )['browser'] === Rasad_Collect_Ua_Parser::BROWSER_UNKNOWN );

test( 'Empty UA', Rasad_Collect_Ua_Parser::parse( '' )['browser'] === Rasad_Collect_Ua_Parser::BROWSER_UNKNOWN );

// --- OS detection ---
test( 'Windows detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' )['os'] === Rasad_Collect_Ua_Parser::OS_WINDOWS );

test( 'macOS detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)' )['os'] === Rasad_Collect_Ua_Parser::OS_MACOS );

test( 'Android detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Linux; Android 13; SM-G991B)' )['os'] === Rasad_Collect_Ua_Parser::OS_ANDROID );

test( 'iOS detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X)' )['os'] === Rasad_Collect_Ua_Parser::OS_IOS );

test( 'Linux detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (X11; Linux x86_64)' )['os'] === Rasad_Collect_Ua_Parser::OS_LINUX );

// --- Device detection ---
test( 'Desktop detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' )['device'] === Rasad_Collect_Ua_Parser::DEVICE_DESKTOP );

test( 'Mobile detected', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X)' )['device'] === Rasad_Collect_Ua_Parser::DEVICE_MOBILE );

test( 'Tablet detected (iPad)', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X)' )['device'] === Rasad_Collect_Ua_Parser::DEVICE_TABLET );

test( 'Android mobile vs tablet', Rasad_Collect_Ua_Parser::parse( 'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36' )['device'] === Rasad_Collect_Ua_Parser::DEVICE_MOBILE );

// --- Labels ---
$labels = Rasad_Collect_Ua_Parser::labels();
test( 'Labels has browser key', isset( $labels['browser'] ) );
test( 'Labels has os key', isset( $labels['os'] ) );
test( 'Labels has device key', isset( $labels['device'] ) );
test( 'Chrome label', $labels['browser'][Rasad_Collect_Ua_Parser::BROWSER_CHROME] === 'Chrome' );
test( 'iOS label', $labels['os'][Rasad_Collect_Ua_Parser::OS_IOS] === 'iOS' );
test( 'Mobile label', $labels['device'][Rasad_Collect_Ua_Parser::DEVICE_MOBILE] === 'Mobile' );

echo "\n" . ( $failures === 0 ? 'ALL UA PARSER TESTS PASSED' : "$failures UA PARSER TESTS FAILED" ) . "\n";
exit( $failures > 0 ? 1 : 0 );
