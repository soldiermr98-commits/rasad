<?php
/**
 * APCu buffer driver — shared memory, fast, for mid-to-high traffic hosts.
 *
 * Hits are kept as a serialized array under a single APCu key. APCu lives
 * in shared RAM, so the buffer survives across requests within the same
 * PHP-FPM pool but is lost when the pool restarts — acceptable for a
 * transient buffer that the aggregator drains every minute.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APCu-based buffer driver.
 */
final class Rasad_Buffer_Apcu implements Rasad_Buffer_Interface {

	/**
	 * APCu key under which the hit queue is stored.
	 *
	 * @var string
	 */
	const KEY = 'rasad_buffer_apcu';

	/**
	 * Whether APCu is usable on this host.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'apcu_fetch' )
			&& function_exists( 'apcu_store' )
			&& (bool) ini_get( 'apcu.enabled' );
	}

	/**
	 * Reads the current hit queue from APCu.
	 *
	 * @return array<int,array<string,mixed>> Hit queue (empty when missing).
	 */
	private function read_queue() {
		$value = apcu_fetch( self::KEY, $success );

		if ( ! $success || ! is_array( $value ) ) {
			return array();
		}

		return $value;
	}

	/**
	 * Writes the hit queue back to APCu.
	 *
	 * @param array<int,array<string,mixed>> $queue Hit queue.
	 * @return bool
	 */
	private function write_queue( array $queue ) {
		return apcu_store( self::KEY, $queue, 0 ); // 0 = no TTL, persists until pool restart.
	}

	/**
	 * {@inheritDoc}
	 */
	public function push( array $hit ) {
		if ( ! $this->is_available() ) {
			return false;
		}

		$queue = $this->read_queue();
		$queue[] = $hit;

		return $this->write_queue( $queue );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Note: APCu read-modify-write is not atomic across processes, so a
	 * concurrent push could theoretically be lost. For the aggregator
	 * (single cron worker at a time) this is acceptable.
	 */
	public function pop_batch( $limit ) {
		$limit = max( 1, (int) $limit );
		$batch = array();

		if ( ! $this->is_available() ) {
			return $batch;
		}

		$queue = $this->read_queue();

		if ( empty( $queue ) ) {
			return $batch;
		}

		$take = array_slice( $queue, 0, $limit );
		$remain = array_slice( $queue, $limit );

		foreach ( $take as $hit ) {
			if ( is_array( $hit ) ) {
				$batch[] = $hit;
			}
		}

		$this->write_queue( $remain );

		return $batch;
	}

	/**
	 * {@inheritDoc}
	 */
	/**
	 * {@inheritDoc}
	 *
	 * Reads without modifying the queue.
	 */
	public function peek_batch( $limit ) {
		$limit = max( 1, (int) $limit );

		if ( ! $this->is_available() ) {
			return array();
		}

		$queue = $this->read_queue();

		return is_array( $queue ) ? array_slice( $queue, 0, $limit ) : array();
	}

	public function count() {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$queue = $this->read_queue();

		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
