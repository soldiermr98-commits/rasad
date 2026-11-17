<?php
/**
 * Plugin Name:       Rasad (رصد)
 * Plugin URI:        https://rasad.ir/
 * Description:       رصد — آمار بازدیدکنندگان بسیار سبک، کاملاً محلی و بدون کوکی برای وردپرس.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Rasad
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rasad
 * Domain Path:       /languages
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RASAD_VERSION', '1.0.0' );
define( 'RASAD_DB_VERSION', '1.2.0' );
define( 'RASAD_FILE', __FILE__ );
define( 'RASAD_DIR', plugin_dir_path( __FILE__ ) );

// plugin_dir_url() calls plugins_url(), which is only available in a full
// WordPress load (not under SHORTINIT, which collect.php uses). RASAD_URL is
// only needed on the front-end/admin (tracker endpoint), so fall back to an
// empty string there rather than fatal on the collect hot path.
define( 'RASAD_URL', function_exists( 'plugins_url' ) ? plugin_dir_url( __FILE__ ) : '' );
define( 'RASAD_CRON_HOOK', 'rasad_aggregate' );

// Hard cap for one beacon payload (bytes). t.js sends ~300-800; 4KB is headroom.
define( 'RASAD_MAX_PAYLOAD', 4096 );

/**
 * Lazily loads a single Rasad_ class file.
 *
 * Mapping rules:
 * - Rasad_Installer                 -> includes/class-installer.php
 * - Rasad_Process_Aggregator        -> includes/process/class-aggregator.php
 * - Rasad_Admin                     -> includes/admin/class-admin.php
 * - Rasad_Admin_Dashboard_Widget    -> includes/admin/class-dashboard-widget.php
 * - Rasad_Admin_Post_Column         -> includes/admin/class-post-column.php
 * - Rasad_Jalali                    -> includes/helpers/class-jdate.php
 * - Rasad_Buffer_File               -> includes/collect/buffer/class-buffer-file.php
 * - Rasad_Buffer_Interface          -> includes/collect/buffer/interface-buffer.php
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function rasad_autoload( $class ) {
	if ( 0 !== strpos( $class, 'Rasad_' ) ) {
		return;
	}

	$parts = explode( '_', strtolower( substr( $class, 6 ) ) );
	$leaf  = array_pop( $parts );

	if ( 'jalali' === $leaf && empty( $parts ) ) {
		// Helper class: Rasad_Jalali -> includes/helpers/class-jdate.php.
		$path = 'includes/helpers/class-jdate.php';
	} elseif ( empty( $parts ) ) {
		// Top-level class: Rasad_Installer -> includes/class-installer.php,
		// except Rasad_Admin which lives in its own subdirectory.
		$path = ( 'admin' === $leaf )
			? 'includes/admin/class-admin.php'
			: 'includes/class-' . $leaf . '.php';
	} elseif ( 'buffer' === $parts[0] ) {
		// Buffer subtree lives under includes/collect/buffer/ with class- prefixed file names.
		$path = 'includes/collect/buffer/'
			. ( 'interface' === $leaf
				? 'interface-buffer.php'
				: 'class-buffer-' . $leaf . '.php' );
	} else {
		// Area class: Rasad_Area_Name -> includes/area/class-name.php.
		$area = array_shift( $parts );
		$path = 'includes/' . $area . '/class-' . implode( '-', array_merge( $parts, array( $leaf ) ) ) . '.php';
	}

	$file = RASAD_DIR . $path;

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'rasad_autoload' );

// Activation hooks and the plugins_loaded bootstrap touch the global hook
// registry, which does not exist under SHORTINIT (collect.php). The autoloader
// and constants above are all collect.php needs, so skip the rest there.
if ( ! defined( 'SHORTINIT' ) || ! SHORTINIT ) {
	register_activation_hook( __FILE__, array( 'Rasad_Installer', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'Rasad_Installer', 'deactivate' ) );
}

/**
 * Boots the plugin. Kept intentionally tiny: nothing heavy runs on plugins_loaded.
 *
 * @return void
 */
function rasad_bootstrap() {
	Rasad_Plugin::instance()->init();
}

if ( ! defined( 'SHORTINIT' ) || ! SHORTINIT ) {
	add_action( 'plugins_loaded', 'rasad_bootstrap', 5 );
}
