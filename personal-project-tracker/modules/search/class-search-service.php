<?php
/**
 * Global search: one query per source table, merged and paginated in PHP.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Search_Service
 *
 * Every repository in this plugin owns its own table's queries (see e.g.
 * PTP_Tasks_Repository::get_by_milestone()'s docblock) — there is no
 * precedent anywhere in this codebase for one cross-table SQL query, so
 * global search follows the same rule: SOURCES below describes each
 * searchable table (its own indexed WHERE-clause search, exactly like
 * every get_list() method already does), search() runs one bounded,
 * indexed, LIMIT'd query per active source, then merges/sorts/paginates
 * the results in PHP. Nothing here ever SELECTs a whole table.
 *
 * URL resolution reuses PTP_Notifications_Service::get_deep_link() for
 * every related_type it already maps (project/task/milestone/
 * calendar_event/expense/revenue/reminder) rather than duplicating that
 * lookup, and only adds new mappings for the source types Notifications
 * has no reason to know about (subtask, note, link, file, prompt
 * document/template, activity log).
 */
class PTP_Search_Service {

	/**
	 * One entry per searchable table. `type` is the Type filter value shown
	 * in the UI — Finance (expense/revenue) and Prompts (document/template)
	 * each group two physical sources under one filter value, matching how
	 * the rest of the plugin already presents those pairs as one module.
	 *
	 * `project_id_column` is the column to filter on directly; null means
	 * this source has no direct, cheap way to scope to a project (a
	 * Project filter simply excludes that source rather than guessing).
	 * `capability` defaults to ptp_manage_data (the Search page's own gate)
	 * when omitted; Finance sources additionally require ptp_manage_finance,
	 * the same privacy boundary Finance enforces everywhere else.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const SOURCES = array(
		'project'         => array(
			'short_table'       => 'projects',
			'type'              => 'project',
			'search_columns'    => array( 'title', 'description' ),
			'title_column'      => 'title',
			'status_column'     => 'status',
			'priority_column'   => 'priority',
			'date_column'       => 'updated_at',
			'project_id_column' => 'id',
		),
		'task'            => array(
			'short_table'       => 'tasks',
			'type'              => 'task',
			'search_columns'    => array( 'title', 'description', 'tags' ),
			'title_column'      => 'title',
			'status_column'     => 'status',
			'priority_column'   => 'priority',
			'date_column'       => 'updated_at',
			'project_id_column' => 'project_id',
		),
		'subtask'         => array(
			'short_table'       => 'subtasks',
			'type'              => 'subtask',
			'search_columns'    => array( 'title' ),
			'title_column'      => 'title',
			'status_column'     => 'status',
			'priority_column'   => 'priority',
			'date_column'       => 'updated_at',
			'project_id_column' => null, // Resolved via a task_id subquery — see project_filter_sql().
		),
		'milestone'       => array(
			'short_table'       => 'milestones',
			'type'              => 'milestone',
			'search_columns'    => array( 'title', 'description' ),
			'title_column'      => 'title',
			'status_column'     => 'status',
			'priority_column'   => 'priority',
			'date_column'       => 'updated_at',
			'project_id_column' => 'project_id',
		),
		'note'            => array(
			'short_table'       => 'notes',
			'type'              => 'note',
			'search_columns'    => array( 'title', 'content' ),
			'title_column'      => 'title',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'updated_at',
			'project_id_column' => 'project_id',
		),
		'link'            => array(
			'short_table'       => 'project_links',
			'type'              => 'link',
			'search_columns'    => array( 'title', 'description', 'url' ),
			'title_column'      => 'title',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'updated_at',
			'project_id_column' => 'project_id',
		),
		'file'            => array(
			'short_table'       => 'project_files',
			'type'              => 'file',
			'search_columns'    => array( 'file_name' ),
			'title_column'      => 'file_name',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'created_at',
			'project_id_column' => 'project_id',
		),
		'expense'         => array(
			'short_table'       => 'expenses',
			'type'              => 'finance',
			'search_columns'    => array( 'description', 'category' ),
			'title_column'      => 'description',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'expense_date',
			'project_id_column' => 'project_id',
			'capability'        => 'ptp_manage_finance',
		),
		'revenue'         => array(
			'short_table'       => 'revenues',
			'type'              => 'finance',
			'search_columns'    => array( 'description', 'category' ),
			'title_column'      => 'description',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'revenue_date',
			'project_id_column' => 'project_id',
			'capability'        => 'ptp_manage_finance',
		),
		'prompt_document' => array(
			'short_table'       => 'prompt_documents',
			'type'              => 'prompt',
			'search_columns'    => array( 'title', 'goal', 'content' ),
			'title_column'      => 'title',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'updated_at',
			'project_id_column' => 'project_id',
		),
		'prompt_template' => array(
			'short_table'       => 'prompt_templates',
			'type'              => 'prompt',
			'search_columns'    => array( 'name', 'category' ),
			'title_column'      => 'name',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'updated_at',
			'project_id_column' => null, // A global, reusable resource — see PTP_Backup_Service's identical note.
		),
		'reminder'        => array(
			'short_table'       => 'reminders',
			'type'              => 'reminder',
			'search_columns'    => array( 'title' ),
			'title_column'      => 'title',
			'status_column'     => 'status',
			'priority_column'   => null,
			'date_column'       => 'remind_at',
			'project_id_column' => null, // Polymorphic related_type/related_id — excluded from a Project filter.
		),
		'activity'        => array(
			'short_table'       => 'activity_logs',
			'type'              => 'activity',
			'search_columns'    => array( 'description', 'action' ),
			'title_column'      => 'description',
			'status_column'     => null,
			'priority_column'   => null,
			'date_column'       => 'created_at',
			'project_id_column' => null, // Polymorphic object_type/object_id — excluded from a Project filter.
		),
	);

	/**
	 * Allowed Type filter values (the grouping shown to the user).
	 *
	 * @return string[]
	 */
	public static function get_types() {
		return array_values( array_unique( wp_list_pluck( self::SOURCES, 'type' ) ) );
	}

