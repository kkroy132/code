<?php
/**
 * Time Tracking data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Time_Repository
 *
 * Owns all reads/writes to the time_entries table. A single row represents
 * either a live timer (status running/paused) or a finished entry (status
 * stopped, whether it was stopped from a timer or logged manually).
 *
 * Duration model: `duration` always holds the number of seconds already
 * accumulated from *completed* segments. While a timer is running,
 * `resumed_at` holds the DATETIME the current segment began, so the live
 * elapsed time is `duration + (now - resumed_at)`; pausing folds that
 * segment into `duration` and clears `resumed_at`. `start_time` is set once
 * when the timer is first started and never changes across pause/resume, so
 * it always reflects the entry's true origin. This avoids ever copying or
 * duplicating time data elsewhere — Projects/Tasks read totals live via
 * get_project_total()/get_task_total() rather than caching a running sum.
 */
class PTP_Time_Repository {

	/**
	 * Columns that may be used to sort the time entry list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'entry_date',
		'start_time',
		'duration',
		'status',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed time_entries table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'time_entries' );
	}

	/**
	 * Allowed time entry statuses.
	 *
	 * @return array<string, string>
	 */
	public static function get_statuses() {
		return array(
			'running' => __( 'Running', 'personal-project-tracker' ),
			'paused'  => __( 'Paused', 'personal-project-tracker' ),
			'stopped' => __( 'Stopped', 'personal-project-tracker' ),
		);
	}

