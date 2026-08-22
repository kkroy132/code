<?php
/**
 * Projects data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Projects_Repository
 *
 * Owns all reads/writes to the projects table, plus the single
 * sanitize-and-validate routine ( prepare_fields() ) shared by both the
 * classic admin-post form handler and the REST controller, so the
 * validation rules only exist in one place.
 */
class PTP_Projects_Repository {

	/**
	 * Columns that may be used to sort the project list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'title',
		'status',
		'priority',
		'start_date',
		'deadline',
		'budget',
		'progress',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed projects table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'projects' );
	}

	/**
	 * Allowed project statuses.
	 *
	 * @return array<string, string>
	 */
	public static function get_statuses() {
		return array(
			'planning'  => __( 'Planning', 'personal-project-tracker' ),
			'active'    => __( 'Active', 'personal-project-tracker' ),
			'on_hold'   => __( 'On Hold', 'personal-project-tracker' ),
			'completed' => __( 'Completed', 'personal-project-tracker' ),
			'cancelled' => __( 'Cancelled', 'personal-project-tracker' ),
			'archived'  => __( 'Archived', 'personal-project-tracker' ),
		);
	}

	/**
	 * Allowed project priorities.
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
	 * safe, column-ready associative array.
	 *
	 * This is the ONLY place project input is validated; both the admin
	 * form controller and the REST controller call this so the rules never
	 * drift apart.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error Sanitized data, or a WP_Error with one or more validation failures.
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$errors->add( 'title_required', __( 'Project title is required.', 'personal-project-tracker' ) );
		}

		$data['title']       = substr( $title, 0, 255 );
		$data['description'] = isset( $raw['description'] ) ? PTP_Security::sanitize_rich_text( $raw['description'] ) : '';

		$status           = isset( $raw['status'] ) ? sanitize_key( wp_unslash( (string) $raw['status'] ) ) : 'planning';
		$data['status']   = self::is_valid_status( $status ) ? $status : 'planning';

		$priority           = isset( $raw['priority'] ) ? sanitize_key( wp_unslash( (string) $raw['priority'] ) ) : 'medium';
		$data['priority']   = self::is_valid_priority( $priority ) ? $priority : 'medium';

		$data['start_date'] = self::sanitize_date( $raw['start_date'] ?? '' );
		$data['deadline']   = self::sanitize_date( $raw['deadline'] ?? '' );

		if ( $data['start_date'] && $data['deadline'] && $data['start_date'] > $data['deadline'] ) {
			$errors->add( 'invalid_date_range', __( 'The deadline cannot be earlier than the start date.', 'personal-project-tracker' ) );
		}

		$data['budget']            = self::sanitize_money( $raw['budget'] ?? null );
		$data['estimated_revenue'] = self::sanitize_money( $raw['estimated_revenue'] ?? null );

		$currency          = isset( $raw['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', wp_unslash( (string) $raw['currency'] ) ) ) : 'USD';
		$data['currency']  = $currency ? substr( $currency, 0, 10 ) : 'USD';

		$progress          = isset( $raw['progress'] ) ? (int) $raw['progress'] : 0;
		$data['progress']  = max( 0, min( 100, $progress ) );

		$color         = isset( $raw['color'] ) ? sanitize_hex_color( wp_unslash( (string) $raw['color'] ) ) : '';
		$data['color'] = $color ? $color : null;

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
	 * @param mixed $value Raw amount.
	 * @return float|null Non-negative float, or null when empty.
	 */
	private static function sanitize_money( $value ) {
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
			'title'             => '%s',
			'description'       => '%s',
			'status'            => '%s',
			'priority'          => '%s',
			'start_date'        => '%s',
			'deadline'          => '%s',
			'budget'            => '%f',
			'currency'          => '%s',
			'estimated_revenue' => '%f',
			'actual_revenue'    => '%f',
			'actual_expenses'   => '%f',
			'progress'          => '%d',
			'color'             => '%s',
			'owner_id'          => '%d',
			'created_at'        => '%s',
			'updated_at'        => '%s',
			'archived_at'       => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Get a single project by ID.
	 *
	 * @param int $id Project ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Project ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Create a new project.
	 *
	 * @param array $raw Raw input.
	 * @return int|WP_Error New project ID, or a WP_Error on validation/DB failure.
	 */
	public static function create( array $raw ) {
		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$now                = ptp_now();
		$data['owner_id']   = get_current_user_id();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert project: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the project. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'project_created',
			'project',
			$id,
			/* translators: %s: project title. */
			sprintf( __( 'Created project "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * Update an existing project.
	 *
	 * @param int   $id  Project ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Project not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update project ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the project. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'project_updated',
			'project',
			$id,
			/* translators: %s: project title. */
			sprintf( __( 'Updated project "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * Permanently delete a project.
	 *
	 * @param int $id Project ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Project not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete project ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the project. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'project_deleted',
			'project',
			$id,
			/* translators: %s: project title. */
			sprintf( __( 'Deleted project "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Archive a project: marks status as 'archived' and stamps archived_at.
	 *
	 * @param int $id Project ID.
	 * @return true|WP_Error
	 */
	public static function archive( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Project not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$now = ptp_now();

		$wpdb->update(
			self::get_table(),
			array(
				'status'      => 'archived',
				'archived_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log(
			'project_archived',
			'project',
			$id,
			/* translators: %s: project title. */
			sprintf( __( 'Archived project "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Restore a previously archived project back to 'active'.
	 *
	 * @param int $id Project ID.
	 * @return true|WP_Error
	 */
	public static function restore( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Project not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				'status'      => 'active',
				'archived_at' => null,
				'updated_at'  => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log(
			'project_restored',
			'project',
			$id,
			/* translators: %s: project title. */
			sprintf( __( 'Restored project "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of projects.
	 *
	 * @param array $args {
	 *     @type string $search   Free-text search against title/description.
	 *     @type string $status   Status filter.
	 *     @type string $priority Priority filter.
	 *     @type string $orderby  Column to sort by.
	 *     @type string $order    'ASC' or 'DESC'.
	 *     @type int    $paged    1-indexed page number.
	 *     @type int    $per_page Results per page (max 100).
	 * }
	 * @return array{items: object[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_list( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'priority' => '',
				'orderby'  => 'updated_at',
				'order'    => 'DESC',
				'paged'    => 1,
				'per_page' => 20,
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

		$orderby = in_array( $args['orderby'], self::$sortable_columns, true ) ? $args['orderby'] : 'updated_at';
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
	 * Get the most recently updated, non-archived projects (for dashboard widgets).
	 *
	 * @param int $limit Max rows to return.
	 * @return object[]
	 */
	public static function get_recent( $limit = 5 ) {
		global $wpdb;

		$table = self::get_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status != 'archived' ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $limit
			)
		);
	}

	/**
	 * Get a lightweight id => title map of all non-archived projects, for
	 * use in other modules' project-picker dropdowns (e.g. the Task form).
	 * Kept here rather than duplicated per-module since Projects already
	 * owns the table.
	 *
	 * @return array<int, string>
	 */
	public static function get_options_for_select() {
		global $wpdb;

		$table = self::get_table();

		$rows = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE status != 'archived' ORDER BY title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$options = array();

		foreach ( $rows as $row ) {
			$options[ (int) $row['id'] ] = $row['title'];
		}

		return $options;
	}

	/**
	 * Get summary statistics used by the Projects list page and the Dashboard.
	 *
	 * @return array{total: int, active: int, completed: int, overdue: int, by_status: array<string,int>}
	 */
	public static function get_stats() {
		global $wpdb;

		$table = self::get_table();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_status = array();

		foreach ( $rows as $row ) {
			$by_status[ $row['status'] ] = (int) $row['cnt'];
		}

		$overdue = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE deadline IS NOT NULL AND deadline < %s AND status NOT IN ('completed','cancelled','archived')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' )
			)
		);

		return array(
			'total'     => array_sum( $by_status ),
			'active'    => $by_status['active'] ?? 0,
			'completed' => $by_status['completed'] ?? 0,
			'overdue'   => $overdue,
			'by_status' => $by_status,
		);
	}
}
