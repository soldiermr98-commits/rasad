<?php
/**
 * GeoIP: local MaxMind-DB (.mmdb) reader + admin-triggered downloads.
 *
 * Format: MaxMind DB binary format, shared by GeoLite2/GeoIP2 and DB-IP
 * (.mmdb) databases. Pure PHP — no PECL extension, no unpack() of the
 * whole file: the search tree and the needed data records are read with
 * seek+read, so even a ~60 MB city database never sits in memory.
 *
 * Scope (SPEC): lookups run ONLY inside the aggregator — never on the
 * collect hot path. With no database present everything degrades to
 * country='' / city_id=0 exactly as before this phase.
 *
 * Databases: DB-IP Lite (CC-BY 4.0), downloaded by an explicit admin
 * action into data/ (deny-protected). URL is filterable.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local GeoIP reader + database management.
 */
final class Rasad_Process_Geoip {

	/**
	 * Database file names inside data/.
	 *
	 * @var string
	 */
	const DB_COUNTRY = 'dbip-country-lite.mmdb';
	const DB_CITY    = 'dbip-city-lite.mmdb';

	/**
	 * Metadata marker: 0xAB 0xCD 0xEF followed by "MaxMind.com".
	 *
	 * @var string
	 */
	const MARKER = "\xAB\xCD\xEFMaxMind.com";

	/**
	 * Open file handles per database kind (kind => resource|false).
	 *
	 * @var array<string,resource|false>
	 */
	private static $handles = array();

	/**
	 * Parsed metadata per kind (kind => array|false|null, null = unread).
	 *
	 * @var array<string,array|false|null>
	 */
	private static $meta = array();

	/**
	 * Per-process lookup memo (kind|ip => decoded map|null).
	 *
	 * @var array<string,array|null>
	 */
	private static $memo = array();

	/**
	 * Cached IPv4 start nodes per kind (for IPv6 trees).
	 *
	 * @var array<string,int>
	 */
	private static $ipv4_start = array();

	/**
	 * Drops all cached handles/metadata/memos (after download/delete).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		foreach ( self::$handles as $fp ) {
			if ( is_resource( $fp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file handle.
				@fclose( $fp );
			}
		}

		self::$handles  = array();
		self::$meta     = array();
		self::$memo     = array();
		self::$ipv4_start = array();
	}

	/**
	 * Directory holding the databases (plugin data/, deny-protected).
	 *
	 * @return string With trailing slash.
	 */
	public static function data_dir() {
		$dir = RASAD_DIR . 'data/';

		/** Filters the GeoIP data directory. */
		return (string) apply_filters( 'rasad_geoip_dir', $dir );
	}

	/**
	 * Absolute path of one database file.
	 *
	 * @param string $kind 'country'|'city'.
	 * @return string
	 */
	public static function file( $kind ) {
		$name = ( 'city' === $kind ) ? self::DB_CITY : self::DB_COUNTRY;
		$path = self::data_dir() . $name;

		/**
		 * Filters the GeoIP database file path (used by tests/alternate layouts).
		 *
		 * @param string $path Absolute path.
		 * @param string $kind Database kind.
		 */
		return (string) apply_filters( 'rasad_geoip_file', $path, $kind );
	}

