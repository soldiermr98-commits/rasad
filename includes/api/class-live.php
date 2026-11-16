<?php
/**
 * Live panel endpoint (Phase 5): who is online right now + a short
 * real-time feed of the latest hits.
 *
 * Data window: sessions with last_seen within the last 5 minutes
 * (indexed: sessions.KEY last_seen) and hits within the same window
 * (indexed: hits.KEY ts). No caching by design — the admin client polls
 * every 15s, but ONLY while the live tab is open and the browser tab is
 * visible (Page Visibility API), with exponential backoff on errors.
 *
 * Privacy: visitor keys are 8-byte daily hashes — the raw IP/UA never
 * reach this response; countries come from the aggregator's GeoIP pass.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live "online now" REST handler.
 */
final class Rasad_Api_Live {

	/**
	 * Sessions considered online when last_seen is this fresh (seconds).
	 *
	 * @var int
	 */
	const WINDOW = 300;

	/**
	 * Maximum online rows returned.
	 *
	 * @var int
	 */
	const ONLINE_LIMIT = 100;

	/**
	 * Feed rows returned.
	 *
	 * @var int
	 */
	const FEED_LIMIT = 20;

	/**
	 * GET /live — capability-gated by the route registration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		global $wpdb;

		$now    = time();
		$since  = $now - self::WINDOW;
		$sess   = Rasad_Installer::table( 'sessions' );
		$pages  = Rasad_Installer::table( 'pages' );
		$hits   = Rasad_Installer::table( 'hits' );
		$refs   = Rasad_Installer::table( 'referrers' );
		$labels = Rasad_Collect_Ua_Parser::labels();

		// 1) Online sessions + the page each visitor is currently on.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$online_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.visitor_key, s.last_seen, s.pageviews, s.duration, s.country, s.device, s.browser, s.os, s.referrer_id, s.exit_page, p.path"
				. " FROM {$sess} s LEFT JOIN {$pages} p ON p.id = s.exit_page"
				. ' WHERE s.last_seen >= %d ORDER BY s.last_seen DESC LIMIT ' . self::ONLINE_LIMIT,
				$since
			)
		);

		// 2) Recent hits feed with page + visitor context.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$feed_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.ts, s.visitor_key, s.country, s.device, s.referrer_id, p.path"
				. " FROM {$hits} h INNER JOIN {$sess} s ON s.id = h.session_id"
				. " INNER JOIN {$pages} p ON p.id = h.page_id"
				. ' WHERE h.ts >= %d ORDER BY h.id DESC LIMIT ' . self::FEED_LIMIT,
				$since
			)
		);

		// 3b) Unprocessed buffer tail: hits within seconds that have not been
		//     aggregated yet (file/APCu/Redis/DB drivers all support peek_batch).
		$tail = self::buffer_tail( $since );

		// 3) Referrer labels for all referenced ids (one small query).
		$ref_ids = array();

		foreach ( array_merge( $online_rows, $feed_rows ) as $row ) {
			$rid = isset( $row->referrer_id ) ? (int) $row->referrer_id : 0;

			if ( $rid > 0 ) {
				$ref_ids[ $rid ] = $rid;
			}
		}

		$ref_map = self::referrer_labels( $ref_ids, $refs );

		$visitors = array();
		$online_vks = array();

		foreach ( $online_rows as $row ) {
			$vk = bin2hex( (string) $row->visitor_key );
			$online_vks[ $vk ] = true;
			$visitors[] = array(
				'id'         => $vk,
				'last_seen'  => (int) $row->last_seen,
				'page'       => isset( $row->path ) && null !== $row->path ? (string) $row->path : '',
				'country'    => (string) $row->country,
				'device'     => self::label( $labels['device'], $row->device ),
				'browser'    => self::label( $labels['browser'], $row->browser ),
				'os'         => self::label( $labels['os'], $row->os ),
				'pageviews'  => (int) $row->pageviews,
				'duration'   => (int) $row->duration,
				'referrer'   => isset( $ref_map[ (int) $row->referrer_id ] ) ? $ref_map[ (int) $row->referrer_id ] : '',
			);
		}

		// Append visitors that only exist in the unprocessed buffer tail.
		foreach ( $tail as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$vk = isset( $hit['vk'] ) ? strtolower( (string) $hit['vk'] ) : '';
			if ( '' === $vk || ! preg_match( '/^[0-9a-f]{16}$/', $vk ) ) {
				continue;
			}
			if ( isset( $online_vks[ $vk ] ) ) {
				continue;
			}
			$online_vks[ $vk ] = true;
			$visitors[] = array(
				'id'         => $vk,
				'last_seen'  => isset( $hit['ts'] ) ? (int) $hit['ts'] : $now,
				'page'       => isset( $hit['p'] ) ? (string) $hit['p'] : '',
				'country'    => '',
				'device'     => '',
				'browser'    => '',
				'os'         => '',
				'pageviews'  => 1,
				'duration'   => 0,
				'referrer'   => '',
			);
		}

		$feed = array();

		// Feed: aggregated hits first, then unprocessed buffer tail (newest first).
		$feed_items = array();

		foreach ( $feed_rows as $row ) {
			$feed_items[] = array(
				'ts'       => (int) $row->ts,
				'time'     => wp_date( 'H:i:s', (int) $row->ts ),
				'page'     => (string) $row->path,
				'id'       => bin2hex( (string) $row->visitor_key ),
				'country'  => (string) $row->country,
				'device'   => self::label( $labels['device'], $row->device ),
				'referrer' => isset( $ref_map[ (int) $row->referrer_id ] ) ? $ref_map[ (int) $row->referrer_id ] : '',
			);
		}

		foreach ( $tail as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$ts = isset( $hit['ts'] ) ? (int) $hit['ts'] : 0;
			if ( $ts < $since ) {
				continue;
			}
			$feed_items[] = array(
				'ts'       => $ts,
				'time'     => wp_date( 'H:i:s', $ts ),
				'page'     => isset( $hit['p'] ) ? (string) $hit['p'] : '',
				'id'       => isset( $hit['vk'] ) ? bin2hex( strtolower( (string) $hit['vk'] ) ) : '',
				'country'  => '',
				'device'   => '',
				'referrer' => '',
			);
		}

		usort( $feed_items, function ( $a, $b ) {
			return $b['ts'] - $a['ts'];
		});

		$feed = array();
		foreach ( array_slice( $feed_items, 0, self::FEED_LIMIT ) as $item ) {
			unset( $item['ts'] );
			$feed[] = $item;
		}

		return new WP_REST_Response(
			array(
				'online'   => count( $visitors ),
				'visitors' => $visitors,
				'feed'     => $feed,
				'now'      => wp_date( 'H:i:s', $now ),
				'window'   => self::WINDOW,
			),
			200
		);
	}

	/**
	 * Resolves referrer ids to display labels (Persian type words as
	 * fallback when a source has no friendly name).
	 *
	 * @param array<int,int> $ids          Referrer row ids (>0).
	 * @param string         $refs_table   Referrers table name.
	 * @return array<int,string> id => label.
	 */
	private static function referrer_labels( $ids, $refs_table ) {
		global $wpdb;

		$map = array();

		if ( empty( $ids ) ) {
			return $map;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, domain, type, name FROM {$refs_table} WHERE id IN ({$placeholders})",
				array_values( $ids )
			)
		);

