<?php
/** Standalone: php tests/test-buffer-concurrency.php (no extensions required). */
require_once __DIR__ . '/bootstrap.php';

// File-backed fake APCu models cross-process visibility and atomic per-key stores.
// Actual serialization/coordination belongs to the driver, not this cache fake.
$GLOBALS['buffer_cache_dir'] = __DIR__ . '/.buffer-concurrency/cache';
if ( ! function_exists( 'apcu_fetch' ) ) {
	function apcu_enabled() { return true; }
	function apcu_fetch( $key, &$success = null ) {
		$value = @file_get_contents( $GLOBALS['buffer_cache_dir'] . '/' . hash( 'sha256', $key ) );
		$success = false !== $value;
		return $success ? unserialize( $value ) : false;
	}
	function apcu_store( $key, $value, $ttl = 0 ) {
		if ( ! empty( $GLOBALS['buffer_fail_store'] ) ) { return false; }
		$path = $GLOBALS['buffer_cache_dir'] . '/' . hash( 'sha256', $key );
		$tmp = $path . '.' . uniqid( '', true );
		return false !== file_put_contents( $tmp, serialize( $value ) ) && rename( $tmp, $path );
	}
	function apcu_delete( $key ) {
		return @unlink( $GLOBALS['buffer_cache_dir'] . '/' . hash( 'sha256', $key ) );
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() { return isset( $GLOBALS['buffer_blog'] ) ? $GLOBALS['buffer_blog'] : 1; }
}
if ( ! class_exists( 'Redis' ) ) {
	class Redis {
		public static $lists = array();
		public static $evals = 0;
		public static $fail = false;
		public function connect( $host, $port, $timeout ) { return ! self::$fail; }
		public function close() { return true; }
		public function rPush( $key, $hit ) {
			if ( self::$fail ) { throw new RuntimeException( 'offline' ); }
			self::$lists[ $key ][] = $hit;
			return count( self::$lists[ $key ] );
		}
		public function lRange( $key, $start, $end ) { return array_slice( isset( self::$lists[ $key ] ) ? self::$lists[ $key ] : array(), $start, $end - $start + 1 ); }
		public function lLen( $key ) { return isset( self::$lists[ $key ] ) ? count( self::$lists[ $key ] ) : 0; }
		public function eval( $script, $args, $keys ) {
			++self::$evals;
			if ( false === strpos( $script, 'LRANGE' ) || false === strpos( $script, 'LTRIM' ) || 1 !== $keys ) { throw new RuntimeException( 'invalid script' ); }
			$items = $this->lRange( $args[0], 0, $args[1] - 1 );
			self::$lists[ $args[0] ] = array_slice( isset( self::$lists[ $args[0] ] ) ? self::$lists[ $args[0] ] : array(), count( $items ) );
			return $items;
		}
	}
}

$root = __DIR__ . '/.buffer-concurrency';
if ( isset( $argv[1] ) && 'worker' === $argv[1] ) {
	$driver = 'apcu' === $argv[2] ? new Rasad_Buffer_Apcu() : new Rasad_Buffer_File( $root . '/files' );
	$success = 0;
	for ( $i = 0; $i < 100; ++$i ) {
		if ( $driver->push( array( 'id' => $argv[3] . '-' . $i ) ) ) { ++$success; }
	}
	echo $success;
	exit( 0 );
}

// This directory is exclusively owned by this standalone suite.
function buffer_test_cleanup( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	foreach ( scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) { continue; }
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) { buffer_test_cleanup( $path ); } else { unlink( $path ); }
	}
	rmdir( $dir );
}
buffer_test_cleanup( $root );
mkdir( $root . '/cache', 0755, true );
mkdir( $root . '/files', 0755, true );

foreach ( array( 'file', 'apcu' ) as $kind ) {
	$driver = 'file' === $kind ? new Rasad_Buffer_File( $root . '/files' ) : new Rasad_Buffer_Apcu();
	if ( ! $driver->is_available() ) { echo "SKIP {$kind} unavailable\n"; continue; }
	$workers = array();
	for ( $i = 0; $i < 4; ++$i ) {
		$pipes = array();
		$process = proc_open( array( PHP_BINARY, __FILE__, 'worker', $kind, (string) $i ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'cannot start worker' ); }
		fclose( $pipes[0] );
		$workers[] = array( $process, $pipes );
	}
	$received = array();
	// Consume while producers append: exercises push/pop races and inode stability.
	for ( $i = 0; $i < 20; ++$i ) {
		$received = array_merge( $received, $driver->pop_batch( 7 ) );
		$driver->peek_batch( 3 );
		if ( 'file' === $kind ) { $driver->compact(); }
		usleep( 1000 );
	}
	$acknowledged = 0;
	foreach ( $workers as $worker ) {
		$output = stream_get_contents( $worker[1][1] );
		$errors = stream_get_contents( $worker[1][2] );
		fclose( $worker[1][1] ); fclose( $worker[1][2] );
		test( $kind . ' worker exited cleanly', 0 === proc_close( $worker[0] ) && '' === $errors );
		$acknowledged += (int) $output;
	}
	$before = $driver->count();
	$peek = $driver->peek_batch( 5 );
	test( $kind . ' peek is nondestructive', $before === $driver->count() && count( $peek ) <= 5 );
	while ( $batch = $driver->pop_batch( 23 ) ) { $received = array_merge( $received, $batch ); }
	$ids = array_column( $received, 'id' );
	test( $kind . ' concurrent acknowledgments all delivered once', $acknowledged > 0 && $acknowledged === count( $ids ) && count( $ids ) === count( array_unique( $ids ) ) );
	test( $kind . ' drained', 0 === $driver->count() );
	if ( 'file' === $kind ) {
		$driver->compact();
		test( 'file append after compaction', $driver->push( array( 'id' => 'after' ) ) && array( array( 'id' => 'after' ) ) === $driver->pop_batch( 1 ) );
		file_put_contents( $root . '/files/hits-' . date( 'Ymd' ) . '.jsonl', '{"partial":', FILE_APPEND );
		test( 'file repairs interrupted unacknowledged tail', $driver->push( array( 'id' => 'repaired' ) ) && array( array( 'id' => 'repaired' ) ) === $driver->pop_batch( 1 ) );
	}
}