	/**
	 * Whether a readable, non-trivial database file exists for $kind.
	 *
	 * @param string $kind 'country'|'city'.
	 * @return bool
	 */
	public static function available( $kind ) {
		$file = self::file( $kind );

		if ( ! is_readable( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file stat.
		$size = @filesize( $file );

		return false !== $size && $size > 128;
	}

	/**
	 * Parsed database metadata (node_count/record_size/ip_version/…).
	 *
	 * @param string $kind 'country'|'city'.
	 * @return array<string,mixed>|false False when missing/corrupt.
	 */
	public static function metadata( $kind ) {
		if ( array_key_exists( $kind, self::$meta ) ) {
			return self::$meta[ $kind ];
		}

		self::$meta[ $kind ] = false;

		if ( ! self::available( $kind ) ) {
			return false;
		}

		$fp = self::open( $kind );

		if ( ! is_resource( $fp ) ) {
			return false;
		}

		self::$meta[ $kind ] = self::parse_metadata( $fp );

		return self::$meta[ $kind ];
	}

	/**
	 * Human-readable status for the settings health block.
	 *
	 * @param string $kind 'country'|'city'.
	 * @return array<string,mixed>
	 */
	public static function status( $kind ) {
		$file = self::file( $kind );
		$out  = array(
			'available'     => false,
			'bytes'         => 0,
			'database_type' => '',
			'build_epoch'   => 0,
			'error'         => '',
		);

		if ( ! self::available( $kind ) ) {
			$out['error'] = 'not_found';

			return $out;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file stat.
		$out['bytes'] = (int) @filesize( $file );
		$meta         = self::metadata( $kind );

		if ( ! is_array( $meta ) ) {
			$out['error'] = 'corrupt';

			return $out;
		}

		$out['available']     = true;
		$out['database_type'] = isset( $meta['database_type'] ) ? (string) $meta['database_type'] : '';
		$out['build_epoch']   = isset( $meta['build_epoch'] ) ? (int) $meta['build_epoch'] : 0;

		return $out;
	}

	/**
	 * Aggregator contract: array( ISO country code, city dim id ).
	 *
	 * @param string $ip Client IP (used only inside the aggregator).
	 * @return array{0:string,1:int} array('IR', 123456) or array('', 0).
	 */
	public static function lookup( $ip ) {
		$code    = self::country( $ip );
		$city_id = 0;

		if ( '' !== $code ) {
			$city_map = self::query( 'city', $ip );
			$city     = self::city_name_from( $city_map );

			if ( '' !== $city ) {
				$city_id = self::city_dim_id( $code, $city );
			}
		}

		return array( $code, $city_id );
	}

	/**
	 * ISO alpha-2 country code for an IP ('' when unknown).
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	public static function country( $ip ) {
		$map = self::query( 'country', $ip );

		if ( ! is_array( $map ) ) {
			return '';
		}

		$code = '';

		if ( isset( $map['country']['iso_code'] ) && is_string( $map['country']['iso_code'] ) ) {
			$code = $map['country']['iso_code'];
		} elseif ( isset( $map['registered_country']['iso_code'] ) && is_string( $map['registered_country']['iso_code'] ) ) {
			$code = $map['registered_country']['iso_code'];
		}

		return strtoupper( substr( trim( $code ), 0, 2 ) );
	}

	/**
	 * City name (English preferred) from a decoded city-database record.
	 *
	 * @param array<string,mixed>|null $map Decoded record.
	 * @return string
	 */
	private static function city_name_from( $map ) {
		if ( ! is_array( $map ) || empty( $map['city'] ) || ! is_array( $map['city'] ) ) {
			return '';
		}

		$city = $map['city'];

		if ( isset( $city['names'] ) && is_array( $city['names'] ) ) {
			if ( isset( $city['names']['en'] ) && is_string( $city['names']['en'] ) ) {
				return trim( $city['names']['en'] );
			}

			foreach ( $city['names'] as $name ) {
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					return trim( $name );
				}
			}
		}

		return '';
	}

	/**
	 * Country+city -> unsigned INT surrogate for dim 3 (crc32, stable).
	 *
	 * @param string $country ISO alpha-2 code.
	 * @param string $city    City name.
	 * @return int 0 when unknown.
	 */
	public static function city_dim_id( $country, $city ) {
		$country = strtoupper( trim( (string) $country ) );
		$city    = trim( (string) $city );

		if ( '' === $country || '' === $city ) {
			return 0;
		}

		$crc = crc32( $country . '|' . $city );

		return $crc < 0 ? $crc + 4294967296 : $crc;
	}

	/**
	 * Builds the admin download URL for one database (monthly DB-IP Lite).
	 *
	 * @param string $kind 'country'|'city'.
	 * @return string
	 */
	public static function download_url( $kind ) {
		$name = ( 'city' === $kind ) ? 'dbip-city-lite' : 'dbip-country-lite';
		$url  = 'https://download.db-ip.com/free/' . $name . '-' . gmdate( 'Y-m' ) . '.mmdb.gz';

		/**
		 * Filters the GeoIP database download URL.
		 *
		 * @param string $url  Full URL.
		 * @param string $kind Database kind.
		 */
		return (string) apply_filters( 'rasad_geoip_download_url', $url, $kind );
	}

	/**
	 * Downloads + validates + atomically installs one database.
	 *
	 * @param string $kind 'country'|'city'.
	 * @return true|WP_Error
	 */
	public static function download( $kind ) {
		$kind = ( 'city' === $kind ) ? 'city' : 'country';
		$url  = self::download_url( $kind );

		// phpcs:ignore WordPress.WP.HttpCommand -- admin-triggered DB-IP download.
		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 120,
				'redirection' => 5,
				'user-agent' => 'Rasad/' . RASAD_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( 200 !== $code ) {
			return new WP_Error( 'rasad_geoip_http', 'HTTP ' . $code . ' from ' . $url );
		}

		$body = (string) wp_remote_retrieve_body( $res );

		if ( strlen( $body ) < 128 ) {
			return new WP_Error( 'rasad_geoip_empty', 'Downloaded file is too small' );
		}

		// Databases ship gzipped; also accept a pre-decompressed body.
		if ( "\x1F\x8B" === substr( $body, 0, 2 ) ) {
			if ( ! function_exists( 'gzdecode' ) ) {
				return new WP_Error( 'rasad_geoip_gzip', 'zlib gzdecode() is unavailable on this host' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions -- local gzip decode.
			$decoded = @gzdecode( $body );

			if ( false === $decoded || strlen( $decoded ) < 128 ) {
				return new WP_Error( 'rasad_geoip_gzip', 'gzip decode failed' );
			}

			$body = $decoded;
		}

		$dir = self::data_dir();

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return new WP_Error( 'rasad_geoip_dir', 'Cannot create data directory' );
		}

		$file = self::file( $kind );
		$tmp  = $file . '.part';

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local atomic write.
		if ( false === @file_put_contents( $tmp, $body, LOCK_EX ) ) {
			return new WP_Error( 'rasad_geoip_write', 'Cannot write temporary file' );
		}

		$fp = @fopen( $tmp, 'rb' );
		$ok = is_resource( $fp ) ? self::parse_metadata( $fp ) : false;

		if ( is_resource( $fp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file handle.
			@fclose( $fp );
		}

		if ( false === $ok ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- local cleanup.
			@unlink( $tmp );

			return new WP_Error( 'rasad_geoip_invalid', 'Downloaded file is not a valid .mmdb database' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local atomic rename.
		if ( ! @rename( $tmp, $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- local cleanup.
			@unlink( $tmp );

			return new WP_Error( 'rasad_geoip_write', 'Cannot install database file' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local chmod.
		@chmod( $file, 0644 );
		self::reset_cache();

		/** Fires after a GeoIP database was installed. */
		do_action( 'rasad_geoip_installed', $kind, $file );

		return true;
	}

	/**
	 * Removes one downloaded database.
	 *
	 * @param string $kind 'country'|'city'.
	 * @return bool True when the file is gone.
	 */
	public static function delete( $kind ) {
		$file = self::file( $kind );
		$gone = ! is_file( $file ) || @unlink( $file );
		self::reset_cache();

		return (bool) $gone;
	}

	/*
	|---------------------------------------------------------------------
	| MaxMind DB internals
	|---------------------------------------------------------------------
	*/

	/**
	 * Opens (and remembers) one database handle.
	 *
	 * @param string $kind Database kind.
	 * @return resource|false
	 */
	private static function open( $kind ) {
		if ( array_key_exists( $kind, self::$handles ) ) {
			return self::$handles[ $kind ];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file handle.
		$fp = @fopen( self::file( $kind ), 'rb' );
		self::$handles[ $kind ] = is_resource( $fp ) ? $fp : false;

		return self::$handles[ $kind ];
	}

	/**
	 * Parses the trailing metadata map of an open database.
	 *
	 * @param resource $fp Open handle.
	 * @return array<string,mixed>|false
	 */
	private static function parse_metadata( $fp ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file stat.
		$stat = @fstat( $fp );

		if ( ! is_array( $stat ) || empty( $stat['size'] ) || $stat['size'] < 160 ) {
			return false;
		}

		$size     = (int) $stat['size'];
		$tail_len = min( $size, 1048576 ); // metadata maps are small; 1 MB is generous.
		$tail_off = $size - $tail_len;

		if ( 0 !== @fseek( $fp, $tail_off ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file read.
		$tail = @fread( $fp, $tail_len );

		if ( ! is_string( $tail ) || '' === $tail ) {
			return false;
		}

		// Metadata sits at the very end: take the LAST marker occurrence.
		$pos = strrpos( $tail, self::MARKER );

		if ( false === $pos ) {
			return false;
		}

		$offset = $tail_off + $pos + strlen( self::MARKER );
		// Pointers are meaningless in the metadata block.
		$meta = self::decode_at( $fp, $offset, 0, 0, false, 0 );

		if ( ! is_array( $meta ) ) {
			return false;
		}

		foreach ( array( 'node_count', 'record_size', 'ip_version' ) as $required ) {
			if ( ! isset( $meta[ $required ] ) || ! is_numeric( $meta[ $required ] ) ) {
				return false;
			}
		}

		$record_size = (int) $meta['record_size'];

		if ( ! in_array( $record_size, array( 24, 28, 32 ), true ) ) {
			return false;
		}

		return array(
			'node_count'     => (int) $meta['node_count'],
			'record_size'    => $record_size,
			'node_byte_size' => (int) ( $record_size / 4 ),
			'ip_version'     => (int) $meta['ip_version'],
			'database_type'  => isset( $meta['database_type'] ) ? (string) $meta['database_type'] : '',
			'build_epoch'    => isset( $meta['build_epoch'] ) ? (int) $meta['build_epoch'] : 0,
		);
	}

	/**
	 * Runs one IP query against a database and returns the decoded record.
	 *
	 * @param string $kind Database kind.
	 * @param string $ip   IPv4/IPv6 address.
	 * @return array<string,mixed>|null Null when unknown/corrupt/no DB.
	 */
	private static function query( $kind, $ip ) {
		$ip = trim( (string) $ip );

		if ( '' === $ip ) {
			return null;
		}

		$memo_key = $kind . '|' . $ip;

		if ( array_key_exists( $memo_key, self::$memo ) ) {
			return self::$memo[ $memo_key ];
		}

		self::$memo[ $memo_key ] = null;

		$meta = self::metadata( $kind );

		if ( ! is_array( $meta ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- IP binary form.
		$bin = @inet_pton( $ip );

		if ( false === $bin || ! is_string( $bin ) || '' === $bin ) {
			return null;
		}

		$fp = self::open( $kind );

		if ( ! is_resource( $fp ) ) {
			return null;
		}

		$bits  = 0;
		$start = 0;

		if ( 4 === strlen( $bin ) ) {
			$bits = 32;

			if ( 6 === $meta['ip_version'] ) {
				// IPv4 inside an IPv6 tree: start after the :: padding (96 zero bits).
				$start = self::ipv4_start( $kind, $meta, $fp );
			}
		} else {
			if ( 4 === $meta['ip_version'] ) {
				// IPv4 database: only IPv4-mapped IPv6 (::ffff:a.b.c.d) is queryable.
				if ( "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF" !== substr( $bin, 0, 12 ) ) {
					return null;
				}

				$bin  = substr( $bin, 12 );
				$bits = 32;
			} else {
				$bits = 128;
			}
		}

		$node       = $start;
		$node_count = $meta['node_count'];
		$depth      = 0;

		while ( $depth < $bits && $node < $node_count ) {
			$byte = ord( $bin[ (int) ( $depth / 8 ) ] );
			$bit  = ( $byte >> ( 7 - ( $depth % 8 ) ) ) & 1;
			$node = self::record( $fp, $meta, $node, $bit );
			++$depth;
		}

		// node == node_count means "no data for this network".
		if ( $node <= $node_count ) {
			return null;
		}

		// Data pointer: file offset = search tree size + (value - node_count),
		// where the first 16 bytes past the tree are the section separator.
		// Inner pointers resolve against (tree size + 16) = the data section proper.
		$tree_size    = $node_count * $meta['node_byte_size'];
		$record_off   = $tree_size + ( $node - $node_count );
		$data_section = $tree_size + 16;
		$decoded      = self::decode_at( $fp, $record_off, $node_count, $data_section, true, 0 );

		self::$memo[ $memo_key ] = is_array( $decoded ) ? $decoded : null;

		return self::$memo[ $memo_key ];
	}

	/**
	 * Reads one search-tree record (left=bit0, right=bit1).
	 *
	 * @param resource             $fp   Open handle.
	 * @param array<string,mixed>  $meta Database metadata.
	 * @param int                  $node Node number.
	 * @param int                  $bit  0|1.
	 * @return int Next node / data pointer value.
	 */
	private static function record( $fp, $meta, $node, $bit ) {
		$size = $meta['node_byte_size'];
		$off  = $node * $size;

		$bytes = self::read( $fp, $off, $size );

		if ( false === $bytes || strlen( $bytes ) < $size ) {
			return $meta['node_count']; // treat as "no data".
		}

		$rs = $meta['record_size'];

		if ( 24 === $rs ) {
			$start = $bit ? 3 : 0;

			return self::be_uint( substr( $bytes, $start, 3 ) );
		}

		if ( 28 === $rs ) {
			$b = array_values( array_map( 'ord', str_split( $bytes ) ) );

			if ( 0 === $bit ) {
				return ( ( $b[3] & 0xF0 ) << 20 ) | ( ( $b[0] << 16 ) | ( $b[1] << 8 ) | $b[2] );
			}

			return ( ( $b[3] & 0x0F ) << 24 ) | ( ( $b[4] << 16 ) | ( $b[5] << 8 ) | $b[6] );
		}

		// 32.
		return self::be_uint( substr( $bytes, $bit ? 4 : 0, 4 ) );
	}

	/**
	 * Starting node for IPv4 queries inside an IPv6 tree (96 zero bits).
	 *
	 * @param string              $kind Database kind.
	 * @param array<string,mixed> $meta Database metadata.
	 * @param resource            $fp   Open handle.
	 * @return int
	 */
	private static function ipv4_start( $kind, $meta, $fp ) {
		if ( isset( self::$ipv4_start[ $kind ] ) ) {
			return self::$ipv4_start[ $kind ];
		}

		$node = 0;

		for ( $i = 0; $i < 96 && $node < $meta['node_count']; $i++ ) {
			$node = self::record( $fp, $meta, $node, 0 );
		}

		if ( $node > $meta['node_count'] ) {
			$node = $meta['node_count'];
		}

		self::$ipv4_start[ $kind ] = $node;

		return $node;
	}

	/**
	 * Decodes one value at an absolute file offset.
	 *
	 * Types implemented per MaxMind DB spec (string, map, array, uints,
	 * int32, bool, double, float, bytes, pointer, extended). Unknown -> null.
	 *
	 * @param resource $fp             Open handle.
	 * @param int      $offset         Absolute file offset.
	 * @param int      $node_count     node_count (unused except pointer base math).
	 * @param int      $data_start     Unused (kept for clarity of pointer space).
	 * @param bool     $allow_pointers Whether in-data pointers may be followed.
	 * @param int      $depth          Recursion guard.
	 * @return mixed|null
	 */
	private static function decode_at( $fp, $offset, $node_count, $data_start, $allow_pointers, $depth ) {
		if ( $depth > 512 ) {
			return null;
		}

		$ctrl = self::u8( $fp, $offset );

		if ( null === $ctrl ) {
			return null;
		}

		++$offset;
		$type = $ctrl >> 5;

		// Pointer (type 1): 001SSVVV + SS+1 bytes.
		if ( 1 === $type ) {
			if ( ! $allow_pointers ) {
				return null;
			}

			$psize  = ( $ctrl >> 3 ) & 0x03;
			$packed = 0;

			if ( 0 === $psize ) {
				$b = self::u8( $fp, $offset );

				if ( null === $b ) {
					return null;
				}

				$packed = ( ( $ctrl & 0x07 ) << 8 ) | $b;
				$offset += 1;
			} elseif ( 1 === $psize ) {
				$bytes = self::read( $fp, $offset, 2 );

				if ( false === $bytes ) {
					return null;
				}

				$packed = ( ( $ctrl & 0x07 ) << 16 ) | self::be_uint( $bytes );
				$offset += 2;
			} elseif ( 2 === $psize ) {
				$bytes = self::read( $fp, $offset, 3 );

				if ( false === $bytes ) {
					return null;
				}

				$packed = ( ( $ctrl & 0x07 ) << 24 ) | self::be_uint( $bytes );
				$offset += 3;
			} else {
				$bytes = self::read( $fp, $offset, 4 );

				if ( false === $bytes ) {
					return null;
				}

				$packed = self::be_uint( $bytes );
				$offset += 4;
			}

			static $pbase = array( 0, 2048, 526336, 0 );

			// Pointers are relative to the data section (after the 16-byte separator).
			// The caller passed the record offset in file space; recompute data start
			// from the tree: node_count * node_byte_size is NOT known here, so the
			// pointer base is resolved by the caller through $data_start.
			$target = $data_start + $pbase[ $psize ] + $packed;

			return self::decode_at( $fp, $target, $node_count, $data_start, true, $depth + 1 );
		}

		// Extended type: next byte + 7.
		if ( 0 === $type ) {
			$next = self::u8( $fp, $offset );

			if ( null === $next ) {
				return null;
			}

			$type = $next + 7;
			++$offset;

			if ( $type < 8 ) {
				return null;
			}
		}

		// Size: low 5 bits, with 29/30/31 escape encodings.
		$size = $ctrl & 0x1F;

		if ( 29 === $size ) {
			$b = self::u8( $fp, $offset );

			if ( null === $b ) {
				return null;
			}

			$size = 29 + $b;
			++$offset;
		} elseif ( 30 === $size ) {
			$bytes = self::read( $fp, $offset, 2 );

			if ( false === $bytes ) {
				return null;
			}

			$size = 285 + self::be_uint( $bytes );
			$offset += 2;
		} elseif ( 31 === $size ) {
			$bytes = self::read( $fp, $offset, 3 );

			if ( false === $bytes ) {
				return null;
			}

			$size = 65821 + self::be_uint( $bytes );
			$offset += 3;
		}

		switch ( $type ) {
			case 2: // utf8_string
				$bytes = self::read( $fp, $offset, $size );

				return ( false === $bytes ) ? null : $bytes;

			case 3: // double (unused by our paths; best effort).
				$bytes = self::read( $fp, $offset, 8 );

				if ( false === $bytes || 8 !== strlen( $bytes ) ) {
					return null;
				}

				$un = @unpack( 'Evalue', $bytes );

				return is_array( $un ) && isset( $un['value'] ) ? $un['value'] : null;

			case 4: // bytes
				$bytes = self::read( $fp, $offset, $size );

				return ( false === $bytes ) ? null : $bytes;

			case 5: // uint16
			case 6: // uint32
			case 9: // uint64
				$bytes = self::read( $fp, $offset, $size );

				if ( false === $bytes ) {
					return null;
				}

				return self::be_uint( $bytes );

			case 7: // map
				$map = array();

				for ( $i = 0; $i < $size; $i++ ) {
					list( $key, $offset ) = self::decode_with_end( $fp, $offset, $node_count, $data_start, $allow_pointers, $depth + 1 );

					if ( ! is_string( $key ) ) {
						return null;
					}

					list( $value, $offset ) = self::decode_with_end( $fp, $offset, $node_count, $data_start, $allow_pointers, $depth + 1 );

					if ( null === $value ) {
						return null;
					}

					$map[ $key ] = $value;
				}

				return $map;

			case 8: // int32
				$bytes = self::read( $fp, $offset, $size );

				if ( false === $bytes ) {
					return null;
				}

				if ( $size < 4 ) {
					return self::be_uint( $bytes );
				}

				$u = self::be_uint( $bytes );

				return $u >= 2147483648 ? $u - 4294967296 : $u;

			case 11: // array
				$list = array();

				for ( $i = 0; $i < $size; $i++ ) {
					list( $value, $offset ) = self::decode_with_end( $fp, $offset, $node_count, $data_start, $allow_pointers, $depth + 1 );

					if ( null === $value ) {
						return null;
					}

					$list[] = $value;
				}

				return $list;

			case 14: // boolean (value lives in the size bits)
				return 0 !== $size;

			case 15: // float
				$bytes = self::read( $fp, $offset, 4 );

				if ( false === $bytes || 4 !== strlen( $bytes ) ) {
					return null;
				}

				$un = @unpack( 'Gvalue', $bytes );

				return is_array( $un ) && isset( $un['value'] ) ? $un['value'] : null;
		}

		return null;
	}

	/**
	 * Decodes one value and returns [value, next_offset] (map/array walks).
	 *
	 * decode_at produces the value; skip_value mirrors the same header
	 * parsing to find where the encoded value ends.
	 *
	 * @param resource $fp             Open handle.
	 * @param int      $offset         Absolute offset.
	 * @param int      $node_count     node_count.
	 * @param int      $data_start     Data section start (pointer base).
	 * @param bool     $allow_pointers Pointers allowed.
	 * @param int      $depth          Recursion guard.
	 * @return array{0:mixed,1:int}
	 */
	private static function decode_with_end( $fp, $offset, $node_count, $data_start, $allow_pointers, $depth ) {
		$value = self::decode_at( $fp, $offset, $node_count, $data_start, $allow_pointers, $depth );
		$end   = self::skip_value( $fp, $offset, $allow_pointers, $depth );

		return array( $value, false === $end ? $offset : $end );
	}

	/**
	 * Computes the byte length of the value starting at $offset (payload
	 * skip after decoding), mirroring decode_at's header parsing.
	 *
	 * @param resource $fp             Open handle.
	 * @param int      $offset         Absolute offset.
	 * @param bool     $allow_pointers Pointers allowed.
	 * @param int      $depth          Recursion guard.
	 * @return int|false End offset or false.
	 */
	private static function skip_value( $fp, $offset, $allow_pointers, $depth ) {
		if ( $depth > 512 ) {
			return false;
		}

		$ctrl = self::u8( $fp, $offset );

		if ( null === $ctrl ) {
			return false;
		}

		++$offset;
		$type = $ctrl >> 5;

		if ( 1 === $type ) {
			$psize = ( $ctrl >> 3 ) & 0x03;

			return $offset + $psize + 1;
		}

		if ( 0 === $type ) {
			$next = self::u8( $fp, $offset );

			if ( null === $next ) {
				return false;
			}

			$type = $next + 7;
			++$offset;
		}

		$size = $ctrl & 0x1F;

		if ( 29 === $size ) {
			$b = self::u8( $fp, $offset );

			if ( null === $b ) {
				return false;
			}

			$size = 29 + $b;
			++$offset;
		} elseif ( 30 === $size ) {
			$bytes = self::read( $fp, $offset, 2 );

			if ( false === $bytes ) {
				return false;
			}

			$size = 285 + self::be_uint( $bytes );
			$offset += 2;
		} elseif ( 31 === $size ) {
			$bytes = self::read( $fp, $offset, 3 );

			if ( false === $bytes ) {
				return false;
			}

			$size = 65821 + self::be_uint( $bytes );
			$offset += 3;
		}

		if ( 7 === $type ) { // map: key/value pairs
			for ( $i = 0; $i < $size; $i++ ) {
				$offset = self::skip_value( $fp, $offset, $allow_pointers, $depth + 1 );

				if ( false === $offset ) {
					return false;
				}

				$offset = self::skip_value( $fp, $offset, $allow_pointers, $depth + 1 );

				if ( false === $offset ) {
					return false;
				}
			}

			return $offset;
		}

		if ( 11 === $type ) { // array
			for ( $i = 0; $i < $size; $i++ ) {
				$offset = self::skip_value( $fp, $offset, $allow_pointers, $depth + 1 );

				if ( false === $offset ) {
					return false;
				}
			}

			return $offset;
		}

		if ( 14 === $type ) { // boolean: no payload
			return $offset;
		}

		if ( 3 === $type ) { // double
			return $offset + 8;
		}

		if ( 15 === $type ) { // float
			return $offset + 4;
		}

		return $offset + $size;
	}

	/*
	|---------------------------------------------------------------------
	| Byte helpers
	|---------------------------------------------------------------------
	*/

	/**
	 * Reads $len bytes at an absolute offset.
	 *
	 * @param resource $fp     Open handle.
	 * @param int      $offset Absolute offset.
	 * @param int      $len    Length.
	 * @return string|false
	 */
	private static function read( $fp, $offset, $len ) {
		if ( $len <= 0 ) {
			return '';
		}

		if ( 0 !== @fseek( $fp, $offset ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file read.
		$bytes = @fread( $fp, $len );

		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $len ) {
			return false;
		}

		return $bytes;
	}

	/**
	 * Reads a single byte.
	 *
	 * @param resource $fp     Open handle.
	 * @param int      $offset Absolute offset.
	 * @return int|null
	 */
	private static function u8( $fp, $offset ) {
		$bytes = self::read( $fp, $offset, 1 );

		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		return ord( $bytes );
	}

	/**
	 * Big-endian unsigned integer from raw bytes (PHP int, 64-bit safe
	 * up to 8 bytes; larger values degrade to float precision).
	 *
	 * @param string $bytes Raw bytes.
	 * @return int|float
	 */
	private static function be_uint( $bytes ) {
		$v = 0;
		$len = strlen( $bytes );

		for ( $i = 0; $i < $len; $i++ ) {
			$v = $v * 256 + ord( $bytes[ $i ] );
		}

		return $v;
	}
}
