<?php
/**
 * Data access layer for the wp_qlqr_activity_log table (the admin action audit trail).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\ActivityLogEntry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This file is the data-access layer for the plugin's own custom tables, so two findings are
 * structural here rather than defects, and are declared once at file level instead of being
 * repeated on every query:
 *
 *  - DirectDatabaseQuery.DirectQuery  — custom tables have no WP_Query/get_posts() equivalent;
 *    direct $wpdb access is the only way to read them.
 *  - DirectDatabaseQuery.NoCaching    — these back click/view analytics that must reflect writes
 *    immediately; a stale object-cache read would report wrong numbers.
 *
 * Table names (from Installer::*_table(), i.e. $wpdb->prefix plus a hardcoded suffix — never
 * caller-supplied) are bound through prepare()'s %i identifier placeholder rather than
 * interpolated into the query string, so every query here is genuinely prepared and no
 * PreparedSQL suppression is needed.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class ActivityLogRepository
 */
final class ActivityLogRepository {

	/**
	 * Insert a new log entry.
	 *
	 * @param array<string, mixed> $data Column => value pairs, already prepared by the caller.
	 * @return bool
	 */
	public function insert( array $data ): bool {
		global $wpdb;

		$defaults = array(
			'object_id'  => null,
			'created_at' => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		return false !== $wpdb->insert( Installer::activity_log_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Paginated list of log entries for the Activity Log admin table (newest first).
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $object_type Filter to one object type ('' or 'all' = every type).
	 *     @type string $search      Free-text search on the description/user_name columns.
	 *     @type int    $per_page    Items per page.
	 *     @type int    $paged       Current page (1-indexed).
	 * }
	 * @return array{items: ActivityLogEntry[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table = Installer::activity_log_table();

		$defaults = array(
			'object_type' => 'all',
			'search'      => '',
			'per_page'    => 20,
			'paged'       => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$has_type_filter   = '' !== $args['object_type'] && 'all' !== $args['object_type'];
		$has_search_filter = '' !== $args['search'];
		$like              = $has_search_filter ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		// Each filter combination gets its own complete literal query, written directly at the
		// prepare() call site (never assembled into a variable first), so every placeholder is
		// statically verifiable — rather than concatenating a WHERE fragment onto a shared string.
		if ( $has_type_filter && $has_search_filter ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE object_type = %s AND (description LIKE %s OR user_name LIKE %s)', $table, $args['object_type'], $like, $like )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND (description LIKE %s OR user_name LIKE %s) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $table, $args['object_type'], $like, $like, $per_page, $offset ),
				ARRAY_A
			);
		} elseif ( $has_type_filter ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE object_type = %s', $table, $args['object_type'] )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $table, $args['object_type'], $per_page, $offset ),
				ARRAY_A
			);
		} elseif ( $has_search_filter ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE (description LIKE %s OR user_name LIKE %s)', $table, $like, $like )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE (description LIKE %s OR user_name LIKE %s) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $table, $like, $like, $per_page, $offset ),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i', $table )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $table, $per_page, $offset ),
				ARRAY_A
			);
		}

		return array(
			'items' => array_map( array( ActivityLogEntry::class, 'from_row' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Delete entries older than $days days. Run on the daily cron via Helpers\ActivityLogger.
	 *
	 * @param int $days Retention window in days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$table  = Installer::activity_log_table();
		$cutoff = current_datetime()->modify( '-' . max( 1, $days ) . ' days' )->format( 'Y-m-d H:i:s' );

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
