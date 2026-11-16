<?php
/**
 * Collect request: decode, validate, exclude, hand off to the buffer.
 *
 * Shared by collect.php (SHORTINIT) and the REST fallback route. Returns an
 * HTTP status code; never echoes anything; never touches the database when a
 * settings mirror file exists (the normal hot path).
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request validation and collect orchestration.
 */
final class Rasad_Collect_Request {

	/**
	 * Diagnostic status of the last process() call.
	 *
	 * ok      — accepted and pushed to the buffer.
	 * dropped — intentionally ignored (bot, DNT, exclusions, rate limit…).
	 * error   — rejected (bad payload, cross-origin, buffer failure…).
	 *
	 * @var string
	 */
	private static $last_status = 'error';

	/**
	 * Status of the most recent process() call.
	 *
	 * @return string ok|dropped|error.
	 */
	public static function last_status() {
		return self::$last_status;
	}

	/**
	 * Self-test key written on activation (selftest-key.php next to the
	 * plugin). Readable without any WordPress API — collect.php uses it
	 * before/without a working bootstrap.
	 *
	 * @return string Hex key or '' when missing.
	 */
	public static function selftest_key() {
		$file = RASAD_DIR . 'selftest-key.php';

		if ( ! is_readable( $file ) ) {
			return '';
		}

		try {
			$key = include $file;
		} catch ( Throwable $e ) {
			return '';
		}

		return is_string( $key ) ? $key : '';
	}

