<?php
/**
 * Append-only file buffer — the default driver for shared hosting.
 *
 * One JSON line per hit in wp-content/uploads/rasad/buffer/, written with
 * LOCK_EX. The directory is protected with .htaccess + index.php.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-based buffer driver.
 */
final class Rasad_Buffer_File implements Rasad_Buffer_Interface {

	/**
	 * Buffer subdirectory name under the Rasad storage dir.
	 *
	 * @var string
	 */
	const SUBDIR = 'buffer';

	/**
	 * Absolute directory path with trailing slash.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * Sets the target directory (defaults to the plugin storage dir).
	 *
	 * @param string|null $dir Optional explicit directory.
	 */
	public function __construct( $dir = null ) {
		$this->dir = ( null === $dir )
			? rtrim( Rasad_Plugin::storage_dir( self::SUBDIR ), '/' ) . '/'
			: rtrim( (string) $dir, '/' ) . '/';
	}

	/**
	 * Creates/protects the buffer directory and checks writability.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! Rasad_Plugin::ensure_storage() ) {
			return false;
		}

		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0755, true ) && ! is_dir( $this->dir ) ) {
			return false;
		}

		Rasad_Plugin::protect_dir( $this->dir );

		return is_writable( $this->dir );
	}

	/**
	 * Appends one JSON line to today's file.
	 *
	 * @param array<string,mixed> $hit Hit data.
	 * @return bool
	 */
	public function push( array $hit ) {
		if ( ! $this->is_available() ) {
			return false;
		}

		$line = wp_json_encode( $hit );

		if ( ! is_string( $line ) || '' === $line ) {
			return false;
		}

		$result = @file_put_contents(
			$this->file(),
			$line . "\n",
			FILE_APPEND | LOCK_EX
		);

		return false !== $result;
	}

	/**
	 * Takes up to $limit hits from the oldest buffer files first.
	 *
	 * Files are consumed completely before being deleted, so leftovers from a
	 * stalled aggregation (including yesterday's file) are processed first.
	 *
	 * @param int $limit Maximum hits to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function pop_batch( $limit ) {
		$limit = max( 1, (int) $limit );
		$batch = array();

		foreach ( $this->files() as $file ) {
			if ( count( $batch ) >= $limit ) {
				break;
			}

			$taken = $this->take_from( $file, $limit - count( $batch ) );

			foreach ( $taken as $line ) {
				$decoded = json_decode( $line, true );

				if ( is_array( $decoded ) ) {
					$batch[] = $decoded;
				}
			}
		}

		return $batch;
	}

	/**
	 * Counts buffered lines across all active files.
	 *
	 * @return int
	 */
	public function count() {
		$total = 0;

		foreach ( $this->files() as $file ) {
			$content = @file_get_contents( $file );

			if ( is_string( $content ) && '' !== $content ) {
				$total += substr_count( $content, "\n" );
			}
		}

		return $total;
	}

	/**
	 * Today's active buffer file.
	 *
	 * Daily naming lets the cleaner purge old files by name/mtime.
	 *
	 * @return string
	 */
	private function file() {
		return $this->dir . 'hits-' . date( 'Ymd' ) . '.jsonl';
	}

	/**
	 * All active buffer files, oldest first.
	 *
	 * @return string[]
	 */
	private function files() {
		$files = glob( $this->dir . 'hits-*.jsonl' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Reads up to $take lines from one file under an exclusive lock, rewrites
	 * whatever remains and deletes the file when fully consumed.
	 *
	 * @param string $file Absolute file path.
	 * @param int    $take Maximum lines to take.
	 * @return string[] Raw JSON lines.
	 */
	private function take_from( $file, $take ) {
		$fh = @fopen( $file, 'c+' );

		if ( false === $fh ) {
			return array();
		}

		if ( ! flock( $fh, LOCK_EX ) ) {
			fclose( $fh );

			return array();
		}

		$content = stream_get_contents( $fh );
		$content = is_string( $content ) ? $content : '';

		if ( '' === trim( $content ) ) {
			ftruncate( $fh, 0 );
			flock( $fh, LOCK_UN );
			fclose( $fh );
			@unlink( $file );

			return array();
		}

		$lines = explode( "\n", $content );
		$taken = array();

		while ( count( $taken ) < $take && ! empty( $lines ) ) {
			$line = array_shift( $lines );

			if ( '' !== trim( $line ) ) {
				$taken[] = $line;
			}
		}

		$remaining = implode( "\n", $lines );

		// Keep a trailing newline (push() appends whole lines), but never
		// double it: explode() already preserves the original terminator as
		// a trailing empty element.
		if ( '' !== $remaining && "\n" !== substr( $remaining, -1 ) ) {
			$remaining .= "\n";
		}

		rewind( $fh );
		ftruncate( $fh, 0 );
		fwrite( $fh, $remaining );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );

		if ( '' === $remaining ) {
			@unlink( $file );
		}

		return $taken;
	}
}
