<?php
/**
 * Settings: single-option storage + the settings screen.
 *
 * Everything is stored in ONE option (`rasad_settings`, autoload = no).
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage and UI.
 */
final class Rasad_Settings {

	/**
	 * Single option name.
	 *
	 * @var string
	 */
	const OPTION = 'rasad_settings';

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	const GROUP = 'rasad_settings';

	/**
	 * Admin menu slug (becomes the dashboard in a later phase).
	 *
	 * @var string
	 */
	/**
	 * Settings submenu slug (the dashboard owns the top-level `rasad` slug).
	 *
	 * @var string
	 */
	const PAGE = 'rasad-settings';

	/**
	 * Top-level dashboard menu slug (also the plugin's canonical screen).
	 *
	 * @var string
	 */
	const PAGE_DASHBOARD = 'rasad';

	/**
	 * Required capability (filterable).
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required for every Rasad screen/endpoint.
		 *
		 * @param string $capability Default `manage_options`.
		 */
		return apply_filters( 'rasad_capability', 'manage_options' );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		$defaults = array(
			'buffer_driver'       => 'auto',
			'aggregate_batch'     => 1000,
			'retention_days'      => 30,
			'filter_bots'         => true,
			'honor_dnt'           => true,
			'excluded_roles'      => array( 'administrator' ),
			'excluded_ips'        => array(),
			'excluded_paths'      => array(),
			'excluded_uas'        => array(),
			'endpoint_mode'       => 'auto',
			'calendar'            => 'jalali',
			'delete_on_uninstall' => false,
		// IP source: remote_addr | cf_connecting_ip | x_forwarded_for | x_real_ip | auto.
		'ip_source'           => 'remote_addr',
		// CIDR list of trusted proxies whose forwarded headers we honor.
		'trusted_proxies'     => array(),
		// Query-string: extra params to keep beyond the hard whitelist.
		'query_string_whitelist' => array(),
		// Max distinct new page paths per day / per hour before /other.
		'query_string_cap_day'   => 1000,
		'query_string_cap_hour'  => 100,
		// same_origin() allow-list of hosts (www. stripped, multisite-aware).
		'trusted_hosts'       => array(),
		// Enforce Origin/Referer on the GET pixel path (?d=1&b=).
		'enforce_origin_get'  => true,
		// Anonymize IP in the buffer line (zero last octet / last 80 bits).
		'ip_anonymize'        => true,
		// Optional, rotatable secret for the server-cron route. When empty,
		// Rasad derives one from the auth salt + site host.
		'cron_key'            => '',
		);

