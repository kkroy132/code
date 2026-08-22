<?php
/**
 * REST API endpoints for Milestones.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Milestones_REST
 *
 * Registers ptp/v1/milestones. All business validation lives in
 * PTP_Milestones_Repository — this class only handles HTTP shape
 * (routing, arg schemas, response codes) and permission checks.
 */
class PTP_Milestones_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'milestones';

	/**
	 * Capability required for every milestone endpoint, matching the
	 * capability the Milestones admin menu item already uses.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_projects';

	/**
	 * Register all milestone routes. Hooked on 'ptp_register_rest_routes'.
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
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
					'args'                => self::collection_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
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
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
					'args'                => self::item_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
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
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				)
			);
		}

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/tasks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_tasks' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'attach_task' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
					'args'                => array(
						'task_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/tasks/(?P<task_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'detach_task' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);
	}

	/**
	 * Arg schema for GET /milestones (list/search/filter/sort/paginate).
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
	 * Arg schema for creating/updating a milestone. Types here are shape
	 * validation only; PTP_Milestones_Repository::prepare_fields() owns
	 * the actual business rules.
	 *
	 * @return array
	 */
	private static function item_args() {
		return array(
			'title'       => array(
				'type'     => 'string',
				'required' => true,
			),
			'description' => array( 'type' => 'string' ),
			'project_id'  => array(
				'type'     => 'integer',
				'required' => true,
			),
			'status'      => array( 'type' => 'string' ),
			'priority'    => array( 'type' => 'string' ),
			'start_date'  => array( 'type' => 'string' ),
			'due_date'    => array( 'type' => 'string' ),
			'progress'    => array( 'type' => 'integer' ),
		);
	}

	/**
	 * GET /milestones
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Milestones_Repository::get_list(
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
	 * GET /milestones/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ) {
		$milestone = PTP_Milestones_Repository::get( (int) $request['id'] );

		if ( ! $milestone ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $milestone ), 200 );
	}

	/**
	 * GET /milestones/stats
	 *
	 * @return WP_REST_Response
	 */
	public static function get_stats() {
		return new WP_REST_Response( PTP_Milestones_Repository::get_stats(), 200 );
	}

	/**
	 * POST /milestones
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Milestones_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Milestones_Repository::get( $result ) ), 201 );
	}

	/**
	 * PUT/PATCH/POST /milestones/{id}
	 *
	 * Expects a full representation of the milestone, not a partial patch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Milestones_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Milestones_Repository::get( $id ) ), 200 );
	}

	/**
	 * DELETE /milestones/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Milestones_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /milestones/{id}/complete
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function complete_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'complete' );
	}

	/**
	 * POST /milestones/{id}/reopen
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reopen_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'reopen' );
	}

	/**
	 * POST /milestones/{id}/archive
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function archive_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'archive' );
	}

	/**
	 * POST /milestones/{id}/restore
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'restore' );
	}

	/**
	 * GET /milestones/{id}/tasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_tasks( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! PTP_Milestones_Repository::exists( $id ) ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Milestone not found.', 'personal-project-tracker' ) ) );
		}

		$tasks = PTP_Tasks_Repository::get_by_milestone( $id );

		return new WP_REST_Response(
			array(
				'items' => array_map(
					function ( $task ) {
						return array(
							'id'     => (int) $task->id,
							'title'  => $task->title,
							'status' => $task->status,
						);
					},
					$tasks
				),
			),
			200
		);
	}

	/**
	 * POST /milestones/{id}/tasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function attach_task( WP_REST_Request $request ) {
		$result = PTP_Milestones_Repository::attach_task( (int) $request['id'], (int) $request->get_param( 'task_id' ) );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return self::get_tasks( $request );
	}

	/**
	 * DELETE /milestones/{id}/tasks/{task_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function detach_task( WP_REST_Request $request ) {
		$result = PTP_Milestones_Repository::detach_task( (int) $request['task_id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return self::get_tasks( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param string           $method  PTP_Milestones_Repository method name to call with the milestone ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_action( WP_REST_Request $request, $method ) {
		$id     = (int) $request['id'];
		$result = call_user_func( array( 'PTP_Milestones_Repository', $method ), $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Milestones_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status = 'ptp_not_found' === $error->get_error_code() ? 404 : 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * @param object|null $milestone Milestone row.
	 * @return array|null
	 */
	private static function prepare_item( $milestone ) {
		if ( ! $milestone ) {
			return null;
		}

		return array(
			'id'          => (int) $milestone->id,
			'project_id'  => (int) $milestone->project_id,
			'title'       => $milestone->title,
			'description' => $milestone->description,
			'status'      => $milestone->status,
			'priority'    => $milestone->priority,
			'start_date'  => $milestone->start_date,
			'due_date'    => $milestone->due_date,
			'progress'    => (int) $milestone->progress,
			'created_at'  => $milestone->created_at,
			'updated_at'  => $milestone->updated_at,
			'archived_at' => $milestone->archived_at,
		);
	}
}
