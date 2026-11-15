<?php
/**
 * Lightweight, self-written UA parser (browser / OS / device).
 *
 * Runs only inside the aggregator (never on the collect hot path). The
 * numeric codes land in `rsd_sessions` (TINYINT) and `rsd_daily_dim`
 * (dim_id), so they are part of the data model — never renumber them.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses a User-Agent string into compact dimension codes.
 */
final class Rasad_Collect_Ua_Parser {

	// Browser codes.
	const BROWSER_UNKNOWN   = 0;
	const BROWSER_CHROME    = 1;
	const BROWSER_FIREFOX   = 2;
	const BROWSER_SAFARI    = 3;
	const BROWSER_EDGE      = 4;
	const BROWSER_OPERA     = 5;
	const BROWSER_SAMSUNG   = 6;
	const BROWSER_UC        = 7;
	const BROWSER_YANDEX    = 8;
	const BROWSER_BRAVE     = 9;
	const BROWSER_IE        = 10;
	const BROWSER_VIVALDI   = 11;

	// Operating system codes.
	const OS_UNKNOWN     = 0;
	const OS_WINDOWS     = 1;
	const OS_MACOS       = 2;
	const OS_ANDROID     = 3;
	const OS_IOS         = 4;
	const OS_LINUX       = 5;
	const OS_CHROME_OS   = 6;
	const OS_HARMONY     = 7;

	// Device codes.
	const DEVICE_UNKNOWN  = 0;
	const DEVICE_DESKTOP  = 1;
	const DEVICE_MOBILE   = 2;
	const DEVICE_TABLET   = 3;

	/**
	 * Parses a UA string.
	 *
	 * Order matters: Chromium forks (Edge/Opera/Samsung/UC/Yandex/Brave/
	 * Vivaldi) must be checked before Chrome, and iOS before macOS.
	 *
	 * @param string $ua User-Agent header.
	 * @return array{browser:int,os:int,device:int}
	 */
	public static function parse( $ua ) {
		$ua = is_string( $ua ) ? $ua : '';

		if ( '' === $ua ) {
			return array(
				'browser' => self::BROWSER_UNKNOWN,
				'os'      => self::OS_UNKNOWN,
				'device'  => self::DEVICE_UNKNOWN,
			);
		}

		return array(
			'browser' => self::browser( $ua ),
			'os'      => self::os( $ua ),
			'device'  => self::device( $ua ),
		);
	}

	/**
	 * Browser code.
	 *
	 * @param string $ua User-Agent.
	 * @return int
	 */
	private static function browser( $ua ) {
		// Chromium forks first — they all embed the Chrome token.
		if ( preg_match( '/(?:Edg|EdgA|EdgiOS|Edge)\//', $ua ) ) {
			return self::BROWSER_EDGE;
		}
		if ( preg_match( '/(?:OPR|OPiOS)\//', $ua ) || false !== stripos( $ua, 'Opera Mini' ) ) {
			return self::BROWSER_OPERA;
		}
		if ( preg_match( '/SamsungBrowser\//', $ua ) ) {
			return self::BROWSER_SAMSUNG;
		}
		if ( preg_match( '/(?:UCBrowser|UBrowser)\//', $ua ) ) {
			return self::BROWSER_UC;
		}
		if ( preg_match( '/YaBrowser\//', $ua ) ) {
			return self::BROWSER_YANDEX;
		}
		if ( preg_match( '/Brave\//', $ua ) ) {
			return self::BROWSER_BRAVE;
		}
		if ( preg_match( '/Vivaldi[\/ ]/', $ua ) ) {
			return self::BROWSER_VIVALDI;
		}
		if ( preg_match( '/(?:Chrome|CriOS)\//', $ua ) ) {
			return self::BROWSER_CHROME;
		}
		if ( preg_match( '/(?:Firefox|FxiOS)\//', $ua ) ) {
			return self::BROWSER_FIREFOX;
		}
		if ( false !== strpos( $ua, 'MSIE' ) || false !== strpos( $ua, 'Trident/' ) ) {
			return self::BROWSER_IE;
		}
		// Safari advertises Safari+Version but never Chrome/Firefox tokens.
		if ( preg_match( '/Version\/[\d.]+.*Safari\/|Safari\//', $ua ) ) {
			return self::BROWSER_SAFARI;
		}

		return self::BROWSER_UNKNOWN;
	}

