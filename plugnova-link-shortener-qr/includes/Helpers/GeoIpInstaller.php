<?php
/**
 * One-click, admin-triggered download and build of the offline GeoIP country database.
 *
 * The database is NOT bundled in the plugin package (it would make the .zip too large for many
 * hosts' upload limits — this plugin previously shipped with it and that caused install failures
 * on tighter shared hosting). Instead, an administrator can click a button in Settings to have
 * the server fetch the public, CC0-licensed IP-range dataset directly and build the compact
 * lookup table used by Helpers\GeoLocator. This is a one-time bulk download of public country
 * data — it is unrelated to, and does not affect, the "no external calls for visitor IPs"
 * guarantee described in the plugin's readme.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GeoIpInstaller
 */
final class GeoIpInstaller {

	/**
	 * npm registry metadata endpoint for the CC0-licensed IPv4-to-country dataset package.
	 * Resolving this first (rather than hardcoding a version-specific .tgz URL) means the
	 * download keeps working as the dataset publishes new versions over time.
	 * See https://github.com/sapics/ip-location-db (geo-asn-country / asn-country dataset).
	 */
	private const PACKAGE_METADATA_URL = 'https://registry.npmjs.org/@ip-location-db/asn-country/latest';

	/**
	 * Where the built IPv4 binary table is stored (outside the plugin folder, so it survives updates).
	 *
	 * @return string
	 */
	public static function target_path(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'plugnova-link-shortener-qr/geoip-country-ipv4.bin';
	}

	/**
	 * Where the built IPv6 binary table is stored.
	 *
	 * @return string
	 */
	public static function target_path_v6(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'plugnova-link-shortener-qr/geoip-country-ipv6.bin';
	}

	/**
	 * Whether the IPv4 GeoIP database is currently installed.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return is_readable( self::target_path() );
	}

	/**
	 * Whether the IPv6 GeoIP database is currently installed.
	 *
	 * @return bool
	 */
	public static function is_installed_v6(): bool {
		return is_readable( self::target_path_v6() );
	}

	/**
	 * Details about the currently installed database(s), for the Settings page.
	 *
	 * @return array{installed:bool, ranges:int, size:int, modified:string, ipv6_installed:bool, ipv6_ranges:int, ipv6_size:int}
	 */
	public static function status(): array {
		$v4 = self::describe_file( self::target_path(), 10 );
		$v6 = self::describe_file( self::target_path_v6(), 34 );

		return array(
			'installed'      => $v4['installed'],
			'ranges'         => $v4['ranges'],
			'size'           => $v4['size'],
			'modified'       => $v4['modified'],
			'ipv6_installed' => $v6['installed'],
			'ipv6_ranges'    => $v6['ranges'],
			'ipv6_size'      => $v6['size'],
		);
	}

	/**
	 * Describe a single .bin table file for the status report.
	 *
	 * @param string $path        Absolute path.
	 * @param int    $record_size Fixed record size in bytes for this table.
	 * @return array{installed:bool, ranges:int, size:int, modified:string}
	 */
	private static function describe_file( string $path, int $record_size ): array {
		if ( ! is_readable( $path ) ) {
			return array(
				'installed' => false,
				'ranges'    => 0,
				'size'      => 0,
				'modified'  => '',
			);
		}

		$size = (int) filesize( $path );

		return array(
			'installed' => true,
			'ranges'    => (int) ( $size / $record_size ),
			'size'      => $size,
			'modified'  => gmdate( 'Y-m-d H:i', (int) filemtime( $path ) ),
		);
	}

	/**
	 * Download the dataset and build the binary lookup table used by GeoLocator.
	 *
	 * @return array{success:bool, message:string}
	 */
	public static function download_and_build(): array {
		if ( ! class_exists( '\PharData' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Your server does not have the PHP "Phar" extension enabled, which is required to unpack the GeoIP dataset. Please ask your hosting provider to enable it.', 'plugnova-link-shortener-qr' ),
			);
		}

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'qlqr-geoip-' . wp_generate_password( 8, false );
		wp_mkdir_p( $tmp_dir );

		$metadata_response = wp_remote_get( self::PACKAGE_METADATA_URL, array( 'timeout' => 30 ) );

		if ( is_wp_error( $metadata_response ) ) {
			self::cleanup_dir( $tmp_dir );
			return array(
				'success' => false,
				/* translators: %s: underlying error message */
				'message' => sprintf( __( 'Could not reach the dataset registry: %s', 'plugnova-link-shortener-qr' ), $metadata_response->get_error_message() ),
			);
		}

		$metadata = json_decode( wp_remote_retrieve_body( $metadata_response ), true );
		$tarball_url = $metadata['dist']['tarball'] ?? '';

		if ( '' === $tarball_url || ! wp_http_validate_url( $tarball_url ) ) {
			self::cleanup_dir( $tmp_dir );
			return array(
				'success' => false,
				'message' => __( 'Could not determine the dataset download URL from the registry response.', 'plugnova-link-shortener-qr' ),
			);
		}

		$response = wp_remote_get(
			$tarball_url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $tmp_dir . '/package.tgz',
			)
		);

