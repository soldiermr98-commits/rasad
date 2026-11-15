<?php
/**
 * REST API integration.
 *
 * Phase 2 ships the public beacon fallback route (/wp-json/rasad/v1/hit);
 * dashboard routes are added in a later phase against the same namespace.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 */
final class Rasad_Api_Rest {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'rasad/v1';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the beacon route (GET for the image-pixel fallback, POST for
	 * sendBeacon/fetch). Public by design — validation happens inside
	 * Rasad_Collect_Request (origin, size, bots, exclusions, rate limit).
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/hit',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_hit' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_hit' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Runs a beacon through the shared collect pipeline.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_hit( $request ) {
		$raw = is_object( $request ) && method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';

		if ( '' === $raw && is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$b = $request->get_param( 'b' );

			if ( is_string( $b ) ) {
				// Full WP runs wp_magic_quotes(), so query params are slashed.
				$raw = wp_unslash( $b );
			}
		}

		$code = Rasad_Collect_Request::process( $raw );

		return new WP_REST_Response( null, $code );
	}
}
