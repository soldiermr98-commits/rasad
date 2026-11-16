<?php
/**
 * Aggregator: drains the buffer into raw tables, then (re)builds the
 * summary tables for every day the batch touched.
 *
 * Triggered by the every-minute WP-Cron event (RASAD_CRON_HOOK), the
 * secret-key REST route, WP-CLI (`wp rasad aggregate`) and — when the
 * backlog grows or cron goes stale — a non-blocking spawn from the
 * collect hot path.
 *
 * Correctness model:
 * - Raw tables (hits/sessions/pages/referrers) are written per batch.
 * - Summary tables are fully recomputed per affected local day through
 *   multi-row INSERT ... ON DUPLICATE KEY UPDATE, so numbers are exact
 *   (unique visitors included) and reruns are idempotent.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buffer -> raw -> summary aggregation pipeline.
 */
final class Rasad_Process_Aggregator {

	/** MySQL named lock: cross-process (and cross-server with shared DB). */
	const LOCK_NAME = 'rasad_agg';

	/** Seconds without an aggregation run before the hot path respawns cron. */
	const STALE_SECONDS = 120;

	/** File-buffer backlog (bytes) considered "large" on the hot path. */
	const LARGE_BYTES = 262144;

	/** Non-file backlog (lines) considered "large". */
	const LARGE_LINES = 200;

	/** Per-run wall-clock budget (cron fires every minute). */
	const RUN_SECONDS = 15;

	/** Inactivity window that splits two sessions of one visitor. */
	const SESSION_GAP = 1800;

	/** Session spawn throttling marker lives this long (seconds). */
	const SPAWN_THROTTLE = 30;

	/** Today is fully recomputed at most this often (seconds); between runs
	 *  only additive view/session increments are applied to today's row. */
	const TODAY_FULL_RECOMPUTE_SECONDS = 300;

	/** Path => array{id:int,title:string,post_id:int,post_type:string}. */
	private static $page_cache = array();

	/** Domain => rsd_referrers.id. */
	private static $ref_cache = array();

	/** Path => array{post_id:int,post_type:string}. */
	private static $post_cache = array();

	/** int => session row object (per batch). */
	private static $sessions_seen = array();

	/** Rows whose day needs a summary rebuild: day string => true. */
	private static $affected = array();

	/** bool — GET_LOCK held for this run. */
	private static $lock_db = false;

	/** resource|null — flock handle for this run. */
	private static $lock_fh = null;

	/** bool — spawn attempted this request. */
	private static $spawned = false;

	/** Search-result page ids (per run). */
	private static $search_pages = null;

	/** WooCommerce events awaiting rsd_events write (per run). */
	private static $events_seen = array();

	/** Additive view increments for today (keyed by local day). */
	private static $additive_views = array();

	/** Additive session increments for today (keyed by local day). */
	private static $additive_sessions = array();

	/*
	|--------------------------------------------------------------------------
	| Hooks & triggers
	|--------------------------------------------------------------------------
	*/

	/**
	 * WP-Cron minute interval.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing intervals.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['rasad_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Rasad)',
		);

		return $schedules;
	}

	/**
	 * Called on `rasad_after_hit`: spawn cron without blocking the beacon
	 * when the buffer is large or the last aggregation is stale.
	 *
	 * @return bool True when a spawn was attempted.
	 */
	public static function maybe_spawn() {
		if ( self::$spawned ) {
			return false;
		}

		/**
		 * Filters whether the hot path may spawn cron for a backlog.
		 *
		 * @param bool $spawn Default true.
		 */
		if ( ! apply_filters( 'rasad_spawn_cron', true ) ) {
			return false;
		}

		self::$spawned = true;

		// Cross-request throttle: at most one spawn attempt per 30 seconds.
		$throttle = Rasad_Plugin::storage_dir( 'buffer/.spawn' );
		$mtime    = @filemtime( $throttle );

		if ( false !== $mtime && ( time() - $mtime ) < self::SPAWN_THROTTLE ) {
			return false;
		}

		$stale = self::is_stale();

		if ( $stale ) {
			if ( ! self::has_backlog() ) {
				return false;
			}
		} elseif ( ! self::is_large() ) {
			return false;
		}

		@file_put_contents( $throttle, (string) time(), LOCK_EX );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();

			return true;
		}

