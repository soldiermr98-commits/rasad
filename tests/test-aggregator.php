<?php
/**
 * Aggregator math tests: day boundaries, bounce rate, average duration,
 * period-over-period delta and dimension id derivation.
 *
 * @package Rasad
 */

// Day helpers.
$day = Rasad_Process_Aggregator::local_day( 1700000000 );
test( 'local_day format', (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) );
test( 'local_day value (UTC)', $day === '2023-11-14' );

list( $start, $end ) = Rasad_Process_Aggregator::day_bounds( '2023-06-15' );
test( 'day_bounds span 86400s (UTC)', ( $end - $start ) === 86400 );
test( 'day_bounds start is midnight', date( 'H:i:s', $start ) === '00:00:00' );
test( 'day_bounds start maps to same day', Rasad_Process_Aggregator::local_day( $start ) === '2023-06-15' );

// Country dimension id.
test( 'country_dim empty is 0', Rasad_Process_Aggregator::country_dim_id( '' ) === 0 );
test( 'country_dim IR > 0', Rasad_Process_Aggregator::country_dim_id( 'IR' ) > 0 );
test( 'country_dim consistent', Rasad_Process_Aggregator::country_dim_id( 'IR' ) === Rasad_Process_Aggregator::country_dim_id( 'IR' ) );

// Public API surface.
test( 'Aggregator has run method', method_exists( 'Rasad_Process_Aggregator', 'run' ) );
test( 'Aggregator has local_day method', method_exists( 'Rasad_Process_Aggregator', 'local_day' ) );
test( 'Aggregator has day_bounds method', method_exists( 'Rasad_Process_Aggregator', 'day_bounds' ) );
test( 'Aggregator has cron_schedules method', method_exists( 'Rasad_Process_Aggregator', 'cron_schedules' ) );
test( 'Aggregator has cron_url method', method_exists( 'Rasad_Process_Aggregator', 'cron_url' ) );
test( 'Aggregator has cron_key method', method_exists( 'Rasad_Process_Aggregator', 'cron_key' ) );

// Bounce rate + average duration (private metric_set() via reflection).
$metric = new ReflectionMethod( 'Rasad_Api_Rest', 'metric_set' );
$metric->setAccessible( true );

$out = $metric->invoke(
	null,
	array(
		'views'          => 100,
		'visitors'       => 5,
		'sessions'       => 10,
		'bounces'        => 4,
		'total_duration' => 300,
	)
);
test( 'metric_set views', $out['views'] === 100 );
test( 'metric_set visitors', $out['visitors'] === 5 );
test( 'metric_set bounce rate 0.4', $out['bounce_rate'] === 0.4 );
test( 'metric_set avg duration 30', $out['avg_duration'] === 30 );

$zero = $metric->invoke( null, array( 'views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0, 'total_duration' => 0 ) );
test( 'metric_set zero sessions bounce', $zero['bounce_rate'] === 0 );
test( 'metric_set zero sessions duration', $zero['avg_duration'] === 0 );

// Period-over-period delta.
$delta = new ReflectionMethod( 'Rasad_Api_Rest', 'delta' );
$delta->setAccessible( true );
test( 'delta 50 vs 100', $delta->invoke( null, 50, 100 ) === -50.0 );
test( 'delta 0 vs 0', $delta->invoke( null, 0, 0 ) === 0.0 );
test( 'delta 10 vs 0 (null)', $delta->invoke( null, 10, 0 ) === null );
