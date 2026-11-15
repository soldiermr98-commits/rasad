<?php
/**
 * Visitor identity: daily salted hash, bot detection, rate limiting.
 *
 * Privacy rules enforced here:
 * - no raw IP or full UA is ever stored by Rasad itself (only an 8-byte
 *   derived key reaches the database, in a later phase);
 * - the salt lives in a file outside the web-served plugin directory
 *   (wp-content/uploads/rasad/) and rotates its daily component every day.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visitor helper.
 */
final class Rasad_Collect_Visitor {

	/**
	 * Per-request cache of the site secret.
	 *
	 * @var string|null
	 */
	private static $secret = null;

	/**
	 * Computes the 16-hex-char (8-byte) visitor key.
	 *
	 * key = substr( HMAC-SHA256( ip + UA + day + secret, secret ), 0, 16 ).
	 * The daily component makes keys rotate every day, so no long-lived
	 * identifier can be built from the data we keep.
	 *
	 * @param string      $ip  Client IP (raw, never stored).
	 * @param string      $ua  User agent (raw, never stored).
	 * @param string|null $day Optional Y-m-d day for tests / re-derivation.
	 * @return string Empty string when no secret can be persisted (hit is dropped).
	 */
	public static function key( $ip, $ua, $day = null ) {
		$secret = self::secret();

		if ( '' === $secret ) {
			return '';
		}

		$day = ( null === $day ) ? date( 'Y-m-d' ) : (string) $day;

		return substr(
			hash_hmac( 'sha256', (string) $ip . "\n" . (string) $ua . "\n" . $day, $secret ),
			0,
			16
		);
	}

	/**
	 * Returns (and creates once) the site secret.
	 *
	 * @return string Hex secret, or '' when the storage dir is unusable.
	 */
	public static function secret() {
		if ( null !== self::$secret ) {
			return self::$secret;
		}

		self::$secret = '';

		if ( ! Rasad_Plugin::ensure_storage() ) {
			return self::$secret;
		}

		$file = Rasad_Plugin::storage_dir( 'secret.key' );
		$data = self::read_secret( $file );

		if ( '' === $data ) {
			// Exclusive create: only one process wins the race, the rest re-read.
			$fp = @fopen( $file, 'x' );

			if ( false !== $fp ) {
				$generated = bin2hex( random_bytes( 32 ) );
				fwrite( $fp, $generated );
				fclose( $fp );
				$data = $generated;
			} else {
				$data = self::read_secret( $file );
			}
		}

		self::$secret = $data;

		return self::$secret;
	}