		/**
		 * Filters Rasad's default settings.
		 *
		 * @param array<string,mixed> $defaults Defaults.
		 */
		return apply_filters( 'rasad_default_settings', $defaults );
	}

	/**
	 * All settings merged with defaults.
	 *
	 * Full WP reads the option; under SHORTINIT (collect.php) it reads the
	 * settings mirror file so the hot path never touches the database.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( ! function_exists( 'get_option' ) ) {
			return self::from_mirror();
		}

		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Settings loaded from the mirror file (with one DB fallback query when
	 * the mirror is missing, e.g. right after an upgrade).
	 *
	 * @return array<string,mixed>
	 */
	private static function from_mirror() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$data   = array();
		$loaded = false;
		$file   = Rasad_Plugin::storage_dir( 'settings.json' );

		if ( is_readable( $file ) ) {
			$raw  = @file_get_contents( $file );
			$json = is_string( $raw ) ? json_decode( $raw, true ) : null;

			if ( is_array( $json ) && isset( $json['settings'] ) && is_array( $json['settings'] ) ) {
				$data   = $json['settings'];
				$loaded = true;
			}
		}

		// DB fallback only when the mirror is missing/unreadable — a mirror
		// with an empty settings object is perfectly valid (defaults apply).
		if ( ! $loaded ) {
			global $wpdb;

			if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
				$row = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
						self::OPTION
					)
				);

				if ( is_string( $row ) && '' !== $row ) {
					$stored = maybe_unserialize( $row );

					if ( is_array( $stored ) ) {
						$data = $stored;
					}
				}
			}
		}

		$cached = wp_parse_args( $data, self::defaults() );

		return $cached;
	}

	/**
	 * Persists settings + site host to wp-content/uploads/rasad/settings.json
	 * so collect.php can validate and filter without a database round-trip.
	 *
	 * @return bool
	 */
	public static function write_mirror() {
		if ( ! Rasad_Plugin::ensure_storage() ) {
			return false;
		}

		$payload = array(
			'settings' => self::all(),
			'host'     => self::current_host(),
			'ts'       => time(),
		);

		$encoded = wp_json_encode( $payload );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		$result = @file_put_contents(
			Rasad_Plugin::storage_dir( 'settings.json' ),
			$encoded,
			LOCK_EX
		);

		return false !== $result;
	}

	/**
	 * Site hostname for collect-time Host validation.
	 *
	 * @return string Lowercase host without port; '' when unknown.
	 */
	public static function site_host() {
		if ( function_exists( 'home_url' ) ) {
			return (string) parse_url( home_url(), PHP_URL_HOST );
		}

		$file = Rasad_Plugin::storage_dir( 'settings.json' );

		if ( is_readable( $file ) ) {
			$raw  = @file_get_contents( $file );
			$json = is_string( $raw ) ? json_decode( $raw, true ) : null;

			if ( is_array( $json ) && ! empty( $json['host'] ) && is_string( $json['host'] ) ) {
				return $json['host'];
			}
		}

		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
			$row = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					'siteurl'
				)
			);

			if ( is_string( $row ) && '' !== $row ) {
				$host = parse_url( $row, PHP_URL_HOST );

				return is_string( $host ) ? $host : '';
			}
		}

		return '';
	}

	/**
	 * Raw hostname helper for write_mirror().
	 *
	 * @return string
	 */
	private static function current_host() {
		$host = function_exists( 'home_url' ) ? parse_url( home_url(), PHP_URL_HOST ) : '';

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null Null only for unknown keys.
	 */
	public static function get( $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Registers menu + Settings API hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		// Menu ownership moved to Rasad_Admin (dashboard = top level,
		// Settings = submenu); Settings only registers its fields here.
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Keep the collect-time mirror in sync with the option.
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'write_mirror' ) );
		add_action( 'added_option_' . self::OPTION, array( __CLASS__, 'write_mirror' ) );

		// GeoIP database download/delete (Phase 5), nonce + capability gated.
		add_action( 'admin_post_rasad_geoip', array( __CLASS__, 'geoip_action' ) );

		// CSV export (Phase 7), nonce + capability gated.
		add_action( 'admin_post_rasad_csv', array( __CLASS__, 'csv_export' ) );
	}

	/**
	 * Registers the single option, one section and all fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'autoload'          => 'no',
			)
		);

		add_settings_section( 'rasad_general', __( 'عمومی', 'rasad' ), '__return_empty_string', self::PAGE );
		add_settings_section( 'rasad_tracking', __( 'ردیابی', 'rasad' ), '__return_empty_string', self::PAGE );
		add_settings_section( 'rasad_system', __( 'سیستم', 'rasad' ), '__return_empty_string', self::PAGE );
		add_settings_section( 'rasad_geoip', __( 'پایگاه دادهٔ GeoIP', 'rasad' ), array( __CLASS__, 'render_geoip_section' ), self::PAGE );

		add_settings_field(
			'buffer_driver',
			__( 'درایور بافر', 'rasad' ),
			array( __CLASS__, 'field_buffer_driver' ),
			self::PAGE,
			'rasad_general'
		);
		add_settings_field(
			'aggregate_batch',
			__( 'اندازهٔ دستهٔ پردازش', 'rasad' ),
			array( __CLASS__, 'field_aggregate_batch' ),
			self::PAGE,
			'rasad_general'
		);
		add_settings_field(
			'retention_days',
			__( 'مدت نگهداری داده‌های خام', 'rasad' ),
			array( __CLASS__, 'field_retention_days' ),
			self::PAGE,
			'rasad_general'
		);

		add_settings_field(
			'filter_bots',
			__( 'فیلتر ربات‌ها', 'rasad' ),
			array( __CLASS__, 'field_filter_bots' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'honor_dnt',
			__( 'Do Not Track', 'rasad' ),
			array( __CLASS__, 'field_honor_dnt' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'excluded_roles',
			__( 'نقش‌های مستثنی', 'rasad' ),
			array( __CLASS__, 'field_excluded_roles' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'excluded_ips',
			__( 'IPهای مستثنی', 'rasad' ),
			array( __CLASS__, 'field_excluded_ips' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'excluded_paths',
			__( 'آدرس‌های مستثنی', 'rasad' ),
			array( __CLASS__, 'field_excluded_paths' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'excluded_uas',
			__( 'User-Agentهای مستثنی', 'rasad' ),
			array( __CLASS__, 'field_excluded_uas' ),
			self::PAGE,
			'rasad_tracking'
		);

		add_settings_field(
			'ip_source',
			__( 'منبع آدرس IP', 'rasad' ),
			array( __CLASS__, 'field_ip_source' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'trusted_proxies',
			__( 'پروکسی‌های معتبر (CIDR)', 'rasad' ),
			array( __CLASS__, 'field_trusted_proxies' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'query_string_whitelist',
			__( 'پارا미터های مجاز در پرس‌وجستجو (اضافه)', 'rasad' ),
			array( __CLASS__, 'field_query_string_whitelist' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'query_string_cap_day',
			__( 'سقف مسیر جدید در روز', 'rasad' ),
			array( __CLASS__, 'field_query_string_cap_day' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'query_string_cap_hour',
			__( 'سقف مسیر جدید در ساعت', 'rasad' ),
			array( __CLASS__, 'field_query_string_cap_hour' ),
			self::PAGE,
			'rasad_tracking'
		);
		add_settings_field(
			'ip_anonymize',
			__( 'ناشناس‌سازی آدرس IP در بافر', 'rasad' ),
			array( __CLASS__, 'field_ip_anonymize' ),
			self::PAGE,
			'rasad_tracking'
		);

		add_settings_field(
			'trusted_hosts',
			__( 'میزبان‌های مجاز (same-origin)', 'rasad' ),
			array( __CLASS__, 'field_trusted_hosts' ),
			self::PAGE,
			'rasad_system'
		);
		add_settings_field(
			'enforce_origin_get',
			__( 'اجرای اصالت‌سنجی بر روی پیکس GET', 'rasad' ),
			array( __CLASS__, 'field_enforce_origin_get' ),
			self::PAGE,
			'rasad_system'
		);

		add_settings_field(
			'cron_key',
			__( 'کلید کرون (دوريا)', 'rasad' ),
			array( __CLASS__, 'field_cron_key' ),
			self::PAGE,
			'rasad_system'
		);

		add_settings_field(
			'endpoint_mode',
			__( 'نقطهٔ پایانی ردیابی', 'rasad' ),
			array( __CLASS__, 'field_endpoint_mode' ),
			self::PAGE,
			'rasad_system'
		);
		add_settings_field(
			'calendar',
			__( 'تقویم', 'rasad' ),
			array( __CLASS__, 'field_calendar' ),
			self::PAGE,
			'rasad_system'
		);
		add_settings_field(
			'delete_on_uninstall',
			__( 'حذف داده‌ها هنگام حذف افزونه', 'rasad' ),
			array( __CLASS__, 'field_delete_on_uninstall' ),
			self::PAGE,
			'rasad_system'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Render helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Radio group renderer.
	 *
	 * @param array<string,string> $choices value => label.
	 * @param string               $key     Setting key.
	 * @param string               $name    Input name suffix.
	 * @return void
	 */
	private static function render_radios( $choices, $key, $name ) {
		$value = self::get( $key );

		foreach ( $choices as $choice => $label ) {
			printf(
				'<label class="rasad-radio" style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s[%2$s]" value="%3$s"%4$s /> %5$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $name ),
				esc_attr( $choice ),
				checked( $value, $choice, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Textarea renderer (one value per line).
	 *
	 * @param string $key         Setting key (also the input name).
	 * @param string $placeholder Placeholder attribute.
	 * @return void
	 */
	private static function render_textarea( $key, $placeholder ) {
		$lines = self::get( $key );
		$lines = is_array( $lines ) ? $lines : array();

		printf(
			'<textarea class="large-text code" rows="5" name="%1$s[%2$s]" placeholder="%3$s">%4$s</textarea>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $placeholder ),
			esc_textarea( implode( "\n", $lines ) )
		);
	}

	/**
	 * Field: buffer driver.
	 *
	 * @return void
	 */
	public static function field_buffer_driver() {
		self::render_radios( self::buffer_choices(), 'buffer_driver', 'buffer_driver' );
		echo '<p class="description">' . esc_html__( '«خودکار» بهترین گزینهٔ در دسترس را انتخاب می‌کند.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: aggregation batch size.
	 *
	 * @return void
	 */
	public static function field_aggregate_batch() {
		printf(
			'<input type="number" class="small-text" min="100" max="5000" step="10" name="%1$s[aggregate_batch]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'aggregate_batch' ) )
		);
		echo '<p class="description">' . esc_html__( 'تعداد رویدادهای پردازش‌شده در هر نوبت جمع‌آوری (۱۰۰ تا ۵۰۰۰).', 'rasad' ) . '</p>';
	}

	/**
	 * Field: raw data retention.
	 *
	 * @return void
	 */
	public static function field_retention_days() {
		printf(
			'<input type="number" class="small-text" min="1" max="3650" step="1" name="%1$s[retention_days]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'retention_days' ) )
		);
		echo '<p class="description">' . esc_html__( 'روز نگهداری جدول‌های خام (hits/sessions). خلاصه‌ها همیشه می‌مانند.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: bot filtering.
	 *
	 * @return void
	 */
	public static function field_filter_bots() {
		printf(
			'<label><input type="checkbox" name="%1$s[filter_bots]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (bool) self::get( 'filter_bots' ), true, false ),
			esc_html__( 'بازدید ربات‌ها و خزنده‌ها ثبت نشود', 'rasad' )
		);
	}

	/**
	 * Field: honor Do-Not-Track.
	 *
	 * @return void
	 */
	public static function field_honor_dnt() {
		printf(
			'<label><input type="checkbox" name="%1$s[honor_dnt]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (bool) self::get( 'honor_dnt' ), true, false ),
			esc_html__( 'درخواست Do Not Track مرورگرها را رعایت کن', 'rasad' )
		);
	}

	/**
	 * Field: excluded roles.
	 *
	 * @return void
	 */
	public static function field_excluded_roles() {
		$selected = self::get( 'excluded_roles' );
		$selected = is_array( $selected ) ? $selected : array();
		$roles    = wp_roles()->get_names();

		foreach ( $roles as $slug => $name ) {
			printf(
				'<label style="display:inline-block;margin:0 12px 4px 0;"><input type="checkbox" name="%1$s[excluded_roles][]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}

		echo '<p class="description">' . esc_html__( 'بازدید کاربران با این نقش‌ها ثبت نمی‌شود.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: excluded IPs.
	 *
	 * @return void
	 */
	public static function field_excluded_ips() {
		self::render_textarea( 'excluded_ips', '203.0.113.10' );
		echo '<p class="description">' . esc_html__( 'هر خط یک نشانی IP.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: excluded paths.
	 *
	 * @return void
	 */
	public static function field_excluded_paths() {
		self::render_textarea( 'excluded_paths', '/cart' );
		echo '<p class="description">' . esc_html__( 'هر خط یک بخش از آدرس که با / شروع می‌شود.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: excluded User-Agent fragments.
	 *
	 * @return void
	 */
	public static function field_excluded_uas() {
		self::render_textarea( 'excluded_uas', 'Pingdom' );
		echo '<p class="description">' . esc_html__( 'هر خط یک قطعه از رشتهٔ User-Agent.', 'rasad' ) . '</p>';
	}

	/* ========================================================================
	 * New fields: IP source, trusted proxies, query-string policy, health
	 * ======================================================================== */

	/**
	 * Field: IP source.
	 *
	 * @return void
	 */
	public static function field_ip_source() {
		self::render_radios( self::ip_source_choices(), 'ip_source', 'ip_source' );
		echo '<p class="description">' . esc_html__( 'منبع استفاده‌شده برای شناسهٔ بازدیدکننده. «خودکار» فقط وقتی هدرهای منتقل‌شده را اعتماد می‌کند که آدرس REMOTE_ADDR در لیست پروکسی‌های معتبر است.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: trusted proxies.
	 *
	 * @return void
	 */
	public static function field_trusted_proxies() {
		self::render_textarea( 'trusted_proxies', '10.0.0.0/8' );
		echo '<p class="description">' . esc_html__( 'هر خط یک نشانی IP یا محدودهٔ CIDR. فقط در حالت «خودکار» مورد بررسی قرار می‌گیرد.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: query-string extra whitelist.
	 *
	 * @return void
	 */
	public static function field_query_string_whitelist() {
		self::render_textarea( 'query_string_whitelist', 'utm_source' );
		echo '<p class="description">' . esc_html__( 'به علاوهٔ فهرست پیش‌فرض (s, paged, page_id, p, cat, tag, post_type, product_cat). هر خط یک نام پارامتر.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: query-string cap per day.
	 *
	 * @return void
	 */
	public static function field_query_string_cap_day() {
		printf(
			'<input type="number" class="small-text" min="0" max="100000" step="10" name="%1$s[query_string_cap_day]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'query_string_cap_day' ) )
		);
		echo '<p class="description">' . esc_html__( 'حداکثر مسیر جدید در هر روز؛ بقیه به "/other" جمع می‌شوند. ۰ = غیرفعال.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: query-string cap per hour.
	 *
	 * @return void
	 */
	public static function field_query_string_cap_hour() {
		printf(
			'<input type="number" class="small-text" min="0" max="100000" step="10" name="%1$s[query_string_cap_hour]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'query_string_cap_hour' ) )
		);
		echo '<p class="description">' . esc_html__( 'حداکثر مسیر جدید در هر ساعت؛ بقیه به "/other" جمع می‌شوند. ۰ = غیرفعال.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: anonymize IP in buffer.
	 *
	 * @return void
	 */
	public static function field_ip_anonymize() {
		printf(
			'<label><input type="checkbox" name="%1$s[ip_anonymize]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (bool) self::get( 'ip_anonymize' ), true, false ),
			esc_html__( 'آخرین بایت IPv4 / آخرین ۸۰ بیت IPv6 در بافر را پاک کند (تأثیر روی GeoIP)', 'rasad' )
		);
	}

	/**
	 * Field: trusted hosts for same-origin.
	 *
	 * @return void
	 */
	public static function field_trusted_hosts() {
		self::render_textarea( 'trusted_hosts', 'www.example.com' );
		echo '<p class="description">' . esc_html__( 'هر خط یک میزبان. www. حذف شده و مقایسه می‌شود؛ برای multisite و domain mapping.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: enforce Origin/Referer on GET pixel.
	 *
	 * @return void
	 */
	public static function field_enforce_origin_get() {
		printf(
			'<label><input type="checkbox" name="%1$s[enforce_origin_get]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (bool) self::get( 'enforce_origin_get' ), true, false ),
			esc_html__( 'برای مسیر پیکس GET (?d=1&b=) هم اصالت‌سنجی Origin/Referer اجرا شود', 'rasad' )
		);
	}

	/**
	 * Field: rotatable server-cron key.
	 *
	 * @return void
	 */
	public static function field_cron_key() {
		printf(
			'<label>' . __( 'کلید کرون:', 'rasad' ) . ' <input type="text" class="regular-text code" name="%1$s[cron_key]" value="%2$s" /></label>',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'cron_key' ) )
		);
		echo '<p class="description">' . esc_html__( 'خالی گذاشتن آن باعث استفاده از کلید استخراجی (هش) می‌شود. تغییر کلید لینک کرون سرور را منقضی می‌کند.', 'rasad' ) . '</p>';
	}
	/**
	 * Field: tracker endpoint mode.
	 *
	 * @return void
	 */
	public static function field_endpoint_mode() {
		self::render_radios( self::endpoint_choices(), 'endpoint_mode', 'endpoint_mode' );
		echo '<p class="description">' . esc_html__( '«خودکار» ابتدا collect.php و در صورت نبود، مسیر REST را انتخاب می‌کند.', 'rasad' ) . '</p>';
	}

	/**
	 * Field: calendar.
	 *
	 * @return void
	 */
	public static function field_calendar() {
		self::render_radios( self::calendar_choices(), 'calendar', 'calendar' );
	}

	/**
	 * Field: delete data on uninstall.
	 *
	 * @return void
	 */
	public static function field_delete_on_uninstall() {
		printf(
			'<label><input type="checkbox" name="%1$s[delete_on_uninstall]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (bool) self::get( 'delete_on_uninstall' ), true, false ),
			esc_html__( 'با حذف افزونه، همهٔ جدول‌ها و داده‌های رصد نیز حذف شوند', 'rasad' )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Page
	|--------------------------------------------------------------------------
	*/

	/**
	 * Section content: per-database status + download/remove actions.
	 * Rendered inside the settings form, so actions are links (no nested
	 * forms); each link carries a nonce and is verified in geoip_action().
	 *
	 * @return void
	 */
	public static function render_geoip_section() {
		foreach ( array( 'country' => __( 'کشور', 'rasad' ), 'city' => __( 'شهر', 'rasad' ) ) as $kind => $label ) {
			$status = Rasad_Process_Geoip::status( $kind );
			$bytes  = isset( $status['bytes'] ) ? (int) $status['bytes'] : 0;
			$human  = size_format( $bytes );

			echo '<p class="rasad-geoip-row"><strong>' . esc_html( $label ) . ':</strong> ';

			if ( ! empty( $status['available'] ) ) {
				$date = ! empty( $status['build_epoch'] )
					? date_i18n( 'Y-m-d', (int) $status['build_epoch'] )
					: '';
				/* translators: %s: database size, %s: build date. */
				echo esc_html( sprintf( __( 'نصب‌شده (%s، بروزرسانی: %s).', 'rasad' ), $human, $date ) );
				echo ' — ' . self::geoip_link( 'delete', $kind, __( 'حذف', 'rasad' ), 'delete' );
			} else {
				$note = 'corrupt' === ( isset( $status['error'] ) ? $status['error'] : '' )
					? __( 'فایل موجود اما خراب است.', 'rasad' )
					: __( 'نصب نشده.', 'rasad' );
				echo esc_html( $note );
				echo ' — ' . self::geoip_link( 'download', $kind, __( 'دانلود', 'rasad' ), 'primary' );
			}

			echo '</p>';
		}

		echo '<p class="description">'
			. esc_html__( 'فایل‌ها از DB-IP Lite (ماهانه) دانلود و در پوشهٔ data/ نگهداری می‌شوند؛ جستجوی موقعیت فقط داخل پردازش تجمیعی انجام می‌شود و مسیر دانلود با فیلتر rasad_geoip_download_url قابل تغییر است.', 'rasad' )
			. '</p>';
	}

	/**
	 * Builds one nonce-protected admin-post link for the GeoIP actions.
	 *
	 * @param string $op      download|delete.
	 * @param string $kind    country|city.
	 * @param string $text    Link text (already translated).
	 * @param string $klass   Button class suffix.
	 * @return string HTML anchor.
	 */
	private static function geoip_link( $op, $kind, $text, $klass ) {
		$url = add_query_arg(
			array(
				'action' => 'rasad_geoip',
				'op'     => $op,
				'db'     => $kind,
			),
			admin_url( 'admin-post.php' )
		);
		$url = wp_nonce_url( $url, 'rasad_geoip' );

		return '<a class="button button-' . esc_attr( $klass ) . '" href="'
			. esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * admin_post_rasad_geoip: download/remove one GeoIP database.
	 * Capability + nonce checked before any filesystem/network work.
	 *
	 * @return void
	 */
	public static function geoip_action() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'rasad' ) );
		}

		check_admin_referer( 'rasad_geoip' );

		$op   = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : '';
		$kind = isset( $_GET['db'] ) && 'city' === $_GET['db'] ? 'city' : 'country';
		$msg  = '';
		$type = 'success';

		if ( 'download' === $op ) {
			$result = Rasad_Process_Geoip::download( $kind );

			if ( true === $result ) {
				$msg = __( 'پایگاه دادهٔ GeoIP با موفقیت دانلود و نصب شد.', 'rasad' );
			} else {
				$type = 'error';
				$msg  = is_wp_error( $result )
					/* translators: %s: error message. */
					? sprintf( __( 'خطا در دانلود: %s', 'rasad' ), $result->get_error_message() )
					: __( 'خطا در دانلود GeoIP.', 'rasad' );
			}
		} elseif ( 'delete' === $op ) {
			Rasad_Process_Geoip::delete( $kind );
			$msg = __( 'پایگاه دادهٔ GeoIP حذف شد.', 'rasad' );
		}

		if ( '' !== $msg ) {
			add_settings_error( 'rasad_geoip', 'rasad_geoip', $msg, $type );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * CSV export: streams the dashboard range as CSV.
	 * Capability + nonce checked before any output.
	 *
	 * @return void
	 */
	public static function csv_export() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'rasad' ) );
		}

		check_admin_referer( 'rasad_csv' );

		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : '';

		if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $from ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $to ) ) {
			wp_die( esc_html__( 'بازهٔ زمانی نامعتبر است.', 'rasad' ) );
		}

		$jalali = (string) self::get( 'calendar' ) === 'jalali';

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			wp_die( esc_html__( 'خطا در دسترسی به پایگاه داده.', 'rasad' ) );
		}

		$daily  = Rasad_Installer::table( 'daily' );
		$pages  = Rasad_Installer::table( 'daily_pages' );
		$p_tbl  = Rasad_Installer::table( 'pages' );
		$dims   = Rasad_Installer::table( 'daily_dim' );
		$refs   = Rasad_Installer::table( 'referrers' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rasad-' . $from . '_' . $to . '.csv"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );

		fputcsv( $out, array(
			__( 'تاریخ', 'rasad' ),
			__( 'بازدیدها', 'rasad' ),
			__( 'بازدیدکنندگان', 'rasad' ),
			__( 'نشست‌ها', 'rasad' ),
			__( 'نرخ پرش', 'rasad' ),
			__( 'میانگین مدت (ثانیه)', 'rasad' ),
		), '', ',', '"' );

		// Daily overview rows
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT day, views, visitors, sessions, bounces, total_duration FROM ' . $daily . '
			WHERE day >= %s AND day <= %s ORDER BY day',
			$from, $to
		) );

		foreach ( (array) $rows as $row ) {
			$date = $jalali ? self::format_jalali_date( (string) $row->day ) : (string) $row->day;
			$bounce = $row->sessions > 0 ? round( $row->bounces / $row->sessions, 4 ) : 0;
			$avg    = $row->sessions > 0 ? (int) round( $row->total_duration / $row->sessions ) : 0;

			fputcsv( $out, array(
				$date,
				(int) $row->views,
				(int) $row->visitors,
				(int) $row->sessions,
				$bounce,
				$avg,
			), '', ',', '"' );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Formats a 'Y-m-d' date as Jalali when the calendar setting is jalali.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return string
	 */
	private static function format_jalali_date( $date ) {
		list( $y, $m, $d ) = explode( '-', $date );
		$j = Rasad_Jalali::gregorian_to_jalali( (int) $y, (int) $m, (int) $d );

		return Rasad_Jalali::persian_numerals( sprintf( '%04d/%02d/%02d', $j['jy'], $j['jm'], $j['jd'] ) );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'rasad' ) );
		}
		?>
		<div class="wrap rasad-settings">
			<h1><?php esc_html_e( 'تنظیمات رصد', 'rasad' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_errors();
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
		submit_button( __( 'ذخیرهٔ تنظیمات', 'rasad' ) );
			?>
			</form>
		</div>

		<?php self::render_health_panel(); ?>

		<?php
	}

	/**
	 * Health-check panel: buffer driver, IP source detection, trusted proxies,
	 * and aggregate/cron status. Rendered on the settings screen.
	 *
	 * @return void
	 */
	public static function render_health_panel() {
		$health = Rasad_Process_Health::report();

		$proxies = empty( $health['trusted_proxies'] )
			? __( 'هیچ‌کدام', 'rasad' )
			: implode( ', ', $health['trusted_proxies'] );
		$hosts   = empty( $health['trusted_hosts'] )
			? __( 'فقط میزبان سایت', 'rasad' )
			: implode( ', ', $health['trusted_hosts'] );
		$jalali = (string) Rasad_Settings::get( 'calendar' ) === 'jalali';
		$cron   = false === $health['cron_next']
			? __( 'فعال نیست', 'rasad' )
			: self::format_health_date( (int) $health['cron_next'], $jalali );
		$agg    = false === $health['last_aggregation']
			? __( 'هرگز اجرا نشده', 'rasad' )
			: self::format_health_date( (int) $health['last_aggregation'], $jalali );

		?>
		<div class="rasad-health" style="margin-top:24px;padding:16px;border:1px solid #ccc;background:#f9f9f9;direction:rtl;">
			<h2><?php esc_html_e( 'بررسی سلامت', 'rasad' ); ?></h2>
			<table class="form-table" style="width:auto;">
				<tr>
					<th scope="row"><?php esc_html_e( 'درایور بافر', 'rasad' ); ?></th>
					<td><?php echo esc_html( $health['buffer_driver'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'حقیقهٔ بافر', 'rasad' ); ?></th>
					<td><?php echo absint( $health['backlog'] ); ?> <?php esc_html_e( 'رویداد', 'rasad' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'منبع IP', 'rasad' ); ?></th>
					<td><?php echo esc_html( $health['ip_source'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'پروکسی‌های معتبر', 'rasad' ); ?></th>
					<td><?php echo esc_html( $proxies ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'میزبان‌های مجاز', 'rasad' ); ?></th>
					<td><?php echo esc_html( $hosts ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'به‌روزرسانی تجمیعی (کرون)', 'rasad' ); ?></th>
					<td><?php echo esc_html( $cron ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'آخرین جمع‌آوری', 'rasad' ); ?></th>
					<td><?php echo esc_html( $agg ); ?></td>
				</tr>
			</table>

			<?php if ( ! empty( $health['problems'] ) ) : ?>
				<h3><?php esc_html_e( 'مشکلات شناسایی‌شده', 'rasad' ); ?></h3>
				<ul>
					<?php foreach ( $health['problems'] as $problem ) : ?>
						<li class="rasad-health-<?php echo esc_attr( $problem['level'] ); ?>">
							<?php echo esc_html( $problem['msg'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'هیچ مشکلی شناسایی نشد.', 'rasad' ); ?></p>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'آدرس شناسهٔ بازدیدکننده در هر درخواست از روی منبع IP انتخاب‌شده استخراج و (در صورت فعال بودن ناشناس‌سازی) در بافر ذخیره می‌شود.', 'rasad' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Formats a Unix timestamp for the health panel, honoring the calendar setting.
	 *
	 * @param int      $ts     Unix timestamp.
	 * @param bool     $jalali True to format as Jalali.
	 * @return string
	 */
	private static function format_health_date( $ts, $jalali ) {
		if ( $jalali ) {
			return Rasad_Jalali::format_persian( $ts, 'Y/m/d H:i' );
		}

		return date_i18n( 'Y-m-d H:i:s', $ts );
	}
	/*
	|--------------------------------------------------------------------------
	| Sanitization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sanitizes the whole settings array on save.
	 *
	 * @param mixed $input Raw input from options.php.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::all();

		// An empty payload means no settings form was posted — keep current values.
		if ( empty( $input ) ) {
			return $out;
		}

		$buffers   = array_keys( self::buffer_choices() );
		$endpoints = array_keys( self::endpoint_choices() );
		$calendars = array_keys( self::calendar_choices() );

		if ( isset( $input['buffer_driver'] ) ) {
			$out['buffer_driver'] = in_array( (string) $input['buffer_driver'], $buffers, true )
				? (string) $input['buffer_driver']
				: 'auto';
		}

		if ( isset( $input['aggregate_batch'] ) ) {
			$out['aggregate_batch'] = max( 100, min( 5000, absint( $input['aggregate_batch'] ) ) );
		}

		if ( isset( $input['retention_days'] ) ) {
			$out['retention_days'] = max( 1, min( 3650, absint( $input['retention_days'] ) ) );
		}

		// Checkboxes and role picks are always derived from the posted form:
		// unchecked boxes are simply absent from $input and must be able to flip off.
		$out['filter_bots']         = ! empty( $input['filter_bots'] );
		$out['honor_dnt']           = ! empty( $input['honor_dnt'] );
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		$roles = ( isset( $input['excluded_roles'] ) && is_array( $input['excluded_roles'] ) )
			? $input['excluded_roles']
			: array();
		$out['excluded_roles'] = array_values(
			array_unique( array_map( 'sanitize_key', $roles ) )
		);

		foreach ( array( 'excluded_ips', 'excluded_paths', 'excluded_uas' ) as $list_key ) {
			$out[ $list_key ] = self::sanitize_lines(
				isset( $input[ $list_key ] ) ? $input[ $list_key ] : ''
			);
		}

		// IP source: must be one of the allowed values.
		$ip_sources = array_keys( self::ip_source_choices() );
		if ( isset( $input['ip_source'] ) ) {
			$out['ip_source'] = in_array( (string) $input['ip_source'], $ip_sources, true )
				? (string) $input['ip_source']
				: 'remote_addr';
		}

		// CIDR lists (trusted proxies / trusted hosts): clean lines only.
		foreach ( array( 'trusted_proxies', 'trusted_hosts' ) as $list_key ) {
			$out[ $list_key ] = self::sanitize_lines(
				isset( $input[ $list_key ] ) ? $input[ $list_key ] : ''
			);
		}

		// Query-string extra whitelist: clean lines only.
		if ( isset( $input['query_string_whitelist'] ) ) {
			$out['query_string_whitelist'] = self::sanitize_lines(
				$input['query_string_whitelist']
			);
		}

		// Numeric caps: 0 means disabled.
		if ( isset( $input['query_string_cap_day'] ) ) {
			$out['query_string_cap_day'] = max( 0, (int) $input['query_string_cap_day'] );
		}

		if ( isset( $input['query_string_cap_hour'] ) ) {
			$out['query_string_cap_hour'] = max( 0, (int) $input['query_string_cap_hour'] );
		}

		// Checkboxes.
		$out['ip_anonymize']      = ! empty( $input['ip_anonymize'] );
		$out['enforce_origin_get'] = ! empty( $input['enforce_origin_get'] );

		// Cron key: rotatable secret (raw string, trimmed).
		if ( isset( $input['cron_key'] ) ) {
			$out['cron_key'] = trim( wp_unslash( (string) $input['cron_key'] ) );
		}

		if ( isset( $input['endpoint_mode'] ) ) {
			$out['endpoint_mode'] = in_array( (string) $input['endpoint_mode'], $endpoints, true )
				? (string) $input['endpoint_mode']
				: 'auto';
		}

		if ( isset( $input['calendar'] ) ) {
			$out['calendar'] = in_array( (string) $input['calendar'], $calendars, true )
				? (string) $input['calendar']
				: 'jalali';
		}

		/**
		 * Filters settings right before they are stored.
		 *
		 * @param array<string,mixed> $out   Sanitized settings.
		 * @param array<string,mixed> $input Raw input.
		 */
		return apply_filters( 'rasad_sanitized_settings', $out, $input );
	}

	/**
	 * Sanitizes a textarea into an array of clean, unique lines.
	 *
	 * Uses wp_strip_all_tags (not sanitize_text_field) on purpose: path and
	 * User-Agent fragments may legitimately contain percent-encoded sequences.
	 *
	 * @param mixed $raw Raw textarea value.
	 * @return string[]
	 */
	private static function sanitize_lines( $raw ) {
		if ( ! is_string( $raw ) ) {
			return array();
		}

		$lines = preg_split( '/[\r\n]+/', $raw );
		$lines = is_array( $lines ) ? $lines : array();
		$clean = array();

		foreach ( $lines as $line ) {
			$line = trim( wp_strip_all_tags( wp_check_invalid_utf8( $line ) ) );

			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Choice lists (shared with later panels)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Buffer driver choices.
	 *
	 * @return array<string,string> value => label.
	 */
	public static function buffer_choices() {
		return array(
			'auto'  => __( 'خودکار', 'rasad' ),
			'redis' => __( 'Redis / Memcached', 'rasad' ),
			'apcu'  => __( 'APCu', 'rasad' ),
			'file'  => __( 'فایل (پیش‌فرض اشتراکی)', 'rasad' ),
			'db'    => __( 'پایگاه داده', 'rasad' ),
		);
	}

	/**
	 * Tracker endpoint mode choices.
	 *
	 * @return array<string,string> value => label.
	 */
	public static function endpoint_choices() {
		return array(
			'auto'    => __( 'خودکار', 'rasad' ),
			'collect' => __( 'collect.php', 'rasad' ),
			'rest'    => __( 'REST API', 'rasad' ),
		);
	}

	/**
	 * Calendar choices.
	 *
	 * @return array<string,string> value => label.
	 */
	public static function calendar_choices() {
		return array(
			'jalali'    => __( 'شمسی', 'rasad' ),
			'gregorian' => __( 'میلادی', 'rasad' ),
		);
	}

	/**
	 * IP source choices.
	 *
	 * @return array<string,string> value => label.
	 */
	public static function ip_source_choices() {
		return array(
			'remote_addr'      => __( 'REMOTE_ADDR (پیش‌فرض)', 'rasad' ),
			'cf_connecting_ip' => __( 'CF-Connecting-IP', 'rasad' ),
			'x_forwarded_for'  => __( 'X-Forwarded-For (اولین IPعمومی)', 'rasad' ),
			'x_real_ip'        => __( 'X-Real-IP', 'rasad' ),
			'auto'             => __( 'خودکار (فقط derrière پروکسی معتبر)', 'rasad' ),
		);
	}

	/**
	 * Trusted hosts for same-origin validation.
	 *
	 * @return string[]
	 */
	public static function trusted_hosts() {
		$hosts = self::get( 'trusted_hosts' );

		return is_array( $hosts ) ? $hosts : array();
	}
}