	/**
	 * @param string $status Status slug to check.
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		return array_key_exists( $status, self::get_statuses() );
	}

	/**
	 * Get a single time entry by ID.
	 *
	 * @param int $id Time entry ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Time entry ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Get a user's currently active timer (running or paused), if any.
	 * Used to enforce "only one active timer per user" and to power the
	 * Active Timer widget.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return object|null
	 */
	public static function get_active_for_user( $user_id = null ) {
		global $wpdb;

		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$table   = self::get_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status IN ('running','paused') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Start a new timer for the current user. Fails if the user already has
	 * an active (running or paused) timer — only one is allowed at a time.
	 *
	 * @param array $raw Raw input: project_id, task_id, description.
	 * @return int|WP_Error New time entry ID, or a WP_Error.
	 */
	public static function start( array $raw ) {
		$user_id = get_current_user_id();

		if ( self::get_active_for_user( $user_id ) ) {
			return new WP_Error( 'ptp_active_timer_exists', __( 'You already have an active timer. Stop or pause it before starting a new one.', 'personal-project-tracker' ) );
		}

		$errors = new WP_Error();

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;

		if ( $project_id && ! PTP_Projects_Repository::exists( $project_id ) ) {
			$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
			$project_id = 0;
		}

		$task_id = isset( $raw['task_id'] ) ? absint( $raw['task_id'] ) : 0;
		$task    = null;

		if ( $task_id ) {
			$task = PTP_Tasks_Repository::get( $task_id );

			if ( ! $task ) {
				$errors->add( 'task_invalid', __( 'The selected task does not exist.', 'personal-project-tracker' ) );
				$task_id = 0;
			}
		}

		if ( $task && $project_id && (int) $task->project_id !== $project_id ) {
			$errors->add( 'task_project_mismatch', __( 'The selected task does not belong to the selected project.', 'personal-project-tracker' ) );
		} elseif ( $task && ! $project_id ) {
			$project_id = (int) $task->project_id;
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		global $wpdb;

		$now = ptp_now();

		$data = array(
			'project_id'  => $project_id ? $project_id : null,
			'task_id'     => $task_id ? $task_id : null,
			'user_id'     => $user_id,
			'description' => isset( $raw['description'] ) ? substr( PTP_Security::sanitize_text( $raw['description'] ), 0, 500 ) : '',
			'start_time'  => $now,
			'resumed_at'  => $now,
			'duration'    => 0,
			'entry_date'  => current_time( 'Y-m-d' ),
			'status'      => 'running',
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert time entry: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not start the timer. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log( 'time_timer_started', 'time_entry', $id, __( 'Started a timer', 'personal-project-tracker' ) );

		return $id;
	}

	/**
	 * Pause the current user's running timer.
	 *
	 * @param int $id Time entry ID.
	 * @return true|WP_Error
	 */
	public static function pause( $id ) {
		$entry = self::get_owned( $id, array( 'running' ) );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		global $wpdb;

		$now     = ptp_now();
		$elapsed = max( 0, strtotime( $now ) - strtotime( $entry->resumed_at ) );

		$wpdb->update(
			self::get_table(),
			array(
				'duration'   => (int) $entry->duration + $elapsed,
				'resumed_at' => null,
				'status'     => 'paused',
				'updated_at' => $now,
			),
			array( 'id' => $entry->id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log( 'time_timer_paused', 'time_entry', $entry->id, __( 'Paused a timer', 'personal-project-tracker' ) );

		return true;
	}

	/**
	 * Resume the current user's paused timer.
	 *
	 * @param int $id Time entry ID.
	 * @return true|WP_Error
	 */
	public static function resume( $id ) {
		$entry = self::get_owned( $id, array( 'paused' ) );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		global $wpdb;

		$now = ptp_now();

		$wpdb->update(
			self::get_table(),
			array(
				'resumed_at' => $now,
				'status'     => 'running',
				'updated_at' => $now,
			),
			array( 'id' => $entry->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log( 'time_timer_resumed', 'time_entry', $entry->id, __( 'Resumed a timer', 'personal-project-tracker' ) );

		return true;
	}

	/**
	 * Stop the current user's running or paused timer, finalizing its duration.
	 *
	 * @param int $id Time entry ID.
	 * @return true|WP_Error
	 */
	public static function stop( $id ) {
		$entry = self::get_owned( $id, array( 'running', 'paused' ) );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		global $wpdb;

		$now      = ptp_now();
		$duration = (int) $entry->duration;

		if ( 'running' === $entry->status && $entry->resumed_at ) {
			$duration += max( 0, strtotime( $now ) - strtotime( $entry->resumed_at ) );
		}

		$wpdb->update(
			self::get_table(),
			array(
				'duration'   => $duration,
				'resumed_at' => null,
				'end_time'   => $now,
				'status'     => 'stopped',
				'updated_at' => $now,
			),
			array( 'id' => $entry->id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log(
			'time_timer_stopped',
			'time_entry',
			$entry->id,
			/* translators: %s: formatted duration, e.g. "1h 30m". */
			sprintf( __( 'Stopped a timer (%s)', 'personal-project-tracker' ), ptp_format_duration( $duration ) )
		);

		return true;
	}

	/**
	 * Load a time entry, verifying it belongs to the current user and is in
	 * one of the allowed states — shared by pause()/resume()/stop() since
	 * the Active Timer is inherently a per-user concept, unlike ordinary
	 * CRUD on manual entries (capability-gated only, like every other module).
	 *
	 * @param int   $id               Time entry ID.
	 * @param array $allowed_statuses Statuses the entry must currently be in.
	 * @return object|WP_Error
	 */
	private static function get_owned( $id, array $allowed_statuses ) {
		$entry = self::get( (int) $id );

		if ( ! $entry ) {
			return new WP_Error( 'ptp_not_found', __( 'Time entry not found.', 'personal-project-tracker' ) );
		}

		if ( (int) $entry->user_id !== get_current_user_id() ) {
			return new WP_Error( 'ptp_forbidden', __( 'You can only control your own timer.', 'personal-project-tracker' ) );
		}

		if ( ! in_array( $entry->status, $allowed_statuses, true ) ) {
			return new WP_Error( 'ptp_invalid_timer_state', __( 'This timer is not in a state that allows this action.', 'personal-project-tracker' ) );
		}

		return $entry;
	}

	/**
	 * Sanitize and validate manual time entry input into a safe, column-ready
	 * array. The only place manual entry input is validated — both the
	 * admin form controller and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_manual_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$entry_date = self::sanitize_date( $raw['entry_date'] ?? '' );

		if ( ! $entry_date ) {
			$errors->add( 'entry_date_required', __( 'A valid date is required.', 'personal-project-tracker' ) );
		}

		$data['entry_date'] = $entry_date;

		$description          = isset( $raw['description'] ) ? PTP_Security::sanitize_text( $raw['description'] ) : '';
		$data['description']  = substr( $description, 0, 500 );

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;

		if ( $project_id && ! PTP_Projects_Repository::exists( $project_id ) ) {
			$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
			$project_id = 0;
		}

		$task_id = isset( $raw['task_id'] ) ? absint( $raw['task_id'] ) : 0;
		$task    = null;

		if ( $task_id ) {
			$task = PTP_Tasks_Repository::get( $task_id );

			if ( ! $task ) {
				$errors->add( 'task_invalid', __( 'The selected task does not exist.', 'personal-project-tracker' ) );
				$task_id = 0;
			}
		}

		if ( $task && $project_id && (int) $task->project_id !== $project_id ) {
			$errors->add( 'task_project_mismatch', __( 'The selected task does not belong to the selected project.', 'personal-project-tracker' ) );
		} elseif ( $task && ! $project_id ) {
			$project_id = (int) $task->project_id;
		}

		$data['project_id'] = $project_id ? $project_id : null;
		$data['task_id']    = $task_id ? $task_id : null;

		$start_raw = isset( $raw['start_time'] ) ? trim( wp_unslash( (string) $raw['start_time'] ) ) : '';
		$end_raw   = isset( $raw['end_time'] ) ? trim( wp_unslash( (string) $raw['end_time'] ) ) : '';

		$start_dt = ( $entry_date && '' !== $start_raw ) ? self::sanitize_time( $entry_date, $start_raw ) : null;
		$end_dt   = ( $entry_date && '' !== $end_raw ) ? self::sanitize_time( $entry_date, $end_raw ) : null;

		if ( '' !== $start_raw && null === $start_dt ) {
			$errors->add( 'start_time_invalid', __( 'The start time is not valid.', 'personal-project-tracker' ) );
		}

		if ( '' !== $end_raw && null === $end_dt ) {
			$errors->add( 'end_time_invalid', __( 'The end time is not valid.', 'personal-project-tracker' ) );
		}

		$duration_minutes = isset( $raw['duration'] ) ? (int) $raw['duration'] : 0;

		if ( $start_dt && $end_dt ) {
			if ( strtotime( $end_dt ) <= strtotime( $start_dt ) ) {
				$errors->add( 'invalid_time_range', __( 'The end time must be after the start time.', 'personal-project-tracker' ) );
				$data['duration'] = 0;
			} else {
				$data['duration'] = strtotime( $end_dt ) - strtotime( $start_dt );
			}
		} elseif ( $duration_minutes > 0 ) {
			$data['duration'] = $duration_minutes * 60;
		} else {
			$errors->add( 'duration_required', __( 'Enter a duration greater than zero, or provide both a start and end time.', 'personal-project-tracker' ) );
			$data['duration'] = 0;
		}

		$data['start_time'] = $start_dt;
		$data['end_time']   = $end_dt;

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * @param string $value Raw date string.
	 * @return string|null 'Y-m-d' formatted date, or null when empty/invalid.
	 */
	private static function sanitize_date( $value ) {
		$value = trim( wp_unslash( (string) $value ) );

		if ( '' === $value ) {
			return null;
		}

		$parsed = DateTime::createFromFormat( 'Y-m-d', $value );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * @param string $entry_date 'Y-m-d' date this time belongs to.
	 * @param string $value      Raw 'H:i' time string.
	 * @return string|null Full 'Y-m-d H:i:s' datetime, or null when invalid.
	 */
	private static function sanitize_time( $entry_date, $value ) {
		$parsed = DateTime::createFromFormat( 'H:i', $value );

		if ( ! $parsed || $parsed->format( 'H:i' ) !== $value ) {
			return null;
		}

		return $entry_date . ' ' . $value . ':00';
	}

	/**
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'project_id'  => '%d',
			'task_id'     => '%d',
			'user_id'     => '%d',
			'description' => '%s',
			'start_time'  => '%s',
			'end_time'    => '%s',
			'resumed_at'  => '%s',
			'duration'    => '%d',
			'entry_date'  => '%s',
			'status'      => '%s',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Create a manual (already-finished) time entry, owned by the current user.
	 *
	 * @param array $raw Raw input.
	 * @return int|WP_Error
	 */
	public static function create_manual( array $raw ) {
		$data = self::prepare_manual_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$now                = ptp_now();
		$data['user_id']    = get_current_user_id();
		$data['status']     = 'stopped';
		$data['resumed_at'] = null;
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert time entry: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not save the time entry. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'time_entry_created',
			'time_entry',
			$id,
			/* translators: %s: formatted duration, e.g. "1h 30m". */
			sprintf( __( 'Logged %s manually', 'personal-project-tracker' ), ptp_format_duration( $data['duration'] ) )
		);

		return $id;
	}

	/**
	 * Update an existing, already-stopped time entry. Editing an active
	 * (running/paused) timer is refused — use pause()/resume()/stop() for
	 * live timer control instead, keeping the two state machines separate.
	 *
	 * @param int   $id  Time entry ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update_manual( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Time entry not found.', 'personal-project-tracker' ) );
		}

		if ( in_array( $existing->status, array( 'running', 'paused' ), true ) ) {
			return new WP_Error( 'ptp_timer_active', __( 'Stop the timer before editing this entry.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_manual_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update time entry ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the time entry. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'time_entry_updated',
			'time_entry',
			$id,
			/* translators: %s: formatted duration, e.g. "1h 30m". */
			sprintf( __( 'Updated a time entry (%s)', 'personal-project-tracker' ), ptp_format_duration( $data['duration'] ) )
		);

		return true;
	}

	/**
	 * Permanently delete a time entry, active or not.
	 *
	 * @param int $id Time entry ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Time entry not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete time entry ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the time entry. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log( 'time_entry_deleted', 'time_entry', $id, __( 'Deleted a time entry', 'personal-project-tracker' ) );

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of time entries.
	 *
	 * @param array $args {
	 *     @type string $search     Free-text search against description.
	 *     @type int    $project_id Project filter.
	 *     @type int    $task_id    Task filter.
	 *     @type int    $user_id    User filter.
	 *     @type string $status     '', a specific status, or 'active' (running+paused).
	 *     @type string $date_from  'Y-m-d' entry_date range start (inclusive).
	 *     @type string $date_to    'Y-m-d' entry_date range end (inclusive).
	 *     @type string $orderby    Column to sort by.
	 *     @type string $order      'ASC' or 'DESC'.
	 *     @type int    $paged      1-indexed page number.
	 *     @type int    $per_page   Results per page (max 100).
	 * }
	 * @return array{items: object[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_list( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'project_id' => 0,
				'task_id'    => 0,
				'user_id'    => 0,
				'status'     => '',
				'date_from'  => '',
				'date_to'    => '',
				'orderby'    => 'entry_date',
				'order'      => 'DESC',
				'paged'      => 1,
				'per_page'   => 20,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$where[]  = 'description LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$project_id = (int) $args['project_id'];

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		$task_id = (int) $args['task_id'];

		if ( $task_id > 0 ) {
			$where[]  = 'task_id = %d';
			$params[] = $task_id;
		}

		$user_id = (int) $args['user_id'];

		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		if ( 'active' === $args['status'] ) {
			$where[] = "status IN ('running','paused')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( self::is_valid_status( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$date_from = self::sanitize_date( $args['date_from'] );

		if ( $date_from ) {
			$where[]  = 'entry_date >= %s';
			$params[] = $date_from;
		}

		$date_to = self::sanitize_date( $args['date_to'] );

		if ( $date_to ) {
			$where[]  = 'entry_date <= %s';
			$params[] = $date_to;
		}

		$orderby = in_array( $args['orderby'], self::$sortable_columns, true ) ? $args['orderby'] : 'entry_date';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Get a row's duration in seconds "live" — including elapsed time from
	 * its current running segment, if any. The one place this formula is
	 * written; REST responses, reporting totals, and admin views all call
	 * this instead of re-deriving it, so a running timer is always reflected
	 * "up to the second" without a periodic sync job.
	 *
	 * @param object|null $row Row (or partial row) with duration/status/resumed_at.
	 * @return int
	 */
	public static function get_live_duration( $row ) {
		if ( ! $row ) {
			return 0;
		}

		$duration = (int) $row->duration;

		if ( 'running' === $row->status && $row->resumed_at ) {
			$duration += max( 0, strtotime( ptp_now() ) - strtotime( $row->resumed_at ) );
		}

		return $duration;
	}

	/**
	 * Sum a set of rows' live durations. Used by every reporting total.
	 *
	 * @param object[] $rows Rows with at least duration/status/resumed_at.
	 * @return int Total seconds.
	 */
	private static function sum_rows( $rows ) {
		$total = 0;

		foreach ( $rows as $row ) {
			$total += self::get_live_duration( $row );
		}

		return $total;
	}

	/**
	 * Get Today / This Week / This Month totals (in seconds) for a user, for
	 * the Time Tracking page's reporting panel. "This Week" respects the
	 * site's configured start-of-week option, matching the Calendar module.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return array{today: int, this_week: int, this_month: int}
	 */
	public static function get_summary_for_user( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		$today         = current_time( 'Y-m-d' );
		$today_dt      = new DateTime( $today );
		$dow           = (int) $today_dt->format( 'w' );
		$start_of_week = (int) get_option( 'start_of_week', 0 );
		$back          = ( $dow - $start_of_week + 7 ) % 7;

		$week_start_dt = clone $today_dt;
		$week_start_dt->modify( "-{$back} days" );
		$week_start = $week_start_dt->format( 'Y-m-d' );

		$month_start = gmdate( 'Y-m-01', strtotime( $today ) );
		$earliest    = $week_start < $month_start ? $week_start : $month_start;

		global $wpdb;

		$table = self::get_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT duration, status, resumed_at, entry_date FROM {$table} WHERE user_id = %d AND entry_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$earliest
			)
		);

		$totals = array(
			'today'      => 0,
			'this_week'  => 0,
			'this_month' => 0,
		);

		foreach ( $rows as $row ) {
			$seconds = self::get_live_duration( $row );

			if ( $row->entry_date === $today ) {
				$totals['today'] += $seconds;
			}

			if ( $row->entry_date >= $week_start && $row->entry_date <= $today ) {
				$totals['this_week'] += $seconds;
			}

			if ( $row->entry_date >= $month_start && $row->entry_date <= $today ) {
				$totals['this_month'] += $seconds;
			}
		}

		return $totals;
	}

	/**
	 * Total tracked seconds for a project, across every user and every
	 * status (a running timer's live elapsed time is included). Used by the
	 * Project detail page's "Total Tracked Time" card.
	 *
	 * @param int $project_id Project ID.
	 * @return int Total seconds.
	 */
	public static function get_project_total( $project_id ) {
		global $wpdb;

		$table = self::get_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT duration, status, resumed_at FROM {$table} WHERE project_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $project_id
			)
		);

		return self::sum_rows( $rows );
	}

	/**
	 * Total tracked seconds for a task. Used by the Task detail page's
	 * "Tracked Time" row.
	 *
	 * @param int $task_id Task ID.
	 * @return int Total seconds.
	 */
	public static function get_task_total( $task_id ) {
		global $wpdb;

		$table = self::get_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT duration, status, resumed_at FROM {$table} WHERE task_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $task_id
			)
		);

		return self::sum_rows( $rows );
	}

	/**
	 * Filtered time totals for the Reports module (Time Report): total
	 * seconds, plus SUM(duration) grouped by day / project / task. Four
	 * small aggregate queries only — never loads individual time entries
	 * into PHP, so this stays fast regardless of how many entries exist.
	 * The Reports Service derives weekly/monthly rollups by summing the
	 * (small, date-range-bounded) by_day buckets rather than re-querying.
	 *
	 * Note: this sums the already-accumulated `duration` column only — a
	 * currently running timer's live, not-yet-folded-in segment is not
	 * included until it is paused or stopped. That keeps this an O(1)
	 * aggregate query rather than a per-row PHP computation.
	 *
	 * @param array $args {
	 *     @type int    $project_id Project filter (0 = all).
	 *     @type int    $task_id    Task filter (0 = all).
	 *     @type string $date_from  'Y-m-d' entry_date range start (inclusive).
	 *     @type string $date_to    'Y-m-d' entry_date range end (inclusive).
	 * }
	 * @return array{total_seconds: int, by_day: array<string,int>, by_project: array<int,int>, by_task: array<int,int>}
	 */
	public static function get_report_totals( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'project_id' => 0,
				'task_id'    => 0,
				'date_from'  => '',
				'date_to'    => '',
			)
		);

