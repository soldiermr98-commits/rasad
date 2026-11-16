<?php
/**
 * Jalali (Shamsi) calendar: self-contained Gregorian <-> Jalali converter.
 *
 * Pure PHP, no extensions, PHP 7.4 compatible. Based on the well-known
 * algorithm by F. Mohammadpour / Kazemi — no external data files needed.
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

	/**
	 * Converts a Gregorian date to Jalali.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month (1-12).
	 * @param int $gd Gregorian day (1-31).
	 * @return array{0:int,1:int,2:int} array('jy'=>int,'jm'=>int,'jd'=>int).
	 */
	public static function gregorian_to_jalali( $gy, $gm, $gd ) {
		list( $jy, $jm, $jd ) = self::gregorian_to_jalali_raw( (int) $gy, (int) $gm, (int) $gd );

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
		list( $gy, $gm, $gd ) = self::jalali_to_gregorian_raw( (int) $jy, (int) $jm, (int) $jd );

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
	 * @param int      $ts    Unix timestamp (site timezone).
	 * @param string   $format Format string with placeholders above.
	 * @return string
	 */
	public static function format_persian( $ts, $format = 'Y/m/d' ) {
		$ts     = (int) $ts;
		$utc    = new DateTimeImmutable( '@' . $ts );
		$site_tz = self::site_timezone();
		$local  = $utc->setTimezone( $site_tz );
		$gy     = (int) $local->format( 'Y' );
		$gm     = (int) $local->format( 'm' );
		$gd     = (int) $local->format( 'd' );
		$gh     = (int) $local->format( 'H' );
		$gi     = (int) $local->format( 'i' );
		$gs     = (int) $local->format( 's' );

		list( $jy, $jm, $jd ) = self::gregorian_to_jalali_raw( $gy, $gm, $gd );

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
	 | Internal algorithm                                                |
	 * ---------------------------------------------------------------- */

	private static function gregorian_to_jalali_raw( $gy, $gm, $gd ) {
		// Days since Gregorian epoch (March 1, year 0 proleptic).
		$days = self::gregorian_days_since_epoch( $gy, $gm, $gd );

		// Jalali epoch offset: 1 Farvardin 1 = Gregorian 19 March 622 (Julian/Gregorian proleptic).
		$jalali_epoch_days = self::gregorian_days_since_epoch( 622, 3, 21 );

		$jalali_days = $days - $jalali_epoch_days;

		// Decompose into 4-year cycles + remaining days.
		$jy = 0;

		while ( $jalali_days >= 1461 ) { // 4-year cycle (365*4 + 1 leap)
			$jy += 4;
			$jalali_days -= 1461;
		}

		if ( $jalali_days >= 366 ) {
			$jy++;
			$jalali_days -= 366;
		} else {
			// Leap year logic handled below per-cycle.
		}

		// Approximate year correction via cycle of 33-year leap pattern.
		$jy += (int) ( $jalali_days / 365 );
		$remainder = $jalali_days % 365;

		if ( $remainder >= 366 ) {
			$jy++;
			$remainder -= 366;
		}

		// Month decomposition: first 6 months = 31 days, next 5 = 30, last = 29/30.
		if ( $remainder <= 185 ) { // 6 * 31 - 1
			$jm = (int) floor( $remainder / 31 ) + 1;
			$jd = ( $remainder % 31 ) + 1;
		} else {
			$remainder -= 186;
			$jm = (int) floor( $remainder / 30 ) + 7;
			$jd = ( $remainder % 30 ) + 1;
		}

		return array( $jy, $jm, $jd );
	}

	private static function jalali_to_gregorian_raw( $jy, $jm, $jd ) {
		// Days since Jalali epoch.
		$jalali_days = 0;

		// Whole years before this one: count leap years via 33-year cycles.
		$years_before = $jy - 1;
		$cycles       = (int) floor( $years_before / 33 );
		$remainder_y  = $years_before % 33;

		$jalali_days += $cycles * 12053; // 33-year cycle days (12053 = 33*365 + 8 leap days approx)

		// Leap years in the remaining partial cycle (positions: 1,5,9,13,17,22,26,30).
		$leap_positions = array( 1, 5, 9, 13, 17, 22, 26, 30 );
		$leaps          = 0;

		for ( $y = 0; $y < $remainder_y; $y++ ) {
			$pos = ( $cycles * 33 + $y ) % 33;

			if ( in_array( $pos, $leap_positions, true ) ) {
				$leaps++;
			}
		}

		$jalali_days += $remainder_y * 365 + $leaps;

		// Days within current year before this month.
		if ( $jm <= 6 ) {
			$jalali_days += ( $jm - 1 ) * 31;
		} else {
			$jalali_days += 6 * 31 + ( $jm - 7 ) * 30;
		}

		$jalali_days += $jd - 1;

		// Convert back to Gregorian.
		$jalali_epoch_days = self::gregorian_days_since_epoch( 622, 3, 21 );
		$greg_days         = $jalali_days + $jalali_epoch_days;

		return self::gregorian_from_days( $greg_days );
	}

	private static function is_jalali_leap( $jy ) {
		// Leap positions in 33-year cycle: 1,5,9,13,17,22,26,30.
		$pos = $jy % 33;

		return in_array( $pos, array( 1, 5, 9, 13, 17, 22, 26, 30 ), true );
	}

	/**
	 * Gregorian days since proleptic epoch for year-month-day.
	 *
	 * Based on Howard Hinnant's civil_from_days inverse (days_from_civil).
	 *
	 * @param int $y Year.
	 * @param int $m Month.
	 * @param int $d Day.
	 * @return int Days.
	 */
	private static function gregorian_days_since_epoch( $y, $m, $d ) {
		$y = (int) $y;
		$m = (int) $m;

		if ( $m <= 2 ) {
			$y -= 1;
		}

		$era = ( $y >= 0 ? $y : $y - 399 ) / 400;
		$yoe = ( $y - $era * 400 ); // [0, 399]
		$doy = ( $m > 2 ? ( 153 * ( $m - 3 ) + 2 ) / 5 + 59 : ( 153 * ( $m + 9 ) + 2 ) / 5 ) + $d - 1;
		$doe = $yoe * 365 + (int) floor( $yoe / 4 ) - (int) floor( $yoe / 100 ) + $doy;

		return (int) ( $era * 146097 ) + $doe - 719468;
	}

	/**
	 * Gregorian year-month-day from days since epoch.
	 *
	 * @param int $days Days since epoch.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function gregorian_from_days( $days ) {
		$days = (int) $days + 719468;
		$era  = ( $days >= 0 ? $days : $days - 146096 ) / 146097;
		$doe  = $days - $era * 146097; // [0, 146096]
		$yoe  = ( $doe - ( $doe / 1460 ) + ( $doe / 36524 ) - ( $doe / 146096 ) ) / 365;
		$y    = $yoe + $era * 400;
		$doy  = $doe - ( 365 * $yoe + (int) floor( $yoe / 4 ) - (int) floor( $yoe / 100 ) );
		$mp   = ( 5 * $doy + 2 ) / 153;
		$d    = $doy - ( 153 * $mp + 2 ) / 5 + 1;
		$m    = $mp < 10 ? $mp + 3 : $mp - 9;

		if ( $m <= 2 ) {
			$y += 1;
		}

		return array( $y, $m, $d );
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
