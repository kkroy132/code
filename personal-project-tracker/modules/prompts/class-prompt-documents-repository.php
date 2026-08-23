<?php
/**
 * Prompt documents data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Prompt_Documents_Repository
 *
 * Owns all reads/writes to the prompt_documents table. A prompt document
 * is a saved, user-editable copy of AI Prompt Studio output: `content` is
 * whatever the user last saved (Generate and Save are separate actions —
 * saving never silently regenerates content), while role/goal/
 * output_format/config capture the structured inputs that produced it, so
 * a saved prompt can be reopened and regenerated from the same settings.
 * `config` is one JSON blob for context selections/requirements/
 * constraints/custom labels rather than a column per option, matching the
 * prompt_templates table's own template-as-JSON shape.
 */
class PTP_Prompt_Documents_Repository {

	/**
	 * Columns that may be used to sort the prompt list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'title',
		'context_type',
		'favorite',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed prompt_documents table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'prompt_documents' );
	}

	/**
	 * Allowed context_type values — a coarse category used for filtering/
	 * search and to remember which quick action a prompt originated from.
	 * The fine-grained selections (which tasks/milestones, which context
	 * types are included) live in `config`.
	 *
	 * @return array<string, string>
	 */
	public static function get_context_types() {
		return array(
			'project'   => __( 'Project', 'personal-project-tracker' ),
			'task'      => __( 'Task', 'personal-project-tracker' ),
			'milestone' => __( 'Milestone', 'personal-project-tracker' ),
			'finance'   => __( 'Finance', 'personal-project-tracker' ),
			'report'    => __( 'Report', 'personal-project-tracker' ),
			'custom'    => __( 'Custom', 'personal-project-tracker' ),
		);
	}

