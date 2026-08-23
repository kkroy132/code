<?php
/**
 * Backup manifest storage.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Backup_Repository
 *
 * Owns the small list of "which backup files exist" — one autoloaded
 * wp_options entry, the same option-backed-repository pattern
 * PTP_Settings already uses. The backup *content* lives in files on disk
 * (see PTP_Backup_Service, which uses the WordPress filesystem API); this
 * class only owns the manifest entries (id, filename, created_at,
 * plugin_version, db_version, size) so the Backup tab can list/download/
 * delete them without reading every file just to show a list.
 */
class PTP_Backup_Repository {

	/**
	 * wp_options key.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'ptp_backup_manifest';

	/**
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public static function get_all() {
		$list = get_option( self::OPTION_NAME, array() );
		$list = is_array( $list ) ? $list : array();

		usort( $list, fn( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ) );

		return $list;
	}

	/**
	 * @param string $id Backup ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		foreach ( self::get_all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $entry Manifest entry.
	 * @return array<string, mixed>
	 */
	public static function add( array $entry ) {
		$all   = get_option( self::OPTION_NAME, array() );
		$all   = is_array( $all ) ? $all : array();
		$all[] = $entry;

		update_option( self::OPTION_NAME, $all );

		return $entry;
	}

	/**
	 * @param string $id Backup ID.
	 * @return bool True if an entry was removed.
	 */
	public static function delete( $id ) {
		$all          = get_option( self::OPTION_NAME, array() );
		$all          = is_array( $all ) ? $all : array();
		$before_count = count( $all );
		$all          = array_values( array_filter( $all, fn( $entry ) => ( $entry['id'] ?? '' ) !== $id ) );

		update_option( self::OPTION_NAME, $all );

		return count( $all ) !== $before_count;
	}
}
