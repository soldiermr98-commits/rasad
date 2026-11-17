<?php
/**
 * Jalali (Shamsi) calendar: self-contained Gregorian <-> Jalali converter.
 *
 * Pure integer math, no extensions, PHP 7.4 compatible. Uses the standard
 * jalali.org reference algorithm (33-year leap cycle), accurate for the
 * whole supported range (Gregorian years ~1789-2261 / Jalali ~1167-1640).
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jalali date utilities.
 *
 * Supports:
 * - gregorian_to_jalali( Y, M, D ) -> array('jy','jm','jd')
 * - jalali_to_gregorian( jy, jm, jd ) -> array('gy','gm','gd')
 * - jalali_year_days( jy )
 * - jalali_month_days( jy, jm )
 * - format_persian( $timestamp, $format ) — strftime-like with Persian numerals.
 * - persian_numerals( $string ) — converts ASCII digits to Persian/Arabic-Indic digits.
 *
 * @package Rasad
 */
final class Rasad_Jalali {

	/** Persian/Arabic-Indic digits. */
	const DIGITS = array(
		'0' => '۰',
		'1' => '۱',
		'2' => '۲',
		'3' => '۳',
		'4' => '۴',
		'5' => '۵',
		'6' => '۶',
		'7' => '۷',
		'8' => '۸',
		'9' => '۹',
	);

	/** Gregorian month day offsets (non-leap). */
	private static $g_days = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

	/**
	 * Converts a Gregorian date to Jalali.
		*
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month (1-12).
	 * @param int $gd Gregorian day (1-31).
	 * @return array{0:int,1:int,2:int} array('jy'=>int,'jm'=>int,'jd'=>int).
	 */
	public static function gregorian_to_jalali( $gy, $gm, $gd ) {
		list( $jy, $jm, $jd ) = self::to_jalali( (int) $gy, (int) $gm, (int) $gd );

		return array( 'jy' => $jy, 'jm' => $jm, 'jd' => $jd );
	}

	/**
	 * Converts a Jalali date to Gregorian.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month (1-12).
	 * @param int $jd Jalali day (1-29/30/31).
	 * @return array{0:int,1:int,2:int} array('gy'=>int,'gm'=>int,'gd'=>int).
	 */
	public static function jalali_to_gregorian( $jy, $jm, $jd ) {
		list( $gy, $gm, $gd ) = self::to_gregorian( (int) $jy, (int) $jm, (int) $jd );

		return array( 'gy' => $gy, 'gm' => $gm, 'gd' => $gd );
	}

	/**
	 * Number of days in a Jalali year.
	 *
	 * @param int $jy Jalali year.
	 * @return int 365 or 366.
	 */
	public static function jalali_year_days( $jy ) {
		return self::is_jalali_leap( (int) $jy ) ? 366 : 365;
	}

	/**
	 * Number of days in a Jalali month.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month (1-12).
	 * @return int 29, 30 or 31.
	 */
	public static function jalali_month_days( $jy, $jm ) {
		$jy = (int) $jy;
		$jm = (int) $jm;

		if ( $jm <= 6 ) {
			return 31;
		}

		if ( $jm <= 11 ) {
			return 30;
		}

		// Esfand (month 12): 29 or 30 in leap years.
		return self::is_jalali_leap( $jy ) ? 30 : 29;
	}

	/**
	 * Formats a Unix timestamp into a Jalali date string.
	 *
	 * Supported placeholders:
	 *   Y — full Jalali year (4 digits)
	 *   y — 2-digit Jalali year
	 *   m — Jalali month, zero-padded (01-12)
	 *   n — Jalali month without padding (1-12)
	 *   d — Jalali day, zero-padded (01-31)
	 *   j — Jalali day without padding (1-31)
	 *   H, i, s — hour, minute, second (unchanged from Gregorian time)
	 *
	 * Unknown characters pass through unchanged.
	 *
	 * @param int    $ts     Unix timestamp (site timezone).
	 * @param string $format Format string with placeholders above.
	 * @return string
	 */
	public static function format_persian( $ts, $format = 'Y/m/d' ) {
		$ts      = (int) $ts;
		$utc     = new DateTimeImmutable( '@' . $ts );
		$site_tz = self::site_timezone();
		$local   = $utc->setTimezone( $site_tz );
		$gy      = (int) $local->format( 'Y' );
		$gm      = (int) $local->format( 'm' );
		$gd      = (int) $local->format( 'd' );
		$gh      = (int) $local->format( 'H' );
		$gi      = (int) $local->format( 'i' );
		$gs      = (int) $local->format( 's' );

		list( $jy, $jm, $jd ) = self::to_jalali( $gy, $gm, $gd );

		$out = '';

		for ( $i = 0, $len = strlen( $format ); $i < $len; $i++ ) {
			$c = $format[ $i ];

			switch ( $c ) {
				case 'Y':
					$out .= self::persian_numerals( sprintf( '%04d', $jy ) );
					break;
				case 'y':
					$out .= self::persian_numerals( sprintf( '%02d', $jy % 100 ) );
					break;
				case 'm':
					$out .= self::persian_numerals( sprintf( '%02d', $jm ) );
					break;
				case 'n':
					$out .= self::persian_numerals( (string) $jm );
					break;
				case 'd':
					$out .= self::persian_numerals( sprintf( '%02d', $jd ) );
					break;
				case 'j':
					$out .= self::persian_numerals( (string) $jd );
					break;
				case 'H':
					$out .= self::persian_numerals( sprintf( '%02d', $gh ) );
					break;
				case 'i':
					$out .= self::persian_numerals( sprintf( '%02d', $gi ) );
					break;
				case 's':
					$out .= self::persian_numerals( sprintf( '%02d', $gs ) );
					break;
				default:
					$out .= $c;
					break;
			}
		}

		return $out;
	}

