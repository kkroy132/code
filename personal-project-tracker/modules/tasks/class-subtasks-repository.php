<?php
/**
 * Subtasks data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Subtasks_Repository
 *
 * Owns all reads/writes to the subtasks table. A subtask always belongs to
 * a task (task_id is required and validated against PTP_Tasks_Repository);
 * status/priority reuse PTP_Tasks_Repository's enums rather than
 * redefining them, since subtasks are conceptually mini-tasks.
 */
class PTP_Subtasks_Repository {

	/**
	 * Get the fully-prefixed subtasks table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'subtasks' );
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$task_id = isset( $raw['task_id'] ) ? absint( $raw['task_id'] ) : 0;

		if ( ! $task_id ) {
			$errors->add( 'task_required', __( 'A subtask must belong to a task.', 'personal-project-tracker' ) );
		} elseif ( ! PTP_Tasks_Repository::exists( $task_id ) ) {
			$errors->add( 'task_invalid', __( 'The selected task does not exist.', 'personal-project-tracker' ) );
		}

		$data['task_id'] = $task_id;

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$errors->add( 'title_required', __( 'Subtask title is required.', 'personal-project-tracker' ) );
		}

		$data['title'] = substr( $title, 0, 255 );

		$status         = isset( $raw['status'] ) ? sanitize_key( wp_unslash( (string) $raw['status'] ) ) : 'todo';
		$data['status'] = PTP_Tasks_Repository::is_valid_status( $status ) ? $status : 'todo';

		$priority         = isset( $raw['priority'] ) ? sanitize_key( wp_unslash( (string) $raw['priority'] ) ) : 'medium';
		$data['priority'] = PTP_Tasks_Repository::is_valid_priority( $priority ) ? $priority : 'medium';

		$due_date         = trim( wp_unslash( (string) ( $raw['due_date'] ?? '' ) ) );
		$parsed           = '' !== $due_date ? DateTime::createFromFormat( 'Y-m-d', $due_date ) : false;
		$data['due_date'] = ( $parsed && $parsed->format( 'Y-m-d' ) === $due_date ) ? $due_date : null;

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'task_id'    => '%d',
			'title'      => '%s',
			'status'     => '%s',
			'priority'   => '%s',
			'due_date'   => '%s',
			'completed'  => '%d',
			'sort_order' => '%d',
			'created_at' => '%s',
			'updated_at' => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Get a single subtask by ID.
	 *
	 * @param int $id Subtask ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Subtask ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Get all subtasks for a task, in display order.
	 *
	 * @param int $task_id Task ID.
	 * @return object[]
	 */
	public static function get_for_task( $task_id ) {
		global $wpdb;

		$table = self::get_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE task_id = %d ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $task_id
			)
		);
	}

	/**
	 * Completion summary for a task's subtasks, used to show a
	 * subtask-based completion percentage on the task.
	 *
	 * @param int $task_id Task ID.
	 * @return array{total: int, completed: int, percentage: int|null} percentage is null when there are no subtasks.
	 */
	public static function get_completion( $task_id ) {
		global $wpdb;

		$table = self::get_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total, SUM(completed) as done FROM {$table} WHERE task_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $task_id
			),
			ARRAY_A
		);

		$total     = (int) ( $row['total'] ?? 0 );
		$completed = (int) ( $row['done'] ?? 0 );

		return array(
			'total'      => $total,
			'completed'  => $completed,
			'percentage' => $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : null,
		);
	}

	/**
	 * Create a new subtask, appended to the end of its task's list.
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

		$table = self::get_table();

		$max_order = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE task_id = %d", $data['task_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$now                = ptp_now();
		$data['completed']  = 0;
		$data['sort_order'] = $max_order + 1;
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( $table, $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert subtask: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the subtask. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		self::log( $data['task_id'], 'subtask_created', sprintf( __( 'Added subtask "%s"', 'personal-project-tracker' ), $data['title'] ) );

		return $id;
	}

	/**
	 * Update a subtask's title/status/priority/due date.
	 *
	 * @param int   $id  Subtask ID.
	 * @param array $raw Raw input. task_id is ignored on update — a subtask
	 *                    does not change parent task via this method.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Subtask not found.', 'personal-project-tracker' ) );
		}

		$raw['task_id'] = $existing->task_id;
		$data           = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		unset( $data['task_id'] );
		$data['updated_at'] = ptp_now();

		global $wpdb;

		$wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		self::log( $existing->task_id, 'subtask_updated', sprintf( __( 'Updated subtask "%s"', 'personal-project-tracker' ), $data['title'] ) );

		return true;
	}

	/**
	 * Delete a subtask.
	 *
	 * @param int $id Subtask ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Subtask not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete subtask ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the subtask. Please try again.', 'personal-project-tracker' ) );
		}

		self::log( $existing->task_id, 'subtask_deleted', sprintf( __( 'Deleted subtask "%s"', 'personal-project-tracker' ), $existing->title ) );

		return true;
	}

	/**
	 * Mark a subtask completed (sets both the completed flag and status).
	 *
	 * @param int $id Subtask ID.
	 * @return true|WP_Error
	 */
	public static function complete( $id ) {
		return self::set_completed( $id, true, 'subtask_completed', __( 'Completed subtask "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * Reopen a completed subtask.
	 *
	 * @param int $id Subtask ID.
	 * @return true|WP_Error
	 */
	public static function reopen( $id ) {
		return self::set_completed( $id, false, 'subtask_reopened', __( 'Reopened subtask "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int    $id           Subtask ID.
	 * @param bool   $completed    New completed state.
	 * @param string $log_action   Activity log action slug.
	 * @param string $log_template sprintf() template with one %s for the title.
	 * @return true|WP_Error
	 */
	private static function set_completed( $id, $completed, $log_action, $log_template ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Subtask not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				'completed'  => $completed ? 1 : 0,
				'status'     => $completed ? 'completed' : 'todo',
				'updated_at' => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		self::log( $existing->task_id, $log_action, sprintf( $log_template, $existing->title ) );

		return true;
	}

	/**
	 * Persist a new subtask display order for one task.
	 *
	 * @param int   $task_id     Task the subtasks belong to.
	 * @param int[] $ordered_ids Subtask IDs in the desired order. Any ID not
	 *                            belonging to $task_id is ignored.
	 * @return true|WP_Error
	 */
	public static function reorder( $task_id, array $ordered_ids ) {
		$task_id = (int) $task_id;
		$current = self::get_for_task( $task_id );
		$valid   = wp_list_pluck( $current, 'id' );

		global $wpdb;

		$table    = self::get_table();
		$position = 0;

		foreach ( $ordered_ids as $subtask_id ) {
			$subtask_id = (int) $subtask_id;

			if ( ! in_array( $subtask_id, $valid, true ) ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'sort_order' => $position,
					'updated_at' => ptp_now(),
				),
				array(
					'id'      => $subtask_id,
					'task_id' => $task_id,
				),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);

			++$position;
		}

		return true;
	}

	/**
	 * Log a subtask action against its parent task, so it shows up in the
	 * task's own Activity feed rather than needing a separate lookup.
	 *
	 * @param int    $task_id     Parent task ID.
	 * @param string $action      Activity log action slug.
	 * @param string $description Human-readable summary.
	 */
	private static function log( $task_id, $action, $description ) {
		PTP_Activity_Log::log( $action, 'task', $task_id, $description );
	}
}