	/**
	 * Run a global search across every source the current user may see.
	 *
	 * @param array $args {
	 *     @type string $search     Free-text query.
	 *     @type string $type       One of get_types(), or '' for every type.
	 *     @type int    $project_id Scope to one project (sources with no
	 *                              direct project relationship are excluded
	 *                              rather than guessed at).
	 *     @type string $status     Exact status match, applied only to
	 *                              sources that have a status column.
	 *     @type string $priority   Exact priority match, applied only to
	 *                              sources that have a priority column.
	 *     @type string $date_from  'Y-m-d' inclusive lower bound on each
	 *                              source's own date column.
	 *     @type string $date_to    'Y-m-d' inclusive upper bound.
	 *     @type int    $paged      1-based page number.
	 *     @type int    $per_page   Results per page (max 100).
	 * }
	 * @return array{items: array[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function search( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'type'       => '',
				'project_id' => 0,
				'status'     => '',
				'priority'   => '',
				'date_from'  => '',
				'date_to'    => '',
				'paged'      => 1,
				'per_page'   => 20,
			)
		);

		$per_page   = max( 1, min( 100, (int) $args['per_page'] ) );
		$paged      = max( 1, (int) $args['paged'] );
		$offset     = ( $paged - 1 ) * $per_page;
		$project_id = (int) $args['project_id'];

		// Enough rows per source to cover every page up to the one requested,
		// bounded so a deep page number can never balloon into a full-table
		// scan; the merge below still only returns $per_page rows.
		$per_source_limit = min( 500, $offset + $per_page );

		$candidates    = array();
		$total_matched = 0;

		foreach ( self::active_sources( $args['type'] ) as $source_key => $source ) {
			if ( $project_id > 0 && null === $source['project_id_column'] && 'subtask' !== $source_key ) {
				continue; // Can't cheaply scope this source to a project — see class docblock.
			}

			list( $where_sql, $params ) = self::build_where( $source, $args, $project_id );

			$total_matched += self::count_matches( $source['short_table'], $where_sql, $params );

			foreach ( self::fetch_rows( $source['short_table'], $source['date_column'], $where_sql, $params, $per_source_limit ) as $row ) {
				$candidates[] = self::normalize_row( $source_key, $source, $row );
			}
		}

		usort( $candidates, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );

		$items = array_slice( $candidates, $offset, $per_page );

		return array(
			'items'       => $items,
			'total'       => $total_matched,
			'total_pages' => $per_page > 0 ? (int) ceil( $total_matched / $per_page ) : 0,
			'page'        => $paged,
			'per_page'    => $per_page,
		);
	}

	/**
	 * @param string $type_filter '' for every source, or one of get_types().
	 * @return array<string, array<string, mixed>> SOURCES entries the current user may see.
	 */
	private static function active_sources( $type_filter ) {
		$sources = array();

		foreach ( self::SOURCES as $source_key => $source ) {
			if ( $type_filter && $source['type'] !== $type_filter ) {
				continue;
			}

			if ( ! current_user_can( $source['capability'] ?? 'ptp_manage_data' ) ) {
				continue;
			}

			$sources[ $source_key ] = $source;
		}

		return $sources;
	}

