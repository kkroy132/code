<?php
/**
 * Links data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Links_Repository
 *
 * Owns all reads/writes to the project_links table. A link may optionally
 * be attached to a Project, Task, and/or Milestone, mirroring the Notes
 * module's relationship-validation approach. URLs are validated for shape
 * only (a well-formed absolute http/https URL) — this module never fetches
 * the URL itself (no scraping, no reachability checks, no favicon/title
 * lookups).
 */
class PTP_Links_Repository {

	/**
	 * Columns that may be used to sort the link list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'title',
		'category',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed project_links table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'project_links' );
	}

	/**
	 * Validate a URL is a well-formed, absolute http(s) URL. Deliberately
	 * shape-only — no network request is ever made to check it.
	 *
	 * @param string $url Raw URL.
	 * @return string '' if invalid, otherwise the sanitized URL.
	 */
	public static function sanitize_url_strict( $url ) {
		$url = esc_url_raw( trim( wp_unslash( (string) $url ) ) );

		if ( '' === $url ) {
			return '';
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place link input is validated — both the admin form controller
	 * and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$errors->add( 'title_required', __( 'Link title is required.', 'personal-project-tracker' ) );
		}

		$data['title'] = substr( $title, 0, 255 );

		$url = isset( $raw['url'] ) ? self::sanitize_url_strict( $raw['url'] ) : '';

		if ( '' === $url ) {
			$errors->add( 'url_invalid', __( 'A valid http(s) URL is required.', 'personal-project-tracker' ) );
		}

		$data['url'] = substr( $url, 0, 1000 );

		$data['description'] = isset( $raw['description'] ) ? PTP_Security::sanitize_textarea( $raw['description'] ) : '';

		$category         = isset( $raw['category'] ) ? PTP_Security::sanitize_text( $raw['category'] ) : '';
		$data['category'] = substr( $category, 0, 100 );

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
			'url'          => '%s',
			'description'  => '%s',
			'category'     => '%s',
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
	 * @param int $id Link ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Link ID.
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
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert link: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not create the link. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'link_created',
			'link',
			$id,
			/* translators: %s: link title. */
			sprintf( __( 'Created link "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * @param int   $id  Link ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Link not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update link ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the link. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'link_updated',
			'link',
			$id,
			/* translators: %s: link title. */
			sprintf( __( 'Updated link "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * @param int $id Link ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Link not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete link ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the link. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'link_deleted',
			'link',
			$id,
			/* translators: %s: link title. */
			sprintf( __( 'Deleted link "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of links.
	 *
	 * @param array $args {
	 *     @type string $search       Free-text search against title/description/url.
	 *     @type int    $project_id   Project filter.
	 *     @type int    $task_id      Task filter.
	 *     @type int    $milestone_id Milestone filter.
	 *     @type string $category     Category filter.
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
				'category'     => '',
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
			$where[]  = '(title LIKE %s OR description LIKE %s OR url LIKE %s)';
			$params[] = $like;
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

		$category = trim( (string) $args['category'] );

		if ( '' !== $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
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