	/**
	 * May this response carry the X-Rasad-Status diagnostic header?
	 *
	 * True when WP_DEBUG is on, or the request carries the valid
	 * self-test key (?rasad_test=… / X-Rasad-Test-Key: …).
	 *
	 * @return bool
	 */
	public static function diag_requested() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}

		$given = '';

		if ( isset( $_GET['rasad_test'] ) && is_string( $_GET['rasad_test'] ) ) {
			$given = $_GET['rasad_test'];
		} elseif ( isset( $_SERVER['HTTP_X_RASAD_TEST_KEY'] ) && is_string( $_SERVER['HTTP_X_RASAD_TEST_KEY'] ) ) {
			$given = $_SERVER['HTTP_X_RASAD_TEST_KEY'];
		}

		if ( '' === $given || strlen( $given ) > 128 ) {
			return false;
		}

		$stored = self::selftest_key();

		return '' !== $stored && strlen( $stored ) === strlen( $given ) && hash_equals( $stored, $given );
	}

	/**
	 * Records `dropped` and returns the silent 204.
	 *
	 * @return int 204.
	 */
	private static function drop() {
		self::$last_status = 'dropped';

		return 204;
	}

	/**
	 * Records `ok` and returns the accepted 204.
	 *
	 * @return int 204.
	 */
	private static function accepted() {
		self::$last_status = 'ok';

		return 204;
	}

	/**
	 * Records `error` and returns the rejection code.
	 *
	 * @param int $code HTTP status (400/403/500).
	 * @return int
	 */
	private static function rejected( $code ) {
		self::$last_status = 'error';

		return (int) $code;
	}

	/**
	 * Processes one raw beacon payload end to end.
	 *
	 * @param string $raw Raw request body (JSON) or pixel query payload.
	 * @return int HTTP status: 204 accepted/silently dropped, 400 bad payload,
	 *              403 cross-origin, 500 buffer failure.
	 */
	public static function process( $raw ) {
		self::$last_status = 'error';

		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > RASAD_MAX_PAYLOAD ) {
			return self::rejected( 400 );
		}

		if ( ! self::same_origin() ) {
			return self::rejected( 403 );
		}

		$settings = Rasad_Settings::all();

		// Browser-side DNT is enforced in t.js; also honor the request header.
		if ( ! empty( $settings['honor_dnt'] ) && isset( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) {
			return self::drop();
		}

		$decoded = self::decode( $raw );

		if ( null === $decoded ) {
			return self::rejected( 400 );
		}

		$data = self::sanitize( $decoded );

		if ( null === $data ) {
			return self::rejected( 400 );
		}

		/**
		 * Filters whether this request should be tracked at all.
		 *
		 * @param bool                    $track Current decision (default true).
		 * @param array<string,mixed>|null $data  Sanitized payload (null at enqueue time).
		 */
		if ( ! apply_filters( 'rasad_should_track', true, $data ) ) {
			return self::drop();
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ip = self::client_ip();

		if ( Rasad_Collect_Visitor::is_bot( $ua ) ) {
			return self::drop();
		}

		if ( self::is_excluded( $data, $ua, $ip, $settings ) ) {
			return self::drop();
		}

		$vk = Rasad_Collect_Visitor::key( $ip, $ua );

		// No persistable secret => refuse to build an unsalted identifier.
		if ( '' === $vk ) {
			return self::drop();
		}

		if ( Rasad_Collect_Visitor::rate_limited( $vk ) ) {
			return self::drop();
		}

		// Role exclusion is the only DB lookup on the hot path (logged-in
		// users only) — placed after the rate limit so floods never query.
		if ( self::is_excluded_user( $settings ) ) {
			return self::drop();
		}

		$hit = self::to_buffer( $data, $vk, $ip, $ua );

		/**
		 * Filters a hit right before it enters the buffer.
		 *
		 * @param array<string,mixed> $hit  Buffer line.
		 * @param array<string,mixed> $data Sanitized request data.
		 */
		$hit = apply_filters( 'rasad_hit_data', $hit, $data );

		if ( ! is_array( $hit ) || empty( $hit['p'] ) ) {
			return self::rejected( 400 );
		}

		$buffer = Rasad_Plugin::buffer();

		if ( ! $buffer->push( $hit ) ) {
			return self::rejected( 500 );
		}

		/**
		 * Fires after a hit was pushed to the buffer (used later to trigger
		 * non-blocking aggregation when the backlog grows).
		 *
		 * @param array<string,mixed> $hit Buffer line.
		 */
		do_action( 'rasad_after_hit', $hit );

		return self::accepted();
	}

	/**
	 * Decodes a JSON body, or a query string (pixel fallback: ?d=1&b=...).
	 *
	 * @param string $raw Raw payload.
	 * @return array<string,mixed>|null Null when nothing usable was sent.
	 */
	public static function decode( $raw ) {
		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$query = array();
		parse_str( (string) $raw, $query );

		// GET/pixel fallback is strict: exactly ?d=1&b=<json object>. Bare
		// query strings are never treated as payloads.
		if ( isset( $query['d'] ) && '1' === (string) $query['d']
			&& isset( $query['b'] ) && is_string( $query['b'] ) ) {
			$inner = json_decode( $query['b'], true );

			return is_array( $inner ) ? $inner : null;
		}

		return null;
	}

	/**
	 * Strictly sanitizes and normalizes a decoded payload.
	 *
	 * Expected keys: e (1 pageview | 2 duration ping), p path, t title,
	 * r referrer, w screen width, l language, tz offset, us/um/uc/ut/ud UTM,
	 * d active duration (ms, ping only).
	 *
	 * @param array<string,mixed> $data Decoded payload.
	 * @return array<string,mixed>|null Null when required fields are missing.
	 */
	public static function sanitize( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$event = isset( $data['e'] ) ? (int) $data['e'] : 1;

		if ( ! in_array( $event, array( 1, 2 ), true ) ) {
			$event = 1;
		}

		if ( ! isset( $data['p'] ) || ! is_string( $data['p'] ) ) {
			return null;
		}

	$raw_path = $data['p'];

	// Strip fragments — they never reach the server and must not split paths.
	$raw_path = self::strip_fragment( $raw_path );

	// UTM lives in the query string: extract it, then strip it so campaign
	// variants never split the page statistics (t.js sends p as-is).
	$utm = self::extract_utm( $raw_path );

	// Query-string policy: keep only the hard whitelist plus admin-editable
	// extras; drop everything else (fbclid, gclid, key, login, token …).
	$path = self::apply_query_policy( $utm['path'] );

	// Cap distinct new paths per day / per hour; overflow → /other.
	$path = self::cap_new_path( $path );

	$path = self::clean_path( $path );

		if ( null === $path ) {
			return null;
		}

		$out = array(
			'e' => $event,
			'p' => $path,
		);

		if ( 2 === $event ) {
			$duration = isset( $data['d'] ) ? (int) $data['d'] : 0;
			$out['d'] = max( 0, min( 3600000, $duration ) );

			return $out;
		}

		$out['t'] = isset( $data['t'] ) ? self::clean_text( $data['t'], 255 ) : '';
		$out['r'] = isset( $data['r'] ) ? self::clean_text( $data['r'], 500 ) : '';
		$out['w'] = isset( $data['w'] ) ? max( 0, min( 16000, (int) $data['w'] ) ) : 0;
		$out['tz'] = isset( $data['tz'] ) ? max( -840, min( 840, (int) $data['tz'] ) ) : 0;
		$out['l'] = '';

		if ( isset( $data['l'] ) && is_string( $data['l'] ) ) {
			$lang = trim( $data['l'] );

			if ( preg_match( '/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,2}$/', $lang ) ) {
				$out['l'] = substr( $lang, 0, 16 );
			}
		}

		foreach ( array( 'us' => 'source', 'um' => 'medium', 'uc' => 'campaign', 'ut' => 'term', 'ud' => 'content' ) as $key => $ignored ) {
			// Prefer UTM parsed from the path; explicit payload keys are a
			// fallback for custom integrations.
			if ( '' !== $utm[ $key ] ) {
				$out[ $key ] = self::clean_text( $utm[ $key ], 100 );
			} else {
				$out[ $key ] = isset( $data[ $key ] ) ? self::clean_text( $data[ $key ], 100 ) : '';
			}
		}

		return $out;
	}

	/**
	 * Splits a raw path into path + UTM values.
	 *
	 * @param string $path Raw path with query string, e.g. "/?s=hello&utm_source=x".
	 * @return array{path:string,us:string,um:string,uc:string,ut:string,ud:string}
	 */
	private static function extract_utm( $path ) {
		$out = array(
			'path' => $path,
			'us'   => '',
			'um'   => '',
			'uc'   => '',
			'ut'   => '',
			'ud'   => '',
		);

		$qpos = strpos( $path, '?' );

		if ( false === $qpos ) {
			return $out;
		}

		$query = substr( $path, $qpos + 1 );
		$out['path'] = substr( $path, 0, $qpos );

		parse_str( $query, $params );

		if ( ! is_array( $params ) || empty( $params ) ) {
			return $out;
		}

		$map = array(
			'utm_source'   => 'us',
			'utm_medium'   => 'um',
			'utm_campaign' => 'uc',
			'utm_term'     => 'ut',
			'utm_content'  => 'ud',
		);

		foreach ( $map as $qkey => $skey ) {
			if ( isset( $params[ $qkey ] ) && is_string( $params[ $qkey ] ) ) {
				$out[ $skey ] = $params[ $qkey ];
				unset( $params[ $qkey ] );
			}
		}

		// Keep internal params (?s=, ?page_id=, …) on the page path.
		$rest = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		if ( '' !== $rest ) {
			$out['path'] .= '?' . $rest;
		}

		return $out;
	}

	/**
	 * Origin/Host validation — no cross-site beacons, no host-header abuse.
	 * Allows a setting + filter list of trusted hosts (www. stripped,
	 * multisite/domain-mapping aware). Origin/Referer enforcement for the
	 * GET pixel path (?d=1&b=) is configurable via enforce_origin_get.
	 *
	 * @return bool
	 */
	private static function same_origin() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';

		if ( '' === $host ) {
			return false;
		}

		// Compare hostnames; ports are irrelevant for site identity.
		$host = preg_replace( '/:\d+$/', '', $host );

		$settings   = Rasad_Settings::all();
		$enforce_get = isset( $settings['enforce_origin_get'] ) ? (bool) $settings['enforce_origin_get'] : true;

		$normalize = function ( $h ) {
			$h = strtolower( (string) $h );
			$h = preg_replace( '/:\d+$/', '', $h );

			if ( 0 === strpos( $h, 'www.' ) ) {
				$h = substr( $h, 4 );
			}

			return $h;
		};

		$host_norm = $normalize( $host );

		// Allowed hosts: site host + trusted_hosts setting + filter.
		$allowed = array();

		$site = Rasad_Settings::site_host();
		if ( '' !== $site ) {
			$allowed[] = $site;
		}

		$allowed = array_merge( $allowed, Rasad_Settings::trusted_hosts() );
		$allowed = array_unique( array_map( $normalize, $allowed ) );

		/**
		 * Filters the list of hosts accepted as same-origin.
		 *
		 * @param string[] $allowed Normalized hostnames.
		 */
		$allowed = apply_filters( 'rasad_same_origin_hosts', $allowed );

		if ( ! in_array( $host_norm, $allowed, true ) ) {
			return false;
		}

		// Origin/Referer enforcement: skipped for the GET pixel path when
		// the admin has disabled it (pixels from cached pages may lack them).
		$is_get_pixel = isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD']
			&& isset( $_GET['d'] ) && '1' === (string) $_GET['d'];

		if ( ! $is_get_pixel || $enforce_get ) {
			foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}

				$origin_host = parse_url( (string) $_SERVER[ $header ], PHP_URL_HOST );

				if ( ! is_string( $origin_host ) || '' === $origin_host ) {
					return false;
				}

				if ( $normalize( $origin_host ) !== $host_norm ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Client IP: honors the IP source setting (REMOTE_ADDR default, or a
	 * forwarded header when explicitly selected or when REMOTE_ADDR is in
	 * the trusted-proxy CIDR list). Never logged, never stored — it only
	 * feeds the visitor key and the GeoIP lookup downstream.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$settings = Rasad_Settings::all();
		$source   = isset( $settings['ip_source'] ) ? (string) $settings['ip_source'] : 'remote_addr';
		$allowed  = array( 'remote_addr', 'cf_connecting_ip', 'x_forwarded_for', 'x_real_ip', 'auto' );

		if ( ! in_array( $source, $allowed, true ) ) {
			$source = 'remote_addr';
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$trusted = isset( $settings['trusted_proxies'] ) && is_array( $settings['trusted_proxies'] )
			? $settings['trusted_proxies']
			: array();

		// Forwarded headers are trusted only when the source is explicitly
		// selected, or (auto) when REMOTE_ADDR is in the trusted-proxy list.
		$may_trust = ( 'auto' === $source )
			? self::ip_in_cidrs( $remote, $trusted )
			: in_array( $source, array( 'cf_connecting_ip', 'x_forwarded_for', 'x_real_ip' ), true );

		$ip = $remote;

		if ( $may_trust ) {
			$candidates = array();

			// Priority order: CF-Connecting-IP → X-Forwarded-For (first public)
			// → X-Real-IP. Explicit mode only checks its own header.
			if ( 'cf_connecting_ip' === $source
				|| ( 'auto' === $source && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) {
				$candidates[] = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] )
					? trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '';
			}

			if ( ( 'x_forwarded_for' === $source || 'auto' === $source )
				&& isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				foreach ( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part && filter_var( $part, FILTER_VALIDATE_IP )
						&& self::is_public_ip( $part ) ) {
						$candidates[] = $part;
						break;
					}
				}
			}

			if ( 'x_real_ip' === $source
				|| ( 'auto' === $source && isset( $_SERVER['HTTP_X_REAL_IP'] ) ) ) {
				$candidates[] = isset( $_SERVER['HTTP_X_REAL_IP'] )
					? trim( (string) $_SERVER['HTTP_X_REAL_IP'] ) : '';
			}

			foreach ( $candidates as $candidate ) {
				if ( '' !== $candidate && filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		// Fall back to REMOTE_ADDR when nothing usable was found.
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = $remote;
		}

		/**
		 * Filters the client IP used for hashing/GeoIP.
		 *
		 * @param string $ip Remote address (post source selection).
		 */
		$ip = apply_filters( 'rasad_client_ip', $ip );

		if ( ! is_string( $ip ) || ! filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return trim( $ip );
	}

	/**
	 * Settings-based exclusions: paths, UA fragments, IPs (exact or CIDR).
	 *
	 * @param array<string,mixed> $data     Sanitized payload.
	 * @param string              $ua       User agent.
	 * @param string              $ip       Client IP.
	 * @param array<string,mixed> $settings Settings.
	 * @return bool True when the hit must be dropped.
	 */
	private static function is_excluded( $data, $ua, $ip, $settings ) {
		// Path: substring match, e.g. "/cart" or "/checkout/".
		$paths = isset( $settings['excluded_paths'] ) && is_array( $settings['excluded_paths'] )
			? $settings['excluded_paths']
			: array();

		foreach ( $paths as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $data['p'], $pattern ) ) {
				return true;
			}
		}

		// User-Agent: case-insensitive substring match.
		$uas = isset( $settings['excluded_uas'] ) && is_array( $settings['excluded_uas'] )
			? $settings['excluded_uas']
			: array();
		$ua_l = strtolower( $ua );

		foreach ( $uas as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $ua_l, strtolower( $pattern ) ) ) {
				return true;
			}
		}

		if ( '' === $ip ) {
			return false;
		}

		// IP: exact match or CIDR range (e.g. 10.0.0.0/8, 2001:db8::/32).
		$ips = isset( $settings['excluded_ips'] ) && is_array( $settings['excluded_ips'] )
			? $settings['excluded_ips']
			: array();

		foreach ( $ips as $pattern ) {
			if ( self::ip_matches( $ip, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks one IP against an exact address or CIDR pattern.
	 *
	 * @param string $ip      Client IP.
	 * @param string $pattern Exact IP or CIDR (IPv4/IPv6).
	 * @return bool
	 */
	private static function ip_matches( $ip, $pattern ) {
		$pattern = trim( (string) $pattern );

		if ( '' === $pattern ) {
			return false;
		}

		if ( false === strpos( $pattern, '/' ) ) {
			return $ip === $pattern;
		}

		list( $subnet, $bits ) = explode( '/', $pattern, 2 );
		$bits  = (int) $bits;
		$ip_bin  = @inet_pton( $ip );
		$sub_bin = @inet_pton( trim( $subnet ) );

		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}

		$max_bits = strlen( $ip_bin ) * 8;

		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $sub_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = 0xFF << ( 8 - $rest ) & 0xFF;

		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $sub_bin[ $bytes ] ) & $mask );
	}

	/**
	 * Drops hits from users whose role is excluded — works even when the page
	 * came from a cache (decided here server-side from the auth cookie).
	 *
	 * Only runs when a wordpress_logged_in_* cookie is present; failures
	 * degrade to "track" (a guest view), never to a fatal.
	 *
	 * NOTE: the login cookie is NOT HMAC-verified here. It only reduces
	 * tracking of the sender's own hits and is never used for authorization.
	 *
	 * A short APCu/transient cache keyed by login avoids a DB query per hit.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return bool
	 */
	private static function is_excluded_user( $settings ) {
		$roles = isset( $settings['excluded_roles'] ) && is_array( $settings['excluded_roles'] )
			? $settings['excluded_roles']
			: array();

		if ( empty( $roles ) || empty( $_COOKIE ) ) {
			return false;
		}

		$cookie_name = '';

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) {
				$cookie_name = (string) $name;
				break;
			}
		}

		if ( '' === $cookie_name ) {
			return false;
		}

		$parts = explode( '|', (string) $_COOKIE[ $cookie_name ] );

		// username | expiration | token | hmac — we only read username + expiry.
		if ( count( $parts ) < 4 ) {
			return false;
		}

		$expires = (int) $parts[1];

		if ( $expires > 0 && $expires < time() ) {
			return false;
		}

		$login = substr( $parts[0], 0, 60 );

		// Short-lived cache keyed by login: APCu first (shared memory, no DB),
		// then transient as a fallback under full WP.
		$cache_key = 'rasad_excl_user_' . md5( $login );
		$ttl = 300; // 5 minutes

		if ( function_exists( 'apcu_fetch' ) ) {
			$cached = apcu_fetch( $cache_key, $success );

			if ( $success ) {
				return (bool) $cached;
			}
		}

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );

			if ( false !== $cached ) {
				return (bool) $cached;
			}
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers come from wpdb.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.meta_value AS caps FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE u.user_login = %s AND m.meta_key = %s LIMIT 1",
				$login,
				$wpdb->get_blog_prefix() . 'capabilities'
			)
		);

		if ( ! $row || ! isset( $row->caps ) || ! is_string( $row->caps ) ) {
			$excluded = false;
		} else {
			$caps = maybe_unserialize( $row->caps );
			$excluded = is_array( $caps ) && self::user_has_role( $caps, $roles );
		}

		// Cache the result for a short window.
		if ( function_exists( 'apcu_store' ) ) {
			@apcu_store( $cache_key, $excluded, $ttl );
		}

		if ( function_exists( 'set_transient' ) ) {
			@set_transient( $cache_key, $excluded, $ttl );
		}

		return $excluded;
	}

	/**
	 * Checks whether any of the required roles is present in the caps array.
	 *
	 * @param array<string,mixed> $caps User capabilities.
	 * @param string[]            $roles Role slugs to look for.
	 * @return bool
	 */
	private static function user_has_role( $caps, $roles ) {
		foreach ( $roles as $role ) {
			if ( ! empty( $caps[ $role ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds the buffer line for a pageview or duration ping.
	 *
	 * Note: `ip` and `ua` ride along ONLY as transient transport for the
	 * aggregator (UA parsing + GeoIP happen there, per SPEC). They are never
	 * written to any database table and disappear with the buffer file.
	 *
	 * @param array<string,mixed> $data Sanitized payload.
	 * @param string              $vk   Visitor key (16 hex chars).
	 * @param string              $ip   Client IP.
	 * @param string              $ua   User agent.
	 * @return array<string,mixed>
	 */
	private static function to_buffer( $data, $vk, $ip, $ua ) {
		$hit = array(
			'ts' => time(),
			'e'  => (int) $data['e'],
			'vk' => $vk,
			'p'  => $data['p'],
		);

		if ( 2 === (int) $data['e'] ) {
			$hit['d'] = isset( $data['d'] ) ? (int) $data['d'] : 0;

			return $hit;
		}

		// Anonymize the IP in the buffer line unless the admin disabled it
		// (e.g. when full precision is needed downstream). The aggregator
		// can still GeoIP from the anonymized prefix for country/city level.
		$settings = Rasad_Settings::all();
		$hit['ip'] = ( ! empty( $settings['ip_anonymize'] ) )
			? self::anonymize_ip( $ip )
			: $ip;
		$hit['ua'] = $ua;
		$hit['w']  = (int) $data['w'];
		$hit['l']  = (string) $data['l'];
		$hit['tz'] = (int) $data['tz'];

		foreach ( array( 't', 'r', 'us', 'um', 'uc', 'ut', 'ud' ) as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				$hit[ $key ] = (string) $data[ $key ];
			}
		}

		return $hit;
	}

	/**
	 * Path sanitizer: must start with "/", no control chars, <= 500 bytes.
	 *
	 * @param mixed $raw Raw value.
	 * @return string|null Null when empty/unusable.
	 */
	private static function clean_path( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$path = wp_check_invalid_utf8( $raw );
		$path = str_replace( array( "\r", "\n", "\0" ), '', $path );
		$path = trim( $path );

		if ( '' === $path ) {
			return null;
		}

		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		if ( strlen( $path ) > 500 ) {
			$path = wp_check_invalid_utf8( substr( $path, 0, 500 ) );
		}

		return $path;
	}

	/**
	 * Generic text sanitizer: tags/control chars stripped, byte-capped.
	 *
	 * @param mixed $raw   Raw value.
	 * @param int   $max   Maximum length in bytes.
	 * @return string
	 */
	private static function clean_text( $raw, $max ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		$text = wp_check_invalid_utf8( $raw );
		$text = wp_strip_all_tags( $text );
		$text = str_replace( array( "\r", "\n", "\0" ), ' ', $text );
		$text = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > $max ) {
			$text = wp_check_invalid_utf8( substr( $text, 0, $max ) );
		}

		return trim( $text );
	}

	/* ========================================================================
	 * New helpers: IP source, CIDR, anonymization, query-string policy
	 * ======================================================================== */

	/**
	 * Strips the URL fragment from a path.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private static function strip_fragment( $path ) {
		$pos = strpos( $path, '#' );

		if ( false !== $pos ) {
			return substr( $path, 0, $pos );
		}

		return $path;
	}

	/**
	 * Applies the query-string policy: strip fragments (already done), keep
	 * only the hard whitelist plus the admin-editable extra whitelist, drop
	 * everything else.
	 *
	 * @param string $path Path with optional query string.
	 * @return string Path with only whitelisted query params.
	 */
	private static function apply_query_policy( $path ) {
		$settings = Rasad_Settings::all();

		$qpos = strpos( $path, '?' );

		if ( false === $qpos ) {
			return $path;
		}

		$base = substr( $path, 0, $qpos );
		$query = substr( $path, $qpos + 1 );

		parse_str( $query, $params );

		if ( ! is_array( $params ) || empty( $params ) ) {
			return $base;
		}

		$hard = array( 's', 'paged', 'page_id', 'p', 'cat', 'tag', 'post_type', 'product_cat' );
		$extra = isset( $settings['query_string_whitelist'] ) && is_array( $settings['query_string_whitelist'] )
			? $settings['query_string_whitelist']
			: array();
		$allowed = array_unique( array_merge( $hard, $extra ) );

		$kept = array();

		foreach ( $params as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, $allowed, true ) ) {
				$kept[ $key ] = $value;
			}
		}

		if ( empty( $kept ) ) {
			return $base;
		}

		return $base . '?' . http_build_query( $kept, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Caps distinct new page paths per day and per hour. When a cap is
	 * reached and the path is new for that window, the path is collapsed
	 * into a single "/other" page.
	 *
	 * @param string $path Sanitized path.
	 * @return string $path or "/other".
	 */
	private static function cap_new_path( $path ) {
		$settings = Rasad_Settings::all();

		$cap_day = isset( $settings['query_string_cap_day'] ) ? (int) $settings['query_string_cap_day'] : 0;
		$cap_hour = isset( $settings['query_string_cap_hour'] ) ? (int) $settings['query_string_cap_hour'] : 0;

		if ( $cap_day <= 0 && $cap_hour <= 0 ) {
			return $path;
		}

		$dir = rtrim( Rasad_Plugin::storage_dir( Rasad_Buffer_File::SUBDIR ), '/' ) . '/';
		$day_file = $dir . 'qs-paths-day-' . date( 'Ymd' ) . '.json';
		$hour_file = $dir . 'qs-paths-hour-' . date( 'YmdHi' ) . '.json';

		$day_seen = self::read_path_set( $day_file );
		$hour_seen = self::read_path_set( $hour_file );

		$day_new = ! in_array( $path, $day_seen, true );
		$hour_new = ! in_array( $path, $hour_seen, true );

		if ( $day_new && $cap_day > 0 && count( $day_seen ) >= $cap_day ) {
			return '/other';
		}

		if ( $hour_new && $cap_hour > 0 && count( $hour_seen ) >= $cap_hour ) {
			return '/other';
		}

		if ( $day_new ) {
			$day_seen[] = $path;
			self::write_path_set( $day_file, $day_seen );
		}

		if ( $hour_new ) {
			$hour_seen[] = $path;
			self::write_path_set( $hour_file, $hour_seen );
		}

		return $path;
	}

	/**
	 * Reads a JSON array of seen paths from a file.
	 *
	 * @param string $file Absolute path.
	 * @return string[]
	 */
	private static function read_path_set( $file ) {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$raw = @file_get_contents( $file );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Writes a JSON array of seen paths to a file.
	 *
	 * @param string $file Absolute path.
	 * @param string[] $paths Paths.
	 * @return bool
	 */
	private static function write_path_set( $file, $paths ) {
		$encoded = wp_json_encode( $paths );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		return false !== @file_put_contents( $file, $encoded, LOCK_EX );
	}

	/**
	 * Anonymizes an IP for buffer storage: zeroes the last IPv4 octet or
	 * the last 80 bits of IPv6.
	 *
	 * @param string $ip Client IP.
	 * @return string Anonymized IP.
	 */
	private static function anonymize_ip( $ip ) {
		if ( '' === $ip ) {
			return $ip;
		}

		if ( false !== strpos( $ip, ':' ) ) {
			// IPv6: keep first 48 bits, zero the last 80 bits.
			$bin = @inet_pton( $ip );

			if ( false !== $bin && 16 === strlen( $bin ) ) {
				for ( $i = 6; $i < 16; $i++ ) {
					$bin[ $i ] = chr( 0 );
				}

				$ntop = @inet_ntop( $bin );

				return is_string( $ntop ) ? $ntop : $ip;
			}
		} else {
			// IPv4: zero the last octet.
			$parts = explode( '.', $ip );

			if ( 4 === count( $parts ) ) {
				$parts[3] = '0';

				return implode( '.', $parts );
			}
		}

		return $ip;
	}

	/**
	 * Checks whether an IP matches any CIDR in a list.
	 *
	 * @param string      $ip    Client IP.
	 * @param string[]    $cidrs CIDR patterns.
	 * @return bool
	 */
	private static function ip_in_cidrs( $ip, $cidrs ) {
		if ( '' === $ip || empty( $cidrs ) || ! is_array( $cidrs ) ) {
			return false;
		}

		foreach ( $cidrs as $cidr ) {
			if ( self::ip_matches( $ip, (string) $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether an IP is public (not private/reserved/loopback).
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private static function is_public_ip( $ip ) {
		$private = array(
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'127.0.0.0/8',
			'169.254.0.0/16',
			'::1/128',
			'fc00::/7',
			'fe80::/10',
			'2001:db8::/32',
		);

		foreach ( $private as $cidr ) {
			if ( self::ip_matches( $ip, $cidr ) ) {
				return false;
			}
		}

		return true;
	}
}
