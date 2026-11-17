<?php
/**
 * Cleaner: retention purge (raw tables), buffer size cap, occasional
 * OPTIMIZE. Runs throttled on the every-minute cron hook — at most one
 * purge per day and one OPTIMIZE per week.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention and maintenance routines.
 */
final class Rasad_Process_Cleaner {

	/** Hard cap for one buffer file (bytes); oldest lines are trimmed first. */
	const MAX_BUFFER_BYTES = 8388608;

	/** Minimum seconds between retention purges. */
	const PURGE_INTERVAL = 86400;

	/** Minimum seconds between OPTIMIZE TABLE runs. */
	const OPTIMIZE_INTERVAL = 604800;

	/** DELETE ... LIMIT chunk size (keeps locks short on shared hosts). */
	const DELETE_CHUNK = 20000;

	/** Safety valve for the purge loop (20k x 100 = 2M rows per run). */
	const DELETE_MAX_ITERATIONS = 100;

	/**
	 * Registers the throttled cleaner on the aggregation cron hook.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( RASAD_CRON_HOOK, array( __CLASS__, 'maybe_run' ), 20 );
	}

	/**
	 * Cron callback: runs at most once per PURGE_INTERVAL.
	 *
	 * @return array<string,mixed>|false Stats, or false when throttled.
	 */
	public static function maybe_run() {
		$marker = Rasad_Plugin::storage_dir( 'buffer/.last_purge' );
		$mtime  = @filemtime( $marker );

		if ( false !== $mtime && ( time() - $mtime ) < self::PURGE_INTERVAL ) {
			return false;
		}

		return self::run();
	}

	/**
	 * Full cleanup pass (cron, CLI `wp rasad clean`, REST cron route).
	 *
	 * @return array<string,mixed>
	 */
	public static function run() {
		global $wpdb;

		$stats = array(
			'hits'     => 0,
			'sessions' => 0,
			'events'   => 0,
			'trimmed'  => 0,
			'optimized'=> 0,
		);

		$retention = (int) Rasad_Settings::get( 'retention_days' );
		$retention = max( 1, min( 3650, $retention ? $retention : 30 ) );
		$cutoff    = time() - ( $retention * 86400 );

		$stats['hits']     = self::purge( Rasad_Installer::table( 'hits' ), 'ts', $cutoff );
		$stats['sessions'] = self::purge( Rasad_Installer::table( 'sessions' ), 'last_seen', $cutoff );

		// Events are keyed by calendar day (site timezone).
		$day_cutoff = Rasad_Process_Aggregator::local_day( $cutoff );
		$stats['events']   = self::purge( Rasad_Installer::table( 'events' ), 'day', $day_cutoff );

		// Compact only fully consumed queue files; never discard pending hits.
		$buffer = Rasad_Plugin::buffer();
		$stats['trimmed'] = method_exists( $buffer, 'compact' ) ? (int) $buffer->compact() : 0;

		$stats['optimized'] = self::maybe_optimize() ? 1 : 0;

		@file_put_contents(
			Rasad_Plugin::storage_dir( 'buffer/.last_purge' ),
			wp_json_encode(
				array(
					'ts'     => time(),
					'stats'  => $stats,
				)
			),
			LOCK_EX
		);

		/** Fires after a cleaner pass (health panel). */
		do_action( 'rasad_cleaned', $stats );

		return $stats;
	}

	/**
	 * Chunked DELETE of rows older than a cutoff value.
	 *
	 * @param string   $table  Full table name.
	 * @param string   $column Age column (`ts` INT, `last_seen` INT, `day` DATE).
	 * @param int|string $cutoff Exclusive upper bound.
	 * @return int Deleted rows.
	 */
	private static function purge( $table, $column, $cutoff ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$deleted = 0;
		$iter    = 0;

		while ( $iter++ < self::DELETE_MAX_ITERATIONS ) {
			$is_date = ( 'day' === $column );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$sql = $wpdb->prepare(
				"DELETE FROM {$table} WHERE {$column} " . ( $is_date ? '< %s' : '< %d' ) . ' LIMIT ' . self::DELETE_CHUNK,
				$is_date ? (string) $cutoff : (int) $cutoff
			);

			$affected = $wpdb->query( $sql );
			$affected = (int) $affected;

			if ( $affected <= 0 ) {
				break;
			}

			$deleted += $affected;

			if ( $affected < self::DELETE_CHUNK ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Weekly OPTIMIZE of the two write-heavy tables (space reclamation
	 * after retention purges). Disabled through `rasad_optimize_tables`.
	 *
	 * @return bool True when OPTIMIZE ran.
	 */
	private static function maybe_optimize() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		/**
		 * Filters whether weekly OPTIMIZE TABLE may run.
		 *
		 * @param bool $optimize Default false (explicit maintenance opt-in).
		 */
		if ( ! apply_filters( 'rasad_optimize_tables', false ) ) {
			return false;
		}

		$marker = Rasad_Plugin::storage_dir( 'buffer/.last_opt' );
		$mtime  = @filemtime( $marker );

		if ( false !== $mtime && ( time() - $mtime ) < self::OPTIMIZE_INTERVAL ) {
			return false;
		}

		$hits     = Rasad_Installer::table( 'hits' );
		$sessions = Rasad_Installer::table( 'sessions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from wpdb.
		$wpdb->query( "OPTIMIZE TABLE `{$hits}`, `{$sessions}`" );

		@file_put_contents( $marker, (string) time(), LOCK_EX );

		return true;
	}
}
