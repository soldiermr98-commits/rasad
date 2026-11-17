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

	/** Site-scoped key; recomputed after switch_to_blog(). */
	private function key() {
		global $wpdb;
		$scope = ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( isset( $wpdb->prefix ) ? $wpdb->prefix : '' ) . '|' . ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 );
		return self::KEY . ':' . hash( 'sha256', $scope );
	}

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
			return (bool) $this->redis->rPush( $this->key(), $encoded );
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
			// Redis executes the read and trim together; concurrent producers and
			// consumers cannot interleave. One round trip regardless of batch size.
			$script = 'local items = redis.call("LRANGE", KEYS[1], 0, ARGV[1] - 1); redis.call("LTRIM", KEYS[1], #items, -1); return items';
			$items = $this->redis->eval( $script, array( $this->key(), $limit ), 1 );
			foreach ( is_array( $items ) ? $items : array() as $encoded ) {
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
	/**
	 * {@inheritDoc}
	 *
	 * Reads without modifying the list.
	 */
	public function peek_batch( $limit ) {
		$limit = max( 1, (int) $limit );
		$batch = array();

		if ( ! $this->is_available() ) {
			return $batch;
		}

		try {
			$encoded = $this->redis->lRange( $this->key(), 0, $limit - 1 );

			if ( is_array( $encoded ) ) {
				foreach ( $encoded as $item ) {
					$hit = json_decode( (string) $item, true );

					if ( is_array( $hit ) ) {
						$batch[] = $hit;
					}
				}
			}
		} catch ( Throwable $e ) {
			return $batch;
		}

		return $batch;
	}

	public function count() {
		if ( ! $this->is_available() ) {
			return 0;
		}

		try {
			$count = $this->redis->lLen( $this->key() );

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
			try {
				@$this->redis->close();
			} catch ( Throwable $e ) {
				// Shutdown must remain safe after a server disconnect.
			}
		}
	}
}