		list( $where, $params ) = self::build_report_where( $args );

		$where_sql = implode( ' AND ', $where );

		$total_sql = "SELECT COALESCE(SUM(duration),0) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$daily_sql  = "SELECT entry_date, SUM(duration) as total FROM {$table} WHERE {$where_sql} GROUP BY entry_date ORDER BY entry_date ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily_rows = $params
			? $wpdb->get_results( $wpdb->prepare( $daily_sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $daily_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_day = array();

		foreach ( $daily_rows as $row ) {
			$by_day[ $row['entry_date'] ] = (int) $row['total'];
		}

		$project_where_sql = $where_sql . ' AND project_id IS NOT NULL';
		$project_sql       = "SELECT project_id, SUM(duration) as total FROM {$table} WHERE {$project_where_sql} GROUP BY project_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$project_rows      = $params
			? $wpdb->get_results( $wpdb->prepare( $project_sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $project_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_project = array();

		foreach ( $project_rows as $row ) {
			$by_project[ (int) $row['project_id'] ] = (int) $row['total'];
		}

		$task_where_sql = $where_sql . ' AND task_id IS NOT NULL';
		$task_sql       = "SELECT task_id, SUM(duration) as total FROM {$table} WHERE {$task_where_sql} GROUP BY task_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$task_rows      = $params
			? $wpdb->get_results( $wpdb->prepare( $task_sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $task_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_task = array();

		foreach ( $task_rows as $row ) {
			$by_task[ (int) $row['task_id'] ] = (int) $row['total'];
		}

		return array(
			'total_seconds' => $total,
			'by_day'        => $by_day,
			'by_project'    => $by_project,
			'by_task'       => $by_task,
		);
	}

	/**
	 * Shared WHERE-clause builder for get_report_totals().
	 *
	 * @param array $args project_id/task_id/date_from/date_to.
	 * @return array{0: string[], 1: array} [where fragments, bound params]
	 */
	private static function build_report_where( array $args ) {
		$where  = array( '1=1' );
		$params = array();

		$project_id = (int) ( $args['project_id'] ?? 0 );

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		$task_id = (int) ( $args['task_id'] ?? 0 );

		if ( $task_id > 0 ) {
			$where[]  = 'task_id = %d';
			$params[] = $task_id;
		}

		$date_from = self::sanitize_date( $args['date_from'] ?? '' );

		if ( $date_from ) {
			$where[]  = 'entry_date >= %s';
			$params[] = $date_from;
		}

		$date_to = self::sanitize_date( $args['date_to'] ?? '' );

		if ( $date_to ) {
			$where[]  = 'entry_date <= %s';
			$params[] = $date_to;
		}

		return array( $where, $params );
	}
}
