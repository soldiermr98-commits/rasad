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
					'woo' => class_exists( 'WooCommerce' ),
					'weekStart' => 'fa_IR' === get_locale() ? 6 : (int) get_option( 'start_of_week', 0 ),
					'defaults' => array(
						'from'  => self::default_from(),
						'to'    => Rasad_Process_Aggregator::local_day( time() ),
						'jalali'=> (string) Rasad_Settings::get( 'calendar' ) === 'jalali',
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

		return ( new DateTimeImmutable( $today, wp_timezone() ) )->modify( '-6 days' )->format( 'Y-m-d' );
	}

	/**
	 * Builds a nonce-protected CSV export URL for the current dashboard range.
	 *
	 * @return string
	 */
	public static function csv_url() {
		$url = add_query_arg(
			array(
				'action' => 'rasad_csv',
				),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'rasad_csv' );
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
			'tab_stats'   => __( 'آمار', 'rasad' ),
			'tab_live'    => __( 'زنده', 'rasad' ),
			'online'      => __( 'بازدیدکنندهٔ آنلاین', 'rasad' ),
			'live_hint'   => __( 'به‌روزرسانی خودکار هر ۱۵ ثانیه', 'rasad' ),
			'live_feed'   => __( 'آخرین رویدادها', 'rasad' ),
			'visitor'     => __( 'بازدیدکننده', 'rasad' ),
			'page_col'    => __( 'صفحه', 'rasad' ),
			'country'     => __( 'کشور', 'rasad' ),
			'device_col'  => __( 'دستگاه', 'rasad' ),
			'referrer_col'=> __( 'ارجاع', 'rasad' ),
			'time_col'    => __( 'زمان', 'rasad' ),
			'jalali'    => __( 'شمسی', 'rasad' ),
			'invalid_date' => __( 'تاریخ معتبر وارد کنید؛ ابتدا باید پیش از انتها و بازه حداکثر ۴۰۰ روز باشد.', 'rasad' ),
			'previous_month' => __( 'ماه قبل', 'rasad' ),
			'next_month' => __( 'ماه بعد', 'rasad' ),
			'pick_date' => __( 'انتخاب تاریخ', 'rasad' ),
			'gregorian' => __( 'میلادی', 'rasad' ),
		);
	}

	/**
	 * Dashboard markup shell (data arrives through REST).
	 * New local design: no CDN, system fonts, RTL-first, SVG chart.
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'rasad' ) );
		}
		$reports = array(
			'pages' => __( 'صفحات پربازدید', 'rasad' ), 'entries' => __( 'صفحات ورود', 'rasad' ),
			'exits' => __( 'صفحات خروج', 'rasad' ), 'posts' => __( 'نوشته‌های برتر', 'rasad' ),
			'post_types' => __( 'نوع نوشته', 'rasad' ), 'referrers' => __( 'منابع ورودی', 'rasad' ),
			'search_engines' => __( 'موتورهای جستجو', 'rasad' ), 'social_networks' => __( 'شبکه‌های اجتماعی', 'rasad' ),
			'campaigns' => __( 'کمپین‌ها', 'rasad' ), 'terms' => __( 'جستجوی داخلی سایت', 'rasad' ),
			'countries' => __( 'کشورها', 'rasad' ), 'cities' => __( 'شهرها', 'rasad' ),
			'devices' => __( 'دستگاه‌ها', 'rasad' ), 'browsers' => __( 'مرورگرها', 'rasad' ), 'os' => __( 'سیستم‌عامل‌ها', 'rasad' ),
		);
		?>
		<div class="wrap rasad-dash" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
		<header class="rasad-header"><h1><?php esc_html_e( 'رصد — آمار بازدید', 'rasad' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Rasad_Settings::PAGE ) ); ?>"><?php esc_html_e( 'تنظیمات', 'rasad' ); ?></a></header>
		<main class="rasad-main">
		<div class="rasad-tabs" role="tablist">
		<button type="button" class="rasad-tab is-active" role="tab" aria-selected="true" data-rasad-tab="stats"><?php esc_html_e( 'آمار', 'rasad' ); ?></button>
		<button type="button" class="rasad-tab" role="tab" aria-selected="false" data-rasad-tab="live"><?php esc_html_e( 'زنده', 'rasad' ); ?></button></div>
		<section data-rasad-panel="stats">
		<div class="rasad-toolbar"><div class="rasad-ranges">
		<?php foreach ( array( 'today' => __( 'امروز', 'rasad' ), 'yesterday' => __( 'دیروز', 'rasad' ), '7d' => __( '۷ روز', 'rasad' ), '30d' => __( '۳۰ روز', 'rasad' ), '90d' => __( '۹۰ روز', 'rasad' ), 'custom' => __( 'دلخواه', 'rasad' ) ) as $key => $label ) : ?>
		<button type="button" class="rasad-range <?php echo '7d' === $key ? 'is-active' : ''; ?>" data-range="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?></div>
		<form class="rasad-custom-range">
		<label><?php esc_html_e( 'از', 'rasad' ); ?> <input type="text" class="rasad-from" name="from" dir="ltr" autocomplete="off" required /></label>
		<label><?php esc_html_e( 'تا', 'rasad' ); ?> <input type="text" class="rasad-to" name="to" dir="ltr" autocomplete="off" required /></label>
		<button type="submit" class="rasad-btn rasad-btn-ghost"><?php esc_html_e( 'اعمال', 'rasad' ); ?></button>
		<a class="rasad-export rasad-btn rasad-btn-ghost" href="<?php echo esc_url( self::csv_url() ); ?>"><?php esc_html_e( 'خروجی CSV', 'rasad' ); ?></a>
		<select class="rasad-export-report" aria-label="<?php esc_attr_e( 'گزارش خروجی', 'rasad' ); ?>">
		<?php foreach ( array( 'daily' => __( 'روزانه', 'rasad' ), 'pages' => __( 'صفحات', 'rasad' ), 'dimensions' => __( 'ابعاد', 'rasad' ) ) as $key => $label ) : ?>
		<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
		<?php if ( class_exists( 'WooCommerce' ) ) : ?><option value="woo"><?php esc_html_e( 'فروشگاه', 'rasad' ); ?></option><?php endif; ?></select>
		</form><p class="rasad-date-hint"></p><p class="rasad-status" role="status" aria-live="polite"></p></div>
		<section class="rasad-cards">
		<?php foreach ( array( 'views', 'visitors', 'sessions', 'bounce_rate', 'avg_duration' ) as $metric ) : ?>
		<div class="rasad-card" data-metric="<?php echo esc_attr( $metric ); ?>"><span class="rasad-card-label"><?php $strings = self::strings(); echo esc_html( $strings[ $metric ] ); ?></span><strong class="rasad-card-value">—</strong><span class="rasad-card-delta"></span></div>
		<?php endforeach; ?></section>
		<section class="rasad-panel"><h2><?php esc_html_e( 'روند بازدیدها و بازدیدکنندگان', 'rasad' ); ?></h2><div class="rasad-chart"></div></section>
		<div class="rasad-ref-groups"></div><section class="rasad-panels">
		<?php foreach ( $reports as $key => $label ) { self::report_table( $key, $label ); } ?>
		</section>
		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
		<section class="rasad-woo"><h2><?php esc_html_e( 'گزارش فروشگاه', 'rasad' ); ?></h2><p><?php esc_html_e( 'ارزها جدا هستند. درآمد کل و ارجاع شامل مالیات و ارسال است؛ درآمد محصول پس از تخفیف و بدون مالیات و ارسال است.', 'rasad' ); ?></p>
		<?php foreach ( array( 'woo_daily' => __( 'فروش روزانه', 'rasad' ), 'woo_products' => __( 'محصولات', 'rasad' ), 'woo_referrers' => __( 'ارجاع فروش', 'rasad' ) ) as $key => $label ) { self::report_table( $key, $label, true ); } ?></section>
		<?php endif; ?></section>
		<section data-rasad-panel="live" hidden><div class="rasad-live-head"><strong class="rasad-live-count">—</strong> <?php esc_html_e( 'بازدیدکنندهٔ آنلاین', 'rasad' ); ?></div><p class="rasad-live-error" role="status"></p>
		<?php self::report_table( 'live', __( 'بازدیدکنندگان زنده', 'rasad' ), false, array( __( 'بازدیدکننده', 'rasad' ), __( 'صفحه', 'rasad' ), __( 'کشور / شهر', 'rasad' ), __( 'دستگاه', 'rasad' ), __( 'ارجاع', 'rasad' ), __( 'بازدید', 'rasad' ) ) );
		self::report_table( 'feed', __( 'آخرین رویدادها', 'rasad' ), false, array( __( 'زمان', 'rasad' ), __( 'صفحه', 'rasad' ), __( 'بازدیدکننده', 'rasad' ), __( 'کشور', 'rasad' ), __( 'ارجاع', 'rasad' ) ) ); ?>
		</section><noscript><?php esc_html_e( 'برای نمایش داشبورد به جاوااسکریپت نیاز است.', 'rasad' ); ?></noscript></main></div>
		<?php
	}

	/** Renders a semantic, empty report table populated by REST. */
	private static function report_table( $key, $label, $woo = false, $headers = array() ) {
		if ( empty( $headers ) ) {
			$headers = $woo ? array( __( 'عنوان', 'rasad' ), __( 'ارز', 'rasad' ), __( 'بازدید محصول', 'rasad' ), __( 'افزودن به سبد', 'rasad' ), __( 'تسویه', 'rasad' ), __( 'سفارش', 'rasad' ), __( 'واحد ردیابی‌شده (سبد + خرید)', 'rasad' ), __( 'درآمد', 'rasad' ) ) : array( __( 'عنوان', 'rasad' ), __( 'بازدید / تعداد', 'rasad' ), __( 'بازدیدکنندگان روزانه', 'rasad' ) );
		}
		echo '<section class="rasad-panel"><h3 class="rasad-panel-title">' . esc_html( $label ) . '</h3><div class="rasad-table-wrap"><table class="rasad-table" data-table="' . esc_attr( $key ) . '"><thead><tr>';
		foreach ( $headers as $header ) { echo '<th scope="col">' . esc_html( $header ) . '</th>'; }
		echo '</tr></thead><tbody></tbody></table></div></section>';
	}
}