	/**
	 * Operating system code.
	 *
	 * @param string $ua User-Agent.
	 * @return int
	 */
	private static function os( $ua ) {
		if ( preg_match( '/iPhone|iPad|iPod/', $ua ) ) {
			return self::OS_IOS;
		}
		if ( false !== strpos( $ua, 'Android' ) ) {
			return self::OS_ANDROID;
		}
		if ( false !== strpos( $ua, 'Windows Phone' ) || preg_match( '/Windows NT/', $ua ) ) {
			return self::OS_WINDOWS;
		}
		if ( false !== strpos( $ua, 'CrOS' ) ) {
			return self::OS_CHROME_OS;
		}
		if ( false !== strpos( $ua, 'HarmonyOS' ) ) {
			return self::OS_HARMONY;
		}
		if ( false !== strpos( $ua, 'Mac OS X' ) || false !== strpos( $ua, 'Macintosh' ) ) {
			return self::OS_MACOS;
		}
		if ( false !== strpos( $ua, 'Linux' ) || false !== strpos( $ua, 'X11' ) ) {
			return self::OS_LINUX;
		}

		return self::OS_UNKNOWN;
	}

	/**
	 * Device class code.
	 *
	 * Notes: iPad is always a tablet; Android without the "Mobile" token is
	 * a tablet; iPadOS 13+ in desktop mode can read as a Mac (accepted edge).
	 *
	 * @param string $ua User-Agent.
	 * @return int
	 */
	private static function device( $ua ) {
		if ( preg_match( '/iPad/', $ua ) ) {
			return self::DEVICE_TABLET;
		}
		if ( preg_match( '/Tablet|PlayBook|Kindle|Silk\/|Nexus (?:7|9|10)/', $ua ) ) {
			return self::DEVICE_TABLET;
		}
		if ( false !== strpos( $ua, 'Android' ) ) {
			return preg_match( '/Mobile/', $ua ) ? self::DEVICE_MOBILE : self::DEVICE_TABLET;
		}
		if ( preg_match( '/iPhone|iPod|Windows Phone|BlackBerry|BB10|IEMobile|Mobi|Opera Mini|webOS/', $ua ) ) {
			return self::DEVICE_MOBILE;
		}

		return self::DEVICE_DESKTOP;
	}

	/**
	 * Canonical English labels for every code (dashboards/exports translate).
	 *
	 * @return array{browser:array<int,string>,os:array<int,string>,device:array<int,string>}
	 */
	public static function labels() {
		return array(
			'browser' => array(
				self::BROWSER_UNKNOWN => 'Unknown',
				self::BROWSER_CHROME  => 'Chrome',
				self::BROWSER_FIREFOX => 'Firefox',
				self::BROWSER_SAFARI  => 'Safari',
				self::BROWSER_EDGE    => 'Edge',
				self::BROWSER_OPERA   => 'Opera',
				self::BROWSER_SAMSUNG => 'Samsung Internet',
				self::BROWSER_UC      => 'UC Browser',
				self::BROWSER_YANDEX  => 'Yandex Browser',
				self::BROWSER_BRAVE   => 'Brave',
				self::BROWSER_IE      => 'Internet Explorer',
				self::BROWSER_VIVALDI => 'Vivaldi',
			),
			'os'      => array(
				self::OS_UNKNOWN   => 'Unknown',
				self::OS_WINDOWS   => 'Windows',
				self::OS_MACOS     => 'macOS',
				self::OS_ANDROID   => 'Android',
				self::OS_IOS       => 'iOS',
				self::OS_LINUX     => 'Linux',
				self::OS_CHROME_OS => 'Chrome OS',
				self::OS_HARMONY   => 'HarmonyOS',
			),
			'device'  => array(
				self::DEVICE_UNKNOWN => 'Unknown',
				self::DEVICE_DESKTOP => 'Desktop',
				self::DEVICE_MOBILE  => 'Mobile',
				self::DEVICE_TABLET  => 'Tablet',
			),
		);
	}
}
