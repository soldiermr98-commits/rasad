<?php
/**
 * Local WooCommerce analytics. No customer fields, cookies, IP or UA are persisted.
 *
 * @package Rasad
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rasad_Integrations_WooCommerce {
	const EVENT_PRODUCT_VIEW = 3;
	const EVENT_ADD_TO_CART = 4;
	const EVENT_CHECKOUT_START = 5;
	const EVENT_PURCHASE = 6;
	private static $registered = false;

	/** Register only after WooCommerce loaded. */
	public static function hooks() {
		if ( self::$registered || ! self::is_woocommerce_active() ) {
			return;
		}
		self::$registered = true;
		add_action( 'wp_head', array( __CLASS__, 'product_tracker_config' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'track_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'track_checkout_start' ), 10 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'capture_order_attribution' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'capture_order_attribution' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'track_purchase' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'track_purchase' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'track_purchase' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'track_purchase_from_thankyou' ), 10, 1 );
	}

	public static function is_woocommerce_active() {
		return function_exists( 'WC' ) && did_action( 'woocommerce_loaded' ) > 0;
	}

	/** Cacheable product identity: actual views are counted from client beacons. */
	public static function product_tracker_config() {
		if ( is_product() ) {
			echo '<script>window.rasadProduct=' . (int) get_queried_object_id() . ';</script>';
		}
	}

	public static function track_product_view() {
		if ( ! is_product() ) {
			return;
		}
		// At `wp`, the global product is not guaranteed to have been set yet.
		$product = wc_get_product( get_queried_object_id() );
		if ( $product instanceof WC_Product ) {
			self::push_event( 3, array( 'wc_product_id' => $product->get_id(), 'wc_value' => $product->get_price() ) );
		}
	}

	/** WooCommerce passes cart key FIRST, then product id and quantity. */
	public static function track_add_to_cart( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		self::push_event( 4, array(
			'wc_product_id' => (int) ( $variation_id ? $variation_id : $product_id ),
			'wc_quantity' => max( 0, (int) $quantity ),
			'wc_value' => $product instanceof WC_Product ? (float) $product->get_price() * $quantity : 0,
		) );
	}

	public static function track_checkout_start() {
		if ( ! is_checkout() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			return;
		}
		$cart = WC()->cart;
		self::push_event( 5, array( 'wc_value' => $cart ? $cart->get_total( 'edit' ) : 0 ) );
	}

	/** Persist a minimal checkout snapshot using the WC CRUD API (HPOS safe). */
	public static function capture_order_attribution( $order, $data = array() ) {
		if ( ! $order instanceof WC_Order || '' !== $order->get_meta( '_rasad_attribution', true ) ) {
			return;
		}
		$context = self::context();
		if ( $context ) {
			global $wpdb;
			$context['wc_referrer_id'] = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT referrer_id FROM ' . Rasad_Installer::table( 'sessions' ) . ' WHERE visitor_key=UNHEX(%s) AND last_seen>=%d ORDER BY last_seen DESC LIMIT 1',
				$context['vk'], time() - 1800
			) );
			// No persistent visitor identifier on the order.
			unset( $context['vk'] );
		}
		$order->update_meta_data( '_rasad_attribution', $context ? $context : array( 'excluded' => 1 ) );
	}

	public static function track_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->is_paid() ) {
			return;
		}
		$context = $order->get_meta( '_rasad_attribution', true );
		// A webhook must never inherit its own server/browser attribution.
		// Legacy/admin orders without a checkout snapshot are direct/unknown.
		if ( is_array( $context ) && ! empty( $context['excluded'] ) ) {
			return;
		}
		if ( ! apply_filters( 'rasad_should_track', true, array( 'e' => 6, 'wc_order_id' => (int) $order_id ) ) ) {
			return;
		}
		$paid = $order->get_date_paid();
		$hit = array_merge( is_array( $context ) ? $context : array(), array(
			'e' => 6, 'p' => '/checkout/', 'ts' => $paid ? $paid->getTimestamp() : time(),
			'wc_order_id' => (int) $order_id, 'wc_value' => (string) $order->get_total(),
			'wc_currency' => (string) $order->get_currency(), 'wc_items' => array(),
		) );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$hit['wc_items'][] = array(
				'product_id' => (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ),
				'quantity' => max( 0, (int) $item->get_quantity() ), 'value' => (string) $item->get_total(),
			);
		}
		// Durable outbox + permanent order-ID ledger. INSERT IGNORE is atomic
		// across payment, status, thank-you and concurrent webhook requests.
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . Rasad_Installer::table( 'wc_orders' ) . ' (order_id,payload,processed,created_at) VALUES (%d,%s,0,%d)',
			(int) $order_id, wp_json_encode( $hit ), time()
		) );
	}

	public static function track_purchase_from_thankyou( $order_id ) {
		self::track_purchase( $order_id );
	}

	/** Request context contains only the rotating key and a referrer domain. */
	private static function context() {
		$settings = Rasad_Settings::all();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '/';
		if ( ! empty( $settings['honor_dnt'] ) && isset( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) {
			return false;
		}
		if ( is_preview() || is_customize_preview() || ( ! empty( $settings['filter_bots'] ) && Rasad_Collect_Visitor::is_bot( $ua ) ) || ! apply_filters( 'rasad_should_track', true, array( 'p' => $path ) ) ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( $user && array_intersect( isset( $settings['excluded_roles'] ) ? (array) $settings['excluded_roles'] : array(), (array) $user->roles ) ) {
			return false;
		}
		foreach ( isset( $settings['excluded_paths'] ) ? (array) $settings['excluded_paths'] : array() as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $path, $pattern ) ) {
				return false;
			}
		}
		foreach ( isset( $settings['excluded_uas'] ) ? (array) $settings['excluded_uas'] : array() as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
				return false;
			}
		}
		foreach ( isset( $settings['excluded_ips'] ) ? (array) $settings['excluded_ips'] : array() as $pattern ) {
			if ( self::ip_matches( $ip, $pattern ) ) {
				return false;
			}
		}
		$vk = Rasad_Collect_Visitor::key( $ip, $ua );
		if ( '' === $vk ) {
			return false;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? parse_url( (string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) : '';
		$ref = is_string( $ref ) ? strtolower( $ref ) : '';
		if ( $ref === strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$ref = '';
		}
		return array( 'vk' => $vk, 'p' => substr( $path ? $path : '/', 0, 500 ), 'r' => $ref ? 'https://' . substr( $ref, 0, 253 ) . '/' : '' );
	}

	/** Exact IP / CIDR exclusions without storing the address. */
	private static function ip_matches( $ip, $pattern ) {
		$parts = explode( '/', trim( (string) $pattern ), 2 );
		if ( 1 === count( $parts ) ) {
			return $ip === $parts[0];
		}
		$a = @inet_pton( $ip );
		$b = @inet_pton( $parts[0] );
		$bits = (int) $parts[1];
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) || $bits < 0 || $bits > strlen( $a ) * 8 ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rest = $bits % 8;
		return substr( $a, 0, $bytes ) === substr( $b, 0, $bytes ) && ( 0 === $rest || ( ord( $a[$bytes] ) & ( 255 << ( 8 - $rest ) ) ) === ( ord( $b[$bytes] ) & ( 255 << ( 8 - $rest ) ) ) );
	}

	private static function push_event( $event, array $data ) {
		$context = self::context();
		if ( false === $context ) {
			return;
		}
		$hit = array_merge( $context, $data, array( 'e' => $event, 'ts' => time(), 'wc_currency' => get_woocommerce_currency() ) );
		if ( Rasad_Plugin::buffer()->push( $hit ) ) {
			do_action( 'rasad_after_hit', $hit );
		}
	}
}
