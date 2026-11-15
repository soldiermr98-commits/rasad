<?php
/**
 * Uninstall handler: full cleanup when "delete data on uninstall" is enabled.
 *
 * @package Rasad
 */

// Silence is golden.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The main plugin file is not loaded during uninstall — pull in the
// bootstrap (constants + autoloader) so Rasad_Installer resolves.
require_once __DIR__ . '/rasad.php';

$settings = get_option( 'rasad_settings', array() );
$settings = is_array( $settings ) ? $settings : array();

// Respect the user's choice: keep data unless they opted in to deletion.
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		Rasad_Installer::uninstall_site();
		restore_current_blog();
	}

	return;
}

Rasad_Installer::uninstall_site();
