<?php
/** Streamed summary CSV exports. @package Rasad */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rasad_Admin_Export {
	/** Capability and nonce gated admin-post download; never exports raw visitors. */
	public static function handle() {
		if ( ! current_user_can( Rasad_Settings::capability() ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی ندارید.', 'rasad' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rasad_csv' );
		$request = new WP_REST_Request( 'GET' );
		foreach ( array( 'from', 'to', 'preset' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$request->set_param( $key, sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) );
			}
		}
		$range = Rasad_Api_Rest::parse_range( $request );
		$report = isset( $_GET['report'] ) && is_string( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : 'daily';
		$schemas = array(
			'daily' => array( 'day', 'views', 'visitors', 'sessions', 'bounces', 'total_duration' ),
			'pages' => array( 'day', 'page_id', 'path', 'title', 'post_id', 'post_type', 'views', 'visitors', 'entries', 'exits' ),
			'dimensions' => array( 'day', 'dim', 'dim_id', 'views', 'visitors' ),
			'woo' => array( 'day', 'scope', 'object_id', 'currency', 'product_views', 'add_to_cart', 'checkouts', 'orders', 'quantity', 'revenue' ),
		);
		if ( ! isset( $schemas[ $report ] ) || ( 'woo' === $report && ! class_exists( 'WooCommerce' ) ) ) {
			wp_die( esc_html__( 'گزارش نامعتبر است.', 'rasad' ), '', array( 'response' => 400 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="rasad-' . $report . '-' . $range['from'] . '-' . $range['to'] . '.csv"' );
		// Discard plugin/admin output buffers so rows reach the client progressively.
		while ( ob_get_level() > 0 ) { if ( ! ob_end_clean() ) { break; } }
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) { exit; }
		fwrite( $out, "\xEF\xBB\xBF" );
		self::row( $out, $schemas[ $report ] );
		global $wpdb;
		$names = array( 'daily' => 'daily', 'pages' => 'daily_pages', 'dimensions' => 'daily_dim', 'woo' => 'wc_daily' );
		$table = Rasad_Installer::table( $names[ $report ] );
		$pages = Rasad_Installer::table( 'pages' );
		// Keyset pagination follows each summary primary key; no growing OFFSET scan.
		$keys = array( 'daily' => array( 'day' ), 'pages' => array( 'day', 'page_id' ), 'dimensions' => array( 'day', 'dim', 'dim_id' ), 'woo' => array( 'day', 'scope', 'object_id', 'currency' ) );
		$columns = array();
		foreach ( $schemas[ $report ] as $column ) {
			$columns[] = ( in_array( $column, array( 'path', 'title', 'post_id', 'post_type' ), true ) ? 'p.' : 's.' ) . $column;
		}
		if ( 'pages' === $report ) {
			$columns = array( 's.day', 's.page_id', 'p.path', 'p.title', 'p.post_id', 'p.post_type', 's.views', 's.visitors', 's.entries', 's.exits' );
		}
		$last = null;
		do {
			$args = array( $range['from'], $range['to'] );
			$where = 's.day >= %s AND s.day <= %s';
			if ( null !== $last ) {
				$parts = array();
				foreach ( $keys[ $report ] as $i => $key ) {
					$term = array();
					for ( $j = 0; $j <= $i; $j++ ) {
						$k = $keys[ $report ][ $j ];
						$term[] = 's.' . $k . ( $j === $i ? ' > %s' : ' = %s' );
						$args[] = $last[ $k ];
					}
					$parts[] = '(' . implode( ' AND ', $term ) . ')';
				}
				$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
			}
			$order = 's.' . implode( ',s.', $keys[ $report ] );
			$join = 'pages' === $report ? " LEFT JOIN {$pages} p ON p.id=s.page_id" : '';
			$sql = 'SELECT ' . implode( ',', $columns ) . " FROM {$table} s{$join} WHERE {$where} ORDER BY {$order} LIMIT 500";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			foreach ( (array) $rows as $row ) { self::row( $out, array_values( $row ) ); $last = $row; }
			fflush( $out );
			flush();
		} while ( count( (array) $rows ) === 500 && ! connection_aborted() );
		fclose( $out );
		exit;
	}

	/** Protect cells from spreadsheet formulas while preserving CSV quoting. */
	public static function row( $stream, array $cells ) {
		foreach ( $cells as &$cell ) {
			$cell = (string) $cell;
			if ( preg_match( '/^[\s\x00-\x1f]*[=+@-]/u', $cell ) || preg_match( '/^[\t\r\n]/', $cell ) ) { $cell = "'" . $cell; }
		}
		unset( $cell );
		fputcsv( $stream, $cells, ',', '"', '' );
	}
}
