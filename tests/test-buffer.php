<?php
/**
 * Tests for Rasad buffer drivers.
 *
 * Run with: php tests/test-buffer.php
 * Requires WordPress bootstrap (ABSPATH defined, classes loaded via autoloader).
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

// --- Interface ---
test( 'Interface has push', method_exists( 'Rasad_Buffer_Interface', 'push' ) );
test( 'Interface has pop_batch', method_exists( 'Rasad_Buffer_Interface', 'pop_batch' ) );
test( 'Interface has count', method_exists( 'Rasad_Buffer_Interface', 'count' ) );
test( 'Interface has is_available', method_exists( 'Rasad_Buffer_Interface', 'is_available' ) );

// --- File buffer driver (always available) ---
$file = new Rasad_Buffer_File();
test( 'File driver is_available', $file->is_available() );

$file->push( array( 'p' => '/test1', 'e' => 1 ) );
$file->push( array( 'p' => '/test2', 'e' => 1 ) );
test( 'File count >= 2', $file->count() >= 2 );

$batch = $file->pop_batch( 10 );
test( 'File pop_batch returns array', is_array( $batch ) );
test( 'File pop_batch has 2 items', count( $batch ) >= 2 );

// After pop, count should be 0 (or close)
$after = $file->count();
test( 'File count after pop is 0', $after === 0 );

// --- APCu buffer (may not be available) ---
if ( class_exists( 'Rasad_Buffer_Apcu' ) ) {
	$apcu = new Rasad_Buffer_Apcu();
	test( 'Apcu driver class exists', true );
	$avail = $apcu->is_available();
	test( 'Apcu is_available returns bool', is_bool( $avail ) );

	if ( $avail ) {
		$apcu->push( array( 'p' => '/apcu1', 'e' => 1 ) );
		$apcu->push( array( 'p' => '/apcu2', 'e' => 1 ) );
		test( 'Apcu count >= 2', $apcu->count() >= 2 );

		$batch = $apcu->pop_batch( 10 );
		test( 'Apcu pop_batch returns array', is_array( $batch ) );
	}
} else {
	echo "SKIP  APCu driver class not found\n";
}

// --- Redis buffer (may not be available) ---
if ( class_exists( 'Rasad_Buffer_Redis' ) ) {
	$redis = new Rasad_Buffer_Redis();
	test( 'Redis driver class exists', true );
	$avail = $redis->is_available();
	test( 'Redis is_available returns bool', is_bool( $avail ) );

	if ( $avail ) {
		$redis->push( array( 'p' => '/redis1', 'e' => 1 ) );
		test( 'Redis count >= 0', $redis->count() >= 0 );

		$batch = $redis->pop_batch( 10 );
		test( 'Redis pop_batch returns array', is_array( $batch ) );
	}
} else {
	echo "SKIP  Redis driver class not found\n";
}

// --- DB buffer (always available if wpdb exists) ---
if ( class_exists( 'Rasad_Buffer_Db' ) ) {
	global $wpdb;
	$db = new Rasad_Buffer_Db();
	test( 'DB driver class exists', true );
	$avail = $db->is_available();
	test( 'DB is_available returns bool', is_bool( $avail ) );

	if ( $avail && isset( $wpdb ) ) {
		$db->push( array( 'p' => '/db1', 'e' => 1 ) );
		test( 'DB count >= 0', $db->count() >= 0 );

		$batch = $db->pop_batch( 10 );
		test( 'DB pop_batch returns array', is_array( $batch ) );
	}
} else {
	echo "SKIP  DB driver class not found\n";
}

echo "\n" . ( $failures === 0 ? 'ALL BUFFER TESTS PASSED' : "$failures BUFFER TESTS FAILED" ) . "\n";
exit( $failures > 0 ? 1 : 0 );
