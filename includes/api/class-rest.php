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
	 * Registers routes: the beacon fallback + the secret-key server-cron route.
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

		register_rest_route(
			self::NS,
			'/cron',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_cron' ),
					'permission_callback' => array( __CLASS__, 'cron_permission' ),
				),
			)
		);
	}

	/**
	 * Validates the secret key of the server-cron route.
	 *
	 * The key is derived (HMAC of the auth salt + site host) — nothing new
	 * is stored; admins get the ready URL from
	 * Rasad_Process_Aggregator::cron_url() (health panel, later phase).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public static function cron_permission( $request ) {
		$key = is_object( $request ) && method_exists( $request, 'get_param' )
			? (string) $request->get_param( 'key' )
			: '';

		if ( '' !== $key && hash_equals( Rasad_Process_Aggregator::cron_key(), $key ) ) {
			return true;
		}

		return new WP_Error(
			'rasad_forbidden',
			__( 'کلید دسترسی نامعتبر است.', 'rasad' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Real server cron entry: aggregate + cleaned, JSON stats back.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_cron() {
		$aggregate = Rasad_Process_Aggregator::run();
		$clean     = Rasad_Process_Cleaner::maybe_run();

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'aggregate' => $aggregate,
				'clean'     => is_array( $clean ) ? $clean : false,
			),
			200
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
