<?php
/** Standalone focused commerce regressions: php tests/test-woocommerce-integration.php. */
$GLOBALS['woo_hooks'] = array();
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['woo_hooks'][$tag] = array( $callback, $accepted_args ); }
function WC() { return null; }
function did_action( $tag ) { return 'woocommerce_loaded' === $tag ? 1 : 0; }
require_once __DIR__ . '/bootstrap.php';
Rasad_Integrations_WooCommerce::hooks();
test( 'cart hook accepts all six official arguments', 6 === $GLOBALS['woo_hooks']['woocommerce_add_to_cart'][1] );
test( 'product views use cacheable browser config', isset( $GLOBALS['woo_hooks']['wp_head'] ) && ! isset( $GLOBALS['woo_hooks']['wp'] ) );
test( 'Blocks checkout captures order attribution', isset( $GLOBALS['woo_hooks']['woocommerce_store_api_checkout_update_order_meta'] ) );

class Rasad_Woo_Test_DB {
	public $prefix = 'wp_';
	public $insert_id = 10;
	public $queries = array();
	public $ledger = array();
	public $fail_summary = false;
	private $snapshot;
	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) { $args = $args[0]; }
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$args ) {
			$value = array_shift( $args );
			return '%s' === $m[0] ? "'" . str_replace( "'", "''", (string) $value ) . "'" : (string) (int) $value;
		}, $sql );
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->ledger; }
		if ( 'ROLLBACK' === $sql ) { $this->ledger = $this->snapshot; }
		if ( $this->fail_summary && false !== strpos( $sql, 'INSERT INTO wp_rsd_wc_daily' ) ) { return false; }
		if ( preg_match( '/INSERT IGNORE INTO wp_rsd_wc_orders .*VALUES \((\d+),/', $sql, $m ) ) {
			if ( isset( $this->ledger[$m[1]] ) ) { return 0; }
			$this->ledger[$m[1]] = 0;
		}
		if ( preg_match( '/SET processed=1.*order_id=(\d+)/', $sql, $m ) ) { $this->ledger[$m[1]] = 1; }
		return 1;
	}
	public function get_row( $sql ) { return (object) array( 'id' => 10, 'title' => 'Checkout' ); }
	public function get_var( $sql ) {
		if ( preg_match( '/WHERE order_id=(\d+) FOR UPDATE/', $sql, $m ) ) { return isset( $this->ledger[$m[1]] ) ? $this->ledger[$m[1]] : null; }
		return 0;
	}
	public function get_results( $sql ) {
		if ( false !== strpos( $sql, 'SELECT order_id,payload' ) && isset( $this->ledger[42] ) && 0 === $this->ledger[42] ) {
			return array( (object) array( 'order_id' => 42, 'payload' => json_encode( $GLOBALS['woo_hit'] ) ) );
		}
		return array();
	}
}
class WC_Order {
	public $paid = true;
	public function is_paid() { return $this->paid; }
	public function get_meta( $key, $single ) { return array( 'wc_referrer_id' => 7 ); }
	public function get_date_paid() { return new DateTimeImmutable( '@1700000000' ); }
	public function get_total() { return '29.95'; }
	public function get_currency() { return 'USD'; }
	public function get_items( $type ) { return array(); }
}
function wc_get_order( $id ) { return $GLOBALS['woo_order']; }
$GLOBALS['wpdb'] = new Rasad_Woo_Test_DB();
$GLOBALS['woo_order'] = new WC_Order();
Rasad_Integrations_WooCommerce::track_purchase( 42 );
Rasad_Integrations_WooCommerce::track_purchase_from_thankyou( 42 );
test( 'duplicate hooks retain one durable order ledger row', count( $GLOBALS['wpdb']->ledger ) === 1 );
$GLOBALS['woo_order']->paid = false;
Rasad_Integrations_WooCommerce::track_purchase( 43 );
test( 'unpaid thankyou cannot record a purchase', ! isset( $GLOBALS['wpdb']->ledger[43] ) );

$GLOBALS['woo_hit'] = array( 'e' => 6, 'ts' => 1700000000, 'p' => '/checkout/', 'wc_order_id' => 42, 'wc_value' => '29.95', 'wc_currency' => 'USD', 'wc_referrer_id' => 7,
	'wc_items' => array( array( 'product_id' => 99, 'quantity' => 2, 'value' => '10.00' ), array( 'product_id' => 99, 'quantity' => 1, 'value' => '5.00' ) ) );
$drain = new ReflectionMethod( 'Rasad_Process_Aggregator', 'drain_orders' );
$drain->setAccessible( true );
$GLOBALS['wpdb']->fail_summary = true;
try { $drain->invoke( null, 10 ); } catch ( Throwable $e ) {}
test( 'failed summary rolls back purchase ledger marker', 0 === $GLOBALS['wpdb']->ledger[42] );
$GLOBALS['wpdb']->fail_summary = false;
$GLOBALS['wpdb']->queries = array();
test( 'pending purchase can retry after failure', 1 === $drain->invoke( null, 10 ) );
test( 'success marks purchase processed', 1 === $GLOBALS['wpdb']->ledger[42] );
test( 'processed purchase does not replay', 0 === $drain->invoke( null, 10 ) );
$summaries = array_values( array_filter( $GLOBALS['wpdb']->queries, function ( $sql ) { return false !== strpos( $sql, 'INSERT INTO wp_rsd_wc_daily' ); } ) );
test( 'purchase writes overall referrer and one coalesced product summary', 3 === count( $summaries ) );
test( 'product orders count once and quantity and line totals coalesce', false !== strpos( $summaries[2], "1,99,'USD',0,0,0,1,3,'15.000000'" ) );
test( 'overall revenue and referrer remain attributed', false !== strpos( $summaries[1], "2,7,'USD',0,0,0,1,3,'29.950000'" ) );
$batch = new ReflectionMethod( 'Rasad_Process_Aggregator', 'process_batch' );
$batch->setAccessible( true );
$hit = array( 'e' => 3, 'ts' => time(), 'p' => '/product/example/', 'vk' => '1234567890abcdef', 'wc_product_id' => 99, 'wc_currency' => 'USD' );
test( 'event-only batch is persisted and counted', 1 === $batch->invoke( null, array( $hit ) ) );
test( 'second batch does not retain earlier events', 0 === $batch->invoke( null, array() ) );
exit( $GLOBALS['rasad_test_failures'] ? 1 : 0 );
