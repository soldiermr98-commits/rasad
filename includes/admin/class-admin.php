<?php
/**
 * Admin dashboard (Phase 4 minimal): menu, assets, markup shell.
 *
 * All numbers are fetched client-side from the cached REST endpoints —
 * this class never queries summary tables itself. Styling lives in
 * assets/admin.css (semantic classes + CSS variables, RTL & dark-ready).
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu, dashboard screen and asset loading.
 */
final class Rasad_Admin {

	/**
	 * Builds the top-level menu: dashboard at the `rasad` slug (the slug
	 * decision from Phase 1) and Settings moved to its own submenu.
	 *
	 * @return void
	 */
	public static function admin_menu() {
		add_menu_page(
			__( 'رصد', 'rasad' ),
			__( 'رصد', 'rasad' ),
			Rasad_Settings::capability(),
			Rasad_Settings::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-bar',
			56
		);

		add_submenu_page(
			Rasad_Settings::PAGE_DASHBOARD,
			__( 'تنظیمات', 'rasad' ),
			__( 'تنظیمات', 'rasad' ),
			Rasad_Settings::capability(),
			Rasad_Settings::PAGE,
			array( 'Rasad_Settings', 'render_page' )
		);
	}

	/**
	 * Loads admin.css/admin.js — ONLY on Rasad's own screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		$own_pages = array(
			'toplevel_page_' . Rasad_Settings::PAGE_DASHBOARD,
			Rasad_Settings::PAGE_DASHBOARD . '_page_' . Rasad_Settings::PAGE,
		);

		if ( ! in_array( $hook_suffix, $own_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'rasad-admin',
			RASAD_URL . 'assets/admin.css',
			array(),
			RASAD_VERSION
		);

		wp_enqueue_script(
			'rasad-admin',
			RASAD_URL . 'assets/admin.js',
			array(),
			RASAD_VERSION,
			true
		);

		wp_add_inline_script(
			'rasad-admin',
			'window.rasadAdmin=' . wp_json_encode(
				array(
					'rest'     => rest_url( Rasad_Api_Rest::NS ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'page'     => Rasad_Settings::PAGE_DASHBOARD,
					'defaults' => array(
						'from' => self::default_from(),
						'to'   => Rasad_Process_Aggregator::local_day( time() ),
					),
					'strings'  => self::strings(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Default dashboard range: last 7 days (incl. today).
	 *
	 * @return string 'Y-m-d'
	 */
	private static function default_from() {
		$today = Rasad_Process_Aggregator::local_day( time() );
		list( $start ) = Rasad_Process_Aggregator::day_bounds( $today );

		return Rasad_Process_Aggregator::local_day( $start - ( 6 * 86400 ) );
	}

	/**
	 * Persian UI strings for the dashboard client.
	 *
	 * @return array<string,string>
	 */
	private static function strings() {
		return array(
			'views'       => __( 'بازدیدها', 'rasad' ),
			'visitors'    => __( 'بازدیدکنندگان', 'rasad' ),
			'sessions'    => __( 'نشست‌ها', 'rasad' ),
			'bounce_rate' => __( 'نرخ پرش', 'rasad' ),
			'avg_duration'=> __( 'میانگین مدت', 'rasad' ),
			'prev'        => __( 'نسبت به دورهٔ قبل', 'rasad' ),
			'range_today' => __( 'امروز', 'rasad' ),
			'range_yday'  => __( 'دیروز', 'rasad' ),
			'range_7'     => __( '۷ روز', 'rasad' ),
			'range_30'    => __( '۳۰ روز', 'rasad' ),
			'range_90'    => __( '۹۰ روز', 'rasad' ),
			'from'        => __( 'از', 'rasad' ),
			'to'          => __( 'تا', 'rasad' ),
			'apply'       => __( 'اعمال', 'rasad' ),
			'loading'     => __( 'در حال بارگذاری…', 'rasad' ),
			'error'       => __( 'خطا در دریافت داده؛ دوباره تلاش کنید.', 'rasad' ),
			'retry'       => __( 'تلاش دوباره', 'rasad' ),
			'top_pages'   => __( 'برترین صفحات', 'rasad' ),
			'entry_pages' => __( 'صفحات ورود', 'rasad' ),
			'exit_pages'  => __( 'صفحات خروج', 'rasad' ),
			'referrers'   => __( 'ارجاع‌دهندگان', 'rasad' ),
			'direct'      => __( 'مستقیم', 'rasad' ),
			'search'      => __( 'جستجو', 'rasad' ),
			'social'      => __( 'شبکهٔ اجتماعی', 'rasad' ),
			'other'       => __( 'سایر', 'rasad' ),
			'campaign'    => __( 'کمپین', 'rasad' ),
			'term'        => __( 'عبارت', 'rasad' ),
			'campaigns'   => __( 'کمپین‌ها', 'rasad' ),
			'search_terms'=> __( 'جستجوی داخلی سایت', 'rasad' ),
			'no_data'     => __( 'داده‌ای در این بازه نیست.', 'rasad' ),
			'export'      => __( 'خروجی CSV', 'rasad' ),
		);
	}