	/**
	 * @param array $source     One SOURCES entry.
	 * @param array $args       search() args.
	 * @param int   $project_id Already-cast project filter.
	 * @return array{0: string, 1: array} [WHERE SQL (unprepared placeholders), params]
	 */
	private static function build_where( array $source, array $args, $project_id ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );

		if ( '' !== $search && ! empty( $source['search_columns'] ) ) {
			$like        = '%' . $wpdb->esc_like( $search ) . '%';
			$or_clauses  = array();

			foreach ( $source['search_columns'] as $column ) {
				$or_clauses[] = "{$column} LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column name from SOURCES, not user input.
				$params[]     = $like;
			}

			$where[] = '(' . implode( ' OR ', $or_clauses ) . ')';
		}

		if ( $project_id > 0 && $source['project_id_column'] ) {
			$where[]  = "{$source['project_id_column']} = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $project_id;
		} elseif ( $project_id > 0 && 'subtasks' === $source['short_table'] ) {
			// Subtasks have no project_id column of their own — resolve the
			// project's task IDs first, then filter by a literal IN-list, the
			// same two-step "fetch parent IDs, then IN filter" pattern
			// PTP_Backup_Service::collect_project_scoped() already uses for
			// this exact relationship, rather than a nested SQL subquery
			// (no query anywhere else in this plugin uses one).
			$task_ids = wp_list_pluck( self::fetch_rows( 'tasks', 'id', 'project_id = %d', array( $project_id ), 500 ), 'id' );

			if ( empty( $task_ids ) ) {
				$where[] = '1=0';
			} else {
				$placeholders = implode( ',', array_fill( 0, count( $task_ids ), '%d' ) );
				$where[]      = "task_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params       = array_merge( $params, array_map( 'intval', $task_ids ) );
			}
		}

		$status = trim( (string) $args['status'] );

		if ( '' !== $status && $source['status_column'] ) {
			$where[]  = "{$source['status_column']} = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $status;
		}

		$priority = trim( (string) $args['priority'] );

		if ( '' !== $priority && $source['priority_column'] ) {
			$where[]  = "{$source['priority_column']} = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $priority;
		}

		$date_from = self::sanitize_date( $args['date_from'] );
		$date_to   = self::sanitize_date( $args['date_to'] );
		$column    = $source['date_column'];

		if ( $date_from ) {
			$where[]  = "{$column} >= %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $date_from . ' 00:00:00';
		}

		if ( $date_to ) {
			$where[]  = "{$column} <= %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $date_to . ' 23:59:59';
		}

