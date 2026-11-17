<?php
/** Standalone regression for every day in the supported analytics period. */
require_once __DIR__ . '/bootstrap.php';
$day = new DateTimeImmutable( '2000-01-01', new DateTimeZone( 'UTC' ) );
$end = new DateTimeImmutable( '2101-01-01', new DateTimeZone( 'UTC' ) );
$count = 0;
while ( $day < $end ) {
	$j = Rasad_Jalali::gregorian_to_jalali( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
	$g = Rasad_Jalali::jalali_to_gregorian( $j['jy'], $j['jm'], $j['jd'] );
	if ( sprintf( '%04d-%02d-%02d', $g['gy'], $g['gm'], $g['gd'] ) !== $day->format( 'Y-m-d' ) || $j['jd'] < 1 || $j['jd'] > Rasad_Jalali::jalali_month_days( $j['jy'], $j['jm'] ) ) {
		fwrite( STDERR, 'FAIL ' . $day->format( 'Y-m-d' ) . ' ' . json_encode( array( $j, $g ) ) . PHP_EOL );
		exit( 1 );
	}
	$count++;
	$day = $day->modify( '+1 day' );
}
echo 'PASS ' . $count . ' consecutive Gregorian/Jalali round trips (2000–2100).' . PHP_EOL;
