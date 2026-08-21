<?php
/**
 * Schema migrations.
 *
 * Every step checks the live schema before it touches anything, so a run that
 * is interrupted half way simply continues where it stopped the next time.
 * The stored schema version is only advanced once every step of a release has
 * reported success.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

/**
 * Applies schema changes to an existing installation.
 */
class Migrations {

	/**
	 * Rows hashed per query while backfilling.
	 */
	const BACKFILL_BATCH = 200;

	/**
	 * Seconds one migration pass may spend backfilling before it yields.
	 */
	const TIME_BUDGET = 3.0;

	/**
	 * Runs every migration the installed version still needs.
	 *
	 * @param string $from Installed schema version, empty on a fresh install.
	 * @return bool True when the schema is fully migrated.
	 */
	public static function run( $from ) {
		$from = is_string( $from ) && '' !== $from ? $from : '0';

		if ( version_compare( $from, '1.2.0', '<' ) ) {
			if ( ! self::to_1_2_0() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Moves a 1.1.x table to hash based uniqueness.
	 *
	 * @return bool True when the step is complete.
	 */
	private static function to_1_2_0() {
		$table = Database::table();

		if ( ! Database::table_exists() ) {
			// Nothing installed yet; dbDelta() will create the current schema.
			return true;
		}

		// 1. The new columns. Nullable at first so existing rows stay valid.
		if ( ! self::column_exists( $table, 'link_hash' ) ) {
			if ( ! self::alter( $table, 'add_hash_column' ) ) {
				return false;
			}
		}

		if ( ! self::column_exists( $table, 'status_reason' ) ) {
			if ( ! self::alter( $table, 'add_reason_column' ) ) {
				return false;
			}
		}

		// 2. Hash every row that does not have one yet. Resumable.
		if ( ! self::backfill_hashes( $table ) ) {
			self::schedule_continuation();

			return false;
		}

		// 3. Collapse rows that would violate the new constraint.
		self::remove_duplicates( $table );

		// 4. Swap the prefix based unique index for the hash based one.
		$indexes = self::index_columns( $table );

		if ( isset( $indexes['link_source'] ) && array( 'source_post_id', 'link_hash' ) !== $indexes['link_source'] ) {
			if ( ! self::alter( $table, 'drop_unique_index' ) ) {
				return false;
			}

			unset( $indexes['link_source'] );
		}

		if ( ! isset( $indexes['link_source'] ) ) {
			if ( ! self::alter( $table, 'add_unique_index' ) ) {
				return false;
			}
		}

		// 5. With every row hashed and unique, the column can be required.
		if ( self::column_is_nullable( $table, 'link_hash' ) ) {
			self::alter( $table, 'require_hash_column' );
		}

		/*
		 * 6. A scan queued by 1.1.x carries a row offset, while batches now
		 * continue from the last post ID. Drop those actions rather than let
		 * them resume with a number that means something else, and mark the
		 * scan as finished so the next one starts cleanly.
		 */
		self::reset_running_scan();

		return true;
	}

	/**
	 * Fills in missing hashes, a batch at a time, within a time budget.
	 *
	 * @param string $table Table name.
	 * @return bool True when no row is left without a hash.
	 */
	private static function backfill_hashes( $table ) {
		global $wpdb;

		$table    = esc_sql( $table );
		$deadline = microtime( true ) + self::TIME_BUDGET;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration on a custom table; the name comes from $wpdb->prefix.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, link_url FROM `{$table}` WHERE link_hash IS NULL ORDER BY id ASC LIMIT %d",
					self::BACKFILL_BATCH
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $rows ) ) {
				return true;
			}

			foreach ( $rows as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$updated = $wpdb->update(
					$table,
					array( 'link_hash' => Database::hash_url( $row['link_url'] ) ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					self::log( 'hash backfill failed for one row' );

					return false;
				}
			}
		} while ( microtime( true ) < $deadline );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE link_hash IS NULL" );

		return 0 === $left;
	}

	/**
	 * Removes rows that repeat a post and URL pair, keeping the oldest.
	 *
	 * The 1.1.x unique index already made these impossible, so this normally
	 * finds nothing. It exists because the new index cannot be created while
	 * even one duplicate is present, and a table whose index was dropped by
	 * hand must still be able to upgrade.
	 *
	 * @param string $table Table name.
	 * @return int Rows removed.
	 */
	private static function remove_duplicates( $table ) {
		global $wpdb;

		$table = esc_sql( $table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration on a custom table; the name comes from $wpdb->prefix.
		$groups = $wpdb->get_results(
			"SELECT source_post_id, link_hash, MIN(id) AS keep_id
			FROM `{$table}`
			GROUP BY source_post_id, link_hash
			HAVING COUNT(*) > 1
			LIMIT 1000",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $groups ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( $groups as $group ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration on a custom table; the name comes from $wpdb->prefix.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE source_post_id = %d AND link_hash = %s AND id > %d",
					(int) $group['source_post_id'],
					$group['link_hash'],
					(int) $group['keep_id']
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$removed += max( 0, (int) $deleted );
		}

		if ( $removed > 0 ) {
			self::log( sprintf( 'removed %d duplicate link rows before adding the unique index', $removed ) );
		}

		return $removed;
	}

	/**
	 * Cancels queued scan batches and clears a scan left running.
	 *
	 * @return void
	 */
	private static function reset_running_scan() {
		Scheduler::unschedule( Scheduler::HOOK_SCAN_BATCH );

		$state = get_option( Scanner::STATE_OPTION, array() );

		if ( is_array( $state ) && ! empty( $state['running'] ) ) {
			$state['running']     = false;
			$state['finished_at'] = Plugin::now();

			update_option( Scanner::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Queues another migration pass through Action Scheduler.
	 *
	 * @return void
	 */
	private static function schedule_continuation() {
		if ( ! Scheduler::is_available() ) {
			return;
		}

		Scheduler::schedule_single( time() + 30, Scheduler::HOOK_MIGRATE, array(), true );
	}

	/**
	 * Applies one of the schema changes this migration knows about.
	 *
	 * The statement is chosen from a fixed set rather than assembled from a
	 * caller supplied fragment, so no arbitrary DDL can reach the database.
	 *
	 * @param string $table  Table name.
	 * @param string $change Change identifier.
	 * @return bool True when the change was applied.
	 */
	private static function alter( $table, $change ) {
		global $wpdb;

		$table = esc_sql( $table );

		switch ( $change ) {
			case 'add_hash_column':
				$sql = "ALTER TABLE `{$table}` ADD COLUMN link_hash binary(32) NULL AFTER link_url";
				break;
			case 'add_reason_column':
				$sql = "ALTER TABLE `{$table}` ADD COLUMN status_reason varchar(32) NOT NULL DEFAULT '' AFTER status";
				break;
			case 'drop_unique_index':
				$sql = "ALTER TABLE `{$table}` DROP INDEX link_source";
				break;
			case 'add_unique_index':
				$sql = "ALTER TABLE `{$table}` ADD UNIQUE KEY link_source (source_post_id,link_hash)";
				break;
			case 'require_hash_column':
				$sql = "ALTER TABLE `{$table}` MODIFY link_hash binary(32) NOT NULL";
				break;
			default:
				return false;
		}

		$was_suppressed = $wpdb->suppress_errors( true );
		$was_showing    = $wpdb->hide_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Fixed statement chosen above; the table name comes from $wpdb->prefix.
		$result = $wpdb->query( $sql );

		$wpdb->suppress_errors( $was_suppressed );

		if ( $was_showing ) {
			$wpdb->show_errors();
		}

		if ( false === $result ) {
			// The change identifier is safe to log; the database error text is not.
			self::log( 'schema change failed: ' . $change );

			return false;
		}

		Database::flush_cache();

		return true;
	}

	/**
	 * Whether a column exists.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function column_exists( $table, $column ) {
		global $wpdb;

		$table = esc_sql( $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup on a custom table; the name comes from $wpdb->prefix.
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $found === $column;
	}

	/**
	 * Whether a column still accepts NULL.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function column_is_nullable( $table, $column ) {
		global $wpdb;

		$table = esc_sql( $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup on a custom table; the name comes from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) && isset( $row['Null'] ) && 'YES' === $row['Null'];
	}

	/**
	 * Lists the columns of every index on a table.
	 *
	 * @param string $table Table name.
	 * @return array<string,string[]> Index name => ordered column names.
	 */
	public static function index_columns( $table ) {
		global $wpdb;

		$table = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexes = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['Key_name'] ) || empty( $row['Column_name'] ) ) {
				continue;
			}

			$sequence = isset( $row['Seq_in_index'] ) ? (int) $row['Seq_in_index'] : 1;

			$indexes[ $row['Key_name'] ][ $sequence ] = $row['Column_name'];
		}

		foreach ( $indexes as $name => $columns ) {
			ksort( $columns );

			$indexes[ $name ] = array_values( $columns );
		}

		return $indexes;
	}

	/**
	 * Records a migration problem without leaking database internals.
	 *
	 * @param string $message Short description.
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Behind WP_DEBUG, no user or database data included.
			error_log( 'Lightweight Broken Link Checker: ' . $message );
		}
	}
}
