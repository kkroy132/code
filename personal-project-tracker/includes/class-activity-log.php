<?php
/**
 * Activity logging service, shared by every feature module.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Activity_Log
 *
 * Thin, reusable wrapper around the activity_logs table. Feature modules
 * call PTP_Activity_Log::log() instead of writing their own INSERT so the
 * audit trail stays in one shape everywhere. Never pass amounts, secrets,
 * or full record contents into $description — a short human-readable
 * summary only.
 */
class PTP_Activity_Log {

	/**
	 * Record an activity log entry.
	 *
	 * @param string $action      Short machine-readable action slug, e.g. 'project_created'.
	 * @param string $object_type Optional. Related object type, e.g. 'project'.
	 * @param int    $object_id   Optional. Related object ID.
	 * @param string $description Optional. Short human-readable summary (no sensitive data).
	 */
	public static function log( $action, $object_type = '', $object_id = 0, $description = '' ) {
		global $wpdb;

		$data   = array(
			'action'     => sanitize_key( $action ),
			'user_id'    => get_current_user_id(),
			'created_at' => ptp_now(),
		);
		$format = array( '%s', '%d', '%s' );

		if ( $object_type ) {
			$data['object_type'] = sanitize_key( $object_type );
			$format[]            = '%s';
		}

		if ( $object_id ) {
			$data['object_id'] = (int) $object_id;
			$format[]          = '%d';
		}

		if ( $description ) {
			$data['description'] = sanitize_text_field( $description );
			$format[]            = '%s';
		}

		$wpdb->insert( ptp_table( 'activity_logs' ), $data, $format );
	}

	/**
	 * Get recent activity for a specific object (e.g. a project's history).
	 *
	 * @param string $object_type Object type, e.g. 'project'.
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Max rows to return.
	 * @return object[]
	 */
	public static function get_for_object( $object_type, $object_id, $limit = 10 ) {
		global $wpdb;

		$table = ptp_table( 'activity_logs' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $object_type ),
				(int) $object_id,
				(int) $limit
			)
		);
	}

	/**
	 * Get the most recent activity across all objects, e.g. for a dashboard widget.
	 *
	 * @param int $limit Max rows to return.
	 * @return object[]
	 */
	public static function get_recent( $limit = 10 ) {
		global $wpdb;

		$table = ptp_table( 'activity_logs' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $limit
			)
		);
	}

	/**
	 * Filtered activity summary for the Reports module (Activity Report):
	 * a per-action count breakdown plus a small capped list of the most
	 * recent matching entries. One GROUP BY query for the counts, one
	 * LIMIT-bounded query for the recent list — never loads the full log
	 * into PHP.
	 *
	 * @param array $args {
	 *     @type string $object_type Object type filter, e.g. 'project'.
	 *     @type string $date_from   'Y-m-d' range start (inclusive).
	 *     @type string $date_to     'Y-m-d' range end (inclusive).
	 *     @type int    $limit       Max recent rows to return (capped at 100).
	 * }
	 * @return array{total: int, by_action: array<string,int>, recent: object[]}
	 */
	public static function get_report( array $args = array() ) {
		global $wpdb;

		$table = ptp_table( 'activity_logs' );

		$args = wp_parse_args(
			$args,
			array(
				'object_type' => '',
				'date_from'   => '',
				'date_to'     => '',
				'limit'       => 20,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['object_type'] ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( $args['object_type'] );
		}

		$date_from = trim( (string) $args['date_from'] );

		if ( $date_from && DateTime::createFromFormat( 'Y-m-d', $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = trim( (string) $args['date_to'] );

		if ( $date_to && DateTime::createFromFormat( 'Y-m-d', $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql  = "SELECT action, COUNT(*) as cnt FROM {$table} WHERE {$where_sql} GROUP BY action"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_rows = $params
			? $wpdb->get_results( $wpdb->prepare( $count_sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $count_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_action = array();

		foreach ( $count_rows as $row ) {
			$by_action[ $row['action'] ] = (int) $row['cnt'];
		}

		$limit         = max( 1, min( 100, (int) $args['limit'] ) );
		$recent_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent_params = array_merge( $params, array( $limit ) );
		$recent        = $wpdb->get_results( $wpdb->prepare( $recent_sql, $recent_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total'     => array_sum( $by_action ),
			'by_action' => $by_action,
			'recent'    => $recent,
		);
	}
}