		$types = array(
			Rasad_Process_Referrer::TYPE_DIRECT   => __( 'مستقیم', 'rasad' ),
			Rasad_Process_Referrer::TYPE_SEARCH   => __( 'جستجو', 'rasad' ),
			Rasad_Process_Referrer::TYPE_SOCIAL   => __( 'شبکهٔ اجتماعی', 'rasad' ),
			Rasad_Process_Referrer::TYPE_OTHER    => __( 'سایر', 'rasad' ),
			Rasad_Process_Referrer::TYPE_CAMPAIGN => __( 'کمپین', 'rasad' ),
		);

		foreach ( $rows as $row ) {
			$rid  = (int) $row->id;
			$name = isset( $row->name ) ? trim( (string) $row->name ) : '';
			$type = isset( $row->type ) ? (int) $row->type : 4;

			if ( '' !== $name ) {
				$map[ $rid ] = $name;
			} elseif ( isset( $types[ $type ] ) && 1 !== $type && '' !== $row->domain ) {
				$map[ $rid ] = (string) $row->domain;
			} elseif ( isset( $types[ $type ] ) ) {
				$map[ $rid ] = $types[ $type ];
			} else {
				$map[ $rid ] = (string) $row->domain;
			}
		}

		return $map;
	}

	/**
	 * Label lookup with graceful fallback.
	 *
	 * @param array<int,string> $labels Code => label map.
	 * @param mixed             $code   Stored numeric code.
	 * @return string
	 */
	private static function label( $labels, $code ) {
		$code = (int) $code;

		if ( $code > 0 && isset( $labels[ $code ] ) ) {
			return (string) $labels[ $code ];
		}

		return '';
	}

	/**
	 * Returns unprocessed buffer hits within the given time window, newest
	 * first, without removing them from the buffer. Used by /live so "online
	 * now" reflects hits within seconds of collection.
	 *
	 * @param int $since Unix timestamp; only hits with ts >= $since are returned.
	 * @return array<int,array<string,mixed>> Decoded hits.
	 */
	private static function buffer_tail( $since ) {
		$buffer = Rasad_Plugin::buffer();

		if ( ! is_object( $buffer ) || ! method_exists( $buffer, 'peek_batch' ) ) {
			return array();
		}

		$tail = array();

		foreach ( $buffer->peek_batch( 500 ) as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}

			$ts = isset( $hit['ts'] ) ? (int) $hit['ts'] : 0;

			if ( $ts < $since ) {
				continue;
			}

			$tail[] = $hit;
		}

		return $tail;
	}
}
