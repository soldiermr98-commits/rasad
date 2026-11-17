<?php
/** Standalone: php tests/test-dashboard-export.php */
define( 'ABSPATH', dirname( __DIR__ ) );
require dirname( __DIR__ ) . '/includes/admin/class-export.php';
$stream = fopen( 'php://temp', 'w+' );
$cells = array( '=1+1', ' +SUM(A1)', '@cmd', '-cmd', "\t=1", "\r=1", 'safe,"quoted"', 'plain', '12.34', '' );
Rasad_Admin_Export::row( $stream, $cells );
rewind( $stream );
$row = fgetcsv( $stream );
foreach ( array( 0, 1, 2, 3, 4, 5 ) as $index ) {
	if ( "'" !== substr( $row[ $index ], 0, 1 ) ) { throw new Exception( 'Unsafe formula cell ' . $index ); }
}
foreach ( array( 6, 7, 8, 9 ) as $index ) {
	if ( $row[ $index ] !== $cells[ $index ] ) { throw new Exception( 'CSV round trip failed ' . $index ); }
}
fclose( $stream );
echo "PASS CSV formula protection, quoting, empty and numeric cells\n";
