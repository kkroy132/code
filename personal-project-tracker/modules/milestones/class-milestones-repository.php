<?php
/**
 * Milestones data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Milestones_Repository
 *
 * Owns all reads/writes to the milestones table, plus the single
 * sanitize-and-validate routine ( prepare_fields() ) shared by the
 * admin-post form handler and the REST controller. Mirrors
 * PTP_Tasks_Repository's shape, including archiving being independent
 * of status (a completed milestone stays completed once archived).
 *
 * Task relationships (view/add/remove) are handled here by delegating to
 * PTP_Tasks_Repository — a milestone never writes to the tasks table
 * directly, so task data always goes through its own owning repository.
 */
class PTP_Milestones_Repository {

	/**
	 * Columns that may be used to sort the milestone list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'title',
		'status',
		'priority',
		'start_date',
		'due_date',
		'progress',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed milestones table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'milestones' );
	}

	/**
	 * Allowed milestone statuses.
	 *
	 * @return array<string, string>
	 */
	public static function get_statuses() {
		return array(
			'planning'    => __( 'Planning', 'personal-project-tracker' ),
			'in_progress' => __( 'In Progress', 'personal-project-tracker' ),
			'completed'   => __( 'Completed', 'personal-project-tracker' ),
			'on_hold'     => __( 'On Hold', 'personal-project-tracker' ),
			'cancelled'   => __( 'Cancelled', 'personal-project-tracker' ),
		);
	}

