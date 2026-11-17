<?php
/** Batched summary-based views for the WordPress posts/pages lists. @package Rasad */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Rasad_Admin_Post_Column {
	const COLUMN = 'rasad_views';
	private static $counts = array();
	public static function hooks() {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
		add_filter( 'the_posts', array( __CLASS__, 'prime' ), 10, 2 );
	}
	public static function add_column( $columns ) {
		if ( current_user_can( Rasad_Settings::capability() ) ) {
			$columns[ self::COLUMN ] = __( 'بازدیدها', 'rasad' );
		}
		return $columns;
	}
	/** One query for all displayed IDs, instead of one transient/query per row. */
	public static function prime( $posts, $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! current_user_can( Rasad_Settings::capability() ) ) { return $posts; }
		$ids = array();
		foreach ( $posts as $post ) {
			if ( in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
				$ids[] = (int) $post->ID;
				self::$counts[ (int) $post->ID ] = 0;
			}
		}
		if ( ! $ids ) { return $posts; }
		global $wpdb;
		$pages = Rasad_Installer::table( 'pages' );
		$daily = Rasad_Installer::table( 'daily_pages' );
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_id, SUM(d.views) AS views FROM {$pages} p INNER JOIN {$daily} d ON d.page_id=p.id WHERE p.post_id IN ({$ph}) GROUP BY p.post_id", $ids
		) );
		foreach ( (array) $rows as $row ) { self::$counts[ (int) $row->post_id ] = (int) $row->views; }
		return $posts;
	}
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column || ! current_user_can( Rasad_Settings::capability() ) ) { return; }
		echo esc_html( number_format_i18n( isset( self::$counts[ $post_id ] ) ? self::$counts[ $post_id ] : 0 ) );
	}
}
