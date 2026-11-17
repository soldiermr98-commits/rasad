<?php
/**
 * APCu queue: individual hit entries with serialized metadata updates.
 * Volatile storage: eviction, cache clears and pool restarts can lose hits.
 *
 * @package Rasad
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rasad_Buffer_Apcu implements Rasad_Buffer_Interface {
	const KEY = 'rasad_buffer_apcu';

	private function key() {
		global $wpdb;
		$scope = ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( isset( $wpdb->prefix ) ? $wpdb->prefix : '' ) . '|' . ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 );
		return self::KEY . ':' . hash( 'sha256', $scope );
	}

	public function is_available() {
		return function_exists( 'apcu_enabled' ) && apcu_enabled()
			&& function_exists( 'apcu_fetch' ) && function_exists( 'apcu_store' ) && function_exists( 'apcu_delete' );
	}

	private function lock() {
		// OS locks cannot expire underneath a paused worker and release on exit.
		$fh = @fopen( sys_get_temp_dir() . '/rasad-apcu-' . hash( 'sha256', $this->key() ) . '.lock', 'c' );
		if ( false === $fh ) {
			return false;
		}
		$deadline = microtime( true ) + 0.1;
		do {
			if ( flock( $fh, LOCK_EX | LOCK_NB ) ) {
				return $fh;
			}
			usleep( 1000 );
		} while ( microtime( true ) < $deadline );
		fclose( $fh );
		return false;
	}

	private function state() {
		$state = apcu_fetch( $this->key(), $success );
		// A generation avoids reusing orphan entry keys after metadata eviction.
		return $success && is_array( $state ) ? $state : array( 'generation' => uniqid( '', true ), 'head' => 0, 'tail' => 0 );
	}

	private function item_key( $state, $id ) {
		return $this->key() . ':' . $state['generation'] . ':' . $id;
	}

	public function push( array $hit ) {
		if ( ! $this->is_available() || false === ( $lock = $this->lock() ) ) {
			return false;
		}
		try {
			$state = $this->state();
			$key = $this->item_key( $state, $state['tail'] );
			if ( ! apcu_store( $key, $hit, 0 ) ) {
				return false;
			}
			++$state['tail'];
			if ( ! apcu_store( $this->key(), $state, 0 ) ) {
				apcu_delete( $key );
				return false;
			}
			return true;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	public function pop_batch( $limit ) {
		return $this->read_batch( $limit, true );
	}

	public function peek_batch( $limit ) {
		return $this->read_batch( $limit, false );
	}

	private function read_batch( $limit, $remove ) {
		$batch = array();
		if ( ! $this->is_available() || false === ( $lock = $this->lock() ) ) {
			return $batch;
		}
		try {
			$state = $this->state();
			$keys = array();
			$limit = max( 1, (int) $limit );
			while ( $state['head'] < $state['tail'] && count( $batch ) < $limit ) {
				$key = $this->item_key( $state, $state['head']++ );
				$hit = apcu_fetch( $key, $success );
				$keys[] = $key;
				if ( $success && is_array( $hit ) ) {
					$batch[] = $hit;
				}
			}
			if ( $remove ) {
				if ( ! apcu_store( $this->key(), $state, 0 ) ) {
					return array();
				}
				foreach ( $keys as $key ) {
					apcu_delete( $key );
				}
			}
			return $batch;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	public function count() {
		if ( ! $this->is_available() ) {
			return 0;
		}
		$state = $this->state();
		return max( 0, $state['tail'] - $state['head'] );
	}
}
