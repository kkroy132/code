<?php
/**
 * Reminders data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Reminders_Repository
 *
 * Owns all reads/writes to the reminders table. A reminder is a scheduled,
 * optionally-recurring instruction to notify the user at remind_at; firing
 * a reminder (creating the actual notification) is
 * PTP_Notifications_Service's job via the ptp_reminder_check cron event —
 * this class only owns the reminder record's lifecycle: active -> snoozed
 * -> active again, or active -> completed for a one-shot reminder once it
 * fires.
 */
class PTP_Reminders_Repository {

	/**
	 * Get the fully-prefixed reminders table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'reminders' );
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_statuses() {
		return array(
			'active'    => __( 'Active', 'personal-project-tracker' ),
			'snoozed'   => __( 'Snoozed', 'personal-project-tracker' ),
			'completed' => __( 'Completed', 'personal-project-tracker' ),
			'cancelled' => __( 'Cancelled', 'personal-project-tracker' ),
		);
	}

	/**
	 * REPEAT options. 'custom' uses recurrence_interval as a whole number
	 * of days between occurrences.
	 *
	 * @return array<string, string>
	 */
	public static function get_recurrences() {
		return array(
			'none'    => __( 'Never', 'personal-project-tracker' ),
			'daily'   => __( 'Daily', 'personal-project-tracker' ),
			'weekly'  => __( 'Weekly', 'personal-project-tracker' ),
			'monthly' => __( 'Monthly', 'personal-project-tracker' ),
			'custom'  => __( 'Custom', 'personal-project-tracker' ),
		);
	}

	/**
	 * What a reminder may be attached to. Empty/'custom' means a standalone
	 * reminder with no related object.
	 *
	 * @return array<string, string>
	 */
	public static function get_related_types() {
		return array(
			'project'        => __( 'Project', 'personal-project-tracker' ),
			'task'           => __( 'Task', 'personal-project-tracker' ),
			'milestone'      => __( 'Milestone', 'personal-project-tracker' ),
			'calendar_event' => __( 'Calendar Event', 'personal-project-tracker' ),
		);
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place reminder input is validated — both the admin form
	 * controller and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$errors->add( 'title_required', __( 'Reminder title is required.', 'personal-project-tracker' ) );
		}

		$data['title'] = substr( $title, 0, 255 );

		$related_type = isset( $raw['related_type'] ) ? sanitize_key( wp_unslash( (string) $raw['related_type'] ) ) : '';
		$related_id   = isset( $raw['related_id'] ) ? absint( $raw['related_id'] ) : 0;

		if ( '' !== $related_type && ! array_key_exists( $related_type, self::get_related_types() ) ) {
			$related_type = '';
		}

		if ( '' !== $related_type && $related_id ) {
			$exists = false;

			switch ( $related_type ) {
				case 'project':
					$exists = PTP_Projects_Repository::exists( $related_id );
					break;
				case 'task':
					$exists = PTP_Tasks_Repository::exists( $related_id );
					break;
				case 'milestone':
					$exists = PTP_Milestones_Repository::exists( $related_id );
					break;
				case 'calendar_event':
					$exists = PTP_Calendar_Repository::exists( $related_id );
					break;
			}

			if ( ! $exists ) {
				$errors->add( 'related_invalid', __( 'The selected related item does not exist.', 'personal-project-tracker' ) );
			}
		} else {
			$related_type = '';
			$related_id   = 0;
		}

		$data['related_type'] = $related_type ? $related_type : null;
		$data['related_id']   = $related_id ? $related_id : null;

		$remind_at_raw = isset( $raw['remind_at'] ) ? trim( wp_unslash( (string) $raw['remind_at'] ) ) : '';
		$remind_at     = self::sanitize_datetime( $remind_at_raw );

		if ( ! $remind_at ) {
			$errors->add( 'remind_at_required', __( 'A valid reminder date/time is required.', 'personal-project-tracker' ) );
		}

		$data['remind_at'] = $remind_at;

		$recurrence         = isset( $raw['recurrence'] ) ? sanitize_key( wp_unslash( (string) $raw['recurrence'] ) ) : 'none';
		$data['recurrence'] = array_key_exists( $recurrence, self::get_recurrences() ) ? $recurrence : 'none';

		$interval = isset( $raw['recurrence_interval'] ) ? absint( $raw['recurrence_interval'] ) : 0;

		if ( 'custom' === $data['recurrence'] && $interval < 1 ) {
			$errors->add( 'recurrence_interval_required', __( 'A custom repeat needs an interval of at least 1 day.', 'personal-project-tracker' ) );
		}

		$data['recurrence_interval'] = $interval ? $interval : null;

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * @param string $value Raw datetime, expected 'Y-m-d H:i' or 'Y-m-d H:i:s'.
	 * @return string|null 'Y-m-d H:i:s', or null when empty/invalid.
	 */
	private static function sanitize_datetime( $value ) {
		if ( '' === $value ) {
			return null;
		}

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i' ) as $format ) {
			$parsed = DateTime::createFromFormat( $format, $value );

			if ( $parsed && $parsed->format( $format ) === $value ) {
				return $parsed->format( 'Y-m-d H:i:s' );
			}
		}

