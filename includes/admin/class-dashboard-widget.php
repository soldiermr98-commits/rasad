<?php
/**
 * WordPress admin dashboard widget: mini sparkline + online count.
 *
 * Shown on the main WP dashboard (not only Rasad's own pages).
 * Data comes from the cached REST endpoint.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rasad dashboard widget.
 */
final class Rasad_Admin_Dashboard_Widget {

	/** Widget id / slug. */
	const ID = 'rasad_dashboard_widget';

	/** Cache key for today's online count (seconds). */
	const ONLINE_CACHE = 'rasad_widget_online';

	/** How long to consider a session "online" (seconds). */
	const ONLINE_SECONDS = 300;

	/**
	 * Registers the widget and its asset enqueue.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_head', array( __CLASS__, 'inline_style' ) );

		// Lightweight AJAX handler for the online count (no full dashboard load).
		add_action( 'wp_ajax_rasad_widget_online', array( __CLASS__, 'ajax_online' ) );
	}

	/**
	 * Adds the widget to the WP dashboard.
	 *
	 * @return void
	 */
	public static function add_widget() {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::ID,
			__( 'رصد — آمار بازدید', 'rasad' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enqueues a tiny script for the online count poll (widget page only).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'rasad-widget',
			RASAD_URL . 'assets/admin.js',
			array(),
			RASAD_VERSION,
			true
		);

		wp_add_inline_script(
			'rasad-widget',
			'window.rasadWidget=' . wp_json_encode(
				array(
					'rest'  => rest_url( Rasad_Api_Rest::NS ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'ttl'   => self::ONLINE_SECONDS * 1000,
				)
			) . ';',
			'after'
		);
	}

	/**
	 * Inline CSS for the widget (kept minimal, no external assets).
	 *
	 * @return void
	 */
	public static function inline_style() {
		if ( 'index.php' !== $GLOBALS['pagenow'] ) {
			return;
		}

		?>
		<style>
		#rasad_dashboard_widget {
			font-family: Vazirmatn, Tahoma, sans-serif;
			direction: rtl;
		}
		#rasad_dashboard_widget .rasad-widget-inner {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 12px 16px;
			background: #f1f5f9;
			border: 1px solid #e2e8f0;
			border-radius: 8px;
		}
		#rasad_dashboard_widget .rasad-widget-stat {
			text-align: right;
		}
		#rasad_dashboard_widget .rasad-widget-label {
			font-size: 12px;
			color: #475569;
			display: block;
		}
		#rasad_dashboard_widget .rasad-widget-value {
			font-size: 24px;
			font-weight: 700;
			color: #0f172a;
			line-height: 1;
		}
		#rasad_dashboard_widget .rasad-widget-spark {
			width: 90px;
			height: 28px;
			flex-shrink: 0;
		}
		#rasad_dashboard_widget .rasad-widget-loading,
		#rasad_dashboard_widget .rasad-widget-error {
			font-size: 12px;
			color: #64748b;
			font-style: italic;
			padding: 12px 16px;
		}
		</style>
		<?php
	}

	/**
	 * Renders the widget markup.
	 *
	 * @return void
	 */
	public static function render() {
		$views   = self::cached_overview( 'views' );
		$online  = self::cached_online();
		$today   = Rasad_Process_Aggregator::local_day( time() );
		$spark   = self::sparkline_svg( $today );
		$loading = __( 'بارگذاری…', 'rasad' );
		?>
		<div id="rasad_dashboard_widget">
			<div class="rasad-widget-inner">
				<div class="rasad-widget-stat">
					<span class="rasad-widget-label"><?php esc_html_e( 'بازدید امروز', 'rasad' ); ?></span>
					<span class="rasad-widget-value" id="rasad-widget-views"><?php echo esc_html( $views ); ?></span>
				</div>
				<div class="rasad-widget-stat" style="margin-left:16px;">
					<span class="rasad-widget-label"><?php esc_html_e( 'آنلاین (۵ دق)', 'rasad' ); ?></span>
					<span class="rasad-widget-value" id="rasad-widget-online"><?php echo esc_html( $online ); ?></span>
				</div>
				<svg class="rasad-widget-spark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 28" aria-hidden="true">
					<?php echo $spark; ?>
				</svg>
			</div>
			<p class="rasad-widget-loading" id="rasad-widget-status"><?php echo esc_html( $loading ); ?></p>
		</div>

		<script id="rasad-widget-js" type="text/javascript">
		(function(){
			var cfg = window.rasadWidget || {};
			var viewsEl = document.getElementById('rasad-widget-views');
			var onlineEl = document.getElementById('rasad-widget-online');
			var statusEl = document.getElementById('rasad-widget-status');
			var today = '<?php echo esc_js( $today ); ?>';
			var fetched = false;

			function fetchOverview() {
				if (fetched) return;
				fetched = true;
				var url = new URL(cfg.rest + '/overview', window.location.origin);
				url.searchParams.set('from', today);
				url.searchParams.set('to', today);
				fetch(url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {'X-WP-Nonce': cfg.nonce || ''},
					credentials: 'include'
				}).then(function(r){ return r.json(); }).then(function(data){
					var v = data && data.current && data.current.views !== undefined ? data.current.views : 0;
					viewsEl.textContent = v.toLocaleString ? v.toLocaleString('fa-IR') : v;
					statusEl.textContent = '<?php esc_html_e( 'به‌روز', 'rasad' ); ?>';
				}).catch(function(){
					statusEl.textContent = '<?php esc_html_e( 'خطا در بارگذاری', 'rasad' ); ?>';
				});
			}

			function pollOnline() {
				var url = new URL(cfg.rest + '/live', window.location.origin);
				fetch(url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {'X-WP-Nonce': cfg.nonce || ''},
					credentials: 'include'
				}).then(function(r){ return r.json(); }).then(function(data){
					var n = 0;
					if (data && data.online !== undefined) n = data.online;
					else if (data && data.sessions !== undefined) n = data.sessions;
					onlineEl.textContent = n.toLocaleString ? n.toLocaleString('fa-IR') : n;
					statusEl.textContent = '<?php esc_html_e( 'به‌روز', 'rasad' ); ?>';
				}).catch(function(){
					// silent: widget stays usable.
				});
				setTimeout(pollOnline, cfg.ttl || 300000);
			}

			fetchOverview();
			pollOnline();
		})();
		</script>
		<?php
	}

	/**
	 * Returns today's view count from a short-lived transient.
	 *
	 * @param string $metric Metric key ('views').
	 * @return int
	 */
	private static function cached_overview( $metric ) {
		$key   = 'rasad_widget_' . $metric . '_' . Rasad_Process_Aggregator::local_day( time() );
		$value = get_transient( $key );

		if ( false !== $value ) {
			return (int) $value;
		}

		$value = self::fetch_overview_value( $metric );

		set_transient( $key, $value, 60 );

		return $value;
	}

	/**
	 * Queries the REST overview endpoint once for a single metric.
	 *
	 * @param string $metric Metric key.
	 * @return int
	 */
	private static function fetch_overview_value( $metric ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$today = Rasad_Process_Aggregator::local_day( time() );
		list( $start, $end ) = Rasad_Process_Aggregator::day_bounds( $today );

		$daily = Rasad_Installer::table( 'daily' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(views), 0) AS views FROM ' . $daily . ' WHERE day >= %s AND day <= %s',
				$today,
				$today
			)
		);

		if ( ! $row ) {
			return 0;
		}

		switch ( $metric ) {
			case 'views':
				return (int) $row->views;
			default:
				return 0;
		}
	}

	/**
	 * Returns the online count (sessions active in the last ONLINE_SECONDS).
	 *
	 * Result is cached for ONLINE_SECONDS so the widget poll does not hammer
	 * the DB on every page load.
	 *
	 * @return int
	 */
	private static function cached_online() {
		$now   = time();
		$key   = 'rasad_widget_online_' . floor( $now / self::ONLINE_SECONDS );
		$value = get_transient( $key );

		if ( false !== $value ) {
			return (int) $value;
		}

		$value = self::count_online();

		set_transient( $key, $value, self::ONLINE_SECONDS );

		return $value;
	}

	/**
	 * Counts sessions with last_seen within ONLINE_SECONDS.
	 *
	 * @return int
	 */
	private static function count_online() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$since = time() - self::ONLINE_SECONDS;
		$sessions = Rasad_Installer::table( 'sessions' );

		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $sessions . ' WHERE last_seen >= %d',
				$since
			)
		);

		return (int) $row;
	}

	/**
	 * Builds a tiny inline sparkline SVG for today's hourly views.
	 *
	 * @param string $today 'Y-m-d'.
	 * @return string SVG path + area markup.
	 */
	private static function sparkline_svg( $today ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return '';
		}

		$hourly = Rasad_Installer::table( 'hourly' );
		list( $start, $end ) = Rasad_Process_Aggregator::day_bounds( $today );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT hour_ts, views FROM ' . $hourly . ' WHERE hour_ts >= %d AND hour_ts < %d ORDER BY hour_ts',
				$start,
				$end
			)
		);

		$values = array();

		foreach ( (array) $rows as $row ) {
			$values[ (int) $row->hour_ts ] = (int) $row->views;
		}

		$points = array();
		$max    = 1;

		for ( $t = $start; $t < $end; $t += 3600 ) {
			$v   = isset( $values[ $t ] ) ? $values[ $t ] : 0;
			$max = max( $max, $v );
			$points[] = $v;
		}

		$n      = count( $points );
		$step   = 100 / max( 1, $n - 1 );
		$scale  = 24 / (float) $max;
		$d      = '';
		$area   = '';

		foreach ( $points as $i => $v ) {
			$x = (int) round( $i * $step );
			$y = 28 - (int) round( $v * $scale );

			if ( 0 === $i ) {
				$d .= 'M' . $x . ',' . $y;
				$area .= 'M' . $x . ',28 ';
			} else {
				$d .= ' L' . $x . ',' . $y;
				$area .= ' L' . $x . ',' . $y;
			}
		}

		$area .= ' L' . (int) round( ( $n - 1 ) * $step ) . ',28 Z';

		return '<path d="' . esc_attr( $d ) . '" fill="none" stroke="#00685f" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />'
			. '<path d="' . esc_attr( $area ) . '" fill="#00685f" fill-opacity="0.12" />';
	}

	/**
	 * AJAX handler for the online count (used by the inline poller).
	 *
	 * @return void
	 */
	public static function ajax_online() {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'rasad' ) ), 403 );
		}

		check_ajax_referer( 'wp_rest' );

		wp_send_json_success( array( 'online' => self::count_online() ) );
	}
}
