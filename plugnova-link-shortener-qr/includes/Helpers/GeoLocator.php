<?php
/**
 * Offline IP-to-country lookup using compact binary range tables (no external API calls per
 * visitor — the visitor's IP never leaves the server). The tables themselves are not bundled in
 * the plugin package (it would make the .zip too large for some hosts' upload limits); instead
 * they're downloaded once via Helpers\GeoIpInstaller, triggered by an administrator from the
 * Settings page, and cached in the uploads directory. Built from the CC0-licensed
 * ip-location-db "asn-country" dataset (public statistical data from the five Regional
 * Internet Registries).
 *
 * Both IPv4 and IPv6 are supported, via two separate tables (fixed-width records differ: 4-byte
 * vs 16-byte addresses). IPv6 range comparison needs no GMP/BCMath: packing each 128-bit address
 * as a fixed-length 16-byte big-endian binary string and comparing with PHP's native string
 * comparison operators (<, >, <=>) yields exactly the same ordering as numeric comparison would,
 * since every string being compared is the same length.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GeoLocator
 */
final class GeoLocator {

	/**
	 * Byte size of a single packed IPv4 record: uint32 range start + uint32 range end + 2-byte country code.
	 */
	private const RECORD_SIZE_V4 = 10;

	/**
	 * Byte size of a single packed IPv6 record: 16-byte range start + 16-byte range end + 2-byte country code.
	 */
	private const RECORD_SIZE_V6 = 34;

	/**
	 * In-memory cache of the loaded IPv4 binary table for the lifetime of the request.
	 *
	 * @var string|null
	 */
	private static ?string $table_v4 = null;

	/**
	 * Number of records in the loaded IPv4 table.
	 *
	 * @var int
	 */
	private static int $record_count_v4 = 0;

	/**
	 * In-memory cache of the loaded IPv6 binary table for the lifetime of the request.
	 *
	 * @var string|null
	 */
	private static ?string $table_v6 = null;

	/**
	 * Number of records in the loaded IPv6 table.
	 *
	 * @var int
	 */
	private static int $record_count_v6 = 0;

	/**
	 * Resolve an IP address to a 2-letter ISO 3166-1 alpha-2 country code.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return string|null Country code (e.g. "BD", "US"), or null if unknown/invalid/no database.
	 */
	public static function country_for_ip( string $ip ): ?string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::country_for_ipv4( $ip );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::country_for_ipv6( $ip );
		}

		return null;
	}

	/**
	 * Binary-search the IPv4 table for the country owning $ip.
	 *
	 * @param string $ip Valid IPv4 address.
	 * @return string|null
	 */
	private static function country_for_ipv4( string $ip ): ?string {
		if ( ! self::load_table_v4() ) {
			return null;
		}

		$packed = pack( 'N', ip2long( $ip ) );
		$target = unpack( 'N', $packed )[1];
		// unpack('N', ...) yields a signed int on 32-bit PHP builds for values > PHP_INT_MAX/2;
		// normalize to an unsigned representation for correct numeric comparison below.
		if ( $target < 0 ) {
			$target += 4294967296;
		}

		$low  = 0;
		$high = self::$record_count_v4 - 1;

		while ( $low <= $high ) {
			$mid    = (int) floor( ( $low + $high ) / 2 );
			$offset = $mid * self::RECORD_SIZE_V4;
			$record = substr( self::$table_v4, $offset, self::RECORD_SIZE_V4 );

			if ( false === $record || strlen( $record ) < self::RECORD_SIZE_V4 ) {
				return null;
			}

			$unpacked = unpack( 'Nstart/Nend/a2country', $record );
			$start    = $unpacked['start'] < 0 ? $unpacked['start'] + 4294967296 : $unpacked['start'];
			$end      = $unpacked['end'] < 0 ? $unpacked['end'] + 4294967296 : $unpacked['end'];

			if ( $target < $start ) {
				$high = $mid - 1;
			} elseif ( $target > $end ) {
				$low = $mid + 1;
			} else {
				$country = $unpacked['country'];
				return '??' === $country ? null : $country;
			}
		}

		return null;
	}

	/**
	 * Binary-search the IPv6 table for the country owning $ip. Ranges and the target address are
	 * all compared as raw 16-byte big-endian binary strings via PHP's native string comparison —
	 * correct because every operand is exactly the same length (see class docblock).
	 *
	 * @param string $ip Valid IPv6 address.
	 * @return string|null
	 */
	private static function country_for_ipv6( string $ip ): ?string {
		if ( ! self::load_table_v6() ) {
			return null;
		}

		$target = inet_pton( $ip );
		if ( false === $target || 16 !== strlen( $target ) ) {
			return null;
		}

		$low  = 0;
		$high = self::$record_count_v6 - 1;

		while ( $low <= $high ) {
			$mid    = (int) floor( ( $low + $high ) / 2 );
			$offset = $mid * self::RECORD_SIZE_V6;
			$record = substr( self::$table_v6, $offset, self::RECORD_SIZE_V6 );

			if ( false === $record || strlen( $record ) < self::RECORD_SIZE_V6 ) {
				return null;
			}

			$start   = substr( $record, 0, 16 );
			$end     = substr( $record, 16, 16 );
			$country = substr( $record, 32, 2 );

			if ( $target < $start ) {
				$high = $mid - 1;
			} elseif ( $target > $end ) {
				$low = $mid + 1;
			} else {
				return '??' === $country ? null : $country;
			}
		}

		return null;
	}

	/**
	 * Load the packed IPv4 binary table into memory once per request.
	 *
	 * @return bool True if the table is available and loaded.
	 */
	private static function load_table_v4(): bool {
		if ( null !== self::$table_v4 ) {
			return '' !== self::$table_v4;
		}

		$contents = self::read_table_file( GeoIpInstaller::target_path(), self::RECORD_SIZE_V4 );

		self::$table_v4        = $contents ?? '';
		self::$record_count_v4 = null === $contents ? 0 : (int) ( strlen( $contents ) / self::RECORD_SIZE_V4 );

		return null !== $contents;
	}

	/**
	 * Load the packed IPv6 binary table into memory once per request.
	 *
	 * @return bool True if the table is available and loaded.
	 */
	private static function load_table_v6(): bool {
		if ( null !== self::$table_v6 ) {
			return '' !== self::$table_v6;
		}

		$contents = self::read_table_file( GeoIpInstaller::target_path_v6(), self::RECORD_SIZE_V6 );

		self::$table_v6        = $contents ?? '';
		self::$record_count_v6 = null === $contents ? 0 : (int) ( strlen( $contents ) / self::RECORD_SIZE_V6 );

		return null !== $contents;
	}

	/**
	 * Read and validate a packed binary range table from disk.
	 *
	 * @param string $path        Absolute path to the .bin file.
	 * @param int    $record_size Expected fixed record size in bytes; the file must be an exact multiple of it.
	 * @return string|null File contents, or null if missing/unreadable/malformed.
	 */
	private static function read_table_file( string $path, int $record_size ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// Reads a local packed binary table, never a URL, and runs on every front-end redirect —
		// bootstrapping WP_Filesystem here would add a per-request cost for no benefit.
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents || 0 !== ( strlen( $contents ) % $record_size ) ) {
			return null;
		}

		return $contents;
	}
}