		return null;
	}

	/**
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'title'               => '%s',
			'related_type'        => '%s',
			'related_id'          => '%d',
			'remind_at'           => '%s',
			'recurrence'          => '%s',
			'recurrence_interval' => '%d',
			'status'              => '%s',
			'snoozed_until'       => '%s',
			'created_at'          => '%s',
			'updated_at'          => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * @param int $id Reminder ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Reminder ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * @param array $raw Raw input.
	 * @return int|WP_Error
	 */
	public static function create( array $raw ) {
		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$now                  = ptp_now();
		$data['status']       = 'active';
		$data['snoozed_until'] = null;
		$data['created_at']   = $now;
		$data['updated_at']   = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert reminder: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the reminder. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'reminder_created',
			'reminder',
			$id,
			/* translators: %s: reminder title. */
			sprintf( __( 'Created reminder "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * @param int   $id  Reminder ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Reminder not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		// Editing a reminder re-activates it (a user changing the time/recurrence
		// clearly wants it live again, whether it was previously completed or snoozed).
		$data['status']        = 'active';
		$data['snoozed_until'] = null;
		$data['updated_at']    = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update reminder ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the reminder. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'reminder_updated',
			'reminder',
			$id,
			/* translators: %s: reminder title. */
			sprintf( __( 'Updated reminder "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * @param int $id Reminder ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Reminder not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete reminder ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the reminder. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'reminder_deleted',
			'reminder',
			$id,
			/* translators: %s: reminder title. */
			sprintf( __( 'Deleted reminder "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Snooze a reminder for a duration computed by the caller (see the
	 * SNOOZE presets in PTP_Notifications_Service::resolve_snooze_until()).
	 *
	 * @param int    $id    Reminder ID.
	 * @param string $until 'Y-m-d H:i:s' datetime to snooze until.
	 * @return true|WP_Error
	 */
	public static function snooze( $id, $until ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Reminder not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array( 'status' => 'snoozed', 'snoozed_until' => $until, 'updated_at' => ptp_now() ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log(
			'reminder_snoozed',
			'reminder',
			$id,
			/* translators: %s: reminder title. */
			sprintf( __( 'Snoozed reminder "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Reminders that are due right now: either active reminders whose
	 * remind_at has passed, or snoozed reminders whose snoozed_until has
	 * passed. Two simple queries merged in PHP rather than one OR'd query,
	 * so each stays a single indexed-column comparison.
	 *
	 * @return object[]
	 */
	public static function get_due() {
		global $wpdb;

		$table = self::get_table();
		$now   = ptp_now();

		$active = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND remind_at <= %s ORDER BY remind_at ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		$snoozed = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'snoozed' AND snoozed_until <= %s ORDER BY snoozed_until ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		return array_merge( $active, $snoozed );
	}

	/**
	 * Advance a reminder past its current occurrence: recompute remind_at
	 * per its recurrence and keep it active, or mark it completed for a
	 * one-shot ('none') reminder. Called once a due reminder has been
	 * turned into a notification.
	 *
	 * @param object $reminder Reminder row (as returned by get()/get_due()).
	 * @return true|WP_Error
	 */
	public static function advance( $reminder ) {
		global $wpdb;

		if ( 'none' === $reminder->recurrence ) {
			$wpdb->update(
				self::get_table(),
				array( 'status' => 'completed', 'snoozed_until' => null, 'updated_at' => ptp_now() ),
				array( 'id' => $reminder->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return true;
		}

		$intervals = array(
			'daily'   => '+1 day',
			'weekly'  => '+1 week',
			'monthly' => '+1 month',
			'custom'  => '+' . max( 1, (int) $reminder->recurrence_interval ) . ' days',
		);

		$modifier = $intervals[ $reminder->recurrence ] ?? '+1 day';
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $reminder->remind_at . ' ' . $modifier ) );

		$wpdb->update(
			self::get_table(),
			array( 'status' => 'active', 'remind_at' => $next, 'snoozed_until' => null, 'updated_at' => ptp_now() ),
			array( 'id' => $reminder->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of reminders.
	 *
	 * @param array $args {
	 *     @type string $search       Free-text search against title.
	 *     @type string $related_type Related type filter.
	 *     @type string $status       Status filter.
	 *     @type int    $paged        1-indexed page number.
	 *     @type int    $per_page     Results per page (max 100).
	 * }
	 * @return array{items: object[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_list( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'search'       => '',
				'related_type' => '',
				'status'       => '',
				'paged'        => 1,
				'per_page'     => 20,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( $args['related_type'] && array_key_exists( $args['related_type'], self::get_related_types() ) ) {
			$where[]  = 'related_type = %s';
			$params[] = $args['related_type'];
		}

		if ( $args['status'] && array_key_exists( $args['status'], self::get_statuses() ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY remind_at ASC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
}