	/**
	 * Converts ASCII digits in a string to Persian/Arabic-Indic digits.
	 *
	 * @param string $text Text possibly containing digits.
	 * @return string
	 */
	public static function persian_numerals( $text ) {
		return strtr( (string) $text, self::DIGITS );
	}

	/**
	 * Converts Persian/Arabic-Indic digits back to ASCII digits.
	 *
	 * @param string $text Text possibly containing Persian digits.
	 * @return string
	 */
	public static function numerals_to_ascii( $text ) {
		return strtr( (string) $text, array_flip( self::DIGITS ) );
	}

	/* ---------------------------------------------------------------- *
	 | Internal algorithm (jalali.org reference, 33-year cycle)         |
	 * ---------------------------------------------------------------- */

	/**
	 * Gregorian -> Jalali core.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month.
	 * @param int $gd Gregorian day.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function to_jalali( $gy, $gm, $gd ) {
		$jy = ( $gy <= 1600 ) ? 0 : 979;
		$gy -= ( $gy <= 1600 ) ? 621 : 1600;
		$gy2 = ( $gm > 2 ) ? $gy + 1 : $gy;
		$days = 365 * $gy
			+ (int) floor( ( $gy2 + 3 ) / 4 )
			- (int) floor( ( $gy2 + 99 ) / 100 )
			+ (int) floor( ( $gy2 + 399 ) / 400 )
			- 80 + $gd + self::$g_days[ $gm - 1 ];

		$jy += 33 * (int) floor( $days / 12053 );
		$days %= 12053;
		$jy += 4 * (int) floor( $days / 1461 );
		$days %= 1461;
		$jy += (int) ( ( $days - 1 ) / 365 );

		if ( $days > 365 ) {
			$days = ( $days - 1 ) % 365;
		}

		if ( $days < 186 ) {
			$jm = 1 + (int) floor( $days / 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + (int) floor( ( $days - 186 ) / 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}

		return array( $jy, $jm, $jd );
	}

	/**
	 * Jalali -> Gregorian core.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month.
	 * @param int $jd Jalali day.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function to_gregorian( $jy, $jm, $jd ) {
		$jy += 1595;

		$total = 365 * $jy
			+ (int) floor( $jy / 33 ) * 8
			+ (int) floor( ( ( $jy % 33 ) + 3 ) / 4 )
			+ $jd
			+ ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 )
			- 355668;

		$gy = 400 * (int) floor( $total / 146097 );
		$total %= 146097;

		if ( $total > 36524 ) {
			$gy += 100 * (int) floor( $total / 36524 );
			$total %= 36524;

			if ( $total >= 365 ) {
				$gy++;
				$total -= 365;
			}
		}

		$gy += 4 * (int) floor( $total / 1461 );
		$total %= 1461;

		if ( $total > 365 ) {
			$gy += (int) floor( ( $total - 1 ) / 365 );
			$total = ( $total - 1 ) % 365;
		}

		$leap = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;

		$gm = 1;

		for ( $m = 2; $m <= 12; $m++ ) {
			$offset = self::$g_days[ $m - 1 ] + ( ( $m > 2 && $leap ) ? 1 : 0 );

			if ( $total < $offset ) {
				break;
			}

			$gm = $m;
		}

		$gd = 1 + $total - self::$g_days[ $gm - 1 ] - ( ( $gm > 2 && $leap ) ? 1 : 0 );

		return array( (int) $gy, (int) $gm, (int) $gd );
	}

	/**
	 * Whether a Jalali year is a leap year (33-year cycle positions
	 * 1, 5, 9, 13, 17, 22, 26, 30).
	 *
	 * @param int $jy Jalali year.
	 * @return bool
	 */
	private static function is_jalali_leap( $jy ) {
		$jy = (int) $jy;

		$a = self::to_gregorian( $jy, 1, 1 );
		$b = self::to_gregorian( $jy + 1, 1, 1 );
		$start = new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $a[0], $a[1], $a[2] ), new DateTimeZone( 'UTC' ) );
		$end = new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $b[0], $b[1], $b[2] ), new DateTimeZone( 'UTC' ) );
		return 366 === (int) $start->diff( $end )->days;
	}

	/**
	 * Site timezone object.
	 *
	 * @return DateTimeZone
	 */
	private static function site_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'UTC' );
	}
}
