<?php
/**
 * Health check panel data: buffer driver, backlog, cron, DB tables,
 * detected problems with fix hints.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health check data provider.
 */
final class Rasad_Process_Health {

	/**
	 * Transient holding the last aggregation run timestamp.
	 *
	 * @var string
	 */
	const LAST_AGGREGATION = 'rasad_last_aggregation';

	/**
	 * Returns a structured health report for the settings screen.
	 *
	 * @return array<string,mixed> Report with keys:
	 *   buffer_driver, backlog, ip_source, trusted_proxies, trusted_hosts,
	 *   cron_next, last_aggregation, table_sizes, problems (array of
	 *   array('level'=>'ok|warning|error', 'msg'=>string)).
	 */
	public static function report() {
		$settings   = Rasad_Settings::all();
		$buffer     = Rasad_Plugin::buffer();
		$driver     = get_class( $buffer );
		$driver     = substr( $driver, strrpos( $driver, '_' ) + 1 );
		$backlog    = (int) $buffer->count();

		$report = array(
			'buffer_driver'  => ucfirst( $driver ),
			'backlog'        => $backlog,
			'ip_source'      => isset( $settings['ip_source'] ) ? $settings['ip_source'] : 'remote_addr',
			'trusted_proxies'=> isset( $settings['trusted_proxies'] ) && is_array( $settings['trusted_proxies'] )
				? $settings['trusted_proxies']
				: array(),
			'trusted_hosts'  => Rasad_Settings::trusted_hosts(),
			'cron_next'      => self::cron_next(),
			'last_aggregation'=> self::last_aggregation(),
			'table_sizes'    => self::table_sizes(),
			'problems'       => array(),
		);

		$problems = array();

		// Buffer driver availability.
		if ( ! $buffer->is_available() ) {
			$problems[] = array(
				'level' => 'error',
			'msg'   => __( 'درایور بافر در دسترس نیست — بازدیدها حذف می‌شوند.', 'rasad' ),
			);
		} elseif ( $backlog > 10000 ) {
			$problems[] = array(
				'level' => 'warning',
				'msg'   => sprintf(
					/* translators: %s: backlog count. */
					__( 'بافر پر است (%s رویداد در انتظار پردازش).', 'rasad' ),
					number_format_i18n( $backlog )
				),
			);
		}

		// Cron schedule.
		$cron_next = self::cron_next();
		if ( false === $cron_next ) {
			$problems[] = array(
				'level' => 'error',
				'msg'   => __( 'زمان‌بندی کرون فعال نیست — جمع‌آوری خودکار اجرا نمی‌شود.', 'rasad' ),
			);
		}

		// Stale aggregation.
		$last_agg = self::last_aggregation();
		if ( false === $last_agg || ( time() - $last_agg ) > 3600 ) {
			$problems[] = array(
				'level' => 'warning',
				'msg'   => __( 'آخرین جمع‌آوری بیش از یک ساعت پیش است.', 'rasad' ),
			);
		}

		// Storage writability.
		if ( ! Rasad_Plugin::ensure_storage() ) {
			$problems[] = array(
				'level' => 'error',
				'msg'   => __( 'پوشهٔ ذخیره‌سازی قابل نوشتن نیست.', 'rasad' ),
			);
		}

		$report['problems'] = $problems;

		return $report;
	}

	/**
	 * Next scheduled cron timestamp, or false when not scheduled.
	 *
	 * @return int|false
	 */
	public static function cron_next() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}

		$next = wp_next_scheduled( RASAD_CRON_HOOK );

		return false === $next ? false : (int) $next;
	}

	/**
	 * Last aggregation timestamp, or false when never run.
	 *
	 * @return int|false
	 */
	public static function last_aggregation() {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}

		$ts = get_transient( self::LAST_AGGREGATION );

		return false === $ts ? false : (int) $ts;
	}

	/**
	 * Marks the last aggregation time as now. Called by the aggregator.
	 *
	 * @return void
	 */
	public static function mark_aggregated() {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( self::LAST_AGGREGATION, time(), 0 );
	}

	/**
	 * DB table sizes in bytes (keyed by table short name).
	 *
	 * @return array<string,int>
	 */
	public static function table_sizes() {
		global $wpdb;

		$sizes = array();

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $sizes;
		}

		foreach ( Rasad_Installer::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from wpdb prefix.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT data_length + index_length AS size FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
					DB_NAME,
					$table
				)
			);

			if ( $row && is_numeric( $row->size ) ) {
				$sizes[ $table ] = (int) $row->size;
			} else {
				$sizes[ $table ] = 0;
			}
		}

		return $sizes;
	}
}
