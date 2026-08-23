<?php
/**
 * Prompt templates data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Prompt_Templates_Repository
 *
 * Owns all reads/writes to the prompt_templates table. A template is a
 * reusable starting point for the prompt form (role, output format,
 * requirements, constraints, goal placeholder) — never a saved, generated
 * prompt itself; that's what prompt_documents is for. `template` stores one
 * JSON blob of those fields, matching prompt_documents' own config-as-JSON
 * shape rather than a column per option.
 */
class PTP_Prompt_Templates_Repository {

	/**
	 * Columns that may be used to sort the template list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'name',
		'category',
		'created_at',
		'updated_at',
	);

	/**
	 * Get the fully-prefixed prompt_templates table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'prompt_templates' );
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place template input is validated — both the admin form
	 * controller and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$name = isset( $raw['name'] ) ? PTP_Security::sanitize_text( $raw['name'] ) : '';

		if ( '' === $name ) {
			$errors->add( 'name_required', __( 'Template name is required.', 'personal-project-tracker' ) );
		}

		$data['name'] = substr( $name, 0, 255 );

		$category         = isset( $raw['category'] ) ? PTP_Security::sanitize_text( $raw['category'] ) : '';
		$data['category'] = substr( $category, 0, 100 );

		$role   = isset( $raw['role'] ) ? sanitize_key( wp_unslash( (string) $raw['role'] ) ) : 'custom';
		$role   = array_key_exists( $role, PTP_Prompt_Generator::ROLES ) ? $role : 'custom';
		$format = isset( $raw['output_format'] ) ? sanitize_key( wp_unslash( (string) $raw['output_format'] ) ) : 'plain_text';
		$format = array_key_exists( $format, PTP_Prompt_Generator::OUTPUT_FORMATS ) ? $format : 'plain_text';

		$template = array(
			'role'                => $role,
			'custom_role_label'   => isset( $raw['custom_role_label'] ) ? substr( PTP_Security::sanitize_text( $raw['custom_role_label'] ), 0, 255 ) : '',
			'output_format'       => $format,
			'custom_output_label' => isset( $raw['custom_output_label'] ) ? substr( PTP_Security::sanitize_text( $raw['custom_output_label'] ), 0, 255 ) : '',
			'goal_placeholder'    => isset( $raw['goal_placeholder'] ) ? PTP_Security::sanitize_textarea( $raw['goal_placeholder'] ) : '',
			'requirements'        => isset( $raw['requirements'] ) ? PTP_Security::sanitize_textarea( $raw['requirements'] ) : '',
			'constraints'         => isset( $raw['constraints'] ) ? PTP_Security::sanitize_textarea( $raw['constraints'] ) : '',
		);

		$data['template'] = wp_json_encode( $template );

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * Decode a template row's `template` JSON blob back into an array.
	 *
	 * @param object|null $row Template row.
	 * @return array
	 */
	public static function get_template_data( $row ) {
		if ( ! $row || empty( $row->template ) ) {
			return array();
		}

		$decoded = json_decode( $row->template, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'name'       => '%s',
			'template'   => '%s',
			'category'   => '%s',
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
	 * @param int $id Template ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Template ID.
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
			ptp_log_error( 'Failed to insert prompt template: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not save the template. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'prompt_template_created',
			'prompt_template',
			$id,
			/* translators: %s: template name. */
			sprintf( __( 'Created prompt template "%s"', 'personal-project-tracker' ), $data['name'] )
		);

		return $id;
	}

	/**
	 * @param int   $id  Template ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Template not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update prompt template ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the template. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'prompt_template_updated',
			'prompt_template',
			$id,
			/* translators: %s: template name. */
			sprintf( __( 'Updated prompt template "%s"', 'personal-project-tracker' ), $data['name'] )
		);

		return true;
	}

	/**
	 * @param int $id Template ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Template not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete prompt template ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the template. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'prompt_template_deleted',
			'prompt_template',
			$id,
			/* translators: %s: template name. */
			sprintf( __( 'Deleted prompt template "%s"', 'personal-project-tracker' ), $existing->name )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of templates.
	 *
	 * @param array $args {
	 *     @type string $search   Free-text search against name/category.
	 *     @type string $category Category filter.
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
				'category' => '',
				'orderby'  => 'name',
				'order'    => 'ASC',
				'paged'    => 1,
				'per_page' => 20,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(name LIKE %s OR category LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$category = trim( (string) $args['category'] );

		if ( '' !== $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		$orderby = in_array( $args['orderby'], self::$sortable_columns, true ) ? $args['orderby'] : 'name';
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
}