		// SHORTINIT (collect.php): no cron API — fire a tiny loopback request.
		return self::loopback_cron();
	}

	/**
	 * Whether the last aggregation marker is missing/stale.
	 *
	 * @return bool
	 */
	private static function is_stale() {
		$mtime = @filemtime( Rasad_Plugin::storage_dir( 'buffer/.last_agg' ) );

		if ( false === $mtime ) {
			return true;
		}

		return ( time() - $mtime ) >= self::STALE_SECONDS;
	}

	/**
	 * Whether any buffer content exists (cheap, driver-aware).
	 *
	 * @return bool
	 */
	private static function has_backlog() {
		if ( Rasad_Plugin::buffer() instanceof Rasad_Buffer_File ) {
			foreach ( glob( Rasad_Plugin::storage_dir( 'buffer/hits-*.jsonl' ) ) as $file ) {
				$size = @filesize( $file );

				if ( false !== $size && $size > 0 ) {
					return true;
				}
			}

			return false;
		}

		return Rasad_Plugin::buffer()->count() > 0;
	}

	/**
	 * Whether the backlog is large enough to demand an immediate run.
	 *
	 * @return bool
	 */
	private static function is_large() {
		if ( Rasad_Plugin::buffer() instanceof Rasad_Buffer_File ) {
			$bytes = 0;

			foreach ( glob( Rasad_Plugin::storage_dir( 'buffer/hits-*.jsonl' ) ) as $file ) {
				$size = @filesize( $file );

				if ( false !== $size ) {
					$bytes += $size;
				}
			}

			return $bytes >= self::LARGE_BYTES;
		}

		return Rasad_Plugin::buffer()->count() >= self::LARGE_LINES;
	}

	/**
	 * Non-blocking loopback hit on wp-cron.php (SHORTINIT fallback).
	 *
	 * @return bool True when the request was written to the wire.
	 */
	private static function loopback_cron() {
		$host = Rasad_Settings::site_host();

		if ( '' === $host || ! function_exists( 'fsockopen' ) ) {
			return false;
		}

		$path    = '/wp-cron.php?doing_wp_cron=' . time();
		$request = "GET {$path} HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
		$targets = array(
			array( 'ssl://' . $host, 443 ),
			array( $host, 80 ),
		);

		foreach ( $targets as $target ) {
			$errno  = 0;
			$errstr = '';
			$fp     = @fsockopen( $target[0], $target[1], $errno, $errstr, 1 );

			if ( false === $fp ) {
				continue;
			}

			stream_set_timeout( $fp, 1 );
			@fwrite( $fp, $request );
			@fclose( $fp );

			return true;
		}

		return false;
	}

	/**
	 * Secret-key URL for real server cron (documented for admins).
	 *
	 * @return string
	 */
	public static function cron_url() {
		$url = function_exists( 'rest_url' )
			? rest_url( Rasad_Api_Rest::NS . '/cron' )
			: home_url( '/wp-json/' . Rasad_Api_Rest::NS . '/cron' );

		return add_query_arg( 'key', self::cron_key(), $url );
	}

	/**
	 * Deterministic secret for the server-cron route (no stored option).
	 *
	 * @return string 64-char hex.
	 */
	public static function cron_key() {
		// A rotatable, admin-configured secret takes precedence over the
		// derived HMAC so keys can be rotated without changing salts.
		$configured = Rasad_Settings::get( 'cron_key' );

		if ( is_string( $configured ) && '' !== $configured ) {
			return $configured;
		}

		$salt = function_exists( 'wp_salt' )
			? (string) wp_salt( 'auth' )
			: ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );

		return hash_hmac( 'sha256', 'rasad-cron|' . Rasad_Settings::site_host(), $salt );
	}

	/**
	 * Registers `wp rasad aggregate` / `wp rasad clean` (called when WP_CLI).
	 *
	 * @return void
	 */
	public static function register_cli() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command(
			'rasad aggregate',
			function () {
				$stats = self::run();
				WP_CLI::success( 'Aggregated: ' . wp_json_encode( $stats ) );
			}
		);

		WP_CLI::add_command(
			'rasad clean',
			function () {
				$stats = Rasad_Process_Cleaner::run();
				WP_CLI::success( 'Cleaned: ' . wp_json_encode( $stats ) );
			}
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Run
	|--------------------------------------------------------------------------
	*/

	/**
	 * One aggregation run (cron/CLI/REST entry point). Lock-protected.
	 *
	 * @return array<string,mixed> Run statistics.
	 */
	public static function run() {
		if ( ! self::acquire() ) {
			return array( 'skipped' => 1 );
		}

		try {
			$stats = self::execute();
		} catch ( Throwable $error ) { // phpcs:ignore -- aggregated silently; details never leave the server.
			$stats = array( 'error' => 1 );
		} finally {
			self::release();
		}

		/** Fires after an aggregation run (health panel, integrations). */
		do_action( 'rasad_aggregated', $stats );

		return $stats;
	}

	/**
	 * Batch loop + recompute (caller holds the lock).
	 *
	 * @return array<string,mixed>
	 */
	private static function execute() {
		self::$page_cache  = array();
		self::$ref_cache   = array();
		self::$post_cache  = array();
		self::$sessions_seen = array();
		self::$affected    = array();
		self::$search_pages = null;
		self::$events_seen = array();
		self::$additive_views = array();
		self::$additive_sessions = array();

		$batch_size = (int) Rasad_Settings::get( 'aggregate_batch' );
		$batch_size = max( 100, min( 5000, $batch_size ? $batch_size : 1000 ) );

		/**
		 * Filters the buffer batch size for one aggregation run.
		 *
		 * @param int $batch_size Default from settings (100-5000).
		 */
		$batch_size = (int) apply_filters( 'rasad_aggregate_batch_size', $batch_size );
		$batch_size = max( 1, $batch_size );

		/**
		 * Filters the per-run wall-clock budget in seconds.
		 *
		 * @param int $seconds Default 15.
		 */
		$seconds = (int) apply_filters( 'rasad_aggregate_run_seconds', self::RUN_SECONDS );
		$deadline = microtime( true ) + max( 1, $seconds );

		$buffer    = Rasad_Plugin::buffer();
		$processed = 0;
		$batches   = 0;
		$error     = null;

		try {
			do {
				$hits = $buffer->pop_batch( $batch_size );

				if ( empty( $hits ) ) {
					break;
				}

				$processed += self::process_batch( $hits );
				$batches++;

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			} while ( true );
		} catch ( Throwable $batch_error ) {
			// Keep what was already written consistent, then rethrow.
			$error = $batch_error;
		}

		if ( ! empty( self::$affected ) ) {
			self::recompute( array_keys( self::$affected ) );
		}

		if ( null === $error ) {
			self::touch_marker( $processed );
		}

		if ( null !== $error ) {
			throw $error;
		}

		$stats = array(
			'processed' => $processed,
			'batches'   => $batches,
			'days'      => count( self::$affected ),
			'left'      => (int) $buffer->count(),
		);

		return $stats;
	}

	/**
	 * Writes one popped batch into raw tables.
	 *
	 * @param array<int,array<string,mixed>> $hits Decoded buffer lines.
	 * @return int Accepted events (pageviews + pings).
	 */
	private static function process_batch( array $hits ) {
		$now   = time();
		$views = array();
		$pings = array();

		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}

			$vk = isset( $hit['vk'] ) && is_string( $hit['vk'] ) ? strtolower( $hit['vk'] ) : '';

			if ( ! preg_match( '/^[0-9a-f]{16}$/', $vk ) ) {
				continue;
			}

			$ts = isset( $hit['ts'] ) ? (int) $hit['ts'] : 0;

			if ( $ts <= 0 || $ts > $now + 600 ) {
				$ts = $now;
			}

			$event = isset( $hit['e'] ) ? (int) $hit['e'] : 1;

			if ( 2 === $event ) {
				$pings[] = array(
					'vk' => $vk,
					'ts' => $ts,
					'd'  => isset( $hit['d'] ) ? max( 0, min( 3600000, (int) $hit['d'] ) ) : 0,
				);

				continue;
			}

			// WooCommerce events (3=product view, 4=add to cart, 5=checkout, 6=purchase)
			// bypass session/pageview processing and go straight to rsd_events.
			if ( in_array( $event, array( 3, 4, 5, 6 ), true ) ) {
				$path = isset( $hit['p'] ) && is_string( $hit['p'] ) ? $hit['p'] : '';

				if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 500 ) {
					continue;
				}

				self::$events_seen[] = array(
					'ts' => $ts,
					'p'  => $path,
					'e'  => $event,
					'v'  => isset( $hit['wc_value'] ) ? (float) $hit['wc_value'] : 0.0,
				);

				self::mark_day( $ts );
				continue;
			}

			$path = isset( $hit['p'] ) && is_string( $hit['p'] ) ? $hit['p'] : '';

		if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 500 ) {
			continue;
		}

		$day = self::local_day( $ts );
		self::$additive_views[ $day ] = isset( self::$additive_views[ $day ] )
			? self::$additive_views[ $day ] + 1
			: 1;

		$views[] = array(
			'vk'  => $vk,
			'ts'  => $ts,
				'p'   => $path,
				'r'   => isset( $hit['r'] ) ? (string) $hit['r'] : '',
				'ua'  => isset( $hit['ua'] ) ? (string) $hit['ua'] : '',
				'ip'  => isset( $hit['ip'] ) ? (string) $hit['ip'] : '',
				't'   => isset( $hit['t'] ) ? (string) $hit['t'] : '',
				'us'  => isset( $hit['us'] ) ? (string) $hit['us'] : '',
				'um'  => isset( $hit['um'] ) ? (string) $hit['um'] : '',
				'uc'  => isset( $hit['uc'] ) ? (string) $hit['uc'] : '',
			);
		}

		if ( empty( $views ) && empty( $pings ) ) {
			return 0;
		}

		// Chronological processing keeps entry/exit attribution natural.
		$views = self::sort_by_ts( $views );
		$pings = self::sort_by_ts( $pings );

		self::load_open_sessions( $views, $pings );

		$hit_rows = array();

		foreach ( $views as $view ) {
			$page_id = self::resolve_page( $view['p'], $view['t'] );
			$session = self::match_session( $view['vk'], $view['ts'] );

			$campaign = Rasad_Process_Referrer::campaign( $view );
			$ref_id   = 0;

			if ( null !== $campaign ) {
				$ref_id = self::resolve_referrer( $campaign['domain'], $campaign['type'], $campaign['name'] );
			} else {
				$row    = Rasad_Process_Referrer::classify( $view['r'] );

				if ( Rasad_Process_Referrer::TYPE_DIRECT !== $row['type'] && '' !== $row['domain'] ) {
					$ref_id = self::resolve_referrer( $row['domain'], $row['type'], $row['name'] );
				}
			}

			/**
			 * Filters the referrer row attributed to a visitor's session.
			 *
			 * @param int                   $ref_id Referrers row id (0 = direct).
			 * @param array<string,mixed>   $view   Normalized view.
			 */
			$ref_id = (int) apply_filters( 'rasad_session_referrer', $ref_id, $view );

			$codes = Rasad_Collect_Ua_Parser::parse( $view['ua'] );

			if ( null === $session ) {
				$session = self::create_session( $view, $page_id, $ref_id, $codes );
			} else {
				self::touch_session( $session, $view, $page_id, $codes );
			}

			self::mark_day( $view['ts'] );
			self::mark_day( (int) $session->started_at );

			$hit_rows[] = array(
				(int) $session->id,
				$page_id,
				(int) $view['ts'],
			);
		}

		self::insert_hits( $hit_rows );
		self::apply_pings( $pings );

		// Persist WooCommerce events to rsd_events (written once, never recomputed).
		self::insert_events( self::$events_seen );

		return count( $views ) + count( $pings );
	}

	/**
	 * Stable chronological sort (ties keep buffer order).
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with a `ts` key.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sort_by_ts( array $rows ) {
		$keys = array_keys( $rows );

		usort(
			$keys,
			function ( $a, $b ) use ( $rows ) {
				$cmp = $rows[ $a ]['ts'] - $rows[ $b ]['ts'];

				return ( 0 !== $cmp ) ? $cmp : ( $a - $b );
			}
		);

		$out = array();

		foreach ( $keys as $key ) {
			$out[] = $rows[ $key ];
		}

		return $out;
	}

	/**
	 * Loads every possibly-open session for this batch in ONE query.
	 *
	 * @param array<int,array<string,mixed>> $views Pageviews.
	 * @param array<int,array<string,mixed>> $pings Duration pings.
	 * @return void
	 */
	private static function load_open_sessions( array $views, array $pings ) {
		self::$sessions_seen = array();

		$all   = array_merge( $views, $pings );
		$min   = 0;
		$max   = 0;

		foreach ( $all as $row ) {
			$min = ( 0 === $min || $row['ts'] < $min ) ? $row['ts'] : $min;
			$max = ( 0 === $max || $row['ts'] > $max ) ? $row['ts'] : $max;
		}

		if ( 0 === $min ) {
			return;
		}

		$vks = array();

		foreach ( $all as $row ) {
			$vks[ $row['vk'] ] = true;
		}

		$vks      = array_keys( $vks );
		$gap      = self::session_gap();
		$ph       = implode( ', ', array_fill( 0, count( $vks ), 'UNHEX(%s)' ) );
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sql = $wpdb->prepare(
			"SELECT id, visitor_key, started_at, last_seen, pageviews, duration, entry_page, exit_page, referrer_id, country, city_id, device, browser, os, is_bounce"
			. " FROM " . Rasad_Installer::table( 'sessions' )
			. " WHERE visitor_key IN ( {$ph} ) AND last_seen >= %d AND last_seen <= %d",
			array_merge( $vks, array( $min - $gap, $max + 300 ) )
		);

		$rows = $wpdb->get_results( $sql );

		foreach ( (array) $rows as $row ) {
			$hex = bin2hex( (string) $row->visitor_key );
			self::$sessions_seen[ (int) $row->id ] = $row;
			$row->_vk = $hex; // phpcs:ignore -- dynamic marker used by match_session().
		}
	}

	/**
	 * Finds the open session of one visitor at one timestamp.
	 *
	 * @param string $vk Visitor key (16 hex).
	 * @param int    $ts Event timestamp.
	 * @return object|null Session row.
	 */
	public static function match_session( $vk, $ts ) {
		$gap    = self::session_gap();
		$best   = null;
		$best_t = 0;

		foreach ( self::$sessions_seen as $row ) {
			if ( ! isset( $row->_vk ) || $row->_vk !== $vk ) {
				continue;
			}

			$last = (int) $row->last_seen;

			if ( $last < ( $ts - $gap ) || $last > ( $ts + 300 ) ) {
				continue;
			}

			if ( $last >= $best_t ) {
				$best   = $row;
				$best_t = $last;
			}
		}

		return $best;
	}

	/**
	 * Inserts a brand-new session row.
	 *
	 * @param array<string,mixed> $view    Normalized view.
	 * @param int                 $page_id Entry page id.
	 * @param int                 $ref_id  Referrer row id.
	 * @param array<string,int>   $codes   Parsed UA codes.
	 * @return object In-memory session row.
	 */
	private static function create_session( array $view, $page_id, $ref_id, array $codes ) {
		global $wpdb;

		$country  = '';
		$city_id  = 0;

		// Track new sessions created today for additive view/session increments.
		self::$additive_sessions[ self::local_day( $view['ts'] ) ] = isset( self::$additive_sessions[ self::local_day( $view['ts'] ) ] )
			? self::$additive_sessions[ self::local_day( $view['ts'] ) ] + 1
			: 1;

		if ( class_exists( 'Rasad_Process_Geoip' ) ) {
			$geo = Rasad_Process_Geoip::lookup( isset( $view['ip'] ) ? $view['ip'] : '' );

			if ( is_array( $geo ) ) {
				$country = isset( $geo[0] ) ? substr( (string) $geo[0], 0, 2 ) : '';
				$city_id = isset( $geo[1] ) ? (int) $geo[1] : 0;
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sql = $wpdb->prepare(
			'INSERT INTO ' . Rasad_Installer::table( 'sessions' )
			. ' (visitor_key, started_at, last_seen, pageviews, duration, entry_page, exit_page,'
			. ' referrer_id, country, city_id, device, browser, os, is_bounce)'
			. ' VALUES (UNHEX(%s), %d, %d, 1, 0, %d, %d, %d, %s, %d, %d, %d, %d, 1)',
			$view['vk'],
			(int) $view['ts'],
			(int) $view['ts'],
			$page_id,
			$page_id,
			$ref_id,
			$country,
			$city_id,
			(int) $codes['device'],
			(int) $codes['browser'],
			(int) $codes['os']
		);
		$wpdb->query( $sql );

		$row = new stdClass();
		$row->id           = (int) $wpdb->insert_id;
		$row->_vk          = $view['vk'];
		$row->started_at   = (int) $view['ts'];
		$row->last_seen    = (int) $view['ts'];
		$row->pageviews    = 1;
		$row->duration     = 0;
		$row->entry_page   = $page_id;
		$row->exit_page    = $page_id;
		$row->referrer_id  = $ref_id;
		$row->country      = $country;
		$row->city_id      = $city_id;
		$row->device       = (int) $codes['device'];
		$row->browser      = (int) $codes['browser'];
		$row->os           = (int) $codes['os'];
		$row->is_bounce    = 1;

		self::$sessions_seen[ $row->id ] = $row;

		return $row;
	}

	/**
	 * Applies a pageview to an open session (pageviews/exit/bounce/dims).
	 *
	 * @param object              $session Session row (mutated).
	 * @param array<string,mixed> $view    Normalized view.
	 * @param int                 $page_id Current page id.
	 * @param array<string,int>   $codes   Parsed UA codes.
	 * @return void
	 */
	private static function touch_session( $session, array $view, $page_id, array $codes ) {
		global $wpdb;

		$session->pageviews = (int) $session->pageviews + 1;
		$session->last_seen = max( (int) $session->last_seen, (int) $view['ts'] );
		$session->exit_page = $page_id;
		$session->is_bounce = $session->pageviews > 1 ? 0 : 1;

		// Same visitor_key => same UA within a day; fill dims defensively.
		foreach ( array( 'device', 'browser', 'os' ) as $field ) {
			if ( empty( $session->$field ) && ! empty( $codes[ $field ] ) ) {
				$session->$field = (int) $codes[ $field ];
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sql = $wpdb->prepare(
			'UPDATE ' . Rasad_Installer::table( 'sessions' )
			. ' SET last_seen = %d, pageviews = %d, exit_page = %d, is_bounce = %d,'
			. ' device = %d, browser = %d, os = %d WHERE id = %d',
			(int) $session->last_seen,
			(int) $session->pageviews,
			(int) $session->exit_page,
			(int) $session->is_bounce,
			(int) $session->device,
			(int) $session->browser,
			(int) $session->os,
			(int) $session->id
		);
		$wpdb->query( $sql );
	}

	/**
	 * Applies duration pings to their sessions (aggregated per session).
	 *
	 * @param array<int,array<string,mixed>> $pings Normalized pings.
	 * @return void
	 */
	private static function apply_pings( array $pings ) {
		if ( empty( $pings ) ) {
			return;
		}

		global $wpdb;

		$by_session = array();

		foreach ( $pings as $ping ) {
			$session = self::match_session( $ping['vk'], $ping['ts'] );

			if ( null === $session ) {
				continue;
			}

			$id = (int) $session->id;

			if ( ! isset( $by_session[ $id ] ) ) {
				$by_session[ $id ] = array(
					'd'  => 0,
					'ts' => 0,
					'row' => $session,
				);
			}

			$by_session[ $id ]['d'] += (int) $ping['d'];
			$by_session[ $id ]['ts'] = max( $by_session[ $id ]['ts'], (int) $ping['ts'] );

			self::mark_day( (int) $ping['ts'] );
			self::mark_day( (int) $session->started_at );
		}

		foreach ( $by_session as $id => $agg ) {
			$session = $agg['row'];
			$seconds = (int) floor( $agg['d'] / 1000 );
			$last    = max( (int) $session->last_seen, $agg['ts'] );

			$session->duration  = (int) $session->duration + $seconds;
			$session->last_seen = $last;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$sql = $wpdb->prepare(
				'UPDATE ' . Rasad_Installer::table( 'sessions' )
				. ' SET duration = duration + %d, last_seen = %d WHERE id = %d',
				$seconds,
				$last,
				$id
			);
			$wpdb->query( $sql );
		}
	}

	/**
	 * Multi-row INSERT of raw hits (chunks of 500).
	 *
	 * @param array<int,array{0:int,1:int,2:int>} $rows session_id, page_id, ts.
	 * @return void
	 */
	private static function insert_hits( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			$ph     = array();

			foreach ( $chunk as $row ) {
				$values[] = $row[0];
				$values[] = $row[1];
				$values[] = $row[2];
				$ph[]     = '(%d, %d, %d)';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$sql = $wpdb->prepare(
				'INSERT INTO ' . Rasad_Installer::table( 'hits' )
				. ' (session_id, page_id, ts) VALUES ' . implode( ', ', $ph ),
				$values
			);
			$wpdb->query( $sql );
		}
	}

	/**
	 * Writes WooCommerce events to rsd_events.
	 * Events are written once when popped from the buffer — they are not
	 * part of the day-recompute cycle (rsd_hits lacks WooCommerce fields).
	 *
	 * @param array<int,array{ts:int,p:string,e:int,v:float}> $events Event records.
	 * @return void
	 */
	private static function insert_events( array $events ) {
		if ( empty( $events ) ) {
			return;
		}

		global $wpdb;
		$table = Rasad_Installer::table( 'events' );

		foreach ( array_chunk( $events, 500 ) as $chunk ) {
			$values = array();
			$ph     = array();

			foreach ( $chunk as $event ) {
				$day   = self::local_day( $event['ts'] );
				// Resolve the path to a page id (product/cart/checkout pages when possible).
				$page_id = self::resolve_page( $event['p'], '' );
				$type  = (string) $event['e'];
				$value = $event['v'];

				$values[] = $day;
				$values[] = $type;
				$values[] = $page_id;
				$values[] = $value;
				$ph[]     = '(%s, %s, %s, %s)';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$sql = $wpdb->prepare(
				'INSERT INTO ' . $table . ' (day, type, page_id, value) VALUES ' . implode( ', ', $ph ),
				$values
			);
			$wpdb->query( $sql );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Lookups (pages / referrers)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolves a path to a rsd_pages row (insert on first sight).
	 *
	 * @param string $path  Page path.
	 * @param string $title Client-provided title (may be '').
	 * @return int Page id.
	 */
	private static function resolve_page( $path, $title ) {
		if ( isset( self::$page_cache[ $path ] ) ) {
			$row = self::$page_cache[ $path ];

			if ( '' === $row['title'] && '' !== $title ) {
				self::backfill_page( $row['id'], $title );
			}

			return (int) $row['id'];
		}

		global $wpdb;

		$hash = substr( md5( $path ), 0, 16 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, title FROM ' . Rasad_Installer::table( 'pages' ) . ' WHERE hash = %s LIMIT 1',
				$hash
			)
		);

		if ( $row ) {
			$id = (int) $row->id;

			self::$page_cache[ $path ] = array(
				'id'    => $id,
				'title' => (string) $row->title,
			);

			if ( '' === (string) $row->title && '' !== $title ) {
				self::backfill_page( $id, $title );
			}

			return $id;
		}

		list( $post_id, $post_type ) = self::resolve_post( $path );

		if ( '' === $title && $post_id && function_exists( 'get_the_title' ) ) {
			$title = (string) get_the_title( $post_id );
			$title = trim( wp_strip_all_tags( $title ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sql = $wpdb->prepare(
			'INSERT INTO ' . Rasad_Installer::table( 'pages' )
			. ' (hash, path, post_id, post_type, title) VALUES (%s, %s, %d, %s, %s)',
			$hash,
			$path,
			$post_id,
			$post_type,
			self::clean_title( $title )
		);
		$wpdb->query( $sql );

		$id = (int) $wpdb->insert_id;

		self::$page_cache[ $path ] = array(
			'id'    => $id,
			'title' => self::clean_title( $title ),
		);

		return $id;
	}

	/**
	 * Stores a late-arriving title on an existing page row.
	 *
	 * @param int    $id    Page id.
	 * @param string $title Title.
	 * @return void
	 */
	private static function backfill_page( $id, $title ) {
		global $wpdb;

		$title = self::clean_title( $title );

		if ( '' === $title ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sql = $wpdb->prepare(
			'UPDATE ' . Rasad_Installer::table( 'pages' )
			. ' SET title = %s WHERE id = %d AND (title IS NULL OR title = %s)',
			$title,
			$id,
			''
		);
		$wpdb->query( $sql );
	}

	/**
	 * Sanitizes a page title for storage.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private static function clean_title( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		return strlen( $title ) > 255 ? substr( $title, 0, 255 ) : $title;
	}

	/**
	 * Resolves a path to post_id/post_type (memoized, full WP only).
	 *
	 * @param string $path Page path.
	 * @return array{0:int,1:string}
	 */
	private static function resolve_post( $path ) {
		if ( ! function_exists( 'url_to_postid' ) || false !== strpos( $path, '?' ) ) {
			return array( 0, '' );
		}

		if ( ! isset( self::$post_cache[ $path ] ) ) {
			$pid = 0;

			if ( function_exists( 'home_url' ) ) {
				$pid = (int) url_to_postid( home_url( $path ) );
			}

			$type = '';

			if ( $pid && function_exists( 'get_post_type' ) ) {
				$type = (string) get_post_type( $pid );
			}

			self::$post_cache[ $path ] = array( $pid, $type );
		}

		return self::$post_cache[ $path ];
	}

	/**
	 * Resolves (and lazily creates) a rsd_referrers row.
	 *
	 * @param string $domain Row key (base domain or `utm:` slug).
	 * @param int    $type   Type constant.
	 * @param string $name   Display name ('' allowed).
	 * @return int Row id (0 only for direct).
	 */
	private static function resolve_referrer( $domain, $type, $name ) {
		if ( '' === $domain || Rasad_Process_Referrer::TYPE_DIRECT === $type ) {
			return 0;
		}

		if ( isset( self::$ref_cache[ $domain ] ) ) {
			return (int) self::$ref_cache[ $domain ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM ' . Rasad_Installer::table( 'referrers' ) . ' WHERE domain = %s LIMIT 1',
				$domain
			)
		);

		if ( $row ) {
			self::$ref_cache[ $domain ] = (int) $row->id;

			return (int) $row->id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sql = $wpdb->prepare(
			'INSERT INTO ' . Rasad_Installer::table( 'referrers' )
			. ' (domain, type, name) VALUES (%s, %d, %s)',
			$domain,
			(int) $type,
			$name
		);
		$wpdb->query( $sql );

		$id = (int) $wpdb->insert_id;

		if ( ! $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id FROM ' . Rasad_Installer::table( 'referrers' ) . ' WHERE domain = %s LIMIT 1',
					$domain
				)
			);
			$id = $row ? (int) $row->id : 0;
		}

		self::$ref_cache[ $domain ] = $id;

		return $id;
	}

	/*
	|--------------------------------------------------------------------------
	| Summary rebuild
	|--------------------------------------------------------------------------
	*/

	/**
	 * Fully recomputes every summary row for the given local days.
	 *
	 * @param string[] $days 'Y-m-d' strings (site timezone).
	 * @return void
	 */
	private static function recompute( array $days ) {
		sort( $days );

		$daily_rows  = array();
		$hourly_rows = array();
		$pages_rows  = array();
		$dim_rows    = array();

		foreach ( $days as $day ) {
			list( $start, $end ) = self::day_bounds( $day );
			$stats               = self::recompute_day( $day, $start, $end );

			$daily_rows  = array_merge( $daily_rows, $stats['daily'] );
			$hourly_rows = array_merge( $hourly_rows, $stats['hourly'] );
			$pages_rows  = array_merge( $pages_rows, $stats['pages'] );
			$dim_rows    = array_merge( $dim_rows, $stats['dims'] );
		}

		self::flush_odku( 'daily', array( 'day', 'views', 'visitors', 'sessions', 'bounces', 'total_duration' ), $daily_rows );
		self::flush_odku( 'hourly', array( 'hour_ts', 'views', 'visitors' ), $hourly_rows );
		self::flush_odku( 'daily_pages', array( 'day', 'page_id', 'views', 'visitors', 'entries', 'exits' ), $pages_rows );
		self::flush_odku( 'daily_dim', array( 'day', 'dim', 'dim_id', 'views', 'visitors' ), $dim_rows );
	}

	/**
	 * Recomputes all summary rows of one day from the raw tables.
	 *
	 * @param string $day   'Y-m-d'.
	 * @param int    $start Local midnight (inclusive).
	 * @param int    $end   Next local midnight (exclusive).
	 * @return array{daily:array, hourly:array, pages:array, dims:array}
	 */
	private static function recompute_day( $day, $start, $end ) {
		global $wpdb;

		$hits     = Rasad_Installer::table( 'hits' );
		$sessions = Rasad_Installer::table( 'sessions' );
		$out      = array(
			'daily'  => array(),
			'hourly' => array(),
			'pages'  => array(),
			'dims'   => array(),
		);

		// --- rsd_daily: views, visitors, sessions, bounces, duration.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$hit_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS v, COUNT(DISTINCT s.visitor_key) AS vi"
				. " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
				. ' WHERE h.ts >= %d AND h.ts < %d',
				$start,
				$end
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$sess_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, COALESCE(SUM(is_bounce), 0) AS b, COALESCE(SUM(duration), 0) AS d"
				. " FROM {$sessions}" . ' WHERE started_at >= %d AND started_at < %d',
				$start,
				$end
			)
		);

		$views     = $hit_stats ? (int) $hit_stats->v : 0;
		$visitors  = $hit_stats ? (int) $hit_stats->vi : 0;
		$sess_cnt  = $sess_stats ? (int) $sess_stats->c : 0;
		$bounces   = $sess_stats ? (int) $sess_stats->b : 0;
		$duration  = $sess_stats ? (int) $sess_stats->d : 0;

		if ( $views > 0 || $sess_cnt > 0 ) {
			$out['daily'][] = array( $day, $views, $visitors, $sess_cnt, $bounces, $duration );
		}

		// --- rsd_hourly (site-timezone hour buckets; views + visitors).
		// The bucket key is the UTC timestamp of the start of the local hour
		// containing the hit: ts - ((ts + offset_at_ts) mod 3600). This keeps
		// zones like Asia/Tehran (+3:30), India (+5:30), Nepal (+5:45) aligned
		// and is DST-safe because the offset is resolved per hit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.ts AS ts, s.visitor_key AS vk"
				. " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
				. ' WHERE h.ts >= %d AND h.ts < %d',
				$start,
				$end
			)
		);

		$tz       = self::tz();
		$buckets  = array();

		foreach ( (array) $rows as $row ) {
			$ts = (int) $row->ts;
			$dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
			// Offset east of UTC in seconds at this exact timestamp (DST-aware).
			$off = (int) $dt->getOffset();
			// Start of the local hour, expressed as a UTC timestamp.
			$hr = $ts - ( ( $ts + $off ) % 3600 );

			if ( ! isset( $buckets[ $hr ] ) ) {
				$buckets[ $hr ] = array( 'v' => 0, 'vi' => array() );
			}
			$buckets[ $hr ]['v']++;
			$buckets[ $hr ]['vi'][ (string) $row->vk ] = true;
		}

		foreach ( $buckets as $hr => $data ) {
			$out['hourly'][] = array( (int) $hr, (int) $data['v'], (int) count( $data['vi'] ) );
		}

		// --- rsd_daily_pages: views/visitors per page.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$page_views = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.page_id AS p, COUNT(*) AS v, COUNT(DISTINCT s.visitor_key) AS vi"
				. " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
				. ' WHERE h.ts >= %d AND h.ts < %d GROUP BY h.page_id',
				$start,
				$end
			)
		);

		$by_page = array();

		foreach ( (array) $page_views as $row ) {
			$by_page[ (int) $row->p ] = array(
				'views'    => (int) $row->v,
				'visitors' => (int) $row->vi,
				'entries'  => 0,
				'exits'    => 0,
			);
		}

		// Entries: sessions that started on this day.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_page AS p, COUNT(*) AS c FROM {$sessions}"
				. ' WHERE started_at >= %d AND started_at < %d GROUP BY entry_page',
				$start,
				$end
			)
		);

		foreach ( (array) $entries as $row ) {
			$p = (int) $row->p;

			if ( ! $p ) {
				continue;
			}

			if ( ! isset( $by_page[ $p ] ) ) {
				$by_page[ $p ] = array(
					'views'    => 0,
					'visitors' => 0,
					'entries'  => 0,
					'exits'    => 0,
				);
			}

			$by_page[ $p ]['entries'] = (int) $row->c;
		}

		// Exits: sessions last seen on this day (exit_page = last view).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$exits = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exit_page AS p, COUNT(*) AS c FROM {$sessions}"
				. ' WHERE last_seen >= %d AND last_seen < %d GROUP BY exit_page',
				$start,
				$end
			)
		);

		foreach ( (array) $exits as $row ) {
			$p = (int) $row->p;

			if ( ! $p ) {
				continue;
			}

			if ( ! isset( $by_page[ $p ] ) ) {
				$by_page[ $p ] = array(
					'views'    => 0,
					'visitors' => 0,
					'entries'  => 0,
					'exits'    => 0,
				);
			}

			$by_page[ $p ]['exits'] = (int) $row->c;
		}

		foreach ( $by_page as $page_id => $vals ) {
			$out['pages'][] = array(
				$day,
				$page_id,
				$vals['views'],
				$vals['visitors'],
				$vals['entries'],
				$vals['exits'],
			);
		}

		// --- rsd_daily_dim: referrer/country/city/browser/os/device (1-6).
		$dim_sources = array(
			1 => 'referrer_id',
			2 => 'country',
			3 => 'city_id',
			4 => 'browser',
			5 => 'os',
			6 => 'device',
		);

		foreach ( $dim_sources as $dim => $field ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.{$field} AS dv, COUNT(*) AS v, COUNT(DISTINCT s.visitor_key) AS vi"
					. " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
					. ' WHERE h.ts >= %d AND h.ts < %d GROUP BY s.' . $field,
					$start,
					$end
				)
			);

			foreach ( (array) $rows as $row ) {
				$dim_id = self::dim_id( $dim, $row->dv );

				$out['dims'][] = array( $day, $dim, $dim_id, (int) $row->v, (int) $row->vi );
			}
		}

		// --- dim 7: internal site search (attributed to the search page id).
		$search_ids = self::search_page_ids();

		if ( ! empty( $search_ids ) ) {
			$ph        = implode( ', ', array_fill( 0, count( $search_ids ), '%d' ) );
			$arguments = array_merge( array( $start, $end ), array_values( $search_ids ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT h.page_id AS dv, COUNT(*) AS v, COUNT(DISTINCT s.visitor_key) AS vi"
					. " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
					. " WHERE h.ts >= %d AND h.ts < %d AND h.page_id IN ( {$ph} ) GROUP BY h.page_id",
					$arguments
				)
			);

			foreach ( (array) $rows as $row ) {
				$out['dims'][] = array( $day, 7, (int) $row->dv, (int) $row->v, (int) $row->vi );
			}
		}

		// --- dim 8: campaigns (session attributed to a type-5 referrer row).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.referrer_id AS dv, COUNT(*) AS v, COUNT(DISTINCT s.visitor_key) AS vi"
				. " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
				. ' WHERE h.ts >= %d AND h.ts < %d AND s.referrer_id > 0'
				. ' GROUP BY s.referrer_id',
				$start,
				$end
			)
		);

		foreach ( (array) $rows as $row ) {
			// Only campaign rows (type 5) belong to dim 8.
			if ( ! self::is_campaign_ref( (int) $row->dv ) ) {
				continue;
			}

			$out['dims'][] = array( $day, 8, (int) $row->dv, (int) $row->v, (int) $row->vi );
		}

		return $out;
	}

	/**
	 * Writes summary rows with a single multi-row INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * @param string               $table   Short table name.
	 * @param string[]             $columns Column list.
	 * @param array<int,array<mixed>> $rows  Value tuples.
	 * @return void
	 */
	private static function flush_odku( $table, array $columns, array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;

		$table_name = Rasad_Installer::table( $table );

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$ph   = array();
			$args  = array();
			$tuple = '(' . implode( ', ', array_fill( 0, count( $columns ), '%s' ) ) . ')';

			// Numeric columns are still bound as strings by %s — MySQL casts
			// on insert, and this keeps one uniform placeholder shape.
			foreach ( $chunk as $row ) {
				$ph[] = $tuple;
				$args = array_merge( $args, array_values( $row ) );
			}

			$updates = array();

			foreach ( array_slice( $columns, 1 ) as $col ) {
				$updates[] = "{$col} = VALUES({$col})";
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$sql = $wpdb->prepare(
				"INSERT INTO {$table_name} (" . implode( ', ', $columns ) . ') VALUES '
				. implode( ', ', $ph )
				. ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ),
				$args
			);
			$wpdb->query( $sql );
		}
	}

