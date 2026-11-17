<?php
/**
 * Referrer classifier tests.
 *
 * @package Rasad
 */

// Direct / empty / malformed.
$direct = array( 'domain' => '', 'type' => Rasad_Process_Referrer::TYPE_DIRECT, 'name' => '' );
test( 'Empty referrer is direct', Rasad_Process_Referrer::classify( '' ) === $direct );
test( 'Null referrer is direct', Rasad_Process_Referrer::classify( null ) === $direct );
test( 'Malformed referrer is direct', Rasad_Process_Referrer::classify( 'not-a-url' ) === $direct );

// Search engines.
$google = Rasad_Process_Referrer::classify( 'https://www.google.com/search?q=test' );
test( 'Google is search', $google['type'] === Rasad_Process_Referrer::TYPE_SEARCH );
test( 'Google name', $google['name'] === 'Google' );

$bing = Rasad_Process_Referrer::classify( 'https://www.bing.com/search?q=test' );
test( 'Bing is search', $bing['type'] === Rasad_Process_Referrer::TYPE_SEARCH );

// Social networks.
$ig = Rasad_Process_Referrer::classify( 'https://www.instagram.com/p/abc123/' );
test( 'Instagram is social', $ig['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );
test( 'Instagram name', $ig['name'] === 'Instagram' );

$tg = Rasad_Process_Referrer::classify( 'https://t.me/channel/123' );
test( 'Telegram is social', $tg['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );

$wa = Rasad_Process_Referrer::classify( 'https://wa.me/1234567890' );
test( 'WhatsApp is social', $wa['type'] === Rasad_Process_Referrer::TYPE_SOCIAL );

// Unknown external host => other.
$other = Rasad_Process_Referrer::classify( 'https://some-blog.example/page' );
test( 'Unknown host is other', $other['type'] === Rasad_Process_Referrer::TYPE_OTHER );

// Internal navigation (site host is example.test) => direct.
$internal = Rasad_Process_Referrer::classify( 'https://example.test/other-page' );
test( 'Same-host referrer is direct', $internal['type'] === Rasad_Process_Referrer::TYPE_DIRECT );

// Campaign (UTM).
$campaign = Rasad_Process_Referrer::campaign(
	array(
		'us' => 'newsletter',
		'um' => 'email',
		'uc' => 'spring-sale',
	)
);
test( 'Campaign type', $campaign['type'] === Rasad_Process_Referrer::TYPE_CAMPAIGN );
test( 'Campaign domain prefix', strpos( $campaign['domain'], 'utm:' ) === 0 );
test( 'Campaign name', $campaign['name'] === 'spring-sale' );

$no_campaign = Rasad_Process_Referrer::campaign( array( 'us' => '', 'um' => '', 'uc' => '' ) );
test( 'No UTM returns null', $no_campaign === null );

// Internal site search.
test( 'Search term extracted', Rasad_Process_Referrer::internal_search( '/?s=hello+world' ) === 'hello world' );
test( 'Non-search path empty', Rasad_Process_Referrer::internal_search( '/about/' ) === '' );

// Type constants.
test( 'TYPE_DIRECT', Rasad_Process_Referrer::TYPE_DIRECT === 1 );
test( 'TYPE_SEARCH', Rasad_Process_Referrer::TYPE_SEARCH === 2 );
test( 'TYPE_SOCIAL', Rasad_Process_Referrer::TYPE_SOCIAL === 3 );
test( 'TYPE_OTHER', Rasad_Process_Referrer::TYPE_OTHER === 4 );
test( 'TYPE_CAMPAIGN', Rasad_Process_Referrer::TYPE_CAMPAIGN === 5 );
