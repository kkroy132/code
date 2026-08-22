<?php
/**
 * REST API endpoints for Tasks.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Tasks_REST
 *
 * Registers ptp/v1/tasks. All business validation lives in
 * PTP_Tasks_Repository::prepare_fields() — this class only handles HTTP
 * shape (routing, arg schemas, response codes) and permission checks.
 */
class PTP_Tasks_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'tasks';

	/**
	 * Register all task routes. Hooked on 'ptp_register_rest_routes'.
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
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
					'args'                => self::collection_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
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
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
					'args'                => self::item_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
				),
			)
		);

		foreach ( array( 'complete', 'reopen', 'archive', 'restore' ) as $action ) {
			register_rest_route(
				$ns,
				'/' . self::BASE . '/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $action . '_item' ),
					'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
				)
			);
		}
	}

	/**
	 * Arg schema for GET /tasks (list/search/filter/sort/paginate).
	 *
	 * @return array
	 */
	private static function collection_args() {
		return array(
			'search'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'status'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'priority'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'project_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			),
			'due_filter' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'view'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'active',
			),
			'orderby'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'due_date',
			),
			'order'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'asc',
			),
			'page'       => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			),
			'per_page'   => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 20,
			),
		);
	}

	/**
	 * Arg schema for creating/updating a task. Types here are shape
	 * validation only; PTP_Tasks_Repository::prepare_fields() owns the
	 * actual business rules (enum membership, project existence, etc.).
	 *
	 * @return array
	 */
	private static function item_args() {
		return array(
			'title'          => array(
				'type'     => 'string',
				'required' => true,
			),
			'description'    => array( 'type' => 'string' ),
			'project_id'     => array(
				'type'     => 'integer',
				'required' => true,
			),
			'milestone_id'   => array( 'type' => array( 'integer', 'null' ) ),
			'status'         => array( 'type' => 'string' ),
			'priority'       => array( 'type' => 'string' ),
			'start_date'     => array( 'type' => 'string' ),
			'due_date'       => array( 'type' => 'string' ),
			'estimated_time' => array( 'type' => array( 'number', 'string', 'null' ) ),
			'tags'           => array( 'type' => 'string' ),
		);
	}

	/**
	 * GET /tasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Tasks_Repository::get_list(
			array(
				'search'     => $request->get_param( 'search' ),
				'status'     => $request->get_param( 'status' ),
				'priority'   => $request->get_param( 'priority' ),
				'project_id' => $request->get_param( 'project_id' ),
				'due_filter' => $request->get_param( 'due_filter' ),
				'view'       => $request->get_param( 'view' ),
				'orderby'    => $request->get_param( 'orderby' ),
				'order'      => $request->get_param( 'order' ),
				'paged'      => $request->get_param( 'page' ),
				'per_page'   => $request->get_param( 'per_page' ),
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
	 * GET /tasks/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ) {
		$task = PTP_Tasks_Repository::get( (int) $request['id'] );

		if ( ! $task ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $task ), 200 );
	}

	/**
	 * GET /tasks/stats
	 *
	 * @return WP_REST_Response
	 */
	public static function get_stats() {
		return new WP_REST_Response( PTP_Tasks_Repository::get_stats(), 200 );
	}

	/**
	 * POST /tasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Tasks_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Tasks_Repository::get( $result ) ), 201 );
	}

	/**
	 * PUT/PATCH/POST /tasks/{id}
	 *
	 * Expects a full representation of the task, not a partial patch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Tasks_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Tasks_Repository::get( $id ) ), 200 );
	}

	/**
	 * DELETE /tasks/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Tasks_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /tasks/{id}/complete
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function complete_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'complete' );
	}

	/**
	 * POST /tasks/{id}/reopen
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reopen_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'reopen' );
	}

	/**
	 * POST /tasks/{id}/archive
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function archive_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'archive' );
	}

	/**
	 * POST /tasks/{id}/restore
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'restore' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param string           $method  PTP_Tasks_Repository method name to call with the task ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_action( WP_REST_Request $request, $method ) {
		$id     = (int) $request['id'];
		$result = call_user_func( array( 'PTP_Tasks_Repository', $method ), $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Tasks_Repository::get( $id ) ), 200 );
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
	 * @param object|null $task Task row.
	 * @return array|null
	 */
	private static function prepare_item( $task ) {
		if ( ! $task ) {
			return null;
		}

		return array(
			'id'             => (int) $task->id,
			'project_id'     => (int) $task->project_id,
			'milestone_id'   => null !== $task->milestone_id ? (int) $task->milestone_id : null,
			'title'          => $task->title,
			'description'    => $task->description,
			'status'         => $task->status,
			'priority'       => $task->priority,
			'due_date'       => $task->due_date,
			'start_date'     => $task->start_date,
			'estimated_time' => null !== $task->estimated_time ? (float) $task->estimated_time : null,
			'actual_time'    => null !== $task->actual_time ? (float) $task->actual_time : null,
			'assigned_user'  => null !== $task->assigned_user ? (int) $task->assigned_user : null,
			'tags'           => $task->tags,
			'created_at'     => $task->created_at,
			'updated_at'     => $task->updated_at,
			'archived_at'    => $task->archived_at,
			'completion'     => PTP_Subtasks_Repository::get_completion( $task->id ),
		);
	}
}
