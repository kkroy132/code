<?php
/**
 * Tasks data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Tasks_Repository
 *
 * Owns all reads/writes to the tasks table, plus the single
 * sanitize-and-validate routine ( prepare_fields() ) shared by the
 * admin-post form handler and the REST controller. Mirrors
 * PTP_Projects_Repository's shape so the two modules stay consistent.
 *
 * Archiving a task is independent of its status (unlike Projects, where
 * 'archived' is itself a status value): a task keeps whatever status it
 * had and simply gets an archived_at timestamp, so completed work stays
 * marked completed even after being tidied out of the active list.
 */
class PTP_Tasks_Repository {

	/**
	 * Columns that may be used to sort the task list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'title',
		'status',
		'priority',
		'start_date',
		'due_date',
		'estimated_time',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed tasks table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'tasks' );
	}

	/**
	 * Allowed task statuses.
	 *
	 * @return array<string, string>
	 */
	public static function get_statuses() {
		return array(
			'todo'        => __( 'Todo', 'personal-project-tracker' ),
			'in_progress' => __( 'In Progress', 'personal-project-tracker' ),
			'blocked'     => __( 'Blocked', 'personal-project-tracker' ),
			'review'      => __( 'Review', 'personal-project-tracker' ),
			'completed'   => __( 'Completed', 'personal-project-tracker' ),
			'cancelled'   => __( 'Cancelled', 'personal-project-tracker' ),
		);
	}

