<?php
/**
 * Buffer driver tests: file-buffer round-trip + graceful degradation of the
 * extension-backed drivers when their extension/object-cache is absent.
 *
 * @package Rasad
 */

// Interface contract.
test( 'Interface has push', method_exists( 'Rasad_Buffer_Interface', 'push' ) );
test( 'Interface has pop_batch', method_exists( 'Rasad_Buffer_Interface', 'pop_batch' ) );
test( 'Interface has count', method_exists( 'Rasad_Buffer_Interface', 'count' ) );
test( 'Interface has is_available', method_exists( 'Rasad_Buffer_Interface', 'is_available' ) );

// File buffer (always available on a writable host).
$file = new Rasad_Buffer_File();
test( 'File driver available', $file->is_available() );

test( 'File push 1', $file->push( array( 'p' => '/test1', 'e' => 1, 'ts' => 1 ) ) );
test( 'File push 2', $file->push( array( 'p' => '/test2', 'e' => 1, 'ts' => 2 ) ) );
test( 'File count >= 2', $file->count() >= 2 );

$batch = $file->pop_batch( 10 );
test( 'File pop_batch array', is_array( $batch ) );
test( 'File pop_batch >= 2 items', count( $batch ) >= 2 );
test( 'File drained', $file->count() === 0 );

// APCu buffer: must degrade gracefully without the extension.
$apcu = new Rasad_Buffer_Apcu();
test( 'APCu unavailable without extension', false === $apcu->is_available() );

// Redis buffer: must degrade gracefully without an object cache.
$redis = new Rasad_Buffer_Redis();
test( 'Redis unavailable without object cache', false === $redis->is_available() );

// DB buffer: must degrade gracefully without $wpdb.
$db = new Rasad_Buffer_Db();
test( 'DB unavailable without $wpdb', false === $db->is_available() );