	/**
	 * @param string $value Context type to check.
	 * @return bool
	 */
	public static function is_valid_context_type( $value ) {
		return array_key_exists( $value, self::get_context_types() );
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place prompt document input is validated — both the admin form
	 * controller and the REST controller call this. `content` is accepted
	 * as submitted (never re-derived here) so a user's manual edits are
	 * never silently overwritten on save.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$goal = isset( $raw['goal'] ) ? PTP_Security::sanitize_textarea( $raw['goal'] ) : '';

		if ( '' === trim( $goal ) ) {
			$errors->add( 'goal_required', __( 'A goal ("What should the AI help you with?") is required.', 'personal-project-tracker' ) );
		}

		$data['goal'] = $goal;

		$title = isset( $raw['title'] ) ? PTP_Security::sanitize_text( $raw['title'] ) : '';

		if ( '' === $title ) {
			$title = '' !== trim( $goal ) ? wp_trim_words( $goal, 8, '…' ) : __( 'Untitled Prompt', 'personal-project-tracker' );
		}

		$data['title'] = substr( $title, 0, 255 );

		$data['content'] = isset( $raw['content'] ) ? PTP_Security::sanitize_textarea( $raw['content'] ) : '';

		$context_type         = isset( $raw['context_type'] ) ? sanitize_key( wp_unslash( (string) $raw['context_type'] ) ) : 'custom';
		$data['context_type'] = self::is_valid_context_type( $context_type ) ? $context_type : 'custom';

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;

		if ( $project_id && ! PTP_Projects_Repository::exists( $project_id ) ) {
			$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
			$project_id = 0;
		}

		$data['project_id'] = $project_id ? $project_id : null;

		$role         = isset( $raw['role'] ) ? sanitize_key( wp_unslash( (string) $raw['role'] ) ) : '';
		$data['role'] = array_key_exists( $role, PTP_Prompt_Generator::ROLES ) ? $role : 'custom';

		$output_format         = isset( $raw['output_format'] ) ? sanitize_key( wp_unslash( (string) $raw['output_format'] ) ) : '';
		$data['output_format'] = array_key_exists( $output_format, PTP_Prompt_Generator::OUTPUT_FORMATS ) ? $output_format : 'plain_text';

		$config = is_array( $raw['config'] ?? null ) ? $raw['config'] : array();

		if ( ! is_array( $raw['config'] ?? null ) && isset( $raw['config'] ) && is_string( $raw['config'] ) ) {
			$decoded = json_decode( wp_unslash( $raw['config'] ), true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}

		$prepared_config = PTP_Prompt_Generator::sanitize_config( $config );

		if ( is_wp_error( $prepared_config ) ) {
			foreach ( $prepared_config->get_error_codes() as $code ) {
				$errors->add( $code, $prepared_config->get_error_message( $code ) );
			}
		} else {
			$data['config'] = wp_json_encode( $prepared_config );
		}

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
			'title'         => '%s',
			'content'       => '%s',
			'context_type'  => '%s',
			'project_id'    => '%d',
			'favorite'      => '%d',
			'role'          => '%s',
			'goal'          => '%s',
			'output_format' => '%s',
			'config'        => '%s',
			'created_at'    => '%s',
			'updated_at'    => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * @param int $id Prompt document ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Prompt document ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Decode a prompt document's `config` JSON blob back into an array.
	 *
	 * @param object|null $document Prompt document row.
	 * @return array
	 */
	public static function get_config( $document ) {
		if ( ! $document || empty( $document->config ) ) {
			return array();
		}

		$decoded = json_decode( $document->config, true );

		return is_array( $decoded ) ? $decoded : array();
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
		$data['favorite']   = 0;
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert prompt document: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not save the prompt. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'prompt_created',
			'prompt_document',
			$id,
			/* translators: %s: prompt title. */
			sprintf( __( 'Created prompt "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return $id;
	}

	/**
	 * @param int   $id  Prompt document ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Prompt not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update prompt document ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the prompt. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'prompt_updated',
			'prompt_document',
			$id,
			/* translators: %s: prompt title. */
			sprintf( __( 'Updated prompt "%s"', 'personal-project-tracker' ), $data['title'] )
		);

		return true;
	}

	/**
	 * Duplicate an existing prompt document (title suffixed, never favorited
	 * by default). The copy is an independent row — editing one never
	 * affects the other.
	 *
	 * @param int $id Prompt document ID to duplicate.
	 * @return int|WP_Error New prompt document ID.
	 */
	public static function duplicate( $id ) {
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Prompt not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$now  = ptp_now();
		$data = array(
			/* translators: %s: original prompt title. */
			'title'         => substr( sprintf( __( '%s (Copy)', 'personal-project-tracker' ), $existing->title ), 0, 255 ),
			'content'       => $existing->content,
			'context_type'  => $existing->context_type,
			'project_id'    => $existing->project_id ? (int) $existing->project_id : null,
			'favorite'      => 0,
			'role'          => $existing->role,
			'goal'          => $existing->goal,
			'output_format' => $existing->output_format,
			'config'        => $existing->config,
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to duplicate prompt document ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not duplicate the prompt. Please try again.', 'personal-project-tracker' ) );
		}

		$new_id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'prompt_duplicated',
			'prompt_document',
			$new_id,
			/* translators: %s: prompt title. */
			sprintf( __( 'Duplicated prompt "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return $new_id;
	}

	/**
	 * @param int $id Prompt document ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Prompt not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete prompt document ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the prompt. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'prompt_deleted',
			'prompt_document',
			$id,
			/* translators: %s: prompt title. */
			sprintf( __( 'Deleted prompt "%s"', 'personal-project-tracker' ), $existing->title )
		);

		return true;
	}

	/**
	 * @param int $id Prompt document ID.
	 * @return true|WP_Error
	 */
	public static function favorite( $id ) {
		return self::set_favorite( $id, 1, 'prompt_favorited', __( 'Favorited prompt "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int $id Prompt document ID.
	 * @return true|WP_Error
	 */
	public static function unfavorite( $id ) {
		return self::set_favorite( $id, 0, 'prompt_unfavorited', __( 'Unfavorited prompt "%s"', 'personal-project-tracker' ) );
	}

	/**
	 * @param int    $id           Prompt document ID.
	 * @param int    $value        0 or 1.
	 * @param string $log_action   Activity log action slug.
	 * @param string $log_template sprintf() template with one %s for the title.
	 * @return true|WP_Error
	 */
	private static function set_favorite( $id, $value, $log_action, $log_template ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Prompt not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$wpdb->update(
			self::get_table(),
			array(
				'favorite'   => $value,
				'updated_at' => ptp_now(),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		PTP_Activity_Log::log( $log_action, 'prompt_document', $id, sprintf( $log_template, $existing->title ) );

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of prompt documents.
	 *
	 * @param array $args {
	 *     @type string $search       Free-text search against title/goal.
	 *     @type int    $project_id   Project filter.
	 *     @type string $context_type Context type filter.
	 *     @type string $favorite     '', '1' (favorites only), or '0'.
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
				'context_type' => '',
				'favorite'     => '',
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
			$where[]  = '(title LIKE %s OR goal LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$project_id = (int) $args['project_id'];

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		if ( $args['context_type'] && self::is_valid_context_type( $args['context_type'] ) ) {
			$where[]  = 'context_type = %s';
			$params[] = $args['context_type'];
		}

		if ( '' !== $args['favorite'] ) {
			$where[]  = 'favorite = %d';
			$params[] = $args['favorite'] ? 1 : 0;
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
