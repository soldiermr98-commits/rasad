<?php
/**
 * Tests for Rasad_Process_Referrer.
 *
 * Run with: php tests/test-referrer.php
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

// --- Direct (empty/malformed referrer) ---
$direct = array( 'domain' => '', 'type' => Rasad_Process_Referrer::TYPE_DIRECT, 'name' => '' );
test( 'Empty referrer is direct', Rasad_Process_Referrer::classify( '' ) === $direct );
test( 'Null referrer is direct', Rasad_Process_Referrer::classify( null ) === $direct );
test( 'Malformed referrer is direct', Rasad_Process_Referrer::classify( 'not-a-url' ) === $direct );

// --- Search engines ---
$google = Rasad_Process_Referrer::classify( 'https://www.google.com/search?q=test' );
test( 'Google classified as search', $google['type'] === Rasad_Process_Referrer::TYPE_SEARCH );
test( 'Google name', $google['name'] === 'Google' );

$bing = Rasad_Process_Referrer::classify( 'https://www.bing.com/search?q=test' );
test( 'Bing classified as search', $bing['type'] === Rasad_Process_Referrer::TYPE_SEARCH );
test( 'Bing name', $bing['name'] === 'Bing' );

$duck = Rasad_Process_Referrer::classify( 'https://duckduckgo.com/?q=test' );
test( 'DuckDuckGo classified as search', $duck['type'] === Rasad_Process_Referrer::TYPE_SEARCH );

// --- Social networks ---
$ig = Rasad_Process_Referrer::classify( 'https://www.instagram.com/p/abc123/' );
test( 'Instagram classified as social', $ig['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );
test( 'Instagram name', $ig['name'] === 'Instagram' );

$fb = Rasad_Process_Referrer::classify( 'https://facebook.com/profile/123' );
test( 'Facebook classified as social', $fb['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );

$tg = Rasad_Process_Referrer::classify( 'https://t.me/channel/123' );
test( 'Telegram classified as social', $tg['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );

$wa = Rasad_Process_Referrer::classify( 'https://wa.me/1234567890' );
test( 'WhatsApp classified as social', $wa['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );

// --- Other ---
$other = Rasad_Process_Referrer::classify( 'https://example.com/page' );
test( 'Unknown domain classified as other', $other['type'] === Rasad_Process_Referrer::TYPE_OTHER );

// --- Internal navigation ---
// When site_host is not example.com, example.com is "other", not direct.
// But if site_host IS example.com, it's direct.
$internal = Rasad_Process_Referrer::classify( 'https://example.com/other-page' );
test( 'Same-host referrer is direct', $internal['type'] === Rasad_Process_Referrer::TYPE_DIRECT );

// --- Campaign (UTM) ---
$campaign = Rasad_Process_Referrer::campaign( array(
	'us' => 'newsletter',
	'um' => 'email',
	'uc' => 'spring-sale',
) );
test( 'Campaign has type 5', $campaign['type'] === Rasad_Process_Referrer::TYPE_CAMPAIGN );
test( 'Campaign domain has utm: prefix', strpos( $campaign['domain'], 'utm:' ) === 0 );
test( 'Campaign name is campaign name', $campaign['name'] === 'spring-sale' );

// --- No UTM data ---
$no_campaign = Rasad_Process_Referrer::campaign( array( 'us' => '', 'um' => '', 'uc' => '' ) );
test( 'No UTM returns null', $no_campaign === null );

// --- Internal site search ---
$term = Rasad_Process_Referrer::internal_search( '/?s=hello+world' );
test( 'Search term extracted', $term === 'hello world' );

$no_term = Rasad_Process_Referrer::internal_search( '/about/' );
test( 'Non-search path returns empty', $no_term === '' );

// --- Type constants ---
test( 'TYPE_DIRECT = 1', Rasad_Process_Referrer::TYPE_DIRECT === 1 );
test( 'TYPE_SEARCH = 2', Rasad_Process_Referrer::TYPE_SEARCH === 2 );
test( 'TYPE_SOCIAL = 3', Rasad_Process_Referrer::TYPE_SOCIAL === 3 );
test( 'TYPE_OTHER = 4', Rasad_Process_Referrer::TYPE_OTHER === 4 );
test( 'TYPE_CAMPAIGN = 5', Rasad_Process_Referrer::TYPE_CAMPAIGN === 5 );

echo "\n" . ( $failures === 0 ? 'ALL REFERRER TESTS PASSED' : "$failures REFERRER TESTS FAILED" ) . "\n";
exit( $failures > 0 ? 1 : 0 );
