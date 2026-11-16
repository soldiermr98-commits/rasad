<?php
/**
 * Rasad test runner (Phase 8).
 *
 * Runs all test suites in sequence.
 *
 * Run with: php tests/run-tests.php
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../rasad.php';

$tests = array(
	'test-ua-parser.php'     => 'UA Parser',
	'test-referrer.php'      => 'Referrer Classifier',
	'test-jalali.php'        => 'Jalali Calendar',
	'test-buffer.php'        => 'Buffer Drivers',
	'test-aggregator.php'    => 'Aggregator Math',
);

$total_failures = 0;

echo "Rasad Test Suite (Phase 8)\n";
echo str_repeat( '=', 50 ) . "\n\n";

foreach ( $tests as $file => $label ) {
	$path = __DIR__ . '/' . $file;

	if ( ! is_file( $path ) ) {
		echo "SKIP  $label ($file not found)\n\n";
		continue;
	}

	echo "Running: $label\n";
	echo str_repeat( '-', 50 ) . "\n";

	ob_start();
	include $path;
	$output = ob_get_clean();

	echo $output . "\n";

	// Extract failure count from output
	if ( preg_match( '/(\d+) .*TESTS FAILED/', $output, $m ) ) {
		$total_failures += (int) $m[1];
	}
}

echo str_repeat( '=', 50 ) . "\n";
echo "Total failures: $total_failures\n";
echo $total_failures === 0 ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED';
echo "\n";

exit( $total_failures > 0 ? 1 : 0 );
