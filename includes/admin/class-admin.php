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

		return Rasad_Process_Aggregator::local_day( $start - ( 6 * 86400 ) );
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

		$today = Rasad_Process_Aggregator::local_day( time() );
		?>
		<div class="rasad-dash rasad-demo" dir="rtl">

			<!-- Top Navigation Bar -->
			<header class="rasad-header">
				<div class="rasad-header-start">
					<div class="rasad-logo">
						<span class="rasad-logo-text">رصد</span>
						<span class="rasad-live-dot" title="<?php esc_attr_e( 'سیستم فعال است', 'rasad' ); ?>" aria-hidden="true"></span>
					</div>
					<div class="rasad-live-badge">
						<span class="rasad-live-dot" aria-hidden="true"></span>
						<span><?php esc_html_e( 'کاربر آنلاین همین الان', 'rasad' ); ?></span>
					</div>
					<nav class="rasad-nav">
						<a class="rasad-nav-link is-active" href="#"><?php esc_html_e( 'پیشخوان', 'rasad' ); ?></a>
						<a class="rasad-nav-link" href="#"><?php esc_html_e( 'زنده', 'rasad' ); ?></a>
						<a class="rasad-nav-link" href="#"><?php esc_html_e( 'صفحات', 'rasad' ); ?></a>
						<a class="rasad-nav-link" href="#"><?php esc_html_e( 'منابع ورودی', 'rasad' ); ?></a>
						<a class="rasad-nav-link" href="#"><?php esc_html_e( 'مخاطبان', 'rasad' ); ?></a>
						<a class="rasad-nav-link" href="#"><?php esc_html_e( 'تنظیمات', 'rasad' ); ?></a>
					</nav>
				</div>
				<div class="rasad-header-end">
					<button class="rasad-btn rasad-btn-ghost">
						<span class="rasad-icon">compare_arrows</span><?php esc_html_e( 'مقایسه با دوره قبل', 'rasad' ); ?>
					</button>
					<button class="rasad-btn rasad-btn-primary">
						<span class="rasad-icon">download</span><?php esc_html_e( 'خروجی گزارش', 'rasad' ); ?>
					</button>
					<div class="rasad-utils">
						<button class="rasad-icon-btn" title="<?php esc_attr_e( 'تقویم شمسی', 'rasad' ); ?>"><span class="rasad-icon">calendar_today</span></button>
						<button class="rasad-icon-btn" title="<?php esc_attr_e( 'حالت تاریک', 'rasad' ); ?>"><span class="rasad-icon">dark_mode</span></button>
					</div>
				</div>
			</header>

			<main class="rasad-main">

				<!-- Secondary Toolbar -->
				<section class="rasad-toolbar">
					<div class="rasad-ranges" role="group" aria-label="<?php esc_attr_e( 'بازهٔ زمانی', 'rasad' ); ?>">
						<button type="button" class="rasad-range" data-range="today"><?php esc_html_e( 'امروز', 'rasad' ); ?></button>
						<button type="button" class="rasad-range" data-range="yesterday"><?php esc_html_e( 'دیروز', 'rasad' ); ?></button>
						<button type="button" class="rasad-range is-active" data-range="7d"><?php esc_html_e( '۷ روز', 'rasad' ); ?></button>
						<button type="button" class="rasad-range" data-range="30d"><?php esc_html_e( '۳۰ روز', 'rasad' ); ?></button>
						<button type="button" class="rasad-range" data-range="90d"><?php esc_html_e( '۹۰ روز', 'rasad' ); ?></button>
						<button type="button" class="rasad-range" data-range="custom"><?php esc_html_e( 'دلخواه', 'rasad' ); ?></button>
					</div>
					<div class="rasad-custom-range">
						<label><?php esc_html_e( 'از', 'rasad' ); ?> <input type="date" name="from" class="rasad-from" max="<?php echo esc_attr( $today ); ?>" /></label>
						<label><?php esc_html_e( 'تا', 'rasad' ); ?> <input type="date" name="to" class="rasad-to" max="<?php echo esc_attr( $today ); ?>" /></label>
						<button type="submit" class="rasad-btn rasad-btn-ghost rasad-calendar" title="<?php esc_attr_e( 'اعمال', 'rasad' ); ?>">
							<span class="rasad-icon">calendar_month</span><?php esc_html_e( 'اعمال', 'rasad' ); ?>
						</button>
						<a href="<?php echo esc_url( self::csv_url() ); ?>" class="rasad-btn rasad-btn-ghost"><?php esc_html_e( 'خروجی CSV', 'rasad' ); ?></a>
					</div>
					<p class="rasad-status" role="status" aria-live="polite"></p>
				</section>

				<!-- Stat Cards -->
				<section class="rasad-cards">
					<div class="rasad-card" data-metric="views">
						<span class="rasad-card-label"><?php esc_html_e( 'بازدیدها', 'rasad' ); ?></span>
						<div class="rasad-card-row">
							<strong class="rasad-card-value">—</strong>
							<span class="rasad-card-delta"></span>
						</div>
						<div class="rasad-spark"><svg viewBox="0 0 100 28" preserveAspectRatio="none"><path d="M0,22 Q15,26 30,14 T60,10 T85,16 T100,4 L100,28 L0,28 Z" fill="var(--rasad-accent)" opacity="0.22"/><path d="M0,22 Q15,26 30,14 T60,10 T85,16 T100,4" fill="none" stroke="var(--rasad-accent)" stroke-width="1.8" stroke-linecap="round"/></svg></div>
					</div>
					<div class="rasad-card" data-metric="visitors">
						<span class="rasad-card-label"><?php esc_html_e( 'بازدیدکنندگان یکتا', 'rasad' ); ?></span>
						<div class="rasad-card-row">
							<strong class="rasad-card-value">—</strong>
							<span class="rasad-card-delta"></span>
						</div>
						<div class="rasad-spark"><svg viewBox="0 0 100 28" preserveAspectRatio="none"><path d="M0,20 Q20,10 40,18 T75,8 T100,3 L100,28 L0,28 Z" fill="var(--rasad-accent)" opacity="0.22"/><path d="M0,20 Q20,10 40,18 T75,8 T100,3" fill="none" stroke="var(--rasad-accent)" stroke-width="1.8" stroke-linecap="round"/></svg></div>
					</div>
					<div class="rasad-card" data-metric="sessions">
						<span class="rasad-card-label"><?php esc_html_e( 'نشست‌ها', 'rasad' ); ?></span>
						<div class="rasad-card-row">
							<strong class="rasad-card-value">—</strong>
							<span class="rasad-card-delta"></span>
						</div>
						<div class="rasad-spark"><svg viewBox="0 0 100 28" preserveAspectRatio="none"><path d="M0,24 Q25,22 45,12 T80,14 T100,2 L100,28 L0,28 Z" fill="var(--rasad-accent)" opacity="0.22"/><path d="M0,24 Q25,22 45,12 T80,14 T100,2" fill="none" stroke="var(--rasad-accent)" stroke-width="1.8" stroke-linecap="round"/></svg></div>
					</div>
					<div class="rasad-card" data-metric="bounce_rate">
						<span class="rasad-card-label"><?php esc_html_e( 'نرخ پرش', 'rasad' ); ?></span>
						<div class="rasad-card-row">
							<strong class="rasad-card-value">—</strong>
							<span class="rasad-card-delta"></span>
						</div>
						<div class="rasad-spark"><svg viewBox="0 0 100 28" preserveAspectRatio="none"><path d="M0,8 Q30,12 55,20 T80,18 T100,24 L100,28 L0,28 Z" fill="var(--rasad-accent)" opacity="0.22"/><path d="M0,8 Q30,12 55,20 T80,18 T100,24" fill="none" stroke="var(--rasad-accent)" stroke-width="1.8" stroke-linecap="round"/></svg></div>
					</div>
					<div class="rasad-card" data-metric="avg_duration">
						<span class="rasad-card-label"><?php esc_html_e( 'میانگین زمان ماندگاری', 'rasad' ); ?></span>
						<div class="rasad-card-row">
							<strong class="rasad-card-value">—</strong>
							<span class="rasad-card-delta"></span>
						</div>
						<div class="rasad-spark"><svg viewBox="0 0 100 28" preserveAspectRatio="none"><path d="M0,18 Q20,24 45,15 T75,9 T100,4 L100,28 L0,28 Z" fill="var(--rasad-accent)" opacity="0.22"/><path d="M0,18 Q20,24 45,15 T75,9 T100,4" fill="none" stroke="var(--rasad-accent)" stroke-width="1.8" stroke-linecap="round"/></svg></div>
					</div>
				</section>

				<!-- Time Series Chart -->
				<section class="rasad-panel rasad-chart-section">
					<div class="rasad-chart-head">
						<div>
							<h2 class="rasad-chart-title"><?php esc_html_e( 'روند بازدید و کاربران یکتا', 'rasad' ); ?></h2>
							<p class="rasad-chart-sub"><?php esc_html_e( 'تفکیک روزانه ترافیک ورودی در مقایسه با هفته ماقبل', 'rasad' ); ?></p>
						</div>
						<div class="rasad-chart-ctrl">
							<div class="rasad-legend">
								<span class="rasad-legend-dot" style="background:var(--rasad-accent)"></span><span><?php esc_html_e( 'دوره فعلی', 'rasad' ); ?></span>
								<span class="rasad-legend-dot" style="background:var(--rasad-secondary);border-top:1px dashed var(--rasad-secondary)"></span><span><?php esc_html_e( 'دوره قبل', 'rasad' ); ?></span>
							</div>
							<div class="rasad-granularity">
								<button class="rasad-chip"><?php esc_html_e( 'ساعتی', 'rasad' ); ?></button>
								<button class="rasad-chip is-active"><?php esc_html_e( 'روزانه', 'rasad' ); ?></button>
							</div>
						</div>
					</div>
					<div class="rasad-chart-wrap">
						<div class="rasad-chart" data-granularity=""></div>
						<div class="rasad-tooltip" style="opacity:0;">
							<div class="rasad-tt-head"><span class="rasad-tt-date">—</span><span class="rasad-tt-dot"></span></div>
							<div class="rasad-tt-row"><span class="rasad-tt-dot" style="background:var(--rasad-accent)"></span><span><?php esc_html_e( 'بازدید فعلی:', 'rasad' ); ?></span><span class="rasad-tt-val">—</span></div>
							<div class="rasad-tt-row"><span class="rasad-tt-dot" style="background:var(--rasad-secondary)"></span><span><?php esc_html_e( 'دوره قبل:', 'rasad' ); ?></span><span class="rasad-tt-val">—</span></div>
							<div class="rasad-tt-foot"><span class="rasad-tt-diff">—</span><span class="rasad-icon">trending_up</span></div>
						</div>
						<div class="rasad-xaxis">
							<span>شنبه ۱۸ آبان</span><span>یکشنبه ۱۹ آبان</span><span>دوشنبه ۲۰ آبان</span><span class="is-active">سه‌شنبه ۲۱ آبان</span><span>چهارشنبه ۲۲ آبان</span><span>پنج‌شنبه ۲۳ آبان</span><span>جمعه ۲۴ آبان</span>
						</div>
					</div>
				</section>

				<!-- Breakdown Panels -->
				<section class="rasad-panels">

					<!-- Top Pages -->
					<div class="rasad-panel">
						<div class="rasad-panel-head">
							<div class="rasad-panel-icon">📄</div>
							<h3 class="rasad-panel-title"><?php esc_html_e( 'صفحات پربازدید', 'rasad' ); ?></h3>
							<a class="rasad-view-all" href="#"><?php esc_html_e( 'مشاهده همه', 'rasad' ); ?> <span class="rasad-icon">chevron_left</span></a>
						</div>
						<div class="rasad-list">
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:35%"></div><span class="rasad-list-path">/blog/seo-guide</span><span class="rasad-list-val">— <span class="rasad-list-pct">—</span></span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:26%"></div><span class="rasad-list-path">/shop/wireless-headphones</span><span class="rasad-list-val">— <span class="rasad-list-pct">—</span></span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:15%"></div><span class="rasad-list-path">/about-us</span><span class="rasad-list-val">— <span class="rasad-list-pct">—</span></span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:10%"></div><span class="rasad-list-path">/pricing</span><span class="rasad-list-val">— <span class="rasad-list-pct">—</span></span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:7%"></div><span class="rasad-list-path">/contact</span><span class="rasad-list-val">— <span class="rasad-list-pct">—</span></span></div>
						</div>
					</div>

					<!-- Referrers -->
					<div class="rasad-panel">
						<div class="rasad-panel-head">
							<div class="rasad-panel-icon">🔗</div>
							<h3 class="rasad-panel-title"><?php esc_html_e( 'منابع ورودی', 'rasad' ); ?></h3>
							<span class="rasad-panel-sub"><?php esc_html_e( 'بر اساس نشست', 'rasad' ); ?></span>
						</div>
						<div class="rasad-ref-groups"></div>
						<div class="rasad-list">
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:62%"></div><span class="rasad-list-avatar">G</span><span class="rasad-list-path"><?php esc_html_e( 'گوگل ارگانیک (Google)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:18%"></div><span class="rasad-list-icon">🔗</span><span class="rasad-list-path"><?php esc_html_e( 'مستقیم (Direct)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:9%"></div><span class="rasad-list-icon">✈️</span><span class="rasad-list-path"><?php esc_html_e( 'تلگرام (Telegram)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:6%"></div><span class="rasad-list-icon">📷</span><span class="rasad-list-path"><?php esc_html_e( 'اینستاگرام (Instagram)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:5%"></div><span class="rasad-list-avatar" style="background:#000;color:#fff">𝕏</span><span class="rasad-list-path"><?php esc_html_e( 'توییتر / ایکس (Twitter)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
						</div>
					</div>

					<!-- Geo -->
					<div class="rasad-panel">
						<div class="rasad-panel-head">
							<div class="rasad-panel-icon">🌍</div>
							<h3 class="rasad-panel-title"><?php esc_html_e( 'کشورها و شهرها', 'rasad' ); ?></h3>
							<span class="rasad-panel-sub"><?php esc_html_e( 'شهرهای پیشرو', 'rasad' ); ?></span>
						</div>
						<div class="rasad-list">
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:84%"></div><span class="rasad-list-flag">🇮🇷</span><span class="rasad-list-path"><?php esc_html_e( 'ایران (تهران، اصفهان، مشهد)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:6%"></div><span class="rasad-list-flag">🇩🇪</span><span class="rasad-list-path"><?php esc_html_e( 'آلمان (فرانکفورت)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:4%"></div><span class="rasad-list-flag">🇨🇦</span><span class="rasad-list-path"><?php esc_html_e( 'کانادا (تورنتو)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:3%"></div><span class="rasad-list-flag">🇦🇪</span><span class="rasad-list-path"><?php esc_html_e( 'امارات متحده (دبی)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:3%"></div><span class="rasad-list-flag">🇳🇱</span><span class="rasad-list-path"><?php esc_html_e( 'هلند (آمستردام)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
						</div>
					</div>

					<!-- Devices -->
					<div class="rasad-panel">
						<div class="rasad-panel-head">
							<div class="rasad-panel-icon">📱</div>
							<h3 class="rasad-panel-title"><?php esc_html_e( 'دستگاه‌های کاربران', 'rasad' ); ?></h3>
							<span class="rasad-panel-sub"><?php esc_html_e( 'تفکیک ابزار', 'rasad' ); ?></span>
						</div>
						<div class="rasad-seg">
							<div class="rasad-seg-bar" style="--w:68%;background:var(--rasad-accent-soft)" title="<?php esc_attr_e( 'موبایل: ۶۸٪', 'rasad' ); ?>"></div>
							<div class="rasad-seg-bar" style="--w:28%;background:var(--rasad-secondary-soft)" title="<?php esc_attr_e( 'دسکتاپ: ۲۸٪', 'rasad' ); ?>"></div>
							<div class="rasad-seg-bar" style="--w:4%;background:var(--rasad-border)" title="<?php esc_attr_e( 'تبلت: ۴٪', 'rasad' ); ?>"></div>
						</div>
						<div class="rasad-list">
							<div class="rasad-list-row"><span class="rasad-list-dot" style="background:var(--rasad-accent)"></span><span class="rasad-list-icon">📱</span><span class="rasad-list-path"><?php esc_html_e( 'موبایل (Mobile)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><span class="rasad-list-dot" style="background:var(--rasad-secondary)"></span><span class="rasad-list-icon">💻</span><span class="rasad-list-path"><?php esc_html_e( 'دسکتاپ (Desktop)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><span class="rasad-list-dot" style="background:var(--rasad-muted)"></span><span class="rasad-list-icon">📟</span><span class="rasad-list-path"><?php esc_html_e( 'تبلت (Tablet)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
						</div>
					</div>

					<!-- Browsers -->
					<div class="rasad-panel">
						<div class="rasad-panel-head">
							<div class="rasad-panel-icon">🌐</div>
							<h3 class="rasad-panel-title"><?php esc_html_e( 'مرورگرها', 'rasad' ); ?></h3>
							<span class="rasad-panel-sub"><?php esc_html_e( 'نسخه‌های وب', 'rasad' ); ?></span>
						</div>
						<div class="rasad-list">
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:64%"></div><span class="rasad-list-icon">🌐</span><span class="rasad-list-path"><?php esc_html_e( 'گوگل کروم (Chrome)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:22%"></div><span class="rasad-list-icon">🧭</span><span class="rasad-list-path"><?php esc_html_e( 'سافاری (Safari)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:9%"></div><span class="rasad-list-icon">🦊</span><span class="rasad-list-path"><?php esc_html_e( 'فایرفاکس (Firefox)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:5%"></div><span class="rasad-list-icon">🌐</span><span class="rasad-list-path"><?php esc_html_e( 'مایکروسافت اج (Edge)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
						</div>
					</div>

					<!-- OS -->
					<div class="rasad-panel">
						<div class="rasad-panel-head">
							<div class="rasad-panel-icon">💻</div>
							<h3 class="rasad-panel-title"><?php esc_html_e( 'سیستم‌عامل‌ها', 'rasad' ); ?></h3>
							<span class="rasad-panel-sub"><?php esc_html_e( 'محیط کاربر', 'rasad' ); ?></span>
						</div>
						<div class="rasad-list">
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:52%"></div><span class="rasad-list-icon">🤖</span><span class="rasad-list-path"><?php esc_html_e( 'اندروید (Android)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:24%"></div><span class="rasad-list-icon">🪟</span><span class="rasad-list-path"><?php esc_html_e( 'ویندوز (Windows)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:18%"></div><span class="rasad-list-icon">📱</span><span class="rasad-list-path"><?php esc_html_e( 'آی‌او‌اس (iOS)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:5%"></div><span class="rasad-list-icon">🍎</span><span class="rasad-list-path"><?php esc_html_e( 'مک‌او‌اس (macOS)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
							<div class="rasad-list-row"><div class="rasad-bar" style="--w:1%"></div><span class="rasad-list-icon">🐧</span><span class="rasad-list-path"><?php esc_html_e( 'لینوکس (Linux)', 'rasad' ); ?></span><span class="rasad-list-val">—</span></div>
						</div>
					</div>

				</section>

				<!-- Footer -->
				<footer class="rasad-footer">
					<div class="rasad-footer-left">
						<span class="rasad-dot"></span>
						<span><?php esc_html_e( 'سامانه آمارگیر رصد نسخه ۲.۴.۰ فعال است (همگام‌سازی بدون کوکی، کاملاً مطابق حریم خصوصی)', 'rasad' ); ?></span>
					</div>
					<div class="rasad-footer-right">
						<a href="#"><?php esc_html_e( 'مستندات API', 'rasad' ); ?></a>
						<a href="#"><?php esc_html_e( 'گزارش وضعیت سرور', 'rasad' ); ?></a>
						<a href="#"><?php esc_html_e( 'پشتیبانی', 'rasad' ); ?></a>
					</div>
				</footer>

				<!-- Tabs + Live panel (kept for admin.js tab switching) -->
				<div class="rasad-tabs" role="tablist" aria-label="<?php esc_attr_e( 'بخش‌های داشبورد', 'rasad' ); ?>" style="margin-top:2rem;">
					<button type="button" class="rasad-tab is-active" role="tab" aria-selected="true" data-rasad-tab="stats"><?php esc_html_e( 'آمار', 'rasad' ); ?></button>
					<button type="button" class="rasad-tab" role="tab" aria-selected="false" data-rasad-tab="live"><?php esc_html_e( 'زنده', 'rasad' ); ?></button>
				</div>

				<div class="rasad-stats-view" data-rasad-panel="stats" style="display:none;"></div>

				<div class="rasad-live-view" data-rasad-panel="live" hidden>
					<div class="rasad-live-head">
						<span class="rasad-live-dot" aria-hidden="true"></span>
						<strong class="rasad-live-count">—</strong>
						<span class="rasad-live-label"><?php esc_html_e( 'بازدیدکنندهٔ آنلاین', 'rasad' ); ?></span>
						<span class="rasad-live-note"><?php esc_html_e( 'به‌روزرسانی خودکار هر ۱۵ ثانیه', 'rasad' ); ?></span>
					</div>
					<p class="rasad-live-error" role="status" aria-live="polite"></p>

					<div class="rasad-panel">
						<table class="rasad-table" data-table="live">
							<thead><tr>
								<th><?php esc_html_e( 'بازدیدکننده', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'صفحه', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'کشور', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'دستگاه', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'ارجاع', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'بازدیدها', 'rasad' ); ?></th>
							</tr></thead>
							<tbody></tbody>
						</table>
					</div>

					<div class="rasad-panel">
						<h2 class="rasad-panel-title"><?php esc_html_e( 'آخرین رویدادها', 'rasad' ); ?></h2>
						<table class="rasad-table" data-table="feed">
							<thead><tr>
								<th><?php esc_html_e( 'زمان', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'صفحه', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'بازدیدکننده', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'کشور', 'rasad' ); ?></th>
								<th><?php esc_html_e( 'ارجاع', 'rasad' ); ?></th>
							</tr></thead>
							<tbody></tbody>
						</table>
					</div>
				</div>

				<noscript>
					<p class="rasad-noscript"><?php esc_html_e( 'برای نمایش داشبورد به جاوااسکریپت نیاز است.', 'rasad' ); ?></p>
				</noscript>

			</main>
		</div>
		<?php
	}
}
