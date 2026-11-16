<?php
/**
 * WooCommerce integration (Phase 8).
 *
 * Loaded only when WooCommerce is active. Pushes e-commerce events
 * (product view, add-to-cart, checkout start, purchase) to the Rasad
 * buffer so they flow through the normal aggregation pipeline into
 * rsd_events. Zero overhead when WooCommerce is absent.
 *
 * Event types (Rasad_Process_Referrer constants not reused — these are
 * separate WooCommerce event codes stored in rsd_events.type):
 *   3 = product view
 *   4 = add to cart
 *   5 = checkout started
 *   6 = purchase
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rasad WooCommerce integration.
 */
final class Rasad_Integrations_WooCommerce {

	/**
	 * Event type: product view.
	 */
	const EVENT_PRODUCT_VIEW  = 3;

	/**
	 * Event type: add to cart.
	 */
	const EVENT_ADD_TO_CART   = 4;

	/**
	 * Event type: checkout started.
	 */
	const EVENT_CHECKOUT_START = 5;

	/**
	 * Event type: purchase.
	 */
	const EVENT_PURCHASE      = 6;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Registers hooks — only when WooCommerce is active.
	 *
	 * @return void
	 */
	public static function hooks() {
		if ( self::$registered ) {
			return;
		}

		if ( ! self::is_woocommerce_active() ) {
			return;
		}

		self::$registered = true;

		// Product view: when a single product page is rendered.
		add_action( 'wp', array( __CLASS__, 'track_product_view' ), 12 );

		// Add to cart.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'track_add_to_cart' ), 10, 2 );

		// Checkout started.
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'track_checkout_start' ), 10 );

		// Purchase completed (order pay + thankyou page both covered).
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'track_purchase' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'track_purchase_from_thankyou' ), 10, 1 );
	}

	/**
	 * Whether WooCommerce is active and loaded.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return ( function_exists( 'WC' ) && did_action( 'woocommerce_loaded' ) > 0 );
	}

	/**
	 * Product view handler.
	 *
	 * @return void
	 */
	public static function track_product_view() {
		if ( ! is_product() ) {
			return;
		}

		global $product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		self::push_event(
			self::EVENT_PRODUCT_VIEW,
			array(
				'product_id' => $product->get_id(),
				'value'      => (float) $product->get_price(),
			)
		);
	}

	/**
	 * Add to cart handler.
	 *
	 * @param int    $product_id Product id.
	 * @param int    $cart_item_key Cart item key.
	 * @return void
	 */
	public static function track_add_to_cart( $product_id, $cart_item_key ) {
		global $woocommerce;

		$value = 0.0;

		if ( is_a( $woocommerce, 'WC_Cart' ) ) {
			$cart_item = $woocommerce->cart->get_cart_item( $cart_item_key );

			if ( is_array( $cart_item ) && isset( $cart_item['data'] ) ) {
				$value = (float) $cart_item['data']->get_price();
			}
		}

		self::push_event(
			self::EVENT_ADD_TO_CART,
			array(
				'product_id' => (int) $product_id,
				'value'      => $value,
			)
		);
	}

	/**
	 * Checkout started handler.
	 *
	 * @return void
	 */
	public static function track_checkout_start() {
		if ( ! is_checkout() ) {
			return;
		}

		global $woocommerce;

		$value = 0.0;

		if ( is_a( $woocommerce, 'WC_Cart' ) ) {
			$value = (float) $woocommerce->cart->get_total();
		}

		self::push_event(
			self::EVENT_CHECKOUT_START,
			array( 'value' => $value )
		);
	}

	/**
	 * Purchase completed handler (payment_complete hook).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function track_purchase( $order_id ) {
		self::track_purchase_order( $order_id );
	}

	/**
	 * Purchase completed handler (thankyou page hook).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function track_purchase_from_thankyou( $order_id ) {
		self::track_purchase_order( $order_id );
	}

	/**
	 * Shared purchase tracking logic (deduplicated by order id within a
	 * single request).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function track_purchase_order( $order_id ) {
		static $recorded = array();

		if ( isset( $recorded[ $order_id ] ) ) {
			return;
		}
		$recorded[ $order_id ] = true;

		$order = wc_get_order( $order_id );

		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		self::push_event(
			self::EVENT_PURCHASE,
			array(
				'order_id' => (int) $order_id,
				'value'    => (float) $order->get_total(),
			)
		);
	}

	/**
	 * Pushes one WooCommerce event to the buffer.
	 *
	 * @param int    $event  Event type constant.
	 * @param array  $data   Event data (product_id, order_id, value, …).
	 * @return void
	 */
	private static function push_event( $event, array $data = array() ) {
		if ( ! class_exists( 'Rasad_Plugin' ) ) {
			return;
		}

		$buffer = Rasad_Plugin::buffer();

		if ( ! $buffer->is_available() ) {
			return;
		}

		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';

		// Derive a visitor key so the aggregator can match the event to a
		// session (same algorithm as the collect hot path).
		$vk = '';

		if ( class_exists( 'Rasad_Collect_Visitor' ) ) {
			$vk = Rasad_Collect_Visitor::key( $ip, $ua );
		}

		$hit = array(
			'e'             => (int) $event,
			'p'             => $path,
			'ts'            => time(),
			'ua'            => $ua,
			'ip'            => $ip,
			'vk'            => $vk,
			'wc_product_id' => isset( $data['product_id'] ) ? (int) $data['product_id'] : 0,
			'wc_order_id'   => isset( $data['order_id'] ) ? (int) $data['order_id'] : 0,
			'wc_value'      => isset( $data['value'] ) ? (float) $data['value'] : 0.0,
		);

		$buffer->push( $hit );
	}
}
