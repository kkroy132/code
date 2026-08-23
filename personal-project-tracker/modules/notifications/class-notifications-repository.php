<?php
/**
 * Notifications data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notifications_Repository
 *
 * Owns all reads/writes to the notifications table — the Notification
 * Center's feed. Every notification is created via create_deduped(), which
 * checks an existing row with the same dedup_key before inserting, so a
 * reminder firing twice (an overlapping cron run) or a smart alert
 * re-evaluating on every hourly pass never produces duplicate rows.
 * Nothing here decides *whether* a notification should be created (global/
 * category/quiet-hours gating) — that is PTP_Notifications_Service's job;
 * this class is CRUD only.
 */
class PTP_Notifications_Repository {

	/**
	 * Get the fully-prefixed notifications table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'notifications' );
	}

	/**
	 * Preference-bucket categories a notification can belong to. 'finance'
	 * doubles as a privacy gate — a notification in this category is hidden
	 * from anyone without ptp_manage_finance, the same way Finance data
	 * stays private everywhere else in the plugin.
	 *
	 * @return array<string, string>
	 */
	public static function get_categories() {
		return array(
			'tasks'        => __( 'Tasks', 'personal-project-tracker' ),
			'milestones'   => __( 'Milestones', 'personal-project-tracker' ),
			'projects'     => __( 'Projects', 'personal-project-tracker' ),
			'calendar'     => __( 'Calendar', 'personal-project-tracker' ),
			'time'         => __( 'Time', 'personal-project-tracker' ),
			'finance'      => __( 'Finance', 'personal-project-tracker' ),
			'custom'       => __( 'Custom', 'personal-project-tracker' ),
			'smart_alerts' => __( 'Smart Alerts', 'personal-project-tracker' ),
		);
	}

