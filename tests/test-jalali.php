<?php
/**
 * Tests for Rasad_Jalali (Gregorian <-> Jalali converter).
 *
 * Run with: php tests/test-jalali.php
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

// --- Gregorian to Jalali ---
// Known reference: 2024-03-21 (Gregorian) = 1403-01-01 (Jalali, Nowruz)
$result = Rasad_Jalali::gregorian_to_jalali( 2024, 3, 21 );
test( '2024-03-21 -> Jalali year', $result['jy'] === 1403 );
test( '2024-03-21 -> Jalali month', $result['jm'] === 1 );
test( '2024-03-21 -> Jalali day', $result['jd'] === 1 );

// 2024-09-22 (Gregorian) = 1403-07-01 (Jalali, Mehr 1)
$result = Rasad_Jalali::gregorian_to_jalali( 2024, 9, 22 );
test( '2024-09-22 -> Jalali year', $result['jy'] === 1403 );
test( '2024-09-22 -> Jalali month', $result['jm'] === 7 );
test( '2024-09-22 -> Jalali day', $result['jd'] === 1 );

// 2025-03-20 (Gregorian) = 1403-12-29 (Jalali, Esfand 29 - leap year)
$result = Rasad_Jalali::gregorian_to_jalali( 2025, 3, 20 );
test( '2025-03-20 -> Jalali year', $result['jy'] === 1403 );
test( '2025-03-20 -> Jalali month', $result['jm'] === 12 );
test( '2025-03-20 -> Jalali day', $result['jd'] === 29 );

// --- Jalali to Gregorian ---
$result = Rasad_Jalali::jalali_to_gregorian( 1403, 1, 1 );
test( '1403-01-01 -> Gregorian year', $result['gy'] === 2024 );
test( '1403-01-01 -> Gregorian month', $result['gm'] === 3 );
test( '1403-01-01 -> Gregorian day', $result['gd'] === 21 );

$result = Rasad_Jalali::jalali_to_gregorian( 1403, 12, 29 );
test( '1403-12-29 -> Gregorian year', $result['gy'] === 2025 );
test( '1403-12-29 -> Gregorian month', $result['gm'] === 3 );
test( '1403-12-29 -> Gregorian day', $result['gd'] === 20 );

// --- Round-trip ---
$gj = Rasad_Jalali::gregorian_to_jalali( 2024, 6, 15 );
$jg = Rasad_Jalali::jalali_to_gregorian( $gj['jy'], $gj['jm'], $gj['jd'] );
test( 'Round-trip year', $jg['gy'] === 2024 );
test( 'Round-trip month', $jg['gm'] === 6 );
test( 'Round-trip day', $jg['gd'] === 15 );

// --- Leap years ---

// --- Month days ---
test( 'Farvardin has 31 days', Rasad_Jalali::jalali_month_days( 1403, 1 ) === 31 );
test( 'Esfand has 30 days in leap year', Rasad_Jalali::jalali_month_days( 1403, 12 ) === 30 );
test( 'Esfand has 29 days in non-leap', Rasad_Jalali::jalali_month_days( 1402, 12 ) === 29 );

test( 'Year days in leap year', Rasad_Jalali::jalali_year_days( 1403 ) === 366 );
test( 'Year days in non-leap', Rasad_Jalali::jalali_year_days( 1402 ) === 365 );

// --- Format Persian ---
$ts = strtotime( '2024-03-21 14:30:00' );
$formatted = Rasad_Jalali::format_persian( $ts, 'Y/m/d H:i' );
test( 'Format Persian year', strpos( $formatted, '1403' ) === 0 );
test( 'Format Persian includes time', strpos( $formatted, '14:30') !== false );

// --- Persian numerals ---
$numerals = Rasad_Jalali::persian_numerals( '12345' );
test( 'Persian numerals', $numerals === '۱۲۳۴۵' );

$ascii = Rasad_Jalali::numerals_to_ascii( '۱۲۳۴۵' );
test( 'Numerals to ASCII', $ascii === '12345' );

echo "\n" . ( $failures === 0 ? 'ALL JALALI TESTS PASSED' : "$failures JALALI TESTS FAILED" ) . "\n";
exit( $failures > 0 ? 1 : 0 );