	/**
	 * Cheap bot detection: UA substring list + header heuristics.
	 *
	 * IP/datacenter ranges are intentionally not checked here — that needs a
	 * GeoIP database and belongs to the aggregator (never the hot path).
	 *
	 * @param string      $ua     User agent.
	 * @param array|null  $server Superglobal (defaults to $_SERVER).
	 * @return bool True when the request looks like a bot/crawler.
	 */
	public static function is_bot( $ua, $server = null ) {
		$server = is_array( $server ) ? $server : $_SERVER;
		$ua     = is_string( $ua ) ? trim( $ua ) : '';

		// An empty UA is never a real browser.
		if ( '' === $ua ) {
			return true;
		}

		// Header heuristics: preview fetchers and prefetch/prerender requests.
		if ( isset( $server['HTTP_X_PURPOSE'] ) && 'preview' === strtolower( (string) $server['HTTP_X_PURPOSE'] ) ) {
			return true;
		}

		if ( isset( $server['HTTP_X_MOZ'] ) && 'preview' === strtolower( (string) $server['HTTP_X_MOZ'] ) ) {
			return true;
		}

		if ( isset( $server['HTTP_SEC_FETCH_MODE'] ) ) {
			$mode = strtolower( (string) $server['HTTP_SEC_FETCH_MODE'] );

			if ( in_array( $mode, array( 'prefetch', 'prerender' ), true ) ) {
				return true;
			}
		}

		// Strip known false-positive device brands before scanning for "bot".
		$scan = str_ireplace( 'cubot', '', $ua );
		$scan = strtolower( $scan );

		$needles = array(
			'bot',
			'spider',
			'crawl',
			'slurp',
			'preview',
			'monitor',
			'scanner',
			'python',
			'curl/',
			'wget',
			'libwww',
			'httpclient',
			'okhttp',
			'go-http-client',
			'winhttp',
			'aiohttp',
			'node-fetch',
			'scrapy',
			'aria2',
			'zgrab',
			'masscan',
			'nmap',
			'nikto',
			'sqlmap',
			'wpscan',
			'gobuster',
			'phantomjs',
			'headlesschrome',
			'lighthouse',
			'pingdom',
			'uptimerobot',
			'facebookexternalhit',
			'twitterbot',
			'whatsapp',
			'embedly',
			'pinterest',
			'redditbot',
			'linkedinbot',
			'telegrambot',
			'slackbot',
			'discordbot',
			'skypeuripreview',
			'vkshare',
			'w3c_validator',
			'feedfetcher',
			'semrush',
			'ahrefs',
			'dotbot',
			'mj12bot',
			'rogerbot',
			'petalbot',
			'applebot',
			'yandex',
			'baiduspider',
			'sogou',
			'exabot',
			'ia_archiver',
			'screaming frog',
			'gtmetrix',
			'webpagetest',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $scan, $needle ) ) {
				return true;
			}
		}

		/**
		 * Filters the bot UA substring list.
		 *
		 * @param string[] $needles Lowercase needles.
		 */
		$extra = apply_filters( 'rasad_bot_patterns', array() );

		if ( is_array( $extra ) ) {
			foreach ( $extra as $needle ) {
				if ( is_string( $needle ) && '' !== $needle && false !== strpos( $scan, strtolower( $needle ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sliding-minute rate limit per visitor key.
	 *
	 * State lives in one small file per minute under buffer/rate/ (JSON map of
	 * visitor_key => hits). Read-modify-write happens under LOCK_EX, so the
	 * limit is exact; old buckets are pruned opportunistically and by the
	 * cleaner in a later phase.
	 *
	 * @param string      $vk  16-hex visitor key.
	 * @param string|null $now Optional YmdHi minute for tests.
	 * @return bool True when this visitor exceeded the per-minute limit.
	 */
	public static function rate_limited( $vk, $now = null ) {
		$vk = (string) $vk;

		if ( '' === $vk ) {
			return true;
		}

		/**
		 * Filters the maximum hits per visitor per minute.
		 *
		 * @param int $limit Default 120.
		 */
		$limit = (int) apply_filters( 'rasad_rate_limit', 120 );
		$limit = max( 1, $limit );

		$now = ( null === $now ) ? date( 'YmdHi' ) : (string) $now;

		if ( ! Rasad_Plugin::ensure_storage() ) {
			// Cannot enforce the limit without storage — fail open so a broken
			// uploads dir does not silently kill analytics; push() will fail anyway.
			return false;
		}

		$dir = rtrim( Rasad_Plugin::storage_dir( 'buffer/rate' ), '/' ) . '/';

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}

		$file  = $dir . $now . '.json';
		$fp    = @fopen( $file, 'c+' );

		if ( false === $fp ) {
			return false;
		}

		if ( ! flock( $fp, LOCK_EX ) ) {
			fclose( $fp );

			return false;
		}

		$raw   = stream_get_contents( $fp );
		$map   = array();

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		}

		$count   = isset( $map[ $vk ] ) ? (int) $map[ $vk ] + 1 : 1;
		$limited = $count > $limit;

		if ( ! $limited ) {
			$map[ $vk ] = $count;
			rewind( $fp );
			ftruncate( $fp, 0 );
			fwrite( $fp, wp_json_encode( $map ) );
			fflush( $fp );
		}

		flock( $fp, LOCK_UN );
		fclose( $fp );

		// 2% of requests: drop rate buckets older than 10 minutes.
		if ( 1 === mt_rand( 1, 50 ) ) {
			self::prune_rate_dir( $dir );
		}

		return $limited;
	}

	/**
	 * Reads and validates the stored secret file.
	 *
	 * @param string $file Absolute path.
	 * @return string Hex string or ''.
	 */
	private static function read_secret( $file ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$data = @file_get_contents( $file );
		$data = is_string( $data ) ? trim( $data ) : '';

		if ( strlen( $data ) < 32 || ! ctype_xdigit( $data ) ) {
			return '';
		}

		return $data;
	}

	/**
	 * Deletes stale rate-limit buckets.
	 *
	 * @param string $dir Rate directory.
	 * @return void
	 */
	private static function prune_rate_dir( $dir ) {
		$files = glob( $dir . '*.json' );

		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff = time() - 600;

		foreach ( $files as $file ) {
			$mtime = @filemtime( $file );

			if ( false !== $mtime && $mtime < $cutoff ) {
				@unlink( $file );
			}
		}
	}
}