		if ( 'activity_logs' === $source['short_table'] && ! current_user_can( 'ptp_manage_finance' ) ) {
			// Unlike expenses/revenues (whole tables gated behind
			// ptp_manage_finance in active_sources()), activity_logs holds
			// every action's entry in one shared table — object_type
			// distinguishes an expense/revenue entry from everything else.
			// Their description text embeds the actual amount (e.g. "Logged
			// expense of $500.00" — see PTP_Expenses_Repository::create()),
			// so those rows must be excluded here the same way
			// PTP_Notifications_Repository already hides finance-category
			// notifications from a non-Finance user.
			$where[] = "object_type NOT IN ('expense','revenue')";
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * @param string $value Raw date string.
	 * @return string '' if empty/invalid, otherwise the validated 'Y-m-d' date.
	 */
	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$parsed = DateTime::createFromFormat( 'Y-m-d', $value );

		return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * @param string $short_table Short table name.
	 * @param string $where_sql   Already-built WHERE clause (placeholders, not values).
	 * @param array  $params      Values for $where_sql's placeholders.
	 * @return int
	 */
	private static function count_matches( $short_table, $where_sql, array $params ) {
		global $wpdb;

		$table     = ptp_table( $short_table );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param string $short_table Short table name.
	 * @param string $date_column Column to sort newest-first by.
	 * @param string $where_sql   Already-built WHERE clause (placeholders, not values).
	 * @param array  $params      Values for $where_sql's placeholders.
	 * @param int    $limit       Row cap — never an unbounded fetch.
	 * @return object[]
	 */
	private static function fetch_rows( $short_table, $date_column, $where_sql, array $params, $limit ) {
		global $wpdb;

		$table       = ptp_table( $short_table );
		$sql         = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$date_column} DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_params = array_merge( $params, array( max( 1, (int) $limit ) ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Shape one DB row into the common result format the admin page and the
	 * REST endpoint both render.
	 *
	 * @param string $source_key SOURCES key.
	 * @param array  $source     SOURCES entry.
	 * @param object $row        Raw DB row.
	 * @return array
	 */
	private static function normalize_row( $source_key, array $source, $row ) {
		$status   = $source['status_column'] ? ( $row->{$source['status_column']} ?? '' ) : '';
		$priority = $source['priority_column'] ? ( $row->{$source['priority_column']} ?? '' ) : '';

		return array(
			'source'         => $source_key,
			'type'           => $source['type'],
			'id'             => (int) $row->id,
			'title'          => self::title_for( $source_key, $row ),
			'project_id'     => $source['project_id_column'] && isset( $row->{$source['project_id_column']} ) ? (int) $row->{$source['project_id_column']} : self::indirect_project_id( $source_key, $row ),
			'status'         => $status,
			'status_label'   => $status ? ( self::status_labels( $source_key )[ $status ] ?? $status ) : '',
			'priority'       => $priority,
			'priority_label' => $priority ? ( self::priority_labels( $source_key )[ $priority ] ?? $priority ) : '',
			'date'           => (string) $row->{$source['date_column']},
			'url'            => self::build_url( $source_key, $row ),
		);
	}

	/**
	 * @param string $source_key SOURCES key.
	 * @param object $row        Raw DB row.
	 * @return string
	 */
	private static function title_for( $source_key, $row ) {
		$column = self::SOURCES[ $source_key ]['title_column'];
		$value  = trim( (string) ( $row->{$column} ?? '' ) );

		if ( '' !== $value ) {
			return $value;
		}

		switch ( $source_key ) {
			case 'expense':
			case 'revenue':
				return $row->category ? (string) $row->category : __( '(no description)', 'personal-project-tracker' );
			case 'activity':
				return (string) $row->action;
			case 'note':
				return __( '(untitled)', 'personal-project-tracker' );
			default:
				return __( '(untitled)', 'personal-project-tracker' );
		}
	}

	/**
	 * A best-effort project_id for sources with no direct column, used only
	 * for display (never for filtering — see project_id_column === null in
	 * SOURCES). Cheap: one extra get() per row, on an already-small,
	 * paginated result set.
	 *
	 * @param string $source_key SOURCES key.
	 * @param object $row        Raw DB row.
	 * @return int|null
	 */
	private static function indirect_project_id( $source_key, $row ) {
		if ( 'subtask' === $source_key ) {
			$task = PTP_Tasks_Repository::get( (int) $row->task_id );

			return $task ? (int) $task->project_id : null;
		}

		if ( 'reminder' === $source_key && 'project' === $row->related_type ) {
			return (int) $row->related_id;
		}

		return null;
	}

	/**
	 * @param string $source_key SOURCES key.
	 * @return array<string, string>
	 */
	private static function status_labels( $source_key ) {
		switch ( $source_key ) {
			case 'project':
				return PTP_Projects_Repository::get_statuses();
			case 'task':
			case 'subtask':
				return PTP_Tasks_Repository::get_statuses();
			case 'milestone':
				return PTP_Milestones_Repository::get_statuses();
			case 'reminder':
				return PTP_Reminders_Repository::get_statuses();
			default:
				return array();
		}
	}

	/**
	 * @param string $source_key SOURCES key.
	 * @return array<string, string>
	 */
	private static function priority_labels( $source_key ) {
		switch ( $source_key ) {
			case 'project':
				return PTP_Projects_Repository::get_priorities();
			case 'task':
			case 'subtask':
				return PTP_Tasks_Repository::get_priorities();
			case 'milestone':
				return PTP_Milestones_Repository::get_priorities();
			default:
				return array();
		}
	}

	/**
	 * Build the "open this result" URL. Reuses
	 * PTP_Notifications_Service::get_deep_link() for every related_type it
	 * already maps rather than duplicating that lookup; only the source
	 * types Notifications has no reason to know about get their own mapping
	 * here.
	 *
	 * @param string $source_key SOURCES key.
	 * @param object $row        Raw DB row.
	 * @return string
	 */
	public static function build_url( $source_key, $row ) {
		switch ( $source_key ) {
			case 'project':
			case 'task':
			case 'milestone':
			case 'expense':
			case 'revenue':
			case 'reminder':
				return PTP_Notifications_Service::get_deep_link( $source_key, (int) $row->id );

			case 'subtask':
				// Subtasks have no page of their own — they're shown inline
				// on their parent task's detail page.
				return add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => (int) $row->task_id ), admin_url( 'admin.php' ) );

			case 'note':
				return add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'view', 'id' => (int) $row->id ), admin_url( 'admin.php' ) );

			case 'link':
				return add_query_arg( array( 'page' => 'ptp-links', 'action' => 'view', 'id' => (int) $row->id ), admin_url( 'admin.php' ) );

			case 'file':
				return add_query_arg( array( 'page' => 'ptp-files', 'action' => 'view', 'id' => (int) $row->id ), admin_url( 'admin.php' ) );

			case 'prompt_document':
				return add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'view', 'id' => (int) $row->id ), admin_url( 'admin.php' ) );

