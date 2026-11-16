<?php
/**
 * REST API: beacon fallback, secret server-cron, and the cached
 * dashboard endpoints (Phase 4).
 *
 * Dashboard routes are GET-only, capability-gated and wrapped in a
 * transient cache: key = version counter + md5(route, range). The counter
 * bumps whenever an aggregation run actually processes hits, so "today"
 * numbers refresh without ever leaving stale transients behind.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 */
final class Rasad_Api_Rest {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'rasad/v1';

	/**
	 * Transient holding the cache version counter.
	 *
	 * @var string
	 */
	const CACHE_VERSION = 'rasad_cache_v';

	/**
	 * TTL for ranges that include today (seconds).
	 *
	 * @var int
	 */
	const TTL_LIVE = 60;

	/**
	 * TTL for fully-past ranges (seconds).
	 *
	 * @var int
	 */
	const TTL_PAST = 43200;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Refresh dashboards only when an aggregation really moved data.
		add_action( 'rasad_aggregated', array( __CLASS__, 'on_aggregated' ) );
	}

	/**
	 * Registers all routes: /hit, /cron and the dashboard endpoints.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/hit',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_hit' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_hit' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cron',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_cron' ),
					'permission_callback' => array( __CLASS__, 'cron_permission' ),
				),
			)
		);

		$dashboard = array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'dashboard_permission' ),
		);

		foreach ( array( 'overview', 'series', 'pages', 'referrers' ) as $slug ) {
			$route                 = $dashboard;
			$route['callback']     = array( __CLASS__, 'handle_' . $slug );
			register_rest_route( self::NS, '/' . $slug, array( $route ) );
		}

		// Live panel (Phase 5): uncached, capability-gated.
		$live                 = $dashboard;
		$live['callback']     = array( 'Rasad_Api_Live', 'handle' );
		register_rest_route( self::NS, '/live', array( $live ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Public beacon + cron routes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Runs a beacon through the shared collect pipeline.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_hit( $request ) {
		$raw = is_object( $request ) && method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';

		if ( '' === $raw && is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$b = $request->get_param( 'b' );

			if ( is_string( $b ) ) {
				// Full WP runs wp_magic_quotes(), so query params are slashed.
				$raw = wp_unslash( $b );
			}
		}

	// Public contract: always 204. The real outcome is only in X-Rasad-Status
	// (diag mode). Keep 4xx/5xx out of the browser console.
	Rasad_Collect_Request::process( $raw );
	$code     = 204;
	$response = new WP_REST_Response( null, $code );
	$response->header( 'Cache-Control', 'no-store' );

		// Diagnostic header: only with WP_DEBUG or a valid self-test key.
		if ( Rasad_Collect_Request::diag_requested() ) {
			$response->header( 'X-Rasad-Status', Rasad_Collect_Request::last_status() );
		}

		return $response;
	}

	/**
	 * Validates the secret key of the server-cron route.
	 *
	 * The key is derived (HMAC of the auth salt + site host) — nothing new
	 * is stored; admins get the ready URL from
	 * Rasad_Process_Aggregator::cron_url() (health panel, later phase).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public static function cron_permission( $request ) {
		$key = is_object( $request ) && method_exists( $request, 'get_param' )
			? (string) $request->get_param( 'key' )
			: '';

		if ( '' !== $key && hash_equals( Rasad_Process_Aggregator::cron_key(), $key ) ) {
			return true;
		}

		return new WP_Error(
			'rasad_forbidden',
			__( 'کلید دسترسی نامعتبر است.', 'rasad' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Real server cron entry: aggregate + cleaned, JSON stats back.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_cron() {
		$aggregate = Rasad_Process_Aggregator::run();
		$clean     = Rasad_Process_Cleaner::maybe_run();

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'aggregate' => $aggregate,
				'clean'     => is_array( $clean ) ? $clean : false,
			),
			200
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Dashboard permissions + cache
	|--------------------------------------------------------------------------
	*/

	/**
	 * Capability gate for every dashboard endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public static function dashboard_permission( $request ) {
		if ( current_user_can( Rasad_Settings::capability() ) ) {
			return true;
		}

		return new WP_Error(
			'rasad_forbidden',
			__( 'شما اجازهٔ دسترسی ندارید.', 'rasad' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Bumps the cache version (drops every cached dashboard payload).
	 *
	 * @return int New version.
	 */
	public static function bump_cache() {
		$version = (int) get_transient( self::CACHE_VERSION ) + 1;

		// 0 => no expiry; old versions die with their own TTLs.
		set_transient( self::CACHE_VERSION, $version, 0 );

		return $version;
	}

	/**
	 * `rasad_aggregated` listener: invalidate only on real data movement.
	 *
	 * @param array<string,mixed> $stats Run statistics.
	 * @return void
	 */
	public static function on_aggregated( $stats ) {
		if ( is_array( $stats ) && ! empty( $stats['processed'] ) ) {
			self::bump_cache();
		}
	}

	/**
	 * Reads a cached payload (null on miss).
	 *
	 * @param array<string,mixed> $params Cache identity (route + range + …).
	 * @return array<string,mixed>|null
	 */
	private static function cache_get( array $params, $live = false ) {
		$version = $live ? (int) get_transient( self::CACHE_VERSION ) : 0;
		$key     = 'rasad_d_' . $version . '_' . md5( wp_json_encode( $params ) );
		// Past ranges are immutable: no version counter, never invalidated by aggregation.
		$value   = get_transient( $key );

		return ( false === $value || ! is_array( $value ) ) ? null : $value;
	}

	/**
	 * Stores a payload with a range-aware TTL.
	 *
	 * @param array<string,mixed> $params Cache identity.
	 * @param array<string,mixed> $data   Payload.
	 * @param bool                $live   True when the range includes today.
	 * @return void
	 */
	private static function cache_set( array $params, array $data, $live ) {
		$version   = $live ? (int) get_transient( self::CACHE_VERSION ) : 0;
		$key       = 'rasad_d_' . $version . '_' . md5( wp_json_encode( $params ) );
		// Past ranges are immutable: no version counter, never invalidated by aggregation.
		$ttl       = $live ? self::TTL_LIVE : self::TTL_PAST;
		$data['_cached'] = time();

		set_transient( $key, $data, $ttl );
	}

	/*
	|--------------------------------------------------------------------------
	| Range parsing (shared by all dashboard endpoints)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalizes ?from/?to/?preset into site-timezone day ranges.
	 *
	 * Presets (computed server-side so the site timezone wins): today,
	 * yesterday, 7d, 30d, 90d. Default: 7d. Max span: 400 days.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string,mixed>
	 */
	private static function parse_range( $request ) {
		$param = function ( $name ) use ( $request ) {
			$value = is_object( $request ) && method_exists( $request, 'get_param' )
				? $request->get_param( $name )
				: null;

			return is_scalar( $value ) ? (string) $value : '';
		};

		$today = Rasad_Process_Aggregator::local_day( time() );
		$from  = $param( 'from' );
		$to    = $param( 'to' );
		$valid = '/^(\d{4})-(\d{2})-(\d{2})$/';

		// Accept only real calendar dates (checkdate rejects e.g. 2024-02-30).
		$from_match = preg_match( $valid, $from, $m_from );
		$to_match   = preg_match( $valid, $to,   $m_to );
		$from_valid = $from_match && checkdate( (int) $m_from[2], (int) $m_from[3], (int) $m_from[1] );
		$to_valid   = $to_match   && checkdate( (int) $m_to[2],   (int) $m_to[3],   (int) $m_to[1] );

		if ( ! $from_valid || ! $to_valid ) {
			$to = $today;

			switch ( $param( 'preset' ) ) {
				case 'today':
					$from = $today;
					break;

				case 'yesterday':
					$from = $to = Rasad_Process_Aggregator::local_day( time() - 86400 );
					break;

				case '30d':
					$from = self::shift_day( $today, -29 );
					break;

				case '90d':
					$from = self::shift_day( $today, -89 );
					break;

				case '7d':
				default:
					$from = self::shift_day( $today, -6 );
					break;
			}
		}

		list( $from_start ) = Rasad_Process_Aggregator::day_bounds( $from );
		list( , $to_end )   = Rasad_Process_Aggregator::day_bounds( $to );

		// from > to: swap.
		if ( $from_start >= $to_end ) {
			$tmp   = $from;
			$from  = $to;
			$to    = $tmp;
			list( $from_start ) = Rasad_Process_Aggregator::day_bounds( $from );
			list( , $to_end )   = Rasad_Process_Aggregator::day_bounds( $to );
		}

		// Cap the span at 400 days.
		if ( ( $to_end - $from_start ) > ( 400 * 86400 ) ) {
			$from = Rasad_Process_Aggregator::local_day( $to_end - ( 400 * 86400 ) );
			list( $from_start ) = Rasad_Process_Aggregator::day_bounds( $from );
		}

		$length   = $to_end - $from_start;
		$prev_end = $from_start;
		$days     = self::count_days( $from, $to );

		// Previous period: the same number of calendar days immediately before
		// `from`, computed in the site timezone so DST transitions are respected.
		$tz        = wp_timezone();
		$from_dt   = DateTimeImmutable::createFromFormat( 'Y-m-d', $from, $tz );
		if ( false === $from_dt ) {
			$from_dt = new DateTimeImmutable( '@' . $from_start );
			$from_dt = $from_dt->setTimezone( $tz );
		}
		$prev_from = $from_dt->modify( sprintf( '%+d days', -$days ) )->format( 'Y-m-d' );
		$prev_to   = ( clone $from_dt )->modify( '-1 day' )->format( 'Y-m-d' );

		list( , $prev_to_end ) = Rasad_Process_Aggregator::day_bounds( $prev_to );
		list( $prev_from_ts )  = Rasad_Process_Aggregator::day_bounds( $prev_from );

		return array(
			'from'          => $from,
			'to'            => $to,
			'from_ts'       => $from_start,
			'to_end'        => $to_end,
			'prev_from'     => $prev_from,
			'prev_to'       => $prev_to,
			'prev_from_ts'  => $prev_from_ts,
			'prev_to_end'   => $prev_to_end,
			'includes_today'=> ( $to >= $today ),
			'days'          => $days,
		);
	}

	/**
	 * Shifts a 'Y-m-d' day by N calendar days.
	 *
	 * @param string $day  Base day.
	 * @param int    $days Negative/positive offset.
	 * @return string
	 */
	private static function shift_day( $day, $days ) {
		$tz = wp_timezone();

		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $day, $tz );

		if ( false === $dt ) {
			list( $start ) = Rasad_Process_Aggregator::day_bounds( $day );

			$dt = new DateTimeImmutable( '@' . $start );
			$dt = $dt->setTimezone( $tz );
		}

		return $dt->modify( sprintf( '%+d days', (int) $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Inclusive calendar-day count (DST-safe).
	 *
	 * @param string $from 'Y-m-d'.
	 * @param string $to   'Y-m-d'.
	 * @return int
	 */
	private static function count_days( $from, $to ) {
		$a = DateTimeImmutable::createFromFormat( 'Y-m-d', $from );
		$b = DateTimeImmutable::createFromFormat( 'Y-m-d', $to );

		if ( false === $a || false === $b ) {
			return 1;
		}

		return max( 1, (int) $a->diff( $b )->days + 1 );
	}

	/**
	 * Standard envelope for dashboard responses.
	 *
	 * @param array<string,mixed> $range  Parsed range.
	 * @param array<string,mixed> $data   Handler payload.
	 * @param bool                $cached Whether the payload came from cache.
	 * @return array<string,mixed>
	 */
	private static function envelope( array $range, array $data, $cached ) {
		return array_merge(
			array(
				'range' => array(
					'from'      => $range['from'],
					'to'        => $range['to'],
					'prev_from' => $range['prev_from'],
					'prev_to'   => $range['prev_to'],
					'days'      => $range['days'],
				),
				'cache' => $cached ? 'hit' : 'miss',
			),
			$data
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Dashboard handlers
	|--------------------------------------------------------------------------
	*/

	/**
	 * GET /overview — cards + previous-period comparison.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_overview( $request ) {
		$range  = self::parse_range( $request );
		$params = array( 'overview', $range['from'], $range['to'] );
		$data   = self::cache_get( $params, $range['includes_today'] );

		if ( null === $data ) {
			$current  = self::sum_daily( $range['from'], $range['to'] );
			$previous = self::sum_daily( $range['prev_from'], $range['prev_to'] );

			$data = array(
				'current'  => self::metric_set( $current ),
				'previous' => self::metric_set( $previous ),
				'deltas'   => array(
					'views'        => self::delta( $current['views'], $previous['views'] ),
					'visitors'     => self::delta( $current['visitors'], $previous['visitors'] ),
					'sessions'     => self::delta( $current['sessions'], $previous['sessions'] ),
					'bounce_rate'  => self::delta( $current['bounce_rate'], $previous['bounce_rate'] ),
					'avg_duration' => self::delta( $current['avg_duration'], $previous['avg_duration'] ),
				),
			);

			self::cache_set( $params, $data, $range['includes_today'] );
			$cached = false;
		} else {
			$cached = true;
		}

		return new WP_REST_Response( self::envelope( $range, $data, $cached ), 200 );
	}

	/**
	 * GET /series — chart points (hourly when range <= 2 days, else daily).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_series( $request ) {
		$range       = self::parse_range( $request );
		$granularity = ( $range['days'] <= 2 ) ? 'hour' : 'day';
		$params      = array( 'series', $granularity, $range['from'], $range['to'] );
		$data        = self::cache_get( $params, $range['includes_today'] );

		if ( null === $data ) {
			$data = ( 'hour' === $granularity )
				? self::series_hourly( $range )
				: self::series_daily( $range );

			$data['granularity'] = $granularity;
			self::cache_set( $params, $data, $range['includes_today'] );
			$cached = false;
		} else {
			$cached = true;
		}

		return new WP_REST_Response( self::envelope( $range, $data, $cached ), 200 );
	}

	/**
	 * GET /pages — top/entry/exit pages + posts + post-type breakdown.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_pages( $request ) {
		$range  = self::parse_range( $request );
		$limit  = is_object( $request ) && method_exists( $request, 'get_param' )
			? (int) $request->get_param( 'limit' )
			: 0;
		$limit  = max( 1, min( 50, $limit ? $limit : 10 ) );
		$params = array( 'pages', $range['from'], $range['to'], $limit );
		$data   = self::cache_get( $params, $range['includes_today'] );

		if ( null === $data ) {
			global $wpdb;

			$table = Rasad_Installer::table( 'daily_pages' );
			$pages = Rasad_Installer::table( 'pages' );
			$base  = "SELECT p.id, p.path, p.title, p.post_id, p.post_type,"
				. " SUM(dp.views) AS views, SUM(dp.visitors) AS visitors,"
				. " SUM(dp.entries) AS entries, SUM(dp.exits) AS exits"
				. " FROM {$table} dp INNER JOIN {$pages} p ON p.id = dp.page_id"
				. ' WHERE dp.day >= %s AND dp.day <= %s';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$top = $wpdb->get_results( $wpdb->prepare( $base . ' GROUP BY p.id ORDER BY views DESC LIMIT %d', $range['from'], $range['to'], $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$entries = $wpdb->get_results( $wpdb->prepare( $base . ' GROUP BY p.id ORDER BY entries DESC LIMIT %d', $range['from'], $range['to'], $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$exits = $wpdb->get_results( $wpdb->prepare( $base . ' GROUP BY p.id ORDER BY exits DESC LIMIT %d', $range['from'], $range['to'], $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$posts = $wpdb->get_results( $wpdb->prepare( $base . ' AND p.post_id > 0 GROUP BY p.id ORDER BY views DESC LIMIT %d', $range['from'], $range['to'], $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$post_types = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.post_type, SUM(dp.views) AS views, SUM(dp.visitors) AS visitors"
				. " FROM {$table} dp INNER JOIN {$pages} p ON p.id = dp.page_id"
				. ' WHERE dp.day >= %s AND dp.day <= %s AND p.post_type IS NOT NULL AND p.post_type <> %s'
				. ' GROUP BY p.post_type ORDER BY views DESC LIMIT 12',
				$range['from'],
				$range['to'],
				''
			) );

			$data = array(
				'top'        => self::page_rows( $top ),
				'entries'    => self::page_rows( $entries, true, 'entries' ),
				'exits'      => self::page_rows( $exits, true, 'exits' ),
				'posts'      => self::page_rows( $posts ),
				'post_types' => self::post_type_rows( $post_types ),
			);

			self::cache_set( $params, $data, $range['includes_today'] );
			$cached = false;
		} else {
			$cached = true;
		}

		return new WP_REST_Response( self::envelope( $range, $data, $cached ), 200 );
	}

	/**
	 * GET /referrers — type groups + top sources + campaigns + search terms.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_referrers( $request ) {
		$range  = self::parse_range( $request );
		$params = array( 'referrers', $range['from'], $range['to'] );
		$data   = self::cache_get( $params, $range['includes_today'] );

		if ( null === $data ) {
			global $wpdb;

			$dim   = Rasad_Installer::table( 'daily_dim' );
			$refs  = Rasad_Installer::table( 'referrers' );
			$pages = Rasad_Installer::table( 'pages' );

			// Type groups — dim_id 0 means direct (LEFT JOIN misses -> type 1).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$types = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(r.type, 1) AS type, SUM(dd.views) AS views, SUM(dd.visitors) AS visitors"
					. " FROM {$dim} dd LEFT JOIN {$refs} r ON r.id = dd.dim_id"
					. ' WHERE dd.dim = 1 AND dd.day >= %s AND dd.day <= %s GROUP BY type',
					$range['from'],
					$range['to']
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$sources = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.domain, r.type, r.name, SUM(dd.views) AS views, SUM(dd.visitors) AS visitors"
					. " FROM {$dim} dd INNER JOIN {$refs} r ON r.id = dd.dim_id"
					. ' WHERE dd.dim = 1 AND dd.day >= %s AND dd.day <= %s GROUP BY r.id ORDER BY views DESC LIMIT 20',
					$range['from'],
					$range['to']
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$campaigns = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.domain, r.name, SUM(dd.views) AS views, SUM(dd.visitors) AS visitors"
					. " FROM {$dim} dd INNER JOIN {$refs} r ON r.id = dd.dim_id"
					. ' WHERE dd.dim = 8 AND dd.day >= %s AND dd.day <= %s GROUP BY r.id ORDER BY views DESC LIMIT 10',
					$range['from'],
					$range['to']
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$terms = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.path, SUM(dd.views) AS views, SUM(dd.visitors) AS visitors"
					. " FROM {$dim} dd INNER JOIN {$pages} p ON p.id = dd.dim_id"
					. ' WHERE dd.dim = 7 AND dd.day >= %s AND dd.day <= %s GROUP BY p.id ORDER BY views DESC LIMIT 15',
					$range['from'],
					$range['to']
				)
			);

			$groups = array(
				Rasad_Process_Referrer::TYPE_DIRECT   => 0,
				Rasad_Process_Referrer::TYPE_SEARCH   => 0,
				Rasad_Process_Referrer::TYPE_SOCIAL   => 0,
				Rasad_Process_Referrer::TYPE_OTHER    => 0,
				Rasad_Process_Referrer::TYPE_CAMPAIGN => 0,
			);

			foreach ( (array) $types as $row ) {
				if ( array_key_exists( (int) $row->type, $groups ) ) {
					$groups[ (int) $row->type ] = (int) $row->views;
				}
			}

			$term_rows = array();

			foreach ( (array) $terms as $row ) {
				$term = Rasad_Process_Referrer::internal_search( $row->path );

				if ( '' === $term ) {
					$term = $row->path;
				}

				$term_rows[] = array(
					'term'     => $term,
					'views'    => (int) $row->views,
					'visitors' => (int) $row->visitors,
				);
			}

			$data = array(
				'groups'   => $groups,
				'sources'  => self::source_rows( $sources ),
				'campaigns'=> self::source_rows( $campaigns ),
				'terms'    => $term_rows,
			);

			self::cache_set( $params, $data, $range['includes_today'] );
			$cached = false;
		} else {
			$cached = true;
		}

		return new WP_REST_Response( self::envelope( $range, $data, $cached ), 200 );
	}

	/*
	|--------------------------------------------------------------------------
	| Query + shaping helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sums one range from rsd_daily and derives rates.
	 *
	 * @param string $from 'Y-m-d'.
	 * @param string $to   'Y-m-d'.
	 * @return array<string,mixed>
	 */
	private static function sum_daily( $from, $to ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors,'
				. ' COALESCE(SUM(sessions), 0) AS sessions, COALESCE(SUM(bounces), 0) AS bounces,'
				. ' COALESCE(SUM(total_duration), 0) AS total_duration'
				. ' FROM ' . Rasad_Installer::table( 'daily' ) . ' WHERE day >= %s AND day <= %s',
				$from,
				$to
			)
		);

		return self::metric_set(
			array(
				'views'          => $row ? (int) $row->views : 0,
				'visitors'       => $row ? (int) $row->visitors : 0,
				'sessions'       => $row ? (int) $row->sessions : 0,
				'bounces'        => $row ? (int) $row->bounces : 0,
				'total_duration' => $row ? (int) $row->total_duration : 0,
			)
		);
	}

	/**
	 * Turns raw sums into the card payload (rates included).
	 *
	 * @param array<string,mixed> $raw Raw sums.
	 * @return array<string,mixed>
	 */
	private static function metric_set( array $raw ) {
		$sessions = isset( $raw['sessions'] ) ? (int) $raw['sessions'] : 0;
		$bounces  = isset( $raw['bounces'] ) ? (int) $raw['bounces'] : 0;
		$duration = isset( $raw['total_duration'] ) ? (int) $raw['total_duration'] : 0;

		$bounce_rate = $sessions > 0 ? round( $bounces / $sessions, 4 ) : 0;

		return array(
			'views'          => isset( $raw['views'] ) ? (int) $raw['views'] : 0,
			'visitors'       => isset( $raw['visitors'] ) ? (int) $raw['visitors'] : 0,
			'sessions'       => $sessions,
			'bounces'        => $bounces,
			'total_duration' => $duration,
			'bounce_rate'    => $bounce_rate,
			'avg_duration'   => $sessions > 0 ? (int) round( $duration / $sessions ) : 0,
		);
	}

	/**
	 * Percent change vs the previous period (null when prev == 0).
	 *
	 * @param float|int $current Current value.
	 * @param float|int $prev    Previous value.
	 * @return float|null
	 */
	private static function delta( $current, $prev ) {
		$prev = (float) $prev;

		if ( 0.0 == $prev ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 0 == 0.0 intended.
			return ( 0.0 == (float) $current ) ? 0.0 : null; // phpcs:ignore Universal.Operators.StrictComparisons
		}

		return round( ( ( (float) $current - $prev ) / $prev ) * 100, 1 );
	}

	/**
	 * Zero-filled hourly series for the chart.
	 *
	 * @param array<string,mixed> $range Parsed range.
	 * @return array<string,mixed>
	 */
	private static function series_hourly( array $range ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT hour_ts, views, visitors FROM ' . Rasad_Installer::table( 'hourly' )
				. ' WHERE hour_ts >= %d AND hour_ts < %d ORDER BY hour_ts',
				$range['from_ts'],
				$range['to_end']
			)
		);

		$by_hour = array();

		foreach ( (array) $rows as $row ) {
			$by_hour[ (int) $row->hour_ts ] = $row;
		}

		$tz   = wp_timezone();
		// Start at local midnight of the `from` day.
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $range['from'] . ' 00:00:00', $tz );
		if ( false === $start ) {
			$start = new DateTimeImmutable( '@' . $range['from_ts'] );
		}
		// End at local midnight of the day *after* `to` (exclusive bound), so
		// partial first/last hours are never dropped and DST transitions are
		// handled by DateTimeImmutable::modify().
		$end = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $range['to'] . ' 00:00:00', $tz );
		if ( false === $end ) {
			$end = ( new DateTimeImmutable( '@' . $range['to_end'] ) )->setTimezone( $tz );
		} else {
			$end = $end->modify( '+1 day' );
		}

		$points = array();
		$cursor = $start;

		while ( $cursor < $end ) {
			$hour_ts = (int) $cursor->getTimestamp();
			$row     = isset( $by_hour[ $hour_ts ] ) ? $by_hour[ $hour_ts ] : null;

			$points[] = array(
				't'        => $hour_ts,
				'label'    => $cursor->format( 'Y-m-d H:i' ),
				'views'    => $row ? (int) $row->views : 0,
				'visitors' => $row ? (int) $row->visitors : 0,
			);

			$cursor = $cursor->modify( '+1 hour' );
		}

		return array( 'points' => $points );
	}

	/**
	 * Zero-filled daily series for the chart.
	 *
	 * @param array<string,mixed> $range Parsed range.
	 * @return array<string,mixed>
	 */
	private static function series_daily( array $range ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, views, visitors FROM ' . Rasad_Installer::table( 'daily' )
				. ' WHERE day >= %s AND day <= %s ORDER BY day',
				$range['from'],
				$range['to']
			)
		);

		$by_day = array();

		foreach ( (array) $rows as $row ) {
			$by_day[ (string) $row->day ] = $row;
		}

		$points = array();
		$cursor = $range['from_ts'];

		while ( $cursor < $range['to_end'] ) {
			$day  = Rasad_Process_Aggregator::local_day( $cursor );
			$row  = isset( $by_day[ $day ] ) ? $by_day[ $day ] : null;
			$next = Rasad_Process_Aggregator::day_bounds( $day );

			$points[] = array(
				't'        => $cursor,
				'label'    => $day,
				'views'    => $row ? (int) $row->views : 0,
				'visitors' => $row ? (int) $row->visitors : 0,
			);

			$cursor = $next[1];
		}

		return array( 'points' => $points );
	}

	/**
	 * Shapes page rows for the client.
	 *
	 * @param object[] $rows        Query rows.
	 * @param bool     $with_count  Include a single count column as `count`.
	 * @param string   $count_field Which column feeds `count`: entries|exits.
	 * @return array<int,array<string,mixed>>
	 */
	private static function page_rows( $rows, $with_count = false, $count_field = 'entries' ) {
		$out = array();

		foreach ( (array) $rows as $row ) {
			$item = array(
				'path'     => (string) $row->path,
				'title'    => ( '' !== (string) $row->title ? (string) $row->title : (string) $row->path ),
				'views'    => (int) $row->views,
				'visitors' => (int) $row->visitors,
			);

			if ( isset( $row->post_id ) && (int) $row->post_id > 0 ) {
				$item['post_id'] = (int) $row->post_id;
			}

			if ( $with_count ) {
				$item['count'] = isset( $row->$count_field ) ? (int) $row->$count_field : 0;
			} else {
				$item['entries'] = isset( $row->entries ) ? (int) $row->entries : 0;
				$item['exits']   = isset( $row->exits ) ? (int) $row->exits : 0;
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Post-type breakdown rows.
	 *
	 * @param object[] $rows Query rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function post_type_rows( $rows ) {
		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'post_type' => (string) $row->post_type,
				'views'     => (int) $row->views,
				'visitors'  => (int) $row->visitors,
			);
		}

		return $out;
	}

	/**
	 * Referrer/campaign rows for the client.
	 *
	 * @param object[] $rows Query rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function source_rows( $rows ) {
		$out = array();

		foreach ( (array) $rows as $row ) {
			$domain = isset( $row->domain ) ? (string) $row->domain : '';

			$out[] = array(
				'domain'   => $domain,
				'name'     => ( isset( $row->name ) && '' !== (string) $row->name ) ? (string) $row->name : $domain,
				'type'     => isset( $row->type ) ? (int) $row->type : 0,
				'views'    => isset( $row->views ) ? (int) $row->views : 0,
				'visitors' => isset( $row->visitors ) ? (int) $row->visitors : 0,
			);
		}

		return $out;
	}
}
