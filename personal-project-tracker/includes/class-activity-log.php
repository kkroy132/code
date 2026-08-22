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
}