			case 'prompt_template':
				// Templates have no view page — edit_template is the only detail page they have.
				return add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'edit_template', 'id' => (int) $row->id ), admin_url( 'admin.php' ) );

			case 'activity':
				return self::build_url_for_activity( $row );

			default:
				return add_query_arg( array( 'page' => 'ptp-search' ), admin_url( 'admin.php' ) );
		}
	}

	/**
	 * An activity log row points at another object via object_type/
	 * object_id; resolve that to the same URL a search result for the
	 * underlying object would get, rather than inventing a nonexistent
	 * "activity detail page".
	 *
	 * @param object $row Activity log row.
	 * @return string
	 */
	private static function build_url_for_activity( $row ) {
		$object_type = (string) $row->object_type;
		$object_id   = (int) $row->object_id;
		$search_url  = add_query_arg( array( 'page' => 'ptp-search' ), admin_url( 'admin.php' ) );

		if ( ! $object_type ) {
			return $search_url;
		}

		// backup/import/restore actions have no underlying record at all —
		// PTP_Activity_Log::log() always records object_id 0 for these (see
		// PTP_Backup_Service::create_backup(), PTP_Import_Service::apply_import()/
		// apply_restore()) — so this must be checked by object_type alone,
		// before the object_id-required checks below.
		if ( in_array( $object_type, array( 'backup', 'import', 'restore' ), true ) ) {
			return add_query_arg( array( 'page' => 'ptp-settings', 'tab' => 'backup' ), admin_url( 'admin.php' ) );
		}

		if ( ! $object_id ) {
			return $search_url;
		}

		// Types PTP_Notifications_Service::get_deep_link() already maps.
		if ( in_array( $object_type, array( 'project', 'task', 'milestone', 'calendar_event', 'expense', 'revenue', 'reminder' ), true ) ) {
			return PTP_Notifications_Service::get_deep_link( $object_type, $object_id );
		}

		$fallback = array(
			'note'            => array( 'page' => 'ptp-notes', 'action' => 'view' ),
			'link'            => array( 'page' => 'ptp-links', 'action' => 'view' ),
			'file'            => array( 'page' => 'ptp-files', 'action' => 'view' ),
			'prompt_document' => array( 'page' => 'ptp-ai-prompts', 'action' => 'view' ),
			'prompt_template' => array( 'page' => 'ptp-ai-prompts', 'action' => 'edit_template' ),
		);

		if ( isset( $fallback[ $object_type ] ) ) {
			return add_query_arg( array_merge( $fallback[ $object_type ], array( 'id' => $object_id ) ), admin_url( 'admin.php' ) );
		}

		// time_entry has no single-record page — send it to the closest list view.
		if ( 'time_entry' === $object_type ) {
			return add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) );
		}

		return $search_url;
	}
}
