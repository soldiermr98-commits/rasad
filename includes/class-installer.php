<?php
/**
 * Installer: table creation and versioned migrations.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates/upgrade Rasad tables (dbDelta) and manages the DB version option.
 */
final class Rasad_Installer {

	/**
	 * Option holding the installed DB schema version.
	 *
	 * @var string
	 */
	const OPTION_DB_VERSION = 'rasad_db_version';

	/**
	 * Short table names (without the `{$wpdb->prefix}rsd_` part), in creation order.
	 *
	 * @var string[]
	 */
	private static $tables = array(
		'pages',
		'referrers',
		'sessions',
		'hits',
		'daily',
		'hourly',
		'daily_pages',
		'daily_dim',
		'events',
		'wc_orders',
		'wc_daily',
		'cities',
		'buffer',
	);

	/**
	 * Full table name for a short name.
	 *
	 * @param string $name Short name, e.g. `sessions`.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'rsd_' . $name;
	}

	/**
	 * All full table names.
	 *
	 * @return string[]
	 */
	public static function table_names() {
		$names = array();

		foreach ( self::$tables as $name ) {
			$names[] = self::table( $name );
		}

		return $names;
	}

	/**
	 * Activation callback (also fires on plugin update).
	 *
	 * @param bool $network_wide True when activating network-wide on multisite.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}

			return;
		}

		self::install();
	}

	/**
	 * Deactivation callback: stops the aggregation schedule.
	 * Data is intentionally kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( RASAD_CRON_HOOK );
	}

	/**
	 * Rewrites/creates the collect.php bootstrap files when missing or
	 * stale (site moved, plugin re-uploaded). Cheap: no-ops when the
	 * stored content already matches.
	 *
	 * Hooked on admin_init so existing installs pick the files up without
	 * a reactivation; activation writes them directly via install().
	 *
	 * @return void
	 */
	public static function ensure_bootstrap_files() {
		if ( function_exists( 'is_admin' ) && ! is_admin() ) {
			return;
		}

		self::write_wp_load_hint();
		self::ensure_selftest_key();
	}

	/**
	 * Writes wp-load-path.php — the absolute wp-load.php path collect.php
	 * tries first (falls back to an upward search when absent/stale).
	 *
	 * @return bool True when the file is present and current.
	 */
	private static function write_wp_load_hint() {
		$target  = RASAD_DIR . 'wp-load-path.php';
		$wp_load = rtrim( (string) ABSPATH, '/\\' ) . '/wp-load.php';

		if ( ! is_readable( $wp_load ) ) {
			return false;
		}

		$content = "<?php\n"
			. "// Written by Rasad on activation — absolute path of wp-load.php for collect.php.\n"
			. "// Safe to delete: collect.php falls back to searching upward from this folder.\n"
			. 'return ' . var_export( $wp_load, true ) . ";\n";

		$existing = @file_get_contents( $target );

		if ( is_string( $existing ) && $existing === $content ) {
			return true;
		}

		if ( false === @file_put_contents( $target, $content, LOCK_EX ) ) {
			return false;
		}

		@chmod( $target, 0644 );

		return true;
	}

	/**
	 * Writes selftest-key.php — a random secret granting the
	 * X-Rasad-Status diagnostic header when sent as ?rasad_test= or
	 * X-Rasad-Test-Key. The file only `return`s the key, so a direct web
	 * request to it outputs nothing.
	 *
	 * @return bool True when the key file exists.
	 */
	public static function ensure_selftest_key() {
		$target = RASAD_DIR . 'selftest-key.php';

		if ( is_readable( $target ) ) {
			return true;
		}

		try {
			$key = bin2hex( random_bytes( 32 ) );
		} catch ( Throwable $e ) {
			$key = sha1( uniqid( (string) mt_rand(), true ) );
		}

		$content = "<?php\n"
			. "// Written by Rasad on activation — self-test key for X-Rasad-Status diagnostics.\n"
			. '// Send it as ?rasad_test=<key> or X-Rasad-Test-Key: <key>.' . "\n"
			. "return '" . $key . "';\n";

		if ( false === @file_put_contents( $target, $content, LOCK_EX ) ) {
			return false;
		}

		@chmod( $target, 0600 );

		return true;
	}

