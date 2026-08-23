<?php
/**
 * Files data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Files_Repository
 *
 * Owns all reads/writes to the project_files table — which stores only
 * WordPress attachment IDs plus a metadata snapshot, never binary file
 * data. The WordPress Media Library (wp_insert_attachment()/
 * media_handle_upload(), driven from the Controller and from the browser's
 * wp.media() picker) is the single source of truth for the files
 * themselves; this repository only manages the association between an
 * attachment and a Project/Task/Milestone/Note. Detaching a file removes
 * that association row only — the underlying attachment is never deleted
 * here, since it may be referenced elsewhere (in this plugin or in WP
 * itself).
 */
class PTP_Files_Repository {

	/**
	 * Columns that may be used to sort the file list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'file_name',
		'file_size',
		'created_at',
	);

	/**
	 * Get the fully-prefixed project_files table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'project_files' );
	}

	/**
	 * @param int $attachment_id Post ID to check.
	 * @return bool
	 */
	public static function is_attachment( $attachment_id ) {
		return 'attachment' === get_post_type( (int) $attachment_id );
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array
	 * (the relationship columns only — file_name/file_type/file_size are
	 * always derived from the attachment itself in create(), never trusted
	 * from client input). The only place file-attachment input is
	 * validated — both the admin form controller and the REST controller
	 * call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$attachment_id = isset( $raw['attachment_id'] ) ? absint( $raw['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! self::is_attachment( $attachment_id ) ) {
			$errors->add( 'attachment_invalid', __( 'Please choose or upload a valid file.', 'personal-project-tracker' ) );
		}

		$data['attachment_id'] = $attachment_id;

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

		$note_id = isset( $raw['note_id'] ) ? absint( $raw['note_id'] ) : 0;

		if ( $note_id && ! PTP_Notes_Repository::exists( $note_id ) ) {
			$errors->add( 'note_invalid', __( 'The selected note does not exist.', 'personal-project-tracker' ) );
			$note_id = 0;
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

		if ( ! $project_id && ! $task_id && ! $milestone_id && ! $note_id ) {
			$errors->add( 'target_required', __( 'A file must be attached to a project, task, milestone, or note.', 'personal-project-tracker' ) );
		}

		$data['project_id']   = $project_id ? $project_id : null;
		$data['task_id']      = $task_id ? $task_id : null;
		$data['milestone_id'] = $milestone_id ? $milestone_id : null;
		$data['note_id']      = $note_id ? $note_id : null;

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
			'project_id'    => '%d',
			'task_id'       => '%d',
			'milestone_id'  => '%d',
			'note_id'       => '%d',
			'attachment_id' => '%d',
			'file_name'     => '%s',
			'file_type'     => '%s',
			'file_size'     => '%d',
			'created_at'    => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * @param int $id File attachment-association ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id File attachment-association ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data          Prepared relationship data (project_id/task_id/milestone_id/note_id).
	 * @return bool
	 */
	private static function already_attached( $attachment_id, array $data ) {
		global $wpdb;

		$table  = self::get_table();
		$where  = array( 'attachment_id = %d' );
		$params = array( (int) $attachment_id );

		foreach ( array( 'project_id', 'task_id', 'milestone_id', 'note_id' ) as $fk ) {
			if ( $data[ $fk ] ) {
				$where[]  = "{$fk} = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params[] = (int) $data[ $fk ];
			} else {
				$where[] = "{$fk} IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Attach an (already-uploaded) Media Library item to a Project/Task/
	 * Milestone/Note. file_name/file_type/file_size are always derived from
	 * the attachment itself via core WP functions, never trusted from
	 * client input.
	 *
	 * @param array $raw Raw input.
	 * @return int|WP_Error
	 */
	public static function create( array $raw ) {
		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( self::already_attached( $data['attachment_id'], $data ) ) {
			return new WP_Error( 'ptp_already_attached', __( 'This file is already attached here.', 'personal-project-tracker' ) );
		}

		$file_path = get_attached_file( $data['attachment_id'] );

		$data['file_name']  = get_the_title( $data['attachment_id'] );
		$data['file_type']  = get_post_mime_type( $data['attachment_id'] );
		$data['file_size']  = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : null;
		$data['created_at'] = ptp_now();

		global $wpdb;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert file attachment: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not attach the file. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'file_attached',
			'file',
			$id,
			/* translators: %s: file name. */
			sprintf( __( 'Attached file "%s"', 'personal-project-tracker' ), $data['file_name'] )
		);

		return $id;
	}

	/**
	 * Detach a file: removes the association row only. The underlying
	 * Media Library attachment is never deleted.
	 *
	 * @param int $id File attachment-association ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'File attachment not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to detach file ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not detach the file. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'file_detached',
			'file',
			$id,
			/* translators: %s: file name. */
			sprintf( __( 'Detached file "%s"', 'personal-project-tracker' ), $existing->file_name ? $existing->file_name : '' )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of file attachments.
	 *
	 * @param array $args {
	 *     @type string $search       Free-text search against file_name/file_type.
	 *     @type int    $project_id   Project filter.
	 *     @type int    $task_id      Task filter.
	 *     @type int    $milestone_id Milestone filter.
	 *     @type int    $note_id      Note filter.
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
				'note_id'      => 0,
				'orderby'      => 'created_at',
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
			$where[]  = '(file_name LIKE %s OR file_type LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'project_id', 'task_id', 'milestone_id', 'note_id' ) as $fk ) {
			$value = (int) $args[ $fk ];

			if ( $value > 0 ) {
				$where[]  = "{$fk} = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params[] = $value;
			}
		}

		$orderby = in_array( $args['orderby'], self::$sortable_columns, true ) ? $args['orderby'] : 'created_at';
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
