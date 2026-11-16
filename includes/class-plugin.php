<?php
/**
 * Main plugin bootstrap, storage paths, buffer factory, front-end tracker.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root plugin controller. Singleton, loaded through the autoloader.
 */
final class Rasad_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Rasad_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Cached storage base path (no trailing slash).
	 *
	 * @var string|null
	 */
	private static $storage = null;

	/**
	 * Whether storage creation was attempted this request.
	 *
	 * @var bool
	 */
	private static $storage_done = false;

	/**
	 * Cached buffer driver instance.
	 *
	 * @var Rasad_Buffer_Interface|null
	 */
	private static $buffer = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Rasad_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor is private (singleton).
	 */
	private function __construct() {}

	/**
	 * Registers the few hooks this plugin needs globally.
	 *
	 * Nothing heavy loads here: buffers/aggregator/admin attach through
	 * `rasad_init` in their own phases.
	 *
	 * @return void
	 */
	public function init() {
		static $initialized = false;

		if ( $initialized ) {
			return;
		}
		$initialized = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Versioned DB migrations, only in admin context (never on the front-end hot path).
		add_action( 'admin_init', array( 'Rasad_Installer', 'maybe_upgrade' ) );

		// Refresh collect.php bootstrap files (wp-load hint + self-test key)
		// for installs that predate them — admin-only, no-ops when current.
		add_action( 'admin_init', array( 'Rasad_Installer', 'ensure_bootstrap_files' ) );

		Rasad_Settings::hooks();
		Rasad_Api_Rest::hooks();

		// Admin screens (lazy): admin_menu / admin_enqueue_scripts fire only
		// inside wp-admin, so front-end requests never load class-admin.php.
		add_action( 'admin_menu', array( 'Rasad_Admin', 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'Rasad_Admin', 'enqueue' ) );

		// Admin dashboard widget (Phase 7) — shown on the main WP dashboard.
		add_action( 'plugins_loaded', array( 'Rasad_Admin_Dashboard_Widget', 'hooks' ), 10 );

		// Post views column (Phase 7) — shown in posts/pages list tables.
		add_action( 'plugins_loaded', array( 'Rasad_Admin_Post_Column', 'hooks' ), 10 );

		// Aggregation pipeline. Callbacks stay lazy: the process/aggregator
		// classes load only when their hook actually fires (cron request,
		// first beacon with a backlog, WP-CLI).
		add_action( RASAD_CRON_HOOK, array( 'Rasad_Process_Aggregator', 'run' ), 10 );
		add_action( RASAD_CRON_HOOK, array( 'Rasad_Process_Cleaner', 'maybe_run' ), 20 );
		add_action( 'rasad_after_hit', array( 'Rasad_Process_Aggregator', 'maybe_spawn' ) );
		add_filter( 'cron_schedules', array( 'Rasad_Process_Aggregator', 'cron_schedules' ) );

		// Make sure the every-minute event exists (cleared on deactivation).
		if ( function_exists( 'wp_next_scheduled' )
			&& function_exists( 'wp_schedule_event' )
			&& ! wp_next_scheduled( RASAD_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'rasad_minute', RASAD_CRON_HOOK );
		}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		Rasad_Process_Aggregator::register_cli();
	}

	// Health check: mark last aggregation time when aggregation completes.
	add_action( 'rasad_aggregated', array( 'Rasad_Process_Health', 'mark_aggregated' ) );

	// Tiny tracker: only on the front-end, only when tracking is allowed.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tracker' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'defer_tracker' ), 10, 2 );

		/**
		 * Fires after the Rasad core is bootstrapped.
		 *
		 * Later phases attach their subsystems here.
		 */
		do_action( 'rasad_init' );
	}

	/**
	 * Loads translations. Registered on `init` (required since WP 6.7).
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'rasad',
			false,
			dirname( plugin_basename( RASAD_FILE ) ) . '/languages'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Storage (wp-content/uploads/rasad/) — usable from full WP AND SHORTINIT
	|--------------------------------------------------------------------------
	*/

	/**
	 * Absolute path inside the Rasad storage dir.
	 *
	 * Deliberately does NOT call wp_upload_dir(): it needs get_option() which
	 * does not exist under SHORTINIT, and both contexts must agree on the
	 * exact same directory. Honors UPLOADS and multisite site subdirs.
	 *
	 * @param string $sub Relative sub path ('' = base dir with trailing slash).
	 * @return string
	 */
	public static function storage_dir( $sub = '' ) {
		if ( null === self::$storage ) {
			if ( defined( 'UPLOADS' ) && UPLOADS ) {
				$base = rtrim( ABSPATH, '/' ) . '/' . trim( (string) UPLOADS, '/' );
			} else {
				$base = rtrim( WP_CONTENT_DIR, '/' ) . '/uploads';

				if ( defined( 'MULTISITE' ) && MULTISITE
					&& function_exists( 'get_current_blog_id' )
					&& function_exists( 'is_main_site' )
					&& ! is_main_site() ) {
					$base .= '/sites/' . get_current_blog_id();
				}
			}

			self::$storage = $base . '/rasad';
		}

		if ( '' === $sub ) {
			return self::$storage . '/';
		}

		return self::$storage . '/' . ltrim( (string) $sub, '/' );
	}

	/**
	 * Creates the storage + buffer directories and protects them
	 * (.htaccess deny + index.php). Safe to call on every request.
	 *
	 * @return bool True when the base directory exists and is writable.
	 */
	public static function ensure_storage() {
		$base = self::storage_dir();

		if ( ! self::$storage_done ) {
			if ( ! is_dir( $base ) ) {
				@mkdir( $base, 0755, true );
			}

			$buffer = self::storage_dir( 'buffer' );

			if ( ! is_dir( $buffer ) ) {
				@mkdir( $buffer, 0755, true );
			}

			self::protect_dir( $base );
			self::protect_dir( $buffer );

			self::$storage_done = true;
		}

		return is_dir( $base ) && is_writable( $base );
	}

	/**
	 * Drops deny-all protection files into a directory (idempotent).
	 *
	 * @param string $dir Absolute directory path with trailing slash.
	 * @return void
	 */
	public static function protect_dir( $dir ) {
		$dir = rtrim( $dir, '/' ) . '/';

		$htaccess = $dir . '.htaccess';

		if ( ! is_file( $htaccess ) ) {
			@file_put_contents(
				$htaccess,
				"# Deny access to Rasad data files.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
				LOCK_EX
			);
		}

		$index = $dir . 'index.php';

		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Buffer factory
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolves the active buffer driver.
	 *
	 * Priority when set to "auto": Redis/Memcached → APCu → file → DB.
	 * Drivers whose classes are missing or unavailable fall through to the
	 * next one, so the plugin degrades gracefully on any host. The chosen
	 * setting can be overridden through the `rasad_buffer_driver` filter.
	 *
	 * @return Rasad_Buffer_Interface
	 */
	public static function buffer() {
		if ( null !== self::$buffer ) {
			return self::$buffer;
		}

		$mode    = (string) Rasad_Settings::get( 'buffer_driver' );
		$mode    = '' === $mode ? 'auto' : $mode;
		$chain   = ( 'auto' === $mode )
			? array( 'redis', 'apcu', 'file', 'db' )
			: array( $mode, 'file', 'db' );
		$classes = array(
			'redis' => 'Rasad_Buffer_Redis',
			'apcu'  => 'Rasad_Buffer_Apcu',
			'file'  => 'Rasad_Buffer_File',
			'db'    => 'Rasad_Buffer_Db',
		);

		foreach ( array_unique( $chain ) as $driver ) {
			if ( ! isset( $classes[ $driver ] ) ) {
				continue;
			}

			$class = $classes[ $driver ];

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$instance = new $class();

			if ( $instance->is_available() ) {
				self::$buffer = $instance;
				break;
			}
		}

		if ( null === self::$buffer ) {
			// Last resort: the file driver (its is_available() already failed).
			self::$buffer = new Rasad_Buffer_File();
		}

		/**
		 * Filters the active buffer driver instance.
		 *
		 * @param Rasad_Buffer_Interface $buffer Resolved driver.
		 * @param string                 $mode   Configured mode (auto/redis/apcu/file/db).
		 */
		self::$buffer = apply_filters( 'rasad_buffer_driver', self::$buffer, $mode );

		return self::$buffer;
	}

	/*
	|--------------------------------------------------------------------------
	| Front-end tracker
	|--------------------------------------------------------------------------
	*/

	/**
	 * Whether the tracker may be printed for the current request.
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( is_admin() || is_feed() || is_preview() || is_customize_preview() ) {
			return false;
		}

		$excluded = Rasad_Settings::get( 'excluded_roles' );

		if ( ! empty( $excluded ) && is_array( $excluded ) && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();

			if ( $user && $user->exists() && array_intersect( $excluded, (array) $user->roles ) ) {
				return false;
			}
		}

		/**
		 * Filters whether the tracker should load (and hits be accepted).
		 *
		 * @param bool  $track Current decision.
		 * @param null  $data  Null at enqueue time; the payload at collect time.
		 */
		return (bool) apply_filters( 'rasad_should_track', true, null );
	}

	/**
	 * Enqueues the tiny tracker with an inline config object.
	 *
	 * @return void
	 */
	public static function enqueue_tracker() {
		if ( ! self::should_track() ) {
			return;
		}

		$mode     = (string) Rasad_Settings::get( 'endpoint_mode' );
		$endpoint = ( 'rest' === $mode )
			? rest_url( Rasad_Api_Rest::NS . '/hit' )
			: RASAD_URL . 'collect.php';

		wp_enqueue_script( 'rasad-t', RASAD_URL . 'assets/t.js', array(), RASAD_VERSION, true );
		wp_add_inline_script(
			'rasad-t',
			'window.rasadC=' . wp_json_encode(
				array(
					'e' => $endpoint,
					'd' => (int) (bool) Rasad_Settings::get( 'honor_dnt' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Adds `defer` to the tracker tag (WP < 6.3 has no script strategies).
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function defer_tracker( $tag, $handle ) {
		if ( 'rasad-t' !== $handle || false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}
}
