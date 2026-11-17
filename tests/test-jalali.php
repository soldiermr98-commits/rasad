<?php
/**
 * Jalali calendar tests.
 *
 * @package Rasad
 */

// Gregorian -> Jalali (astronomical convention: Nowruz varies Mar 20/21).
$r = Rasad_Jalali::gregorian_to_jalali( 2024, 3, 20 );
test( '2024-03-20 -> 1403 year', $r['jy'] === 1403 );
test( '2024-03-20 -> 1403 month 1', $r['jm'] === 1 );
test( '2024-03-20 -> 1403 day 1', $r['jd'] === 1 );

$r = Rasad_Jalali::gregorian_to_jalali( 2024, 9, 22 );
test( '2024-09-22 -> 1403 year', $r['jy'] === 1403 );
test( '2024-09-22 -> 1403 month 7', $r['jm'] === 7 );
test( '2024-09-22 -> 1403 day 1', $r['jd'] === 1 );

// Jalali -> Gregorian.
$r = Rasad_Jalali::jalali_to_gregorian( 1403, 1, 1 );
test( '1403-01-01 -> 2024', $r['gy'] === 2024 );
test( '1403-01-01 -> March', $r['gm'] === 3 );
test( '1403-01-01 -> day 20', $r['gd'] === 20 );

// Round-trip.
$gj = Rasad_Jalali::gregorian_to_jalali( 2024, 6, 15 );
$jg = Rasad_Jalali::jalali_to_gregorian( $gj['jy'], $gj['jm'], $gj['jd'] );
test( 'Round-trip year', $jg['gy'] === 2024 );
test( 'Round-trip month', $jg['gm'] === 6 );
test( 'Round-trip day', $jg['gd'] === 15 );

// Month/year lengths.
test( 'Farvardin has 31 days', Rasad_Jalali::jalali_month_days( 1403, 1 ) === 31 );
test( 'Esfand leap 30 days', Rasad_Jalali::jalali_month_days( 1403, 12 ) === 30 );
test( 'Esfand non-leap 29 days', Rasad_Jalali::jalali_month_days( 1402, 12 ) === 29 );
test( 'Leap year 366 days', Rasad_Jalali::jalali_year_days( 1403 ) === 366 );
test( 'Non-leap year 365 days', Rasad_Jalali::jalali_year_days( 1402 ) === 365 );

// Persian formatting + numerals.
$formatted = Rasad_Jalali::format_persian( strtotime( '2024-03-21 14:30:00 UTC' ), 'Y/m/d H:i' );
test( 'Format Persian year', strpos( $formatted, '۱۴۰۳' ) === 0 );
test( 'Format Persian time', strpos( $formatted, '۱۴:۳۰' ) !== false );

test( 'Persian numerals', Rasad_Jalali::persian_numerals( '12345' ) === '۱۲۳۴۵' );
test( 'Numerals to ASCII', Rasad_Jalali::numerals_to_ascii( '۱۲۳۴۵' ) === '12345' );
