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
	 * Unprefixed table name.
	 */
	const TABLE = 'blc_links';

	/**
	 * Unprefixed table name used when `blc_links` is already taken.
	 */
	const FALLBACK_TABLE = 'lwblc_links';

	/**
	 * Option holding the unprefixed table name actually in use.
	 */
	const TABLE_OPTION = 'lwblc_table_name';

	/**
	 * Request level cache for table_exists().
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Request level cache for status_counts().
	 *
	 * @var array<string,int>|null
	 */
	private static $counts = null;

	/**
	 * Request level cache for the resolved table name.
	 *
	 * @var string|null
	 */
	private static $table_name = null;

	/**
	 * Clears the request level caches.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$table_exists = null;
		self::$counts       = null;
		self::$table_name   = null;
	}

	/**
	 * Returns the prefixed table name.
	 *
	 * Normally `{prefix}blc_links`. If that name is already held by a table
	 * this plugin did not create — the long standing Broken Link Checker
	 * plugin uses exactly the same name with a completely different schema —
	 * activation switches to `{prefix}lwblc_links` and records the choice, so
	 * the two plugins never write to each other's data.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		if ( null === self::$table_name ) {
			$stored = get_option( self::TABLE_OPTION, '' );

			self::$table_name = self::FALLBACK_TABLE === $stored
				? $wpdb->prefix . self::FALLBACK_TABLE
				: $wpdb->prefix . self::TABLE;
		}

		return self::$table_name;
	}

	/**
	 * Picks the table name to install into and stores it.
	 *
	 * Called before the schema is created. A `blc_links` table that has no
	 * `source_post_id` column belongs to another plugin and must be left alone.
	 *
	 * @return string The unprefixed table name that will be used.
	 */
	public static function resolve_table_name() {
		global $wpdb;

		$stored = get_option( self::TABLE_OPTION, '' );

		if ( self::FALLBACK_TABLE === $stored || self::TABLE === $stored ) {
			return $stored;
		}

		$name  = self::TABLE;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup during activation.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $exists === $table ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
			$sql = $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'source_post_id' );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Schema lookup, prepared above.
			$column = $wpdb->get_var( $sql );

			if ( 'source_post_id' !== $column ) {
				$name = self::FALLBACK_TABLE;
			}
		}

		update_option( self::TABLE_OPTION, $name );

		self::flush_cache();

		return $name;
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
			KEY status_modified (status,post_modified_date),
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

		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup, cached for the request above.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		self::$table_exists = ( $found === $table );

		return self::$table_exists;
	}

	/**
	 * Counts rows grouped by status.
	 *
	 * The result is cached for the rest of the request: the admin screen asks
	 * for it from the summary box, the filter views and the AJAX payload.
	 *
	 * @param bool $refresh Set to true to bypass the request cache.
	 * @return array<string,int> Status => count, including zero counts.
	 */
	public static function status_counts( $refresh = false ) {
		global $wpdb;

		if ( ! $refresh && null !== self::$counts ) {
			return self::$counts;
		}

		$counts = array_fill_keys( self::statuses(), 0 );

		if ( ! self::table_exists() ) {
			self::$counts = $counts;

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

		self::$counts = $counts;

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

		self::flush_cache();
	}
}
