<?php
/**
 * Buffer driver contract.
 *
 * Every driver moves raw hits from the collect hot path to the aggregator:
 * push() must be as cheap as possible; pop_batch()/count() are called from
 * the aggregator and health checks only.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface implemented by all buffer drivers.
 */
interface Rasad_Buffer_Interface {

	/**
	 * Appends one hit to the buffer.
	 *
	 * @param array<string,mixed> $hit Normalized hit array (see Rasad_Collect_Request).
	 * @return bool True when the hit was persisted.
	 */
	public function push( array $hit );

	/**
	 * Removes up to $limit hits from the buffer and returns them in order.
	 *
	 * @param int $limit Maximum number of hits to return.
	 * @return array<int,array<string,mixed>> Decoded hits (empty when the buffer is empty).
	 */
	public function pop_batch( $limit );

	/**
	 * Approximate number of buffered hits (used by the aggregator + health check).
	 *
	 * @return int
	 */
	public function count();

	/**
	 * Whether this driver can run on the current host.
	 *
	 * @return bool
	 */
	public function is_available();
}
