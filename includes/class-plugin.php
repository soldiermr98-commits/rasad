<?php
/**
 * Main plugin bootstrap.
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
	 * Everything else (buffers, aggregator, REST, admin screens) hooks in
	 * later through the `rasad_init` action, so nothing heavy loads early.
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

		Rasad_Settings::hooks();

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
}