	/**
	 * Dashboard markup shell (data arrives through REST).
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'rasad' ) );
		}

		$today = Rasad_Process_Aggregator::local_day( time() );
		?>
		<div class="wrap rasad-dash" dir="rtl">
			<h1 class="rasad-title"><?php esc_html_e( 'رصد — آمار بازدید', 'rasad' ); ?></h1>

			<div class="rasad-toolbar">
				<div class="rasad-ranges" role="group" aria-label="<?php esc_attr_e( 'بازهٔ زمانی', 'rasad' ); ?>">
					<button type="button" class="rasad-range" data-range="today"><?php esc_html_e( 'امروز', 'rasad' ); ?></button>
					<button type="button" class="rasad-range" data-range="yesterday"><?php esc_html_e( 'دیروز', 'rasad' ); ?></button>
					<button type="button" class="rasad-range is-active" data-range="7d"><?php esc_html_e( '۷ روز', 'rasad' ); ?></button>
					<button type="button" class="rasad-range" data-range="30d"><?php esc_html_e( '۳۰ روز', 'rasad' ); ?></button>
					<button type="button" class="rasad-range" data-range="90d"><?php esc_html_e( '۹۰ روز', 'rasad' ); ?></button>
				</div>
				<form class="rasad-custom-range" method="get">
					<label><?php esc_html_e( 'از', 'rasad' ); ?> <input type="date" name="from" class="rasad-from" max="<?php echo esc_attr( $today ); ?>" /></label>
					<label><?php esc_html_e( 'تا', 'rasad' ); ?> <input type="date" name="to" class="rasad-to" max="<?php echo esc_attr( $today ); ?>" /></label>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'اعمال', 'rasad' ); ?></button>
				</form>
				<p class="rasad-status" role="status" aria-live="polite"></p>
			</div>

			<div class="rasad-cards">
				<div class="rasad-card" data-metric="views">
					<span class="rasad-card-label"><?php esc_html_e( 'بازدیدها', 'rasad' ); ?></span>
					<strong class="rasad-card-value">—</strong>
					<span class="rasad-card-delta"></span>
				</div>
				<div class="rasad-card" data-metric="visitors">
					<span class="rasad-card-label"><?php esc_html_e( 'بازدیدکنندگان', 'rasad' ); ?></span>
					<strong class="rasad-card-value">—</strong>
					<span class="rasad-card-delta"></span>
				</div>
				<div class="rasad-card" data-metric="sessions">
					<span class="rasad-card-label"><?php esc_html_e( 'نشست‌ها', 'rasad' ); ?></span>
					<strong class="rasad-card-value">—</strong>
					<span class="rasad-card-delta"></span>
				</div>
				<div class="rasad-card" data-metric="bounce_rate">
					<span class="rasad-card-label"><?php esc_html_e( 'نرخ پرش', 'rasad' ); ?></span>
					<strong class="rasad-card-value">—</strong>
					<span class="rasad-card-delta"></span>
				</div>
				<div class="rasad-card" data-metric="avg_duration">
					<span class="rasad-card-label"><?php esc_html_e( 'میانگین مدت', 'rasad' ); ?></span>
					<strong class="rasad-card-value">—</strong>
					<span class="rasad-card-delta"></span>
				</div>
			</div>

			<div class="rasad-panel rasad-chart-panel">
				<h2 class="rasad-panel-title"><?php esc_html_e( 'روند بازدید', 'rasad' ); ?></h2>
				<div class="rasad-chart" data-granularity=""></div>
			</div>

			<div class="rasad-panels">
				<div class="rasad-panel">
					<h2 class="rasad-panel-title"><?php esc_html_e( 'برترین صفحات', 'rasad' ); ?></h2>
					<table class="rasad-table" data-table="pages">
						<thead><tr><th><?php esc_html_e( 'صفحه', 'rasad' ); ?></th><th><?php esc_html_e( 'بازدید', 'rasad' ); ?></th><th><?php esc_html_e( 'بازدیدکننده', 'rasad' ); ?></th></tr></thead>
						<tbody></tbody>
					</table>
				</div>
				<div class="rasad-panel">
					<h2 class="rasad-panel-title"><?php esc_html_e( 'صفحات ورود و خروج', 'rasad' ); ?></h2>
					<table class="rasad-table" data-table="entries">
						<thead><tr><th><?php esc_html_e( 'ورود', 'rasad' ); ?></th><th><?php esc_html_e( 'تعداد', 'rasad' ); ?></th></tr></thead>
						<tbody></tbody>
					</table>
					<table class="rasad-table" data-table="exits">
						<thead><tr><th><?php esc_html_e( 'خروج', 'rasad' ); ?></th><th><?php esc_html_e( 'تعداد', 'rasad' ); ?></th></tr></thead>
						<tbody></tbody>
					</table>
				</div>
				<div class="rasad-panel">
					<h2 class="rasad-panel-title"><?php esc_html_e( 'ارجاع‌دهندگان', 'rasad' ); ?></h2>
					<div class="rasad-ref-groups"></div>
					<table class="rasad-table" data-table="referrers">
						<thead><tr><th><?php esc_html_e( 'منبع', 'rasad' ); ?></th><th><?php esc_html_e( 'بازدید', 'rasad' ); ?></th></tr></thead>
						<tbody></tbody>
					</table>
					<h3 class="rasad-panel-subtitle"><?php esc_html_e( 'جستجوی داخلی سایت', 'rasad' ); ?></h3>
					<table class="rasad-table" data-table="terms">
						<thead><tr><th><?php esc_html_e( 'عبارت', 'rasad' ); ?></th><th><?php esc_html_e( 'بازدید', 'rasad' ); ?></th></tr></thead>
						<tbody></tbody>
					</table>
				</div>
			</div>

			<noscript>
				<p class="rasad-noscript"><?php esc_html_e( 'برای نمایش داشبورد به جاوااسکریپت نیاز است.', 'rasad' ); ?></p>
			</noscript>
		</div>
		<?php
	}
}
