<?php
/**
 * Redis buffer driver — distributed, high-throughput, for high-traffic hosts.
 *
 * Hits are pushed onto a Redis list (`rasad:buffer`) and consumed from the
 * head by the aggregator. Requires the PHP `redis` extension. Falls back
 * gracefully when Redis is unreachable or the extension is missing.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redis-based buffer driver.
 */
final class Rasad_Buffer_Redis implements Rasad_Buffer_Interface {

	/**
	 * Redis list key holding the hit queue.
	 *
	 * @var string
	 */
	const KEY = 'rasad:buffer';

	/**
	 * Redis instance, or null when unavailable.
	 *
	 * @var Redis|null
	 */
	private $redis = null;

	/**
	 * Whether the driver can operate on this host.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! class_exists( 'Redis' ) ) {
			return false;
		}

		if ( null !== $this->redis ) {
			return true;
		}

		$redis = new Redis();

		try {
			// Short timeout: never block the collect hot path.
			$connected = @$redis->connect( '127.0.0.1', 6379, 0.5 );
		} catch ( Throwable $e ) {
			$connected = false;
		}

		if ( ! $connected ) {
			return false;
		}

		$this->redis = $redis;

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function push( array $hit ) {
		if ( ! $this->is_available() ) {
			return false;
		}

		$encoded = wp_json_encode( $hit );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		try {
			return (bool) $this->redis->rPush( self::KEY, $encoded );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function pop_batch( $limit ) {
		$limit = max( 1, (int) $limit );
		$batch = array();

		if ( ! $this->is_available() ) {
			return $batch;
		}

		try {
			for ( $i = 0; $i < $limit; $i++ ) {
				$encoded = $this->redis->lPop( self::KEY );

				if ( false === $encoded || null === $encoded ) {
					break;
				}

				$hit = json_decode( (string) $encoded, true );

				if ( is_array( $hit ) ) {
					$batch[] = $hit;
				}
			}
		} catch ( Throwable $e ) {
			return $batch;
		}

		return $batch;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count() {
		if ( ! $this->is_available() ) {
			return 0;
		}

		try {
			$count = $this->redis->lLen( self::KEY );

			return is_int( $count ) ? (int) $count : 0;
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Closes the Redis connection when the driver is done.
	 *
	 * @return void
	 */
	public function __destruct() {
		if ( $this->redis instanceof Redis ) {
			@$this->redis->close();
		}
	}
}