	/**
	 * @param int $id Notification ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Notification ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Whether a notification with this dedup_key already exists, created
	 * within the last $cooldown_hours (0 = "ever", used for one-shot,
	 * occurrence-scoped keys like a specific reminder firing).
	 *
	 * @param string $dedup_key      Stable dedup key.
	 * @param int    $cooldown_hours Cooldown window in hours; 0 means "any existing row blocks".
	 * @return bool
	 */
	public static function was_recently_notified( $dedup_key, $cooldown_hours = 0 ) {
		global $wpdb;

		$table = self::get_table();

		if ( $cooldown_hours <= 0 ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE dedup_key = %s", $dedup_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . (int) $cooldown_hours . ' hours' ) );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE dedup_key = %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dedup_key,
				$cutoff
			)
		);
	}

	/**
	 * Create a notification unless one with the same dedup_key was already
	 * created within the cooldown window (see was_recently_notified()).
	 * The single write path every notification source (reminders, smart
	 * alerts, and any future source) should use.
	 *
	 * @param array $data {
	 *     @type string $type          Machine-readable kind, e.g. 'task_overdue', 'reminder'.
	 *     @type string $title         Short title.
	 *     @type string $message       Optional longer message. Never include financial figures unless category is 'finance'.
	 *     @type string $category      One of self::get_categories().
	 *     @type string $related_type  Optional, e.g. 'project', 'task', 'milestone', 'calendar_event', 'reminder'.
	 *     @type int    $related_id    Optional.
	 *     @type string $dedup_key     Optional stable key for duplicate prevention.
	 *     @type int    $cooldown_hours Cooldown window for the dedup check (default 0 = "ever").
	 * }
	 * @return int|false New notification ID, or false if skipped as a duplicate.
	 */
	public static function create_deduped( array $data ) {
		$dedup_key      = isset( $data['dedup_key'] ) ? substr( (string) $data['dedup_key'], 0, 191 ) : '';
		$cooldown_hours = isset( $data['cooldown_hours'] ) ? (int) $data['cooldown_hours'] : 0;

		if ( '' !== $dedup_key && self::was_recently_notified( $dedup_key, $cooldown_hours ) ) {
			return false;
		}

		global $wpdb;

		$now = ptp_now();
		$row = array(
			'type'          => sanitize_key( $data['type'] ?? 'notice' ),
			'title'         => substr( (string) ( $data['title'] ?? '' ), 0, 255 ),
			'message'       => isset( $data['message'] ) ? (string) $data['message'] : null,
			'related_type'  => isset( $data['related_type'] ) && $data['related_type'] ? sanitize_key( $data['related_type'] ) : null,
			'related_id'    => isset( $data['related_id'] ) && $data['related_id'] ? (int) $data['related_id'] : null,
			'category'      => isset( $data['category'] ) && $data['category'] ? sanitize_key( $data['category'] ) : 'custom',
			'dedup_key'     => '' !== $dedup_key ? $dedup_key : null,
			'is_read'       => 0,
			'snoozed_until' => null,
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' );

		$inserted = $wpdb->insert( self::get_table(), $row, $formats );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert notification: ' . $wpdb->last_error );

			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $id Notification ID.
	 * @return true|WP_Error
	 */
	public static function mark_read( $id ) {
		return self::set_read( $id, 1 );
	}

	/**
	 * @param int $id Notification ID.
	 * @return true|WP_Error
	 */
	public static function mark_unread( $id ) {
		return self::set_read( $id, 0 );
	}

	/**
	 * @param int $id    Notification ID.
	 * @param int $value 0 or 1.
	 * @return true|WP_Error
	 */
	private static function set_read( $id, $value ) {
		$id = (int) $id;

		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'ptp_not_found', __( 'Notification not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array( 'is_read' => $value, 'updated_at' => ptp_now() ),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Mark every currently-visible unread notification read in one pass.
	 * Loops PTP_Notifications_Repository::mark_read() per row (bounded by
	 * the same per_page cap as get_list()) rather than a single bulk
	 * UPDATE, matching the repository's other multi-row operations
	 * (e.g. PTP_Milestones_Repository::delete() looping detach_task()).
	 *
	 * @param bool $include_finance Whether to also mark 'finance'-category notifications (only when the caller can view Finance).
	 * @return int Number of rows updated.
	 */
	public static function mark_all_read( $include_finance ) {
		$unread = self::get_list(
			array(
				'status'          => 'unread',
				'include_finance' => $include_finance,
				'include_snoozed' => true,
				'per_page'        => 200,
			)
		);

		$count = 0;

		foreach ( $unread['items'] as $notification ) {
			if ( true === self::mark_read( $notification->id ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Snooze a notification: it disappears from the default list until
	 * $until passes, then reappears (still unread) — nothing is lost, the
	 * row is never deleted or altered beyond this timestamp.
	 *
	 * @param int    $id    Notification ID.
	 * @param string $until 'Y-m-d H:i:s' datetime to snooze until.
	 * @return true|WP_Error
	 */
	public static function snooze( $id, $until ) {
		$id = (int) $id;

		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'ptp_not_found', __( 'Notification not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array( 'snoozed_until' => $until, 'updated_at' => ptp_now() ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * @param int $id Notification ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id = (int) $id;

		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'ptp_not_found', __( 'Notification not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete notification ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the notification. Please try again.', 'personal-project-tracker' ) );
		}

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of notifications. Snoozed
	 * (snoozed_until in the future) rows are excluded from the default
	 * view unless explicitly requested.
	 *
	 * @param array $args {
	 *     @type string $status          '' (all), 'read', or 'unread'.
	 *     @type string $category        Category filter.
	 *     @type bool   $include_finance Whether 'finance'-category rows may be included at all (capability gate).
	 *     @type bool   $include_snoozed Whether to include currently-snoozed rows.
	 *     @type int    $paged           1-indexed page number.
	 *     @type int    $per_page        Results per page (max 200).
	 * }
	 * @return array{items: object[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_list( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'status'          => '',
				'category'        => '',
				'include_finance' => true,
				'include_snoozed' => false,
				'paged'           => 1,
				'per_page'        => 100,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( 'read' === $args['status'] ) {
			$where[] = 'is_read = 1';
		} elseif ( 'unread' === $args['status'] ) {
			$where[] = 'is_read = 0';
		}

		if ( $args['category'] && array_key_exists( $args['category'], self::get_categories() ) ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		} elseif ( empty( $args['include_finance'] ) ) {
			$where[] = "category != 'finance'"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( empty( $args['include_snoozed'] ) ) {
			$where[]  = '(snoozed_until IS NULL OR snoozed_until <= %s)';
			$params[] = ptp_now();
		}

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			'page'        => $paged,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Count of currently-visible unread notifications, for an admin-bar-
	 * style badge. Excludes snoozed and (unless requested) finance rows.
	 *
	 * @param bool $include_finance Whether to count 'finance'-category rows.
	 * @return int
	 */
	public static function get_unread_count( $include_finance = true ) {
		$result = self::get_list(
			array(
				'status'          => 'unread',
				'include_finance' => $include_finance,
				'per_page'        => 1,
			)
		);

		return $result['total'];
	}
}
