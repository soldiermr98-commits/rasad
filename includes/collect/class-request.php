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
	 * Processes one raw beacon payload end to end.
	 *
	 * @param string $raw Raw request body (JSON) or pixel query payload.
	 * @return int HTTP status: 204 accepted/silently dropped, 400 bad payload,
	 *              403 cross-origin, 500 buffer failure.
	 */
	public static function process( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > RASAD_MAX_PAYLOAD ) {
			return 400;
		}

		if ( ! self::same_origin() ) {
			return 403;
		}

		$settings = Rasad_Settings::all();

		// Browser-side DNT is enforced in t.js; also honor the request header.
		if ( ! empty( $settings['honor_dnt'] ) && isset( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) {
			return 204;
		}

		$decoded = self::decode( $raw );

		if ( null === $decoded ) {
			return 400;
		}

		$data = self::sanitize( $decoded );

		if ( null === $data ) {
			return 400;
		}

		/**
		 * Filters whether this request should be tracked at all.
		 *
		 * @param bool                    $track Current decision (default true).
		 * @param array<string,mixed>|null $data  Sanitized payload (null at enqueue time).
		 */
		if ( ! apply_filters( 'rasad_should_track', true, $data ) ) {
			return 204;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ip = self::client_ip();

		if ( Rasad_Collect_Visitor::is_bot( $ua ) ) {
			return 204;
		}

		if ( self::is_excluded( $data, $ua, $ip, $settings ) ) {
			return 204;
		}

		$vk = Rasad_Collect_Visitor::key( $ip, $ua );

		// No persistable secret => refuse to build an unsalted identifier.
		if ( '' === $vk ) {
			return 204;
		}

		if ( Rasad_Collect_Visitor::rate_limited( $vk ) ) {
			return 204;
		}

		// Role exclusion is the only DB lookup on the hot path (logged-in
		// users only) — placed after the rate limit so floods never query.
		if ( self::is_excluded_user( $settings ) ) {
			return 204;
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
			return 400;
		}

		$buffer = Rasad_Plugin::buffer();

		if ( ! $buffer->push( $hit ) ) {
			return 500;
		}

		/**
		 * Fires after a hit was pushed to the buffer (used later to trigger
		 * non-blocking aggregation when the backlog grows).
		 *
		 * @param array<string,mixed> $hit Buffer line.
		 */
		do_action( 'rasad_after_hit', $hit );

		return 204;
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

		if ( isset( $query['b'] ) && is_string( $query['b'] ) ) {
			$decoded = json_decode( $query['b'], true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		if ( ! empty( $query ) ) {
			return $query;
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

		// UTM lives in the query string: extract it, then strip it so campaign
		// variants never split the page statistics (t.js sends p as-is).
		$utm = self::extract_utm( $data['p'] );
		$path = self::clean_path( $utm['path'] );

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

		$site = strtolower( Rasad_Settings::site_host() );

		if ( '' !== $site && $site !== $host ) {
			return false;
		}

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$origin_host = parse_url( (string) $_SERVER[ $header ], PHP_URL_HOST );

			if ( ! is_string( $origin_host ) || '' === $origin_host || strtolower( $origin_host ) !== $host ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Client IP: REMOTE_ADDR only (filterable). Never logged, never stored —
	 * it only feeds the visitor key and the GeoIP lookup downstream.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		/**
		 * Filters the client IP used for hashing/GeoIP.
		 *
		 * @param string $ip Remote address.
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

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}

		$login = substr( $parts[0], 0, 60 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers come from wpdb.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.meta_value AS caps FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE u.user_login = %s AND m.meta_key = %s LIMIT 1",
				$login,
				$wpdb->get_blog_prefix() . 'capabilities'
			)
		);

		if ( ! $row || ! isset( $row->caps ) || ! is_string( $row->caps ) ) {
			return false;
		}

		$caps = maybe_unserialize( $row->caps );

		if ( ! is_array( $caps ) ) {
			return false;
		}

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

		$hit['ip'] = $ip;
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
}
