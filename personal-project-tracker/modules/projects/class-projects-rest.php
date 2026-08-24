<?php
/**
 * REST API endpoints for Projects.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Projects_REST
 *
 * Registers ptp/v1/projects. All business validation lives in
 * PTP_Projects_Repository::prepare_fields() — this class only handles
 * HTTP shape (routing, arg schemas, response codes) and permission checks.
 */
class PTP_Projects_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'projects';

	/**
	 * Register all project routes. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		$ns = PTP_REST_API::NAMESPACE_NAME;

		register_rest_route(
			$ns,
			'/' . self::BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_items' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
					'args'                => self::collection_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
					'args'                => self::item_args(),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_stats' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
					'args'                => self::item_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/archive',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'archive_item' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_item' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reorder_items' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' ),
				'args'                => array(
					'order' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Arg schema for GET /projects (list/search/filter/sort/paginate).
	 *
	 * @return array
	 */
	private static function collection_args() {
		return array(
			'search'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'status'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'priority' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'orderby'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'sort_order',
			),
			'order'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'asc',
			),
			'page'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			),
			'per_page' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 20,
			),
		);
	}

	/**
	 * Arg schema for creating/updating a project. Types here are shape
	 * validation only; PTP_Projects_Repository::prepare_fields() owns the
	 * actual business rules (enum membership, date ordering, etc.).
	 *
	 * @return array
	 */
	private static function item_args() {
		return array(
			'title'             => array(
				'type'     => 'string',
				'required' => true,
			),
			'description'       => array( 'type' => 'string' ),
			'status'            => array( 'type' => 'string' ),
			'priority'          => array( 'type' => 'string' ),
			'start_date'        => array( 'type' => 'string' ),
			'deadline'          => array( 'type' => 'string' ),
			'budget'            => array( 'type' => array( 'number', 'string', 'null' ) ),
			'currency'          => array( 'type' => 'string' ),
			'estimated_revenue' => array( 'type' => array( 'number', 'string', 'null' ) ),
			'progress'          => array( 'type' => 'integer' ),
			'color'             => array( 'type' => 'string' ),
		);
	}

	/**
	 * GET /projects
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Projects_Repository::get_list(
			array(
				'search'   => $request->get_param( 'search' ),
				'status'   => $request->get_param( 'status' ),
				'priority' => $request->get_param( 'priority' ),
				'orderby'  => $request->get_param( 'orderby' ),
				'order'    => $request->get_param( 'order' ),
				'paged'    => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items'       => array_map( array( __CLASS__, 'prepare_item' ), $result['items'] ),
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
			),
			200
		);
	}

	/**
	 * GET /projects/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ) {
		$project = PTP_Projects_Repository::get( (int) $request['id'] );

		if ( ! $project ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Project not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $project ), 200 );
	}

	/**
	 * GET /projects/stats
	 *
	 * @return WP_REST_Response
	 */
	public static function get_stats() {
		return new WP_REST_Response( PTP_Projects_Repository::get_stats(), 200 );
	}

	/**
	 * POST /projects
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Projects_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Projects_Repository::get( $result ) ), 201 );
	}

	/**
	 * PUT/PATCH/POST /projects/{id}
	 *
	 * Expects a full representation of the project, not a partial patch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Projects_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Projects_Repository::get( $id ) ), 200 );
	}

	/**
	 * DELETE /projects/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Projects_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /projects/{id}/archive
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function archive_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Projects_Repository::archive( $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Projects_Repository::get( $id ) ), 200 );
	}

	/**
	 * POST /projects/{id}/restore
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Projects_Repository::restore( $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Projects_Repository::get( $id ) ), 200 );
	}

	/**
	 * POST /projects/reorder
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function reorder_items( WP_REST_Request $request ) {
		$order = array_map( 'absint', (array) $request->get_param( 'order' ) );

		PTP_Projects_Repository::reorder( $order );

		return new WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Attach an HTTP status to a WP_Error so WP_REST_Server reports it correctly.
	 *
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status = 'ptp_not_found' === $error->get_error_code() ? 404 : 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * Shape a DB row into a REST-safe response array.
	 *
	 * @param object|null $project Project row.
	 * @return array|null
	 */
	private static function prepare_item( $project ) {
		if ( ! $project ) {
			return null;
		}

		return array(
			'id'                => (int) $project->id,
			'title'             => $project->title,
			'description'       => $project->description,
			'status'            => $project->status,
			'priority'          => $project->priority,
			'start_date'        => $project->start_date,
			'deadline'          => $project->deadline,
			'budget'            => null !== $project->budget ? (float) $project->budget : null,
			'currency'          => $project->currency,
			'estimated_revenue' => null !== $project->estimated_revenue ? (float) $project->estimated_revenue : null,
			'actual_revenue'    => (float) $project->actual_revenue,
			'actual_expenses'   => (float) $project->actual_expenses,
			'progress'          => (int) $project->progress,
			'color'             => $project->color,
			'sort_order'        => (int) $project->sort_order,
			'created_at'        => $project->created_at,
			'updated_at'        => $project->updated_at,
			'archived_at'       => $project->archived_at,
		);
	}
}