	/**
	 * Ensures the plugin data/ folder exists and is deny-protected
	 * (GeoIP databases live there).
	 *
	 * @return bool True when the directory exists.
	 */
	private static function prepare_data_dir() {
		$dir = RASAD_DIR . 'data';

		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}

		if ( is_dir( $dir ) ) {
			Rasad_Plugin::protect_dir( $dir . '/' );
		}

		return is_dir( $dir );
	}

	/**
	 * Full install for the current site: tables + default settings + version flag.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();

		// Create the single settings option with autoload=no BEFORE any settings
		// screen can save it (register_setting only honors autoload from WP 6.6).
		if ( false === get_option( Rasad_Settings::OPTION, false ) ) {
			add_option( Rasad_Settings::OPTION, Rasad_Settings::defaults(), '', 'no' );
		}

		// Protected storage dir (buffer/, secret, settings mirror) + mirror file,
		// so collect.php can run without touching the database.
		Rasad_Plugin::ensure_storage();
		Rasad_Settings::write_mirror();

		// collect.php bootstrap pointers: resolved wp-load path + self-test key.
		self::write_wp_load_hint();
		self::ensure_selftest_key();

		// GeoIP database folder (deny-all .htaccess + index.php, idempotent).
		self::prepare_data_dir();

		update_option( self::OPTION_DB_VERSION, RASAD_DB_VERSION, 'no' );

		/**
		 * Fires after Rasad tables are installed for the current site.
		 */
		do_action( 'rasad_installed' );
	}

	/**
	 * Runs pending versioned migrations when the stored DB version is behind.
	 * Only invoked from admin context (see Rasad_Plugin::init()).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$stored = get_option( self::OPTION_DB_VERSION, '0' );

		if ( version_compare( (string) $stored, RASAD_DB_VERSION, '>=' ) ) {
			return;
		}

		foreach ( self::migrations() as $version => $method ) {
			if ( version_compare( $stored, $version, '<' ) ) {
				call_user_func( array( __CLASS__, $method ) );
				$stored = $version;
				update_option( self::OPTION_DB_VERSION, $version, 'no' );
			}
		}

		if ( RASAD_DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			update_option( self::OPTION_DB_VERSION, RASAD_DB_VERSION, 'no' );
		}
	}

	/**
	 * Ordered migration map: target version => method name.
	 * New phases append entries here and bump RASAD_DB_VERSION.
	 *
	 * @return array<string,string>
	 */
	private static function migrations() {
		return array(
			'1.0.0' => 'migrate_1_0_0',
			'1.1.0' => 'migrate_1_1_0',
			'1.2.0' => 'migrate_1_2_0',
			'1.3.0' => 'migrate_1_3_0',
		);
	}

	/**
	 * Migration 1.0.0: initial schema.
	 *
	 * @return void
	 */
	public static function migrate_1_0_0() {
		self::create_tables();
	}

	/**
	 * Migration 1.1.0: adds the rsd_buffer table for the DB fallback
	 * buffer driver (Phase 6).
	 *
	 * @return void
	 */
	public static function migrate_1_1_0() {
		self::create_tables();
	}

	/**
	 * Migration 1.2.0: rebuilds rsd_hourly using site-timezone hour buckets
	 * (replaces UTC-hour buckets). Recomputes hourly rows for every day that
	 * has hits so zones like Asia/Tehran (+3:30) align correctly.
	 *
	 * @return void
	 */
	public static function migrate_1_2_0() {
		Rasad_Process_Aggregator::rebuild_hourly();
	}
	/** Migration 1.3.0: durable commerce, summaries and city names. */
	public static function migrate_1_3_0() {
		self::create_tables();
	}

	/** Creates/updates all tables through dbDelta. */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::schema( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Full CREATE TABLE statements (dbDelta format, InnoDB, utf8mb4).
	 *
	 * Schema follows SPEC.MD:
	 * - compact numeric IDs with lookup tables,
	 * - minimal indexes on write-heavy tables (hits, sessions),
	 * - summary tables keyed for range scans.
	 *
	 * @param string $charset_collate Charset/collate clause from $wpdb.
	 * @return array<string,string> Short name => SQL.
	 */
	private static function schema( $charset_collate ) {
		$t = array();

		foreach ( self::$tables as $name ) {
			$t[ $name ] = self::table( $name );
		}

		$schema = array();

		// Lookup: pages. hash = 16-char path hash for compact joins.
		$schema['pages'] = "CREATE TABLE {$t['pages']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hash CHAR(16) NOT NULL,
  path VARCHAR(500) NOT NULL,
  post_id BIGINT UNSIGNED NULL,
  post_type VARCHAR(20) NULL,
  title VARCHAR(255) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash)
) ENGINE=InnoDB {$charset_collate};";

		// Lookup: referrers (type: 1 direct, 2 search, 3 social, 4 other, 5 campaign).
		$schema['referrers'] = "CREATE TABLE {$t['referrers']} (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain VARCHAR(255) NOT NULL,
  type TINYINT UNSIGNED NOT NULL DEFAULT 4,
  name VARCHAR(100) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY domain (domain)
) ENGINE=InnoDB {$charset_collate};";

		// Session state. visitor_key = BINARY(8) daily-rotating hash (no raw IP/UA).
		$schema['sessions'] = "CREATE TABLE {$t['sessions']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_key BINARY(8) NOT NULL,
  started_at INT UNSIGNED NOT NULL,
  last_seen INT UNSIGNED NOT NULL,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  duration INT UNSIGNED NOT NULL DEFAULT 0,
  entry_page BIGINT UNSIGNED NOT NULL DEFAULT 0,
  exit_page BIGINT UNSIGNED NOT NULL DEFAULT 0,
  referrer_id INT UNSIGNED NOT NULL DEFAULT 0,
  country CHAR(2) NOT NULL DEFAULT '',
  city_id INT UNSIGNED NOT NULL DEFAULT 0,
  device TINYINT UNSIGNED NOT NULL DEFAULT 0,
  browser TINYINT UNSIGNED NOT NULL DEFAULT 0,
  os TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_bounce TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY visitor_started (visitor_key,started_at),
  KEY last_seen (last_seen)
) ENGINE=InnoDB {$charset_collate};";

		// Raw hits: only two indexes (purge by ts, session lookups); auto-purged.
		$schema['hits'] = "CREATE TABLE {$t['hits']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  page_id BIGINT UNSIGNED NOT NULL,
  ts INT UNSIGNED NOT NULL,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY session_id (session_id)
) ENGINE=InnoDB {$charset_collate};";

		// Daily overview.
		$schema['daily'] = "CREATE TABLE {$t['daily']} (
  day DATE NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  visitors INT UNSIGNED NOT NULL DEFAULT 0,
  sessions INT UNSIGNED NOT NULL DEFAULT 0,
  bounces INT UNSIGNED NOT NULL DEFAULT 0,
  total_duration BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (day)
) ENGINE=InnoDB {$charset_collate};";

		// Hourly overview (used when the chart range <= 2 days).
		$schema['hourly'] = "CREATE TABLE {$t['hourly']} (
  hour_ts INT UNSIGNED NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  visitors INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (hour_ts)
) ENGINE=InnoDB {$charset_collate};";

		// Per-day page stats (entries/exits power entry & exit pages).
		$schema['daily_pages'] = "CREATE TABLE {$t['daily_pages']} (
  day DATE NOT NULL,
  page_id BIGINT UNSIGNED NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  visitors INT UNSIGNED NOT NULL DEFAULT 0,
  entries INT UNSIGNED NOT NULL DEFAULT 0,
  exits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,page_id)
) ENGINE=InnoDB {$charset_collate};";

		// Per-day dimension stats.
		// dim: 1 referrer, 2 country, 3 city, 4 browser, 5 os, 6 device, 7 search_term, 8 utm_campaign.
		$schema['daily_dim'] = "CREATE TABLE {$t['daily_dim']} (
  day DATE NOT NULL,
  dim TINYINT UNSIGNED NOT NULL,
  dim_id INT UNSIGNED NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  visitors INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,dim,dim_id)
) ENGINE=InnoDB {$charset_collate};";

		// Events: outbound clicks, downloads, WooCommerce actions.
		$schema['events'] = "CREATE TABLE {$t['events']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  day DATE NOT NULL,
  type VARCHAR(32) NOT NULL,
  page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  value DECIMAL(20,6) NULL,
  product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  referrer_id INT UNSIGNED NOT NULL DEFAULT 0,
  quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY day_type (day,type)
) ENGINE=InnoDB {$charset_collate};";

		// Permanent order-id ledger; pending payloads are cleared after atomic aggregation.
		$schema['wc_orders'] = "CREATE TABLE {$t['wc_orders']} (
  order_id BIGINT UNSIGNED NOT NULL,
  payload LONGTEXT NOT NULL,
  processed TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at INT UNSIGNED NOT NULL,
  PRIMARY KEY  (order_id),
  KEY processed_order (processed,order_id)
) ENGINE=InnoDB {$charset_collate};";

		$schema['wc_daily'] = "CREATE TABLE {$t['wc_daily']} (
  day DATE NOT NULL,
  scope TINYINT UNSIGNED NOT NULL,
  object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT '',
  product_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  add_to_cart BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checkouts BIGINT UNSIGNED NOT NULL DEFAULT 0,
  orders BIGINT UNSIGNED NOT NULL DEFAULT 0,
  quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
  revenue DECIMAL(20,6) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,scope,object_id,currency)
) ENGINE=InnoDB {$charset_collate};";

		$schema['cities'] = "CREATE TABLE {$t['cities']} (
  id INT UNSIGNED NOT NULL,
  country CHAR(2) NOT NULL DEFAULT '',
  name VARCHAR(191) NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB {$charset_collate};";

		// Buffer: transient hit queue for the DB fallback driver.
		$schema['buffer'] = "CREATE TABLE {$t['buffer']} (  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,  hit LONGTEXT NOT NULL,  created_at INT UNSIGNED NOT NULL,  PRIMARY KEY  (id),  KEY created_at (created_at)) ENGINE=InnoDB {$charset_collate};";

		/**
		 * Filters the CREATE TABLE statements before dbDelta runs.
		 *
		 * @param array<string,string> $schema Short table name => SQL.
		 */
		return apply_filters( 'rasad_db_schema', $schema );
	}

	/**
	 * Full cleanup for the current site (used by uninstall.php, per site).
	 *
	 * @return void
	 */
	public static function uninstall_site() {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			// Table names come from $wpdb->prefix and cannot be prepared.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		delete_option( Rasad_Settings::OPTION );
		delete_option( self::OPTION_DB_VERSION );
		wp_clear_scheduled_hook( RASAD_CRON_HOOK );

		// collect.php bootstrap pointers belong to the install, not the data.
		@unlink( RASAD_DIR . 'wp-load-path.php' );
		@unlink( RASAD_DIR . 'selftest-key.php' );

		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) ) {
			self::delete_directory( $uploads['basedir'] . '/rasad' );
		}

		/**
		 * Fires after Rasad data was deleted for the current site.
		 */
		do_action( 'rasad_uninstalled' );
	}

	/**
	 * Recursively deletes a directory, but only inside wp-content/uploads.
	 *
	 * @param string $dir Directory path.
	 * @return bool True when the directory is gone.
	 */
	public static function delete_directory( $dir ) {
		$dir = wp_normalize_path( (string) $dir );
		$uploads = wp_upload_dir();

		if ( empty( $dir ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base = wp_normalize_path( $uploads['basedir'] );

		// Safety guard: never touch anything outside uploads.
		if ( 0 !== strpos( $dir, $base ) || ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		return rmdir( $dir );
	}
}
