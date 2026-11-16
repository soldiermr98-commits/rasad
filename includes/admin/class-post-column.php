<?php
/**
 * Rasad views column in the posts/pages list tables.
 *
 * Shows each post's total pageviews (rsd_daily_pages) in a sortable,
 * unobtrusive column. Cached per post for 15 minutes.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rasad post views column.
 */
final class Rasad_Admin_Post_Column {

	/** Column key. */
	const COLUMN = 'rasad_views';

	/** Transient TTL for per-post view counts (seconds). */
	const TTL = 900;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'add_column' ), 15 );
		add_filter( 'manage_post_posts_custom_column', array( __CLASS__, 'render_column' ), 15, 3 );

		// Page post type too.
		add_filter( 'manage_page_posts_columns', array( __CLASS__, 'add_column' ), 15 );
		add_filter( 'manage_page_posts_custom_column', array( __CLASS__, 'render_column' ), 15, 3 );

		// Make the column sortable.
		add_filter( 'manage_edit-post_sortable_columns', array( __CLASS__, 'make_sortable' ) );
		add_filter( 'manage_edit-page_sortable_columns', array( __CLASS__, 'make_sortable' ) );

		// Orderby handling.
		add_action( 'pre_get_posts', array( __CLASS__, 'orderby' ) );
	}

	/**
	 * Adds the Rasad views column header.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			return $columns;
		}

		$out    = array();
		$added  = false;

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key && ! $added ) {
				$out[ self::COLUMN ] = __( 'بازدیدها', 'rasad' );
				$added             = true;
			}
		}

		if ( ! $added ) {
			$out[ self::COLUMN ] = __( 'بازدیدها', 'rasad' );
		}

		return $out;
	}

	/**
	 * Renders the cell content for one post.
	 *
	 * @param string  $column    Column name.
	 * @param int     $post_id   Post id.
	 * @return string
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return '';
		}

		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			return '';
		}

		$views = self::get_post_views( (int) $post_id );

		if ( 0 === $views ) {
			return '<span class="rasad-views-zero">—</span>';
		}

		return number_format_i18n( $views );
	}

	/**
	 * Makes the column sortable.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array
	 */
	public static function make_sortable( $columns ) {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = 'rasad_views';

		return $columns;
	}

	/**
	 * Applies ordering when the Rasad column is the sort key.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public static function orderby( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'rasad_views' !== $orderby ) {
			return;
		}

		global $wpdb;

		$post_type = $query->get( 'post_type' );

		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$daily_pages = Rasad_Installer::table( 'daily_pages' );
		$posts       = $wpdb->posts;

		$direction = 'DESC' === strtoupper( $query->get( 'order' ) ) ? 'DESC' : 'ASC';

		$query->set( 'meta_key', '' );
		$query->set( 'orderby', '' );
		$query->set( 'order', '' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from wpdb.
		$subquery = '(SELECT SUM(dp.views) FROM ' . $daily_pages . ' dp WHERE dp.page_id = ' . $posts . '.ID)';

		add_filter(
			'posts_orderby',
			function ( $sql ) use ( $subquery, $direction ) {
				return $subquery . ' ' . $direction;
			}
		);
	}

	/**
	 * Returns the all-time view count for one post.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private static function get_post_views( $post_id ) {
		$key = 'rasad_post_views_' . $post_id;

		$value = get_transient( $key );

		if ( false !== $value ) {
			return (int) $value;
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$daily_pages = Rasad_Installer::table( 'daily_pages' );
		$pages       = Rasad_Installer::table( 'pages' );

		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(dp.views), 0) FROM ' . $daily_pages . ' dp INNER JOIN ' . $pages . ' p ON p.id = dp.page_id WHERE p.post_id = %d',
				$post_id
			)
		);

		$views = (int) $row;

		set_transient( $key, $views, self::TTL );

		return $views;
	}
}
