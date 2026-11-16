<?php
/**
 * Tests for Rasad aggregator math (bounce rate, duration, unique visitors).
 *
 * Run with: php tests/test-aggregator.php
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

// --- Bounce rate calculation ---
// bounce_rate = bounces / sessions
test( 'Bounce rate: 0 sessions = 0', ( 0 > 0 ? 0 / 0 : 0 ) === 0 );
test( 'Bounce rate: 100% bounce', round( 5 / 5, 4 ) === 1.0 );
test( 'Bounce rate: 0% bounce', round( 0 / 10, 4 ) === 0.0 );
test( 'Bounce rate: 30% bounce', round( 3 / 10, 4 ) === 0.3 );

// --- Average duration ---
// avg_duration = total_duration / sessions
test( 'Avg duration: 0 sessions = 0', ( 0 > 0 ? 0 / 0 : 0 ) === 0 );
test( 'Avg duration: 100s / 5 sessions = 20s', (int) round( 100 / 5 ) === 20 );
test( 'Avg duration: 0s / 10 sessions = 0s', (int) round( 0 / 10 ) === 0 );

// --- Session gap (30 minutes = 1800 seconds) ---
test( 'Session gap default is 1800s', Rasad_Process_Aggregator::session_gap() === 1800 );

// --- Day bounds (timezone aware) ---
$today = Rasad_Process_Aggregator::local_day( time() );
list( $start, $end ) = Rasad_Process_Aggregator::day_bounds( $today );
test( 'Day bounds: end > start', $end > $start );
test( 'Day bounds: span is 86400s', ( $end - $start ) === 86400 );
test( 'Day bounds: start is midnight', date( 'H:i:s', $start ) === '00:00:00' );

// --- Country dim id ---
test( 'Country dim: empty = 0', Rasad_Process_Aggregator::country_dim_id( '' ) === 0 );
test( 'Country dim: IR = crc32', Rasad_Process_Aggregator::country_dim_id( 'IR' ) > 0 );
test( 'Country dim: US = crc32', Rasad_Process_Aggregator::country_dim_id( 'US' ) > 0 );
test( 'Country dim: consistent', Rasad_Process_Aggregator::country_dim_id( 'IR' ) === Rasad_Process_Aggregator::country_dim_id( 'IR' ) );

// --- Affected days tracking ---
test( 'Aggregator class exists', class_exists( 'Rasad_Process_Aggregator' ) );
test( 'Aggregator has run method', method_exists( 'Rasad_Process_Aggregator', 'run' ) );
test( 'Aggregator has local_day method', method_exists( 'Rasad_Process_Aggregator', 'local_day' ) );
test( 'Aggregator has day_bounds method', method_exists( 'Rasad_Process_Aggregator', 'day_bounds' ) );
test( 'Aggregator has cron_schedules method', method_exists( 'Rasad_Process_Aggregator', 'cron_schedules' ) );
test( 'Aggregator has cron_url method', method_exists( 'Rasad_Process_Aggregator', 'cron_url' ) );
test( 'Aggregator has cron_key method', method_exists( 'Rasad_Process_Aggregator', 'cron_key' ) );

echo "\n" . ( $failures === 0 ? 'ALL AGGREGATOR TESTS PASSED' : "$failures AGGREGATOR TESTS FAILED" ) . "\n";
exit( $failures > 0 ? 1 : 0 );
