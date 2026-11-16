<?php
/**
 * DB buffer driver — fallback when no memory cache is available.
 *
 * Hits are stored in a dedicated `rsd_buffer` table. Slower than APCu or
 * Redis because every push touches the database, but works on any host
 * that runs WordPress.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-based buffer driver.
 */
final class Rasad_Buffer_Db implements Rasad_Buffer_Interface {

	/**
	 * Short table name (without the `{$wpdb->prefix}rsd_` prefix).
	 *
	 * @var string
	 */
	const TABLE = 'buffer';

	/**
	 * Whether the database is usable on this host.
	 *
	 * @return bool
	 */
	public function is_available() {
		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' );
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	private function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'rsd_' . self::TABLE;
	}

	/**
	 * Ensures the buffer table exists (idempotent).
	 *
	 * @return bool
	 */
	private function ensure_table() {
		global $wpdb;

		if ( ! $this->is_available() ) {
			return false;
		}

		$table = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			hit LONGTEXT NOT NULL,
			created_at INT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		$result = $wpdb->query( $sql );

		return false !== $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function push( array $hit ) {
		global $wpdb;

		if ( ! $this->is_available() || ! $this->ensure_table() ) {
			return false;
		}

		$encoded = wp_json_encode( $hit );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix.
		$result = $wpdb->insert(
			$this->table_name(),
			array(
				'hit'         => $encoded,
				'created_at'  => time(),
			),
			array(
				'hit'         => '%s',
				'created_at'  => '%d',
			)
		);

		return false !== $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function pop_batch( $limit ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );
		$batch = array();

		if ( ! $this->is_available() || ! $this->ensure_table() ) {
			return $batch;
		}

		$table = $this->table_name();

		// Take the oldest $limit rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, hit FROM {$table} ORDER BY id ASC LIMIT %d",
				$limit
			)
		);

		if ( empty( $rows ) ) {
			return $batch;
		}

		$ids = array();

		foreach ( (array) $rows as $row ) {
			$hit = json_decode( $row->hit, true );

			if ( is_array( $hit ) ) {
				$batch[] = $hit;
			}

			$ids[] = (int) $row->id;
		}

		if ( empty( $ids ) ) {
			return $batch;
		}

		// Delete consumed rows in batches of 100 to avoid over-long IN() lists.
		foreach ( array_chunk( $ids, 100 ) as $id_chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $id_chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ({$placeholders})",
					$id_chunk
				)
			);
		}

		return $batch;
	}

	/**
	 * {@inheritDoc}
	 */
	/**
	 * {@inheritDoc}
	 *
	 * Reads without deleting rows.
	 */
	public function peek_batch( $limit ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );
		$batch = array();

		if ( ! $this->is_available() || ! $this->ensure_table() ) {
			return $batch;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hit FROM {$table} ORDER BY id ASC LIMIT %d",
				$limit
			)
		);

		foreach ( (array) $rows as $row ) {
			$hit = json_decode( $row->hit, true );

			if ( is_array( $hit ) ) {
				$batch[] = $hit;
			}
		}

		return $batch;
	}

	public function count() {
		global $wpdb;

		if ( ! $this->is_available() || ! $this->ensure_table() ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return is_int( $count ) ? (int) $count : 0;
	}
}