	/**
	 * Allowed milestone priorities.
	 *
	 * @return array<string, string>
	 */
	public static function get_priorities() {
		return array(
			'low'      => __( 'Low', 'personal-project-tracker' ),
			'medium'   => __( 'Medium', 'personal-project-tracker' ),
			'high'     => __( 'High', 'personal-project-tracker' ),
			'critical' => __( 'Critical', 'personal-project-tracker' ),
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
	 * @param string $priority Priority slug to check.
	 * @return bool
	 */
	public static function is_valid_priority( $priority ) {
		return array_key_exists( $priority, self::get_priorities() );
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place milestone input is validated — both the admin form
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
			$errors->add( 'title_required', __( 'Milestone title is required.', 'personal-project-tracker' ) );
		}

		$data['title']       = substr( $title, 0, 255 );
		$data['description'] = isset( $raw['description'] ) ? PTP_Security::sanitize_rich_text( $raw['description'] ) : '';

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;

		if ( ! $project_id ) {
			$errors->add( 'project_required', __( 'A milestone must belong to a project.', 'personal-project-tracker' ) );
		} elseif ( ! PTP_Projects_Repository::exists( $project_id ) ) {
			$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
		}

		$data['project_id'] = $project_id;

		$status         = isset( $raw['status'] ) ? sanitize_key( wp_unslash( (string) $raw['status'] ) ) : 'planning';
		$data['status'] = self::is_valid_status( $status ) ? $status : 'planning';

		$priority         = isset( $raw['priority'] ) ? sanitize_key( wp_unslash( (string) $raw['priority'] ) ) : 'medium';
		$data['priority'] = self::is_valid_priority( $priority ) ? $priority : 'medium';

		$data['start_date'] = self::sanitize_date( $raw['start_date'] ?? '' );
		$data['due_date']   = self::sanitize_date( $raw['due_date'] ?? '' );

		if ( $data['start_date'] && $data['due_date'] && $data['start_date'] > $data['due_date'] ) {
			$errors->add( 'invalid_date_range', __( 'The due date cannot be earlier than the start date.', 'personal-project-tracker' ) );
		}

		$progress         = isset( $raw['progress'] ) ? (int) $raw['progress'] : 0;
		$data['progress'] = max( 0, min( 100, $progress ) );

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
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'project_id'  => '%d',
			'title'       => '%s',
			'description' => '%s',
			'status'      => '%s',
			'priority'    => '%s',
			'start_date'  => '%s',
			'due_date'    => '%s',
			'progress'    => '%d',
			'created_at'  => '%s',
			'updated_at'  => '%s',
			'archived_at' => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Get a single milestone by ID.
	 *
	 * @param int $id Milestone ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Milestone ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Create a new milestone.
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
			ptp_log_error( 'Failed to insert milestone: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the milestone. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'milestone_created',
			'milestone',
			$id,
			/* translators: %s: milestone title. */
			sprintf( __( 'Created milestone "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * Update an existing milestone.
	 *
	 * @param int   $id  Milestone ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update milestone ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the milestone. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'milestone_updated',
			'milestone',
			$id,
			/* translators: %s: milestone title. */
			sprintf( __( 'Updated milestone "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * Permanently delete a milestone. Tasks assigned to it are detached
	 * (milestone_id set back to NULL), never deleted — deleting a milestone
	 * must not delete task records.
	 *
	 * @param int $id Milestone ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) );
		}

		foreach ( PTP_Tasks_Repository::get_by_milestone( $id ) as $task ) {
			self::detach_task( $task->id );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete milestone ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the milestone. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'milestone_deleted',
			'milestone',
			$id,
			/* translators: %s: milestone title. */
			sprintf( __( 'Deleted milestone "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Mark a milestone completed.
	 *
	 * @param int $id Milestone ID.
	 * @return true|WP_Error
	 */
	public static function complete( $id ) {
		return self::set_status( $id, 'completed', 'milestone_completed', __( 'Completed milestone "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * Reopen a milestone back to Planning.
	 *
	 * @param int $id Milestone ID.
	 * @return true|WP_Error
	 */
	public static function reopen( $id ) {
		return self::set_status( $id, 'planning', 'milestone_reopened', __( 'Reopened milestone "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int    $id           Milestone ID.
	 * @param string $status       New status.
	 * @param string $log_action   Activity log action slug.
	 * @param string $log_template sprintf() template with one %s for the title.
	 * @return true|WP_Error
	 */
	private static function set_status( $id, $status, $log_action, $log_template ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				'status'     => $status,
				'updated_at' => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log( $log_action, 'milestone', $id, sprintf( $log_template, $existing->title ) );

		return true;
	}

	/**
	 * Archive a milestone: stamps archived_at without changing its status.
	 *
	 * @param int $id Milestone ID.
	 * @return true|WP_Error
	 */
	public static function archive( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				'archived_at' => ptp_now(),
				'updated_at'  => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log(
			'milestone_archived',
			'milestone',
			$id,
			/* translators: %s: milestone title. */
			sprintf( __( 'Archived milestone "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Restore a previously archived milestone.
	 *
	 * @param int $id Milestone ID.
	 * @return true|WP_Error
	 */
	public static function restore( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				'archived_at' => null,
				'updated_at'  => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log(
			'milestone_restored',
			'milestone',
			$id,
			/* translators: %s: milestone title. */
			sprintf( __( 'Restored milestone "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Assign an existing task to a milestone. The task must belong to the
	 * same project as the milestone (data integrity) and its record is
	 * never duplicated — only its milestone_id is updated via
	 * PTP_Tasks_Repository, which already owns that table.
	 *
	 * @param int $milestone_id Milestone ID.
	 * @param int $task_id      Task ID.
	 * @return true|WP_Error
	 */
	public static function attach_task( $milestone_id, $task_id ) {
		$milestone = self::get( $milestone_id );

		if ( ! $milestone ) {
			return new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) );
		}

		$task = PTP_Tasks_Repository::get( $task_id );

		if ( ! $task ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
		}

		if ( (int) $task->project_id !== (int) $milestone->project_id ) {
			return new WP_Error( 'ptp_project_mismatch', __( 'A task can only be added to a milestone in the same project.', 'personal-project-tracker' ) );
		}

		$result = PTP_Tasks_Repository::update( $task_id, array_merge( self::task_to_raw( $task ), array( 'milestone_id' => $milestone_id ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PTP_Activity_Log::log(
			'milestone_task_attached',
			'milestone',
			$milestone_id,
			/* translators: %s: task title. */
			sprintf( __( 'Added task "%s" to this milestone', 'personal-project-tracker' ), $task->title )
		);

		return true;
	}

	/**
	 * Remove a task from whichever milestone it belongs to.
	 *
	 * @param int $task_id Task ID.
	 * @return true|WP_Error
	 */
	public static function detach_task( $task_id ) {
		$task = PTP_Tasks_Repository::get( $task_id );

		if ( ! $task ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
		}

		$previous_milestone_id = $task->milestone_id;

		$result = PTP_Tasks_Repository::update( $task_id, array_merge( self::task_to_raw( $task ), array( 'milestone_id' => 0 ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $previous_milestone_id ) {
			PTP_Activity_Log::log(
				'milestone_task_detached',
				'milestone',
				(int) $previous_milestone_id,
				/* translators: %s: task title. */
				sprintf( __( 'Removed task "%s" from this milestone', 'personal-project-tracker' ), $task->title )
			);
		}

		return true;
	}

	/**
	 * Rebuild a full raw-input array from an existing task row, so it can be
	 * round-tripped through PTP_Tasks_Repository::update() — which expects
	 * a full representation, not a partial patch — without this module
	 * needing to know or duplicate Tasks' validation rules.
	 *
	 * @param object $task Task row.
	 * @return array
	 */
	private static function task_to_raw( $task ) {
		return array(
			'title'          => $task->title,
			'description'    => $task->description,
			'project_id'     => $task->project_id,
			'status'         => $task->status,
			'priority'       => $task->priority,
			'start_date'     => $task->start_date,
			'due_date'       => $task->due_date,
			'estimated_time' => $task->estimated_time,
			'tags'           => $task->tags,
		);
	}

	/**
	 * Get a filtered, sorted, paginated list of milestones.
	 *
	 * @param array $args {
	 *     @type string $search     Free-text search against title/description.
	 *     @type string $status     Status filter.
	 *     @type string $priority   Priority filter.
	 *     @type int    $project_id Project filter.
	 *     @type string $due_filter One of '', 'overdue', 'today', 'week', 'none'.
	 *     @type string $view       'active' (default, archived_at IS NULL), 'archived', or 'all'.
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
				'status'     => '',
				'priority'   => '',
				'project_id' => 0,
				'due_filter' => '',
				'view'       => 'active',
				'orderby'    => 'due_date',
				'order'      => 'ASC',
				'paged'      => 1,
				'per_page'   => 20,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( $args['status'] && self::is_valid_status( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( $args['priority'] && self::is_valid_priority( $args['priority'] ) ) {
			$where[]  = 'priority = %s';
			$params[] = $args['priority'];
		}

		$project_id = (int) $args['project_id'];

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		$today = current_time( 'Y-m-d' );

		switch ( $args['due_filter'] ) {
			case 'overdue':
				$where[]  = "due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled')";
				$params[] = $today;
				break;
			case 'today':
				$where[]  = 'due_date = %s';
				$params[] = $today;
				break;
			case 'week':
				$where[]  = 'due_date IS NOT NULL AND due_date BETWEEN %s AND %s';
				$params[] = $today;
				$params[] = gmdate( 'Y-m-d', strtotime( $today . ' +6 days' ) );
				break;
			case 'none':
				$where[] = 'due_date IS NULL';
				break;
		}

		switch ( $args['view'] ) {
			case 'archived':
				$where[] = 'archived_at IS NOT NULL';
				break;
			case 'all':
				break;
			default:
				$where[] = 'archived_at IS NULL';
				break;
		}

		$orderby = in_array( $args['orderby'], self::$sortable_columns, true ) ? $args['orderby'] : 'due_date';
		$order   = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';

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
	 * Get every non-archived, not-done milestone that is currently overdue,
	 * for the Smart Alerts engine's "Milestone deadline approaching" /
	 * overdue detection. Mirrors PTP_Tasks_Repository::get_overdue().
	 *
	 * @return object[]
	 */
	public static function get_overdue() {
		global $wpdb;

		$table = self::get_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE archived_at IS NULL AND due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled') ORDER BY due_date ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' )
			)
		);
	}

	/**
	 * Get summary statistics used by the Milestones list page and the Dashboard.
	 *
	 * @return array{total: int, upcoming: int, overdue: int, completed: int, by_status: array<string,int>}
	 */
	public static function get_stats() {
		global $wpdb;

		$table = self::get_table();
		$today = current_time( 'Y-m-d' );

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} WHERE archived_at IS NULL GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_status = array();

		foreach ( $rows as $row ) {
			$by_status[ $row['status'] ] = (int) $row['cnt'];
		}

		$upcoming = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE archived_at IS NULL AND due_date IS NOT NULL AND due_date BETWEEN %s AND %s AND status NOT IN ('completed','cancelled')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today,
				gmdate( 'Y-m-d', strtotime( $today . ' +13 days' ) )
			)
		);

		$overdue = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE archived_at IS NULL AND due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today
			)
		);

		return array(
			'total'     => array_sum( $by_status ),
			'upcoming'  => $upcoming,
			'overdue'   => $overdue,
			'completed' => $by_status['completed'] ?? 0,
			'by_status' => $by_status,
		);
	}

	/**
	 * Filtered milestone counts for the Reports module (Milestone Report).
	 * Two GROUP-BY/COUNT queries only — never loads individual milestone
	 * rows into PHP.
	 *
	 * @param array $args {
	 *     @type int    $project_id Project filter (0 = all).
	 *     @type string $priority   Priority filter.
	 *     @type string $date_from  'Y-m-d' due_date range start (inclusive).
	 *     @type string $date_to    'Y-m-d' due_date range end (inclusive).
	 *     @type string $view       'active' (default, archived_at IS NULL), 'archived', or 'all'.
	 * }
	 * @return array{total: int, completed: int, in_progress: int, overdue: int, by_status: array<string,int>}
	 */
	public static function get_report_counts( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'project_id' => 0,
				'priority'   => '',
				'date_from'  => '',
				'date_to'    => '',
				'view'       => 'active',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		switch ( $args['view'] ) {
			case 'archived':
				$where[] = 'archived_at IS NOT NULL';
				break;
			case 'all':
				break;
			default:
				$where[] = 'archived_at IS NULL';
				break;
		}

		$project_id = (int) $args['project_id'];

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		if ( $args['priority'] && self::is_valid_priority( $args['priority'] ) ) {
			$where[]  = 'priority = %s';
			$params[] = $args['priority'];
		}

		$date_from = self::sanitize_date( $args['date_from'] );

		if ( $date_from ) {
			$where[]  = 'due_date >= %s';
			$params[] = $date_from;
		}

		$date_to = self::sanitize_date( $args['date_to'] );

		if ( $date_to ) {
			$where[]  = 'due_date <= %s';
			$params[] = $date_to;
		}

		$where_sql = implode( ' AND ', $where );

		$status_sql  = "SELECT status, COUNT(*) as cnt FROM {$table} WHERE {$where_sql} GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $params
			? $wpdb->get_results( $wpdb->prepare( $status_sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $status_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_status = array();

		foreach ( $status_rows as $row ) {
			$by_status[ $row['status'] ] = (int) $row['cnt'];
		}

		$overdue_where   = $where;
		$overdue_where[] = "due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue_params  = array_merge( $params, array( current_time( 'Y-m-d' ) ) );
		$overdue_sql     = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $overdue_where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue         = (int) $wpdb->get_var( $wpdb->prepare( $overdue_sql, $overdue_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total'       => array_sum( $by_status ),
			'completed'   => $by_status['completed'] ?? 0,
			'in_progress' => $by_status['in_progress'] ?? 0,
			'overdue'     => $overdue,
			'by_status'   => $by_status,
		);
	}

	/**
	 * Same shape as get_report_counts(), but for many projects at once via
	 * a single GROUP BY project_id, status query plus a single GROUP BY
	 * project_id overdue query — two queries total regardless of how many
	 * project IDs are given, instead of get_report_counts() called once per
	 * project (the N+1 pattern the Reports project listing used to hit).
	 *
	 * @param int[] $project_ids Project IDs to report on.
	 * @param array $args        Same filters as get_report_counts() minus project_id.
	 * @return array<int, array{total: int, completed: int, in_progress: int, overdue: int, by_status: array<string,int>}> Keyed by project_id; a project with no matching milestones is still present with all-zero counts.
	 */
	public static function get_report_counts_by_projects( array $project_ids, array $args = array() ) {
		global $wpdb;

		$project_ids = array_values( array_unique( array_map( 'absint', $project_ids ) ) );

		if ( empty( $project_ids ) ) {
			return array();
		}

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'priority'  => '',
				'date_from' => '',
				'date_to'   => '',
				'view'      => 'active',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		switch ( $args['view'] ) {
			case 'archived':
				$where[] = 'archived_at IS NOT NULL';
				break;
			case 'all':
				break;
			default:
				$where[] = 'archived_at IS NULL';
				break;
		}

		if ( $args['priority'] && self::is_valid_priority( $args['priority'] ) ) {
			$where[]  = 'priority = %s';
			$params[] = $args['priority'];
		}

		$date_from = self::sanitize_date( $args['date_from'] );

		if ( $date_from ) {
			$where[]  = 'due_date >= %s';
			$params[] = $date_from;
		}

		$date_to = self::sanitize_date( $args['date_to'] );

		if ( $date_to ) {
			$where[]  = 'due_date <= %s';
			$params[] = $date_to;
		}

		$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
		$where[]      = "project_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params       = array_merge( $params, $project_ids );

		$where_sql = implode( ' AND ', $where );

		$status_sql  = "SELECT project_id, status, COUNT(*) as cnt FROM {$table} WHERE {$where_sql} GROUP BY project_id, status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $wpdb->get_results( $wpdb->prepare( $status_sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_project_status = array();

		foreach ( $status_rows as $row ) {
			$by_project_status[ (int) $row['project_id'] ][ $row['status'] ] = (int) $row['cnt'];
		}

		$overdue_where   = $where;
		$overdue_where[] = "due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue_params  = array_merge( $params, array( current_time( 'Y-m-d' ) ) );
		$overdue_sql     = "SELECT project_id, COUNT(*) as cnt FROM {$table} WHERE " . implode( ' AND ', $overdue_where ) . ' GROUP BY project_id'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue_rows    = $wpdb->get_results( $wpdb->prepare( $overdue_sql, $overdue_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$overdue_by_project = array();

		foreach ( $overdue_rows as $row ) {
			$overdue_by_project[ (int) $row['project_id'] ] = (int) $row['cnt'];
		}

		$result = array();

		foreach ( $project_ids as $project_id ) {
			$by_status = $by_project_status[ $project_id ] ?? array();

			$result[ $project_id ] = array(
				'total'       => array_sum( $by_status ),
				'completed'   => $by_status['completed'] ?? 0,
				'in_progress' => $by_status['in_progress'] ?? 0,
				'overdue'     => $overdue_by_project[ $project_id ] ?? 0,
				'by_status'   => $by_status,
			);
		}

		return $result;
	}

	/**
	 * Get a lightweight id => title map of all non-archived milestones
	 * (across every project), for the Calendar's "link to a milestone"
	 * event picker.
	 *
	 * @return array<int, string>
	 */
	public static function get_options_for_select() {
		global $wpdb;

		$table = self::get_table();

		$rows = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE archived_at IS NULL ORDER BY title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$options = array();

		foreach ( $rows as $row ) {
			$options[ (int) $row['id'] ] = $row['title'];
		}

		return $options;
	}

	/**
	 * Get non-archived milestones whose due date falls within a date range,
	 * for the Calendar module. Due dates are never copied into a separate
	 * events table — the Calendar reads them live from here.
	 *
	 * @param string $start 'Y-m-d' range start (inclusive).
	 * @param string $end   'Y-m-d' range end (inclusive).
	 * @return object[] Rows with id, title, due_date, status, priority, project_id.
	 */
	public static function get_deadlines_in_range( $start, $end ) {
		global $wpdb;

		$table = self::get_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, due_date, status, priority, project_id FROM {$table} WHERE due_date IS NOT NULL AND due_date BETWEEN %s AND %s AND archived_at IS NULL ORDER BY due_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start,
				$end
			)
		);
	}

	/**
	 * Get the soonest upcoming (non-overdue, non-completed) milestones, for
	 * the Dashboard widget.
	 *
	 * @param int $limit Max rows to return.
	 * @return object[]
	 */
	public static function get_upcoming( $limit = 5 ) {
		global $wpdb;

		$table = self::get_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE archived_at IS NULL AND due_date IS NOT NULL AND due_date >= %s AND status NOT IN ('completed','cancelled') ORDER BY due_date ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' ),
				(int) $limit
			)
		);
	}
}