$apcu = new Rasad_Buffer_Apcu();
$apcu->push( array( 'id' => 'site1' ) );
$GLOBALS['buffer_blog'] = 2;
test( 'APCu sites isolated', 0 === $apcu->count() );
$apcu->push( array( 'id' => 'site2' ) );
$GLOBALS['buffer_blog'] = 1;
test( 'APCu original site retained', array( array( 'id' => 'site1' ) ) === $apcu->pop_batch( 10 ) );
$apcu->push( array( 'id' => 'preserved' ) );
$GLOBALS['buffer_fail_store'] = true;
test( 'APCu failed store is not acknowledged', false === $apcu->push( array( 'id' => 'failure' ) ) );
test( 'APCu failed metadata update does not deliver', array() === $apcu->pop_batch( 10 ) );
$GLOBALS['buffer_fail_store'] = false;
test( 'APCu failed pop preserves queued item', array( array( 'id' => 'preserved' ) ) === $apcu->pop_batch( 10 ) );

if ( property_exists( 'Redis', 'lists' ) ) {
	$redis = new Rasad_Buffer_Redis();
	$redis->push( array( 'id' => 1 ) ); $redis->push( array( 'id' => 2 ) );
	test( 'Redis peek preserves FIFO', array( array( 'id' => 1 ), array( 'id' => 2 ) ) === $redis->peek_batch( 10 ) && 2 === $redis->count() );
	$GLOBALS['buffer_blog'] = 2;
	test( 'Redis site isolated', 0 === $redis->count() );
	$GLOBALS['buffer_blog'] = 1;
	test( 'Redis batch atomic command', array( array( 'id' => 1 ), array( 'id' => 2 ) ) === $redis->pop_batch( 10 ) && 1 === Redis::$evals && 0 === $redis->count() );
	Redis::$fail = true;
	test( 'Redis disconnected write not acknowledged', false === $redis->push( array( 'id' => 3 ) ) );
	Redis::$fail = false;
}

class Buffer_Test_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	public $rows = array();
	public $snapshot = array();
	public $fail_delete = false;
	public $engine = 'InnoDB';
	public function get_charset_collate() { return ''; }
	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) { $sql = preg_replace( '/%[ds]/', is_numeric( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 ); }
		return $sql;
	}
	public function insert( $table, $data, $format ) {
		$id = count( $this->rows ) + 1;
		$this->rows[ $id ] = (object) array( 'id' => $id, 'hit' => $data['hit'] );
		return 1;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->rows; }
		if ( 'ROLLBACK' === $sql ) { $this->rows = $this->snapshot; }
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			if ( $this->fail_delete ) { return false; }
			preg_match( '/IN \(([^)]+)\)/', $sql, $match );
			$ids = explode( ',', $match[1] );
			foreach ( $ids as $id ) { unset( $this->rows[ (int) $id ] ); }
			return count( $ids );
		}
		return 0;
	}
	public function get_results( $sql ) {
		$this->queries[] = $sql;
		preg_match( '/LIMIT (\d+)/', $sql, $match );
		return array_slice( array_values( $this->rows ), 0, (int) $match[1] );
	}
	public function get_var( $sql ) { return false !== strpos( $sql, 'ENGINE' ) ? $this->engine : (string) count( $this->rows ); }
}
$wpdb = new Buffer_Test_Wpdb();
$db = new Rasad_Buffer_Db();
$db->push( array( 'id' => 1 ) ); $db->push( array( 'id' => 2 ) );
test( 'DB count accepts SQL string', 2 === $db->count() );
test( 'DB peek nondestructive', 1 === count( $db->peek_batch( 1 ) ) && 2 === $db->count() );
$wpdb->fail_delete = true;
test( 'DB delete failure rolls back without delivery', array() === $db->pop_batch( 1 ) && 2 === $db->count() && in_array( 'ROLLBACK', $wpdb->queries, true ) );
$wpdb->fail_delete = false;
test( 'DB committed consumption', array( array( 'id' => 1 ), array( 'id' => 2 ) ) === $db->pop_batch( 10 ) && 0 === $db->count() );
test( 'DB row locks selected', (bool) preg_grep( '/SELECT.*FOR UPDATE$/', $wpdb->queries ) );
test( 'DB table initialized once per site', 1 === count( preg_grep( '/^CREATE TABLE/', $wpdb->queries ) ) );
$wpdb = new Buffer_Test_Wpdb(); $wpdb->engine = 'MyISAM';
$db = new Rasad_Buffer_Db();
test( 'DB nontransactional engine refuses acknowledgment', false === $db->push( array( 'id' => 3 ) ) );
unset( $wpdb );

buffer_test_cleanup( $root );
exit( $GLOBALS['rasad_test_failures'] ? 1 : 0 );
