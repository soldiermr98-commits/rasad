<?php
/**
 * Append-only file buffer. A per-file read cursor avoids rewriting the backlog.
 *
 * @package Rasad
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rasad_Buffer_File implements Rasad_Buffer_Interface {
	const SUBDIR = 'buffer';
	private $dir = '';

	public function __construct( $dir = null ) {
		$this->dir = rtrim( null === $dir ? Rasad_Plugin::storage_dir( self::SUBDIR ) : (string) $dir, '/\\' ) . '/';
	}

	public function is_available() {
		if ( ! Rasad_Plugin::ensure_storage() ) {
			return false;
		}
		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0755, true ) && ! is_dir( $this->dir ) ) {
			return false;
		}
		Rasad_Plugin::protect_dir( $this->dir );
		return Rasad_Plugin::writable_dir( $this->dir );
	}

	public function push( array $hit ) {
		if ( ! $this->is_available() ) {
			return false;
		}
		$line = wp_json_encode( $hit );
		if ( ! is_string( $line ) || '' === $line ) {
			return false;
		}
		$fh = @fopen( $this->dir . 'hits-' . date( 'Ymd' ) . '.jsonl', 'c+b' );
		if ( false === $fh ) {
			return false;
		}
		$result = false;
		if ( flock( $fh, LOCK_EX ) ) {
			fseek( $fh, 0, SEEK_END );
			$start = ftell( $fh );
			// A killed writer can leave an unterminated record. Repair that
			// unacknowledged tail before appending a new complete record.
			if ( $start > 0 ) {
				fseek( $fh, -1, SEEK_END );
				if ( "\n" !== fread( $fh, 1 ) ) {
					$end = $start;
					$start = 0;
					while ( $end > 0 ) {
						$begin = max( 0, $end - 8192 );
						fseek( $fh, $begin );
						$chunk = fread( $fh, $end - $begin );
						$last = is_string( $chunk ) ? strrpos( $chunk, "\n" ) : false;
						if ( false !== $last ) {
							$start = $begin + $last + 1;
							break;
						}
						$end = $begin;
					}
					if ( ! ftruncate( $fh, $start ) ) {
						flock( $fh, LOCK_UN );
						fclose( $fh );
						return false;
					}
				}
			}
			fseek( $fh, 0, SEEK_END );
			$line .= "\n";
			$result = $this->write_all( $fh, $line ) && fflush( $fh );
			if ( ! $result && false !== $start ) {
				// Never acknowledge a partial JSON line; preserve earlier appends.
				ftruncate( $fh, $start );
			}
			flock( $fh, LOCK_UN );
		}
		fclose( $fh );
		return $result;
	}

	public function pop_batch( $limit ) {
		return $this->read_batch( max( 1, (int) $limit ), true );
	}

	public function peek_batch( $limit ) {
		return $this->read_batch( max( 1, (int) $limit ), false );
	}

	public function count() {
		$total = 0;
		foreach ( $this->files() as $file ) {
			$fh = @fopen( $file, 'rb' );
			if ( false === $fh ) {
				continue;
			}
			if ( flock( $fh, LOCK_SH ) ) {
				fseek( $fh, $this->offset( $file ) );
				while ( false !== ( $line = fgets( $fh ) ) ) {
					if ( "\n" === substr( $line, -1 ) ) {
						++$total;
					}
				}
				flock( $fh, LOCK_UN );
			}
			fclose( $fh );
		}
		return $total;
	}

	/**
	 * Reclaims fully consumed files without replacing their append inode.
	 * Cursor reset precedes truncation: interruption may replay, never skip,
	 * previously consumed data. Caller may run this during scheduled cleanup.
	 *
	 * @return int Number of files compacted.
	 */
	public function compact() {
		$total = 0;
		foreach ( $this->files() as $file ) {
			$fh = @fopen( $file, 'r+b' );
			if ( false === $fh ) {
				continue;
			}
			if ( flock( $fh, LOCK_EX | LOCK_NB ) ) {
				$stat = fstat( $fh );
				if ( is_array( $stat ) && $stat['size'] > 0 && $this->offset( $file ) === (int) $stat['size'] ) {
					$tmp = $file . '.offset.' . uniqid( '', true );
					if ( 1 === @file_put_contents( $tmp, '0' ) && @rename( $tmp, $file . '.offset' ) ) {
						if ( ftruncate( $fh, 0 ) && fflush( $fh ) ) {
							++$total;
						}
					} else {
						@unlink( $tmp );
					}
				}
				flock( $fh, LOCK_UN );
			}
			fclose( $fh );
		}
		return $total;
	}

	private function files() {
		$files = glob( $this->dir . 'hits-*.jsonl' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		sort( $files, SORT_STRING );
		return $files;
	}

	private function offset( $file ) {
		$value = @file_get_contents( $file . '.offset' );
		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0;
	}

	private function read_batch( $limit, $remove ) {
		$batch = array();
		foreach ( $this->files() as $file ) {
			if ( count( $batch ) >= $limit ) {
				break;
			}
			$fh = @fopen( $file, 'rb' );
			if ( false === $fh ) {
				continue;
			}
			if ( ! flock( $fh, $remove ? LOCK_EX : LOCK_SH ) ) {
				fclose( $fh );
				continue;
			}
			$offset = $this->offset( $file );
			fseek( $fh, $offset );
			$taken = array();
			while ( count( $batch ) + count( $taken ) < $limit && false !== ( $line = fgets( $fh ) ) ) {
				// A crash may leave a partial final append. Never consume it.
				if ( "\n" !== substr( $line, -1 ) ) {
					break;
				}
				$offset = ftell( $fh );
				$hit = json_decode( $line, true );
				if ( is_array( $hit ) ) {
					$taken[] = $hit;
				}
			}
			$ok = true;
			if ( $remove ) {
				// Atomic cursor replacement: failure leaves the previous batch readable.
				$tmp = $file . '.offset.' . uniqid( '', true );
				$value = (string) $offset;
				$ok = strlen( $value ) === @file_put_contents( $tmp, $value ) && @rename( $tmp, $file . '.offset' );
				if ( ! $ok ) {
					@unlink( $tmp );
				}
			}
			if ( $ok ) {
				$batch = array_merge( $batch, $taken );
			}
			// Keep the append inode in place, even after it has been drained.
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
		return $batch;
	}

	private function write_all( $fh, $value ) {
		$offset = 0;
		$length = strlen( $value );
		while ( $offset < $length ) {
			$written = fwrite( $fh, substr( $value, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}
}
