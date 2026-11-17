<?php
/**
 * Rasad test runner.
 *
 * Run with: php tests/run-tests.php
 *
 * @package Rasad
 */

require_once __DIR__ . '/bootstrap.php';

$tests = array(
	'test-ua-parser.php'  => 'UA Parser',
	'test-referrer.php'   => 'Referrer Classifier',
	'test-jalali.php'     => 'Jalali Calendar',
	'test-buffer.php'     => 'Buffer Drivers',
	'test-aggregator.php' => 'Aggregator Math',
);

echo "Rasad Test Suite\n";
echo str_repeat( '=', 50 ) . "\n\n";

foreach ( $tests as $file => $label ) {
	$path = __DIR__ . '/' . $file;

	if ( ! is_file( $path ) ) {
		echo "SKIP  {$label} ({$file} not found)\n\n";
		continue;
	}

	echo "Running: {$label}\n";
	echo str_repeat( '-', 50 ) . "\n";

	require $path;

	echo "\n";
}

$failures = (int) $GLOBALS['rasad_test_failures'];

echo str_repeat( '=', 50 ) . "\n";
echo "Total failures: {$failures}\n";
echo ( 0 === $failures ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED' ) . "\n";

exit( $failures > 0 ? 1 : 0 );