	/**
	 * Allowed task priorities.
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
	 * Sanitize and validate raw input (from $_POST or a REST request) into a
	 * safe, column-ready associative array. The only place task input is
	 * validated — both the admin form controller and the REST controller
	 * call this so the rules never drift apart.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error Sanitized data, or a WP_Error with one or more validation failures.
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$errors->add( 'title_required', __( 'Task title is required.', 'personal-project-tracker' ) );
		}

		$data['title']       = substr( $title, 0, 255 );
		$data['description'] = isset( $raw['description'] ) ? PTP_Security::sanitize_rich_text( $raw['description'] ) : '';

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;

		if ( ! $project_id ) {
			$errors->add( 'project_required', __( 'A task must belong to a project.', 'personal-project-tracker' ) );
		} elseif ( ! PTP_Projects_Repository::exists( $project_id ) ) {
			$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
		}

		$data['project_id'] = $project_id;

		// Milestones are implemented in a later phase; accept and validate
		// the column now (data integrity) without exposing a picker yet.
		$milestone_id = isset( $raw['milestone_id'] ) ? absint( $raw['milestone_id'] ) : 0;

		if ( $milestone_id && ! self::milestone_exists( $milestone_id ) ) {
			$errors->add( 'milestone_invalid', __( 'The selected milestone does not exist.', 'personal-project-tracker' ) );
		}

		$data['milestone_id'] = $milestone_id ? $milestone_id : null;

		$status         = isset( $raw['status'] ) ? sanitize_key( wp_unslash( (string) $raw['status'] ) ) : 'todo';
		$data['status'] = self::is_valid_status( $status ) ? $status : 'todo';

		$priority         = isset( $raw['priority'] ) ? sanitize_key( wp_unslash( (string) $raw['priority'] ) ) : 'medium';
		$data['priority'] = self::is_valid_priority( $priority ) ? $priority : 'medium';

		$data['start_date'] = self::sanitize_date( $raw['start_date'] ?? '' );
		$data['due_date']   = self::sanitize_date( $raw['due_date'] ?? '' );

		if ( $data['start_date'] && $data['due_date'] && $data['start_date'] > $data['due_date'] ) {
			$errors->add( 'invalid_date_range', __( 'The due date cannot be earlier than the start date.', 'personal-project-tracker' ) );
		}

		$data['estimated_time'] = self::sanitize_hours( $raw['estimated_time'] ?? null );

		$tags         = isset( $raw['tags'] ) ? PTP_Security::sanitize_text( $raw['tags'] ) : '';
		$data['tags'] = substr( $tags, 0, 500 );

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * @param int $milestone_id Milestone ID.
	 * @return bool
	 */
	private static function milestone_exists( $milestone_id ) {
		global $wpdb;

		$table = ptp_table( 'milestones' );

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE id = %d", $milestone_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @param mixed $value Raw hours value.
	 * @return float|null Non-negative float, or null when empty.
	 */
	private static function sanitize_hours( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = (float) $value;

		return $value < 0 ? 0.0 : $value;
	}

	/**
	 * Map sanitized data keys to their $wpdb format specifiers.
	 *
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'project_id'      => '%d',
			'milestone_id'    => '%d',
			'title'           => '%s',
			'description'     => '%s',
			'status'          => '%s',
			'priority'        => '%s',
			'due_date'        => '%s',
			'start_date'      => '%s',
			'estimated_time'  => '%f',
			'actual_time'     => '%f',
			'assigned_user'   => '%d',
			'tags'            => '%s',
			'created_at'      => '%s',
			'updated_at'      => '%s',
			'archived_at'     => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Get a single task by ID.
	 *
	 * @param int $id Task ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Task ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Create a new task.
	 *
	 * @param array $raw Raw input.
	 * @return int|WP_Error New task ID, or a WP_Error on validation/DB failure.
	 */
	public static function create( array $raw ) {
		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$now                     = ptp_now();
		$data['assigned_user']   = get_current_user_id();
		$data['created_at']      = $now;
		$data['updated_at']      = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert task: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the task. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'task_created',
			'task',
			$id,
			/* translators: %s: task title. */
			sprintf( __( 'Created task "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * Update an existing task.
	 *
	 * @param int   $id  Task ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update task ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the task. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'task_updated',
			'task',
			$id,
			/* translators: %s: task title. */
			sprintf( __( 'Updated task "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * Permanently delete a task (its subtasks are removed along with it).
	 *
	 * @param int $id Task ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->delete( ptp_table( 'subtasks' ), array( 'task_id' => $id ), array( '%d' ) );

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete task ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the task. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'task_deleted',
			'task',
			$id,
			/* translators: %s: task title. */
			sprintf( __( 'Deleted task "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Mark a task completed.
	 *
	 * @param int $id Task ID.
	 * @return true|WP_Error
	 */
	public static function complete( $id ) {
		return self::set_status( $id, 'completed', 'task_completed', __( 'Completed task "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * Reopen a completed/cancelled task back to Todo.
	 *
	 * @param int $id Task ID.
	 * @return true|WP_Error
	 */
	public static function reopen( $id ) {
		return self::set_status( $id, 'todo', 'task_reopened', __( 'Reopened task "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int    $id            Task ID.
	 * @param string $status        New status.
	 * @param string $log_action    Activity log action slug.
	 * @param string $log_template  sprintf() template with one %s for the title.
	 * @return true|WP_Error
	 */
	private static function set_status( $id, $status, $log_action, $log_template ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
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

		PTP_Activity_Log::log( $log_action, 'task', $id, sprintf( $log_template, $existing->title ) );

		return true;
	}

	/**
	 * Archive a task: stamps archived_at without changing its status.
	 *
	 * @param int $id Task ID.
	 * @return true|WP_Error
	 */
	public static function archive( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
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
			'task_archived',
			'task',
			$id,
			/* translators: %s: task title. */
			sprintf( __( 'Archived task "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Restore a previously archived task.
	 *
	 * @param int $id Task ID.
	 * @return true|WP_Error
	 */
	public static function restore( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) );
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
			'task_restored',
			'task',
			$id,
			/* translators: %s: task title. */
			sprintf( __( 'Restored task "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of tasks.
	 *
	 * @param array $args {
	 *     @type string $search     Free-text search against title/description/tags.
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
			$where[]  = '(title LIKE %s OR description LIKE %s OR tags LIKE %s)';
			$params[] = $like;
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
	 * Get all tasks assigned to a milestone, for the Milestone detail page's
	 * "Related Tasks" section. Owned here (not duplicated in the Milestones
	 * module) since Tasks already owns all reads/writes to this table.
	 *
	 * @param int $milestone_id Milestone ID.
	 * @return object[]
	 */
	public static function get_by_milestone( $milestone_id ) {
		global $wpdb;

		$table = self::get_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE milestone_id = %d ORDER BY due_date ASC, title ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $milestone_id
			)
		);
	}

	/**
	 * Get a lightweight id => title map of a project's non-archived tasks,
	 * for the Milestone detail page's "Add existing task" picker.
	 *
	 * @param int $project_id Project ID.
	 * @return array<int, string>
	 */
	public static function get_options_for_project( $project_id ) {
		global $wpdb;

		$table = self::get_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title FROM {$table} WHERE project_id = %d AND archived_at IS NULL ORDER BY title ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $project_id
			),
			ARRAY_A
		);

		$options = array();

		foreach ( $rows as $row ) {
			$options[ (int) $row['id'] ] = $row['title'];
		}

		return $options;
	}

	/**
	 * Get a lightweight id => title map of all non-archived tasks (across
	 * every project), for the Calendar's "link to a task" event picker.
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
	 * Get non-archived tasks whose due date falls within a date range, for
	 * the Calendar module. Due dates are never copied into a separate
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
	 * Get every non-archived, not-done task that is currently overdue, for
	 * the Smart Alerts engine's "Task overdue" rule. Read-only; Smart Alerts
	 * decides what to do with each row (cooldown, notification text) — this
	 * method only owns the query, same as every other cross-module read
	 * method on this repository.
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
	 * Get summary statistics used by the Tasks list page and the Dashboard.
	 *
	 * @return array{total: int, today: int, overdue: int, in_progress: int, completed: int, by_status: array<string,int>}
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

		$today_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE archived_at IS NULL AND due_date = %s AND status NOT IN ('completed','cancelled')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today
			)
		);

		$overdue = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE archived_at IS NULL AND due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today
			)
		);

		return array(
			'total'       => array_sum( $by_status ),
			'today'       => $today_count,
			'overdue'     => $overdue,
			'in_progress' => $by_status['in_progress'] ?? 0,
			'completed'   => $by_status['completed'] ?? 0,
			'by_status'   => $by_status,
		);
	}

	/**
	 * Filtered task counts for the Reports module (Task Report): total,
	 * completed, in progress, overdue, and completion rate. Two GROUP-BY/
	 * COUNT queries only — never loads individual task rows into PHP, so
	 * this stays fast regardless of how many tasks exist.
	 *
	 * @param array $args {
	 *     @type int    $project_id Project filter (0 = all).
	 *     @type string $priority   Priority filter.
	 *     @type string $date_from  'Y-m-d' due_date range start (inclusive).
	 *     @type string $date_to    'Y-m-d' due_date range end (inclusive).
	 *     @type string $view       'active' (default, archived_at IS NULL), 'archived', or 'all'.
	 * }
	 * @return array{total: int, completed: int, in_progress: int, overdue: int, completion_rate: float|null, by_status: array<string,int>}
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

		$overdue_where    = $where;
		$overdue_where[]  = "due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue_params   = array_merge( $params, array( current_time( 'Y-m-d' ) ) );
		$overdue_sql      = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $overdue_where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue          = (int) $wpdb->get_var( $wpdb->prepare( $overdue_sql, $overdue_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total     = array_sum( $by_status );
		$completed = $by_status['completed'] ?? 0;

		return array(
			'total'           => $total,
			'completed'       => $completed,
			'in_progress'     => $by_status['in_progress'] ?? 0,
			'overdue'         => $overdue,
			'completion_rate' => $total > 0 ? round( ( $completed / $total ) * 100, 2 ) : null,
			'by_status'       => $by_status,
		);
	}
}
