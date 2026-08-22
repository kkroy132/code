<?php
/**
 * Calendar data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Calendar_Repository
 *
 * Owns CRUD for custom calendar events ONLY. Project deadlines, task due
 * dates, and milestone due dates are never copied into calendar_events —
 * this repository reads them live from PTP_Projects_Repository,
 * PTP_Tasks_Repository, and PTP_Milestones_Repository (each module's own
 * get_deadlines_in_range() method) and merges everything into one
 * unified, normalized list at render time via get_unified_items(). This
 * is the "one unified calendar, no duplicate date system" requirement:
 * there is exactly one source of truth for every date in the plugin.
 */
class PTP_Calendar_Repository {

	/**
	 * Get the fully-prefixed calendar_events table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'calendar_events' );
	}

	/**
	 * Sanitize and validate raw input for a custom event into a safe,
	 * column-ready array. The only place event input is validated — both
	 * the admin form controller and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$errors->add( 'title_required', __( 'Event title is required.', 'personal-project-tracker' ) );
		}

		$data['title']       = substr( $title, 0, 255 );
		$data['description'] = isset( $raw['description'] ) ? PTP_Security::sanitize_rich_text( $raw['description'] ) : '';
		$data['event_type']  = 'custom';

		$all_day         = ! empty( $raw['all_day'] );
		$data['all_day'] = $all_day ? 1 : 0;

		$start_date     = $raw['start_date'] ?? '';
		$start_time     = $all_day ? '' : ( $raw['start_time'] ?? '' );
		$start_datetime = self::sanitize_datetime( $start_date, $start_time, '00:00:00' );

		if ( ! $start_datetime ) {
			$errors->add( 'start_required', __( 'A valid start date is required.', 'personal-project-tracker' ) );
		}

		$data['start_datetime'] = $start_datetime;

		$end_date = trim( wp_unslash( (string) ( $raw['end_date'] ?? '' ) ) );
		$end_time = $all_day ? '' : ( $raw['end_time'] ?? '' );

		if ( '' !== $end_date ) {
			$end_datetime = self::sanitize_datetime( $end_date, $end_time, '23:59:00' );

			if ( ! $end_datetime ) {
				$errors->add( 'end_invalid', __( 'The end date is not valid.', 'personal-project-tracker' ) );
			} elseif ( $start_datetime && $end_datetime < $start_datetime ) {
				$errors->add( 'invalid_range', __( 'The event cannot end before it starts.', 'personal-project-tracker' ) );
			}

			$data['end_datetime'] = $end_datetime;
		} else {
			$data['end_datetime'] = null;
		}

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;

		if ( $project_id && ! PTP_Projects_Repository::exists( $project_id ) ) {
			$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
		}

		$data['project_id'] = $project_id ? $project_id : null;

		$task_id = isset( $raw['task_id'] ) ? absint( $raw['task_id'] ) : 0;

		if ( $task_id && ! PTP_Tasks_Repository::exists( $task_id ) ) {
			$errors->add( 'task_invalid', __( 'The selected task does not exist.', 'personal-project-tracker' ) );
		}

		$data['task_id'] = $task_id ? $task_id : null;

		$milestone_id = isset( $raw['milestone_id'] ) ? absint( $raw['milestone_id'] ) : 0;

		if ( $milestone_id && ! PTP_Milestones_Repository::exists( $milestone_id ) ) {
			$errors->add( 'milestone_invalid', __( 'The selected milestone does not exist.', 'personal-project-tracker' ) );
		}

		$data['milestone_id'] = $milestone_id ? $milestone_id : null;

		$location         = isset( $raw['location'] ) ? PTP_Security::sanitize_text( $raw['location'] ) : '';
		$data['location'] = '' !== $location ? substr( $location, 0, 255 ) : null;

		$color         = isset( $raw['color'] ) ? sanitize_hex_color( wp_unslash( (string) $raw['color'] ) ) : '';
		$data['color'] = $color ? $color : null;

		// Reminder integration point: how many minutes before start_datetime
		// to notify the user. Purely data at this stage — no cron job or
		// notification delivery exists yet; the future Reminders/
		// Notifications module (a later phase) queries this column.
		$reminder_raw = $raw['reminder_minutes'] ?? '';

		if ( '' === $reminder_raw || null === $reminder_raw ) {
			$data['reminder_minutes'] = null;
		} else {
			$data['reminder_minutes'] = max( 0, (int) $reminder_raw );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * Combine a date and time into a validated 'Y-m-d H:i:s' string.
	 *
	 * @param string $date         Raw date, expected 'Y-m-d'.
	 * @param string $time         Raw time, expected 'H:i'. Falls back to $default_time when empty/invalid.
	 * @param string $default_time 'H:i:s' fallback when no valid time is given.
	 * @return string|null
	 */
	private static function sanitize_datetime( $date, $time, $default_time ) {
		$date = trim( wp_unslash( (string) $date ) );

		if ( '' === $date ) {
			return null;
		}

		$parsed_date = DateTime::createFromFormat( 'Y-m-d', $date );

		if ( ! $parsed_date || $parsed_date->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		$time         = trim( wp_unslash( (string) $time ) );
		$parsed_time  = '' !== $time ? DateTime::createFromFormat( 'H:i', $time ) : false;
		$time_string  = ( $parsed_time && $parsed_time->format( 'H:i' ) === $time ) ? $parsed_time->format( 'H:i:s' ) : $default_time;

		return $date . ' ' . $time_string;
	}

	/**
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'title'            => '%s',
			'description'      => '%s',
			'event_type'       => '%s',
			'start_datetime'   => '%s',
			'end_datetime'     => '%s',
			'all_day'          => '%d',
			'location'         => '%s',
			'color'            => '%s',
			'reminder_minutes' => '%d',
			'project_id'       => '%d',
			'task_id'          => '%d',
			'milestone_id'     => '%d',
			'created_at'       => '%s',
			'updated_at'       => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Get a single custom event by ID.
	 *
	 * @param int $id Event ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND event_type = 'custom'", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Event ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Create a new custom event.
	 *
	 * @param array $raw Raw input.
	 * @return int|WP_Error
	 */
	public static function create( array $raw ) {
		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$now                = ptp_now();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert calendar event: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the event. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'calendar_event_created',
			'calendar_event',
			$id,
			/* translators: %s: event title. */
			sprintf( __( 'Created event "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * Update an existing custom event.
	 *
	 * @param int   $id  Event ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Event not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update calendar event ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the event. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'calendar_event_updated',
			'calendar_event',
			$id,
			/* translators: %s: event title. */
			sprintf( __( 'Updated event "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * Permanently delete a custom event.
	 *
	 * @param int $id Event ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Event not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete calendar event ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the event. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'calendar_event_deleted',
			'calendar_event',
			$id,
			/* translators: %s: event title. */
			sprintf( __( 'Deleted event "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Get custom events whose [start, end] span overlaps a date range.
	 *
	 * @param string $start      'Y-m-d' range start (inclusive).
	 * @param string $end        'Y-m-d' range end (inclusive).
	 * @param int    $project_id Optional project filter.
	 * @return object[]
	 */
	public static function get_events_in_range( $start, $end, $project_id = 0 ) {
		global $wpdb;

		$table = self::get_table();

		$where  = array( "event_type = 'custom'", 'start_datetime <= %s', 'COALESCE(end_datetime, start_datetime) >= %s' );
		$params = array( $end . ' 23:59:59', $start . ' 00:00:00' );

		$project_id = (int) $project_id;

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY start_datetime ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Build the single unified, normalized list of calendar items for a
	 * date range: custom events plus live project/task/milestone
	 * deadlines. This is what every calendar view (month/week/day/agenda)
	 * and the REST endpoint render from.
	 *
	 * @param array $args {
	 *     @type string $start      'Y-m-d' range start (inclusive).
	 *     @type string $end        'Y-m-d' range end (inclusive).
	 *     @type string $filter     'all' (default), 'projects', 'tasks', 'milestones', or 'custom'.
	 *     @type int    $project_id Optional project filter, applied across every item type.
	 * }
	 * @return array<int, array{type: string, id: int, title: string, date: string, datetime: string|null, end_date: string|null, all_day: bool, project_id: int, status: string|null, priority: string|null, color: string, url: string}>
	 */
	public static function get_unified_items( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'start'      => current_time( 'Y-m-d' ),
				'end'        => current_time( 'Y-m-d' ),
				'filter'     => 'all',
				'project_id' => 0,
			)
		);

		$filter     = in_array( $args['filter'], array( 'all', 'projects', 'tasks', 'milestones', 'custom' ), true ) ? $args['filter'] : 'all';
		$project_id = (int) $args['project_id'];
		$items      = array();

		if ( 'all' === $filter || 'custom' === $filter ) {
			foreach ( self::get_events_in_range( $args['start'], $args['end'] ) as $event ) {
				$items[] = self::normalize_custom_event( $event );
			}
		}

		if ( ( 'all' === $filter || 'projects' === $filter ) && class_exists( 'PTP_Projects_Repository' ) ) {
			foreach ( PTP_Projects_Repository::get_deadlines_in_range( $args['start'], $args['end'] ) as $project ) {
				$items[] = self::normalize_project_deadline( $project );
			}
		}

		if ( ( 'all' === $filter || 'tasks' === $filter ) && class_exists( 'PTP_Tasks_Repository' ) ) {
			foreach ( PTP_Tasks_Repository::get_deadlines_in_range( $args['start'], $args['end'] ) as $task ) {
				$items[] = self::normalize_task_deadline( $task );
			}
		}

		if ( ( 'all' === $filter || 'milestones' === $filter ) && class_exists( 'PTP_Milestones_Repository' ) ) {
			foreach ( PTP_Milestones_Repository::get_deadlines_in_range( $args['start'], $args['end'] ) as $milestone ) {
				$items[] = self::normalize_milestone_deadline( $milestone );
			}
		}

		if ( $project_id > 0 ) {
			$items = array_values(
				array_filter( $items, fn( $item ) => (int) $item['project_id'] === $project_id )
			);
		}

		usort( $items, fn( $a, $b ) => strcmp( $a['date'] . ( $a['datetime'] ?? '' ), $b['date'] . ( $b['datetime'] ?? '' ) ) );

		return $items;
	}

	/**
	 * @param object $event Row from calendar_events.
	 * @return array Normalized calendar item.
	 */
	private static function normalize_custom_event( $event ) {
		return array(
			'type'       => 'custom',
			'id'         => (int) $event->id,
			'title'      => $event->title,
			'date'       => substr( $event->start_datetime, 0, 10 ),
			'datetime'   => $event->all_day ? null : $event->start_datetime,
			'end_date'   => $event->end_datetime ? substr( $event->end_datetime, 0, 10 ) : null,
			'all_day'    => (bool) $event->all_day,
			'project_id' => $event->project_id ? (int) $event->project_id : 0,
			'status'     => null,
			'priority'   => null,
			'color'      => $event->color ? $event->color : '#2271b1',
			'url'        => add_query_arg( array( 'page' => 'ptp-calendar', 'action' => 'view', 'id' => $event->id ), admin_url( 'admin.php' ) ),
		);
	}

	/**
	 * @param object $project Row from PTP_Projects_Repository::get_deadlines_in_range().
	 * @return array Normalized calendar item.
	 */
	private static function normalize_project_deadline( $project ) {
		return array(
			'type'       => 'project',
			'id'         => (int) $project->id,
			'title'      => $project->title,
			'date'       => $project->deadline,
			'datetime'   => null,
			'end_date'   => null,
			'all_day'    => true,
			'project_id' => (int) $project->id,
			'status'     => $project->status,
			'priority'   => $project->priority,
			'color'      => $project->color ? $project->color : '#d63638',
			'url'        => add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $project->id ), admin_url( 'admin.php' ) ),
		);
	}

	/**
	 * @param object $task Row from PTP_Tasks_Repository::get_deadlines_in_range().
	 * @return array Normalized calendar item.
	 */
	private static function normalize_task_deadline( $task ) {
		return array(
			'type'       => 'task',
			'id'         => (int) $task->id,
			'title'      => $task->title,
			'date'       => $task->due_date,
			'datetime'   => null,
			'end_date'   => null,
			'all_day'    => true,
			'project_id' => (int) $task->project_id,
			'status'     => $task->status,
			'priority'   => $task->priority,
			'color'      => '#0a4b78',
			'url'        => add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => $task->id ), admin_url( 'admin.php' ) ),
		);
	}

	/**
	 * @param object $milestone Row from PTP_Milestones_Repository::get_deadlines_in_range().
	 * @return array Normalized calendar item.
	 */
	private static function normalize_milestone_deadline( $milestone ) {
		return array(
			'type'       => 'milestone',
			'id'         => (int) $milestone->id,
			'title'      => $milestone->title,
			'date'       => $milestone->due_date,
			'datetime'   => null,
			'end_date'   => null,
			'all_day'    => true,
			'project_id' => (int) $milestone->project_id,
			'status'     => $milestone->status,
			'priority'   => $milestone->priority,
			'color'      => '#9a6700',
			'url'        => add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $milestone->id ), admin_url( 'admin.php' ) ),
		);
	}
}