		if ( is_wp_error( $response ) ) {
			self::cleanup_dir( $tmp_dir );
			return array(
				'success' => false,
				/* translators: %s: underlying error message */
				'message' => sprintf( __( 'Download failed: %s', 'plugnova-link-shortener-qr' ), $response->get_error_message() ),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			self::cleanup_dir( $tmp_dir );
			return array(
				'success' => false,
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'Download failed: server responded with HTTP %d.', 'plugnova-link-shortener-qr' ), $status_code ),
			);
		}

		try {
			$phar = new \PharData( $tmp_dir . '/package.tgz' );
			$phar->extractTo( $tmp_dir . '/extracted', null, true );
		} catch ( \Exception $e ) {
			self::cleanup_dir( $tmp_dir );
			return array(
				'success' => false,
				/* translators: %s: underlying error message */
				'message' => sprintf( __( 'Could not unpack the downloaded dataset: %s', 'plugnova-link-shortener-qr' ), $e->getMessage() ),
			);
		}

		$csv_path = self::find_csv( $tmp_dir . '/extracted' );
		if ( ! $csv_path ) {
			self::cleanup_dir( $tmp_dir );
			return array(
				'success' => false,
				'message' => __( 'The downloaded dataset did not contain the expected data file.', 'plugnova-link-shortener-qr' ),
			);
		}

		$built = self::build_binary( $csv_path, self::target_path() );

		// IPv6 is best-effort: if the package doesn't include an IPv6 file (or building it fails
		// for any reason), the IPv4 install above still succeeds — visitors just fall back to no
		// country detection for IPv6 requests, same as before this feature existed.
		$csv_path_v6 = self::find_csv_v6( $tmp_dir . '/extracted' );
		$built_v6    = $csv_path_v6 ? self::build_binary_v6( $csv_path_v6, self::target_path_v6() ) : false;

		self::cleanup_dir( $tmp_dir );

		if ( ! $built ) {
			return array(
				'success' => false,
				'message' => __( 'Could not write the GeoIP database file. Check that your uploads directory is writable.', 'plugnova-link-shortener-qr' ),
			);
		}

		$status = self::status();

		$message = $built_v6
			? sprintf(
				/* translators: 1: number of IPv4 ranges loaded, 2: number of IPv6 ranges loaded */
				__( 'GeoIP database installed successfully (%1$s IPv4 ranges, %2$s IPv6 ranges).', 'plugnova-link-shortener-qr' ),
				number_format_i18n( $status['ranges'] ),
				number_format_i18n( $status['ipv6_ranges'] )
			)
			: sprintf(
				/* translators: %s: number of IPv4 ranges loaded */
				__( 'GeoIP database installed successfully (%s IPv4 ranges). The IPv6 dataset was not available this time, so IPv6 visitors will not show a country until a future update includes it.', 'plugnova-link-shortener-qr' ),
				number_format_i18n( $status['ranges'] )
			);

		return array(
			'success' => true,
			'message' => $message,
		);
	}

	/**
	 * Remove the installed GeoIP database(s) (reverts to no country detection).
	 */
	public static function remove(): void {
		foreach ( array( self::target_path(), self::target_path_v6() ) as $path ) {
			if ( is_readable( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Recursively find the "*-ipv4-num.csv" file inside the extracted package.
	 *
	 * @param string $dir Directory to search.
	 * @return string|null Absolute path, or null if not found.
	 */
	private static function find_csv( string $dir ): ?string {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && str_ends_with( $file->getFilename(), '-ipv4-num.csv' ) ) {
				return $file->getPathname();
			}
		}

		return null;
	}

	/**
	 * Recursively find the IPv6 data file inside the extracted package. Matches any CSV whose
	 * name contains "ipv6" rather than a single exact suffix, since build_binary_v6() autodetects
	 * per-line whether values are textual addresses or big-integer numeric form either way.
	 *
	 * @param string $dir Directory to search.
	 * @return string|null Absolute path, or null if not found.
	 */
	private static function find_csv_v6( string $dir ): ?string {
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && str_contains( $file->getFilename(), 'ipv6' ) && str_ends_with( $file->getFilename(), '.csv' ) ) {
				return $file->getPathname();
			}
		}

		return null;
	}

	/**
	 * Parse the "start,end,country_code" CSV and pack it into the fixed-width binary format
	 * that Helpers\GeoLocator reads (uint32 start + uint32 end + 2-byte country code per record).
	 *
	 * @param string $csv_path    Source CSV path.
	 * @param string $target_path Destination .bin path.
	 * @return bool
	 */
	private static function build_binary( string $csv_path, string $target_path ): bool {
		$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		wp_mkdir_p( dirname( $target_path ) );

		$out = fopen( $target_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$parts = explode( ',', trim( $line ) );
			if ( 3 !== count( $parts ) ) {
				continue;
			}

			list( $start, $end, $cc ) = $parts;
			$cc = strtoupper( substr( trim( $cc ), 0, 2 ) );
			if ( 2 !== strlen( $cc ) ) {
				$cc = '??';
			}

			fwrite( $out, pack( 'NNa2', (int) $start, (int) $end, $cc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}

	/**
	 * Parse the IPv6 "start,end,country_code" CSV and pack it into the fixed-width binary format
	 * Helpers\GeoLocator reads for IPv6 (16-byte start + 16-byte end + 2-byte country code).
	 *
	 * Upstream datasets publish the start/end columns in one of two forms depending on release,
	 * and this handles either without needing GMP/BCMath: plain textual addresses
	 * ("2001:db8::1", via inet_pton()) or a 128-bit value written out as a plain decimal number
	 * ("42540766411282592856903984951653826561", via decimal_to_bytes16()). Each line is
	 * autodetected independently by whether it contains a colon.
	 *
	 * @param string $csv_path    Source CSV path.
	 * @param string $target_path Destination .bin path.
	 * @return bool
	 */
	private static function build_binary_v6( string $csv_path, string $target_path ): bool {
		$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		wp_mkdir_p( dirname( $target_path ) );

		$out = fopen( $target_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		$wrote_any = false;

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$parts = explode( ',', trim( $line ) );
			if ( 3 !== count( $parts ) ) {
				continue;
			}

			list( $start_raw, $end_raw, $cc ) = $parts;

			$start = self::to_ipv6_bytes( trim( $start_raw ) );
			$end   = self::to_ipv6_bytes( trim( $end_raw ) );
			if ( null === $start || null === $end ) {
				continue;
			}

			$cc = strtoupper( substr( trim( $cc ), 0, 2 ) );
			if ( 2 !== strlen( $cc ) ) {
				$cc = '??';
			}

			fwrite( $out, $start . $end . $cc ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$wrote_any = true;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $wrote_any ) {
			wp_delete_file( $target_path );
			return false;
		}

		return true;
	}

	/**
	 * Convert one CSV field to a 16-byte big-endian IPv6 address, autodetecting textual vs.
	 * plain-decimal-number form (see build_binary_v6() docblock).
	 *
	 * @param string $value Raw field value.
	 * @return string|null Exactly 16 bytes, or null if $value is neither form.
	 */
	private static function to_ipv6_bytes( string $value ): ?string {
		if ( str_contains( $value, ':' ) ) {
			$packed = inet_pton( $value );
			return ( false !== $packed && 16 === strlen( $packed ) ) ? $packed : null;
		}

		if ( '' !== $value && ctype_digit( $value ) ) {
			return self::decimal_to_bytes16( $value );
		}

		return null;
	}

	/**
	 * Convert an arbitrary-precision non-negative decimal string (up to 39 digits — the max
	 * possible 128-bit value) to a 16-byte big-endian binary string, without GMP/BCMath.
	 * Uses schoolbook long division: repeatedly divides the decimal digit string by 256, taking
	 * one output byte (the remainder) per pass, most-significant byte last, then reverses.
	 *
	 * @param string $decimal Non-negative base-10 digit string.
	 * @return string Exactly 16 bytes.
	 */
	private static function decimal_to_bytes16( string $decimal ): string {
		$digits = str_split( ltrim( $decimal, '0' ) ?: '0' );
		$bytes  = array();

		for ( $i = 0; $i < 16; $i++ ) {
			$remainder       = 0;
			$quotient_digits = array();

			foreach ( $digits as $digit ) {
				$value     = $remainder * 10 + (int) $digit;
				$quotient  = intdiv( $value, 256 );
				$remainder = $value % 256;

				if ( ! empty( $quotient_digits ) || 0 !== $quotient ) {
					$quotient_digits[] = (string) $quotient;
				}
			}

			$bytes[] = $remainder;
			$digits  = empty( $quotient_digits ) ? array( '0' ) : $quotient_digits;
		}

		return pack( 'C*', ...array_reverse( $bytes ) );
	}

	/**
	 * Best-effort recursive cleanup of the temporary working directory, via WP_Filesystem so no
	 * direct PHP filesystem calls are needed (and so hosts using a non-direct filesystem transport
	 * still get their temp files cleaned up).
	 *
	 * @param string $dir Directory to remove.
	 */
	private static function cleanup_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Recursive delete: WP_Filesystem::delete() with $recursive = true removes the directory and
		// everything under it in one call, replacing the manual unlink()/rmdir() walk this used to do.
		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