/**
 * Rebuilds rsd_hourly for every day that has hits using site-timezone
 * hour buckets. Used by migration 1.2.0 after the switch from UTC-hour
 * buckets. Idempotent: truncates the table first.
 *
 * @return int Number of hourly rows written.
 */
public static function rebuild_hourly() {
    global $wpdb;

    $hits     = Rasad_Installer::table( 'hits' );
    $sessions = Rasad_Installer::table( 'sessions' );
    $hourly   = Rasad_Installer::table( 'hourly' );

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
    $wpdb->query( 'TRUNCATE TABLE ' . $hourly );

    $days = $wpdb->get_col( "SELECT DISTINCT DATE(FROM_UNIXTIME(ts)) FROM {$hits}" );

    $tz      = self::tz();
    $written = 0;

    foreach ( (array) $days as $day ) {
        list( $start, $end ) = self::day_bounds( (string) $day );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.ts AS ts, s.visitor_key AS vk"
                . " FROM {$hits} h INNER JOIN {$sessions} s ON s.id = h.session_id"
                . ' WHERE h.ts >= %d AND h.ts < %d',
                $start,
                $end
            )
        );

        $buckets = array();

        foreach ( (array) $rows as $row ) {
            $ts = (int) $row->ts;
            $dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
            // Offset east of UTC in seconds at this exact timestamp (DST-aware).
            $off = (int) $dt->getOffset();
            // Start of the local hour, expressed as a UTC timestamp.
            $hr = $ts - ( ( $ts + $off ) % 3600 );

            if ( ! isset( $buckets[ $hr ] ) ) {
                $buckets[ $hr ] = array( 'v' => 0, 'vi' => array() );
            }
            $buckets[ $hr ]['v']++;
            $buckets[ $hr ]['vi'][ (string) $row->vk ] = true;
        }

        foreach ( $buckets as $hr => $data ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
            $wpdb->insert(
                $hourly,
                array(
                    'hour_ts' => (int) $hr,
                    'views'   => (int) $data['v'],
                    'visitors'=> (int) count( $data['vi'] ),
                ),
                array( '%d', '%d', '%d' )
            );
            $written++;
        }
    }

    return (int) $written;
}
	/**
	 * Maps a raw dimension value to the INT dim_id storage.
	 *
	 * @param int   $dim Dimension constant (1-8).
	 * @param mixed $value Raw value from the summary query.
	 * @return int
	 */
	public static function dim_id( $dim, $value ) {
		if ( 2 === $dim ) {
			return self::country_dim_id( (string) $value );
		}

		return (int) $value;
	}

	/**
	 * Country code -> unsigned INT surrogate (crc32, stable + reversible
	 * by precomputation; '' maps to 0 = unresolved/unknown).
	 *
	 * @param string $code ISO alpha-2 country code.
	 * @return int
	 */
	public static function country_dim_id( $code ) {
		$code = strtoupper( trim( (string) $code ) );

		if ( '' === $code ) {
			return 0;
		}

		$crc = crc32( $code );

		return $crc < 0 ? $crc + 4294967296 : $crc;
	}

	/**
	 * Whether a referrer row id is a campaign row (type 5).
	 *
	 * @param int $ref_id Referrer row id.
	 * @return bool
	 */
	private static function is_campaign_ref( $ref_id ) {
		static $cache = array();

		if ( $ref_id <= 0 ) {
			return false;
		}

		if ( ! isset( $cache[ $ref_id ] ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$type = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT type FROM ' . Rasad_Installer::table( 'referrers' ) . ' WHERE id = %d LIMIT 1',
					$ref_id
				)
			);
			$cache[ $ref_id ] = ( Rasad_Process_Referrer::TYPE_CAMPAIGN === (int) $type );
		}

		return $cache[ $ref_id ];
	}

	/**
	 * Page ids whose path carries an internal search query (?s= / &s=).
	 * Cached per run; drives daily_dim dim 7.
	 *
	 * @return int[]
	 */
	private static function search_page_ids() {
		if ( null !== self::$search_pages ) {
			return self::$search_pages;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$rows = $wpdb->get_col(
			'SELECT id FROM ' . Rasad_Installer::table( 'pages' )
			. " WHERE path LIKE '%?s=%' OR path LIKE '%&s=%'"
		);

		$ids = array();

		foreach ( (array) $rows as $id ) {
			$ids[] = (int) $id;
		}

		self::$search_pages = $ids;

		return $ids;
	}

	/*
	|--------------------------------------------------------------------------
	| Time helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Site timezone.
	 *
	 * @return DateTimeZone
	 */
	private static function tz() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Local calendar day of a timestamp.
	 *
	 * @param int $ts Unix timestamp.
	 * @return string 'Y-m-d'
	 */
	public static function local_day( $ts ) {
		return ( new DateTimeImmutable( '@' . (int) $ts ) )
			->setTimezone( self::tz() )
			->format( 'Y-m-d' );
	}

	/**
	 * [start, end) unix bounds of a local calendar day (DST-correct).
	 *
	 * @param string $day 'Y-m-d'.
	 * @return array{0:int,1:int}
	 */
	public static function day_bounds( $day ) {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $day . ' 00:00:00', self::tz() );

		if ( false === $dt ) {
			$now = time();

			return array( $now - ( $now % 86400 ), $now + 86400 );
		}

		return array(
			$dt->getTimestamp(),
			$dt->modify( '+1 day' )->getTimestamp(),
		);
	}

	/**
	 * Session inactivity gap (filterable).
	 *
	 * @return int
	 */
	private static function session_gap() {
		/**
		 * Filters the session inactivity gap in seconds.
		 *
		 * @param int $gap Default 1800 (30 minutes).
		 */
		return max( 60, (int) apply_filters( 'rasad_session_gap', self::SESSION_GAP ) );
	}

	/**
	 * Marks a local day as needing a summary rebuild.
	 *
	 * @param int $ts Timestamp.
	 * @return void
	 */
	private static function mark_day( $ts ) {
		self::$affected[ self::local_day( $ts ) ] = true;
	}

	/**
	 * Publishes the "last aggregation" marker (health + spawn staleness).
	 *
	 * @param int $processed Events processed.
	 * @return void
	 */
	private static function touch_marker( $processed ) {
		@file_put_contents(
			Rasad_Plugin::storage_dir( 'buffer/.last_agg' ),
			wp_json_encode(
				array(
					'ts'        => time(),
					'processed' => (int) $processed,
				)
			),
			LOCK_EX
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Locking
	|--------------------------------------------------------------------------
	*/

	/**
	 * Acquires the run lock: MySQL GET_LOCK when possible + a local flock
	 * (the flock serializes file-buffer pops on a single host).
	 *
	 * @return bool False when another run holds the lock.
	 */
	private static function acquire() {
		if ( null !== self::$lock_fh || self::$lock_db ) {
			return false;
		}

		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$name = self::LOCK_NAME . '_' . substr( md5( (string) Rasad_Settings::site_host() ), 0, 20 );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0) AS l', $name ) );

			if ( '0' === (string) $result ) {
				// Held by another process.
				return false;
			}

			self::$lock_db = ( '1' === (string) $result );
		}

		Rasad_Plugin::ensure_storage();

		$fh = @fopen( Rasad_Plugin::storage_dir( 'buffer/.agg.lock' ), 'c' );

		if ( false === $fh || ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			if ( false !== $fh ) {
				fclose( $fh );
			}
			self::release_db_lock();

			return false;
		}

		self::$lock_fh = $fh;

		return true;
	}

	/**
	 * Releases whatever locks the run holds.
	 *
	 * @return void
	 */
	private static function release() {
		if ( null !== self::$lock_fh ) {
			flock( self::$lock_fh, LOCK_UN );
			fclose( self::$lock_fh );
			self::$lock_fh = null;
		}

		self::release_db_lock();
	}

	/**
	 * Releases the MySQL named lock (when it was granted).
	 *
	 * @return void
	 */
	private static function release_db_lock() {
		if ( ! self::$lock_db ) {
			return;
		}

		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$name = self::LOCK_NAME . '_' . substr( md5( (string) Rasad_Settings::site_host() ), 0, 20 );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s) AS l', $name ) );
		}

		self::$lock_db = false;
	}
}
