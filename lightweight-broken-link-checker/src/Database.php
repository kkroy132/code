<?php
/**
 * Table definition and low level data access for discovered links.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `{prefix}blc_links` table.
 */
class Database {

	/**
	 * Link has never been checked.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Link responded successfully.
	 */
	const STATUS_OK = 'ok';

	/**
	 * Link is confirmed broken.
	 */
	const STATUS_BROKEN = 'broken';

	/**
	 * Link answered with a 3xx redirect.
	 */
	const STATUS_REDIRECT = 'redirect';

	/**
	 * Number of characters of `link_url` covered by the unique index.
	 *
	 * Kept well under the 767 byte InnoDB prefix limit for utf8mb4
	 * (180 * 4 bytes + 8 bytes for the bigint = 728 bytes).
	 */
	const URL_INDEX_LENGTH = 180;

	/**
	 * Returns the prefixed table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'blc_links';
	}

	/**
	 * Returns every status the `status` column accepts.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING,
			self::STATUS_OK,
			self::STATUS_BROKEN,
			self::STATUS_REDIRECT,
		);
	}

	/**
	 * Validates a status value, falling back to `pending`.
	 *
	 * @param string $status Candidate status.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	/**
	 * Returns the CREATE TABLE statement used by dbDelta().
	 *
	 * `status` is a varchar rather than a real ENUM because dbDelta() cannot
	 * reliably diff ENUM definitions; the allowed values are enforced in PHP
	 * through self::sanitize_status().
	 *
	 * @return string
	 */
	public static function schema() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		$url_len = self::URL_INDEX_LENGTH;

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			link_url varchar(2048) NOT NULL,
			link_text varchar(255) NOT NULL DEFAULT '',
			source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_post_title varchar(255) NOT NULL DEFAULT '',
			post_modified_date datetime DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			fail_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY link_source (link_url({$url_len}),source_post_id),
			KEY status_checked (status,last_checked_at),
			KEY source_post_id (source_post_id),
			KEY post_modified_date (post_modified_date)
		) {$collate};";
	}

	/**
	 * Whether the links table exists in the database.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, not cacheable.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Counts rows grouped by status.
	 *
	 * @return array<string,int> Status => count, including zero counts.
	 */
	public static function status_counts() {
		global $wpdb;

		$counts = array_fill_keys( self::statuses(), 0 );

		if ( ! self::table_exists() ) {
			return $counts;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix; no user input in this query.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = self::sanitize_status( $row['status'] );

				$counts[ $status ] = isset( $counts[ $status ] )
					? $counts[ $status ] + (int) $row['total']
					: (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Total number of stored links.
	 *
	 * @return int
	 */
	public static function total_links() {
		return array_sum( self::status_counts() );
	}

	/**
	 * Deletes every stored link.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
