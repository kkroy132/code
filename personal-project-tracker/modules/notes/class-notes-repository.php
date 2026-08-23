<?php
/**
 * Notes data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notes_Repository
 *
 * Owns all reads/writes to the notes table. A note may optionally be
 * attached to a Project, Task, and/or Milestone — reusing those
 * repositories' exists()/get() methods for validation, never duplicating
 * their data. Pin and Archive are independent booleans (not the
 * archived_at-timestamp pattern Tasks/Milestones use), matching the plain
 * TINYINT columns the notes table was already provisioned with.
 */
class PTP_Notes_Repository {

	/**
	 * Columns that may be used to sort the note list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'title',
		'pinned',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed notes table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'notes' );
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place note input is validated — both the admin form controller
	 * and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$title   = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';
		$content = isset( $raw['content'] ) ? PTP_Security::sanitize_rich_text( $raw['content'] ) : '';

		if ( '' === $title && '' === trim( strip_tags( $content ) ) ) {
			$errors->add( 'content_required', __( 'A note needs a title or content.', 'personal-project-tracker' ) );
		}

		$data['title']   = substr( $title, 0, 255 );
		$data['content'] = $content;

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

		$milestone_id = isset( $raw['milestone_id'] ) ? absint( $raw['milestone_id'] ) : 0;
		$milestone    = null;

		if ( $milestone_id ) {
			$milestone = PTP_Milestones_Repository::get( $milestone_id );

			if ( ! $milestone ) {
				$errors->add( 'milestone_invalid', __( 'The selected milestone does not exist.', 'personal-project-tracker' ) );
				$milestone_id = 0;
			}
		}

		if ( $task && $project_id && (int) $task->project_id !== $project_id ) {
			$errors->add( 'task_project_mismatch', __( 'The selected task does not belong to the selected project.', 'personal-project-tracker' ) );
		} elseif ( $task && ! $project_id ) {
			$project_id = (int) $task->project_id;
		}

		if ( $milestone && $project_id && (int) $milestone->project_id !== $project_id ) {
			$errors->add( 'milestone_project_mismatch', __( 'The selected milestone does not belong to the selected project.', 'personal-project-tracker' ) );
		} elseif ( $milestone && ! $project_id ) {
			$project_id = (int) $milestone->project_id;
		}

		$data['project_id']   = $project_id ? $project_id : null;
		$data['task_id']      = $task_id ? $task_id : null;
		$data['milestone_id'] = $milestone_id ? $milestone_id : null;

		$tags         = isset( $raw['tags'] ) ? PTP_Security::sanitize_text( $raw['tags'] ) : '';
		$data['tags'] = substr( $tags, 0, 500 );

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
			'project_id'   => '%d',
			'task_id'      => '%d',
			'milestone_id' => '%d',
			'title'        => '%s',
			'content'      => '%s',
			'pinned'       => '%d',
			'archived'     => '%d',
			'tags'         => '%s',
			'created_at'   => '%s',
			'updated_at'   => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * @param int $id Note ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Note ID.
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

		$now                = ptp_now();
		$data['pinned']     = 0;
		$data['archived']   = 0;
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert note: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the note. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'note_created',
			'note',
			$id,
			/* translators: %s: note title. */
			sprintf( __( 'Created note "%s"', 'personal-project-tracker' ), $data['title'] ? $data['title'] : __( '(untitled)', 'personal-project-tracker' ) )
		);

		return $id;
	}

	/**
	 * @param int   $id  Note ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Note not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update note ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the note. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'note_updated',
			'note',
			$id,
			/* translators: %s: note title. */
			sprintf( __( 'Updated note "%s"', 'personal-project-tracker' ), $data['title'] ? $data['title'] : __( '(untitled)', 'personal-project-tracker' ) )
		);

		return true;
	}

	/**
	 * @param int $id Note ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Note not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete note ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the note. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'note_deleted',
			'note',
			$id,
			/* translators: %s: note title. */
			sprintf( __( 'Deleted note "%s"', 'personal-project-tracker' ), $existing->title ? $existing->title : __( '(untitled)', 'personal-project-tracker' ) )
		);

		return true;
	}

	/**
	 * @param int    $id           Note ID.
	 * @param string $column       'pinned' or 'archived'.
	 * @param int    $value        0 or 1.
	 * @param string $log_action   Activity log action slug.
	 * @param string $log_template sprintf() template with one %s for the title.
	 * @return true|WP_Error
	 */
	private static function set_flag( $id, $column, $value, $log_action, $log_template ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Note not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				$column      => $value,
				'updated_at' => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log( $log_action, 'note', $id, sprintf( $log_template, $existing->title ? $existing->title : __( '(untitled)', 'personal-project-tracker' ) ) );

		return true;
	}

	/**
	 * @param int $id Note ID.
	 * @return true|WP_Error
	 */
	public static function pin( $id ) {
		return self::set_flag( $id, 'pinned', 1, 'note_pinned', __( 'Pinned note "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int $id Note ID.
	 * @return true|WP_Error
	 */
	public static function unpin( $id ) {
		return self::set_flag( $id, 'pinned', 0, 'note_unpinned', __( 'Unpinned note "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int $id Note ID.
	 * @return true|WP_Error
	 */
	public static function archive( $id ) {
		return self::set_flag( $id, 'archived', 1, 'note_archived', __( 'Archived note "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int $id Note ID.
	 * @return true|WP_Error
	 */
	public static function restore( $id ) {
		return self::set_flag( $id, 'archived', 0, 'note_restored', __( 'Restored note "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * Get a filtered, sorted, paginated list of notes.
	 *
	 * @param array $args {
	 *     @type string $search       Free-text search against title/content.
	 *     @type int    $project_id   Project filter.
	 *     @type int    $task_id      Task filter.
	 *     @type int    $milestone_id Milestone filter.
	 *     @type string $pinned       '', '1' (pinned only), or '0' (unpinned only).
	 *     @type string $view         'active' (default, archived=0), 'archived', or 'all'.
	 *     @type string $orderby      Column to sort by.
	 *     @type string $order        'ASC' or 'DESC'.
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
				'project_id'   => 0,
				'task_id'      => 0,
				'milestone_id' => 0,
				'pinned'       => '',
				'view'         => 'active',
				'orderby'      => 'updated_at',
				'order'        => 'DESC',
				'paged'        => 1,
				'per_page'     => 20,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR content LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'project_id', 'task_id', 'milestone_id' ) as $fk ) {
			$value = (int) $args[ $fk ];

			if ( $value > 0 ) {
				$where[]  = "{$fk} = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params[] = $value;
			}
		}

		if ( '' !== $args['pinned'] ) {
			$where[]  = 'pinned = %d';
			$params[] = $args['pinned'] ? 1 : 0;
		}

		switch ( $args['view'] ) {
			case 'archived':
				$where[] = 'archived = 1';
				break;
			case 'all':
				break;
			default:
				$where[] = 'archived = 0';
				break;
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
}
