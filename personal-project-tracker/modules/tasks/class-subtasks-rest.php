<?php
/**
 * REST API endpoints for Subtasks.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Subtasks_REST
 *
 * Registers ptp/v1/subtasks. Subtasks are managed inline on the Task
 * detail page, so every operation here is REST/JS-driven — there is no
 * classic admin-post form for them.
 */
class PTP_Subtasks_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'subtasks';

	/**
	 * Register all subtask routes. Hooked on 'ptp_register_rest_routes'.
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
					'args'                => array(
						'task_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
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
			'/' . self::BASE . '/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reorder_items' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_tasks' ),
				'args'                => array(
					'task_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'order'   => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)',
			array(
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

		foreach ( array( 'complete', 'reopen' ) as $action ) {
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
	 * Arg schema for creating/updating a subtask.
	 *
	 * @return array
	 */
	private static function item_args() {
		return array(
			'task_id'  => array( 'type' => 'integer' ),
			'title'    => array(
				'type'     => 'string',
				'required' => true,
			),
			'status'   => array( 'type' => 'string' ),
			'priority' => array( 'type' => 'string' ),
			'due_date' => array( 'type' => 'string' ),
		);
	}

	/**
	 * GET /subtasks?task_id=X
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$task_id = (int) $request->get_param( 'task_id' );
		$items   = PTP_Subtasks_Repository::get_for_task( $task_id );

		return new WP_REST_Response(
			array(
				'items'      => array_map( array( __CLASS__, 'prepare_item' ), $items ),
				'completion' => PTP_Subtasks_Repository::get_completion( $task_id ),
			),
			200
		);
	}

	/**
	 * POST /subtasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Subtasks_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Subtasks_Repository::get( $result ) ), 201 );
	}

	/**
	 * PUT/PATCH/POST /subtasks/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Subtasks_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Subtasks_Repository::get( $id ) ), 200 );
	}

	/**
	 * DELETE /subtasks/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Subtasks_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /subtasks/{id}/complete
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function complete_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'complete' );
	}

	/**
	 * POST /subtasks/{id}/reopen
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reopen_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'reopen' );
	}

	/**
	 * POST /subtasks/reorder
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reorder_items( WP_REST_Request $request ) {
		$task_id = (int) $request->get_param( 'task_id' );

		if ( ! PTP_Tasks_Repository::exists( $task_id ) ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Task not found.', 'personal-project-tracker' ) ) );
		}

		$order = array_map( 'absint', (array) $request->get_param( 'order' ) );

		PTP_Subtasks_Repository::reorder( $task_id, $order );

		return new WP_REST_Response(
			array(
				'items' => array_map( array( __CLASS__, 'prepare_item' ), PTP_Subtasks_Repository::get_for_task( $task_id ) ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param string           $method  PTP_Subtasks_Repository method name to call with the subtask ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_action( WP_REST_Request $request, $method ) {
		$id     = (int) $request['id'];
		$result = call_user_func( array( 'PTP_Subtasks_Repository', $method ), $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Subtasks_Repository::get( $id ) ), 200 );
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
	 * @param object|null $subtask Subtask row.
	 * @return array|null
	 */
	private static function prepare_item( $subtask ) {
		if ( ! $subtask ) {
			return null;
		}

		return array(
			'id'         => (int) $subtask->id,
			'task_id'    => (int) $subtask->task_id,
			'title'      => $subtask->title,
			'status'     => $subtask->status,
			'priority'   => $subtask->priority,
			'due_date'   => $subtask->due_date,
			'completed'  => (bool) $subtask->completed,
			'sort_order' => (int) $subtask->sort_order,
			'created_at' => $subtask->created_at,
			'updated_at' => $subtask->updated_at,
		);
	}
}
