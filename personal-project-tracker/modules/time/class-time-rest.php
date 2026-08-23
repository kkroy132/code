<?php
/**
 * REST API endpoints for Time Tracking.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Time_REST
 *
 * Registers ptp/v1/time. All business validation lives in
 * PTP_Time_Repository — this class only handles HTTP shape (routing, arg
 * schemas, response codes) and permission checks. The Active Timer widget
 * (start/pause/resume/stop, live tick) is driven entirely through this API.
 */
class PTP_Time_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'time';

	/**
	 * Capability required for every time tracking endpoint, matching the
	 * capability the Time Tracking admin menu item already uses.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_tasks';

	/**
	 * Register all time tracking routes. Hooked on 'ptp_register_rest_routes'.
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
			'/' . self::BASE . '/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_summary' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/active',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_active' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start_timer' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				'args'                => array(
					'project_id'  => array( 'type' => 'integer' ),
					'task_id'     => array( 'type' => 'integer' ),
					'description' => array( 'type' => 'string' ),
				),
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

		foreach ( array( 'pause', 'resume', 'stop' ) as $action ) {
			register_rest_route(
				$ns,
				'/' . self::BASE . '/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $action . '_timer' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				)
			);
		}
	}

	/**
	 * Arg schema for GET /time (list/search/filter/sort/paginate).
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
			'project_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			),
			'task_id'    => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			),
			'user_id'    => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			),
			'status'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'date_from'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'date_to'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'orderby'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'entry_date',
			),
			'order'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'desc',
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
	 * Arg schema for creating/updating a manual time entry. Types here are
	 * shape validation only; PTP_Time_Repository::prepare_manual_fields()
	 * owns the actual business rules.
	 *
	 * @return array
	 */
	private static function item_args() {
		return array(
			'entry_date'  => array(
				'type'     => 'string',
				'required' => true,
			),
			'start_time'  => array( 'type' => 'string' ),
			'end_time'    => array( 'type' => 'string' ),
			'duration'    => array( 'type' => 'integer' ),
			'project_id'  => array( 'type' => 'integer' ),
			'task_id'     => array( 'type' => 'integer' ),
			'description' => array( 'type' => 'string' ),
		);
	}

	/**
	 * GET /time
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Time_Repository::get_list(
			array(
				'search'     => $request->get_param( 'search' ),
				'project_id' => $request->get_param( 'project_id' ),
				'task_id'    => $request->get_param( 'task_id' ),
				'user_id'    => $request->get_param( 'user_id' ),
				'status'     => $request->get_param( 'status' ),
				'date_from'  => $request->get_param( 'date_from' ),
				'date_to'    => $request->get_param( 'date_to' ),
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
	 * GET /time/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ) {
		$entry = PTP_Time_Repository::get( (int) $request['id'] );

		if ( ! $entry ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Time entry not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $entry ), 200 );
	}

	/**
	 * GET /time/summary
	 *
	 * @return WP_REST_Response
	 */
	public static function get_summary() {
		return new WP_REST_Response( PTP_Time_Repository::get_summary_for_user(), 200 );
	}

	/**
	 * GET /time/active
	 *
	 * @return WP_REST_Response
	 */
	public static function get_active() {
		$entry = PTP_Time_Repository::get_active_for_user();

		return new WP_REST_Response( self::prepare_item( $entry ), 200 );
	}

	/**
	 * POST /time/start
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start_timer( WP_REST_Request $request ) {
		$result = PTP_Time_Repository::start( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Time_Repository::get( $result ) ), 201 );
	}

	/**
	 * POST /time/{id}/pause
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pause_timer( WP_REST_Request $request ) {
		return self::run_action( $request, 'pause' );
	}

	/**
	 * POST /time/{id}/resume
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resume_timer( WP_REST_Request $request ) {
		return self::run_action( $request, 'resume' );
	}

	/**
	 * POST /time/{id}/stop
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stop_timer( WP_REST_Request $request ) {
		return self::run_action( $request, 'stop' );
	}

	/**
	 * POST /time
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Time_Repository::create_manual( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Time_Repository::get( $result ) ), 201 );
	}

	/**
	 * PUT/PATCH/POST /time/{id}
	 *
	 * Expects a full representation of the manual entry, not a partial patch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Time_Repository::update_manual( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Time_Repository::get( $id ) ), 200 );
	}

	/**
	 * DELETE /time/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Time_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param string           $method  PTP_Time_Repository method name to call with the entry ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_action( WP_REST_Request $request, $method ) {
		$id     = (int) $request['id'];
		$result = call_user_func( array( 'PTP_Time_Repository', $method ), $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Time_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status_map = array(
			'ptp_not_found' => 404,
			'ptp_forbidden' => 403,
		);
		$status     = $status_map[ $error->get_error_code() ] ?? 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * @param object|null $entry Time entry row.
	 * @return array|null
	 */
	private static function prepare_item( $entry ) {
		if ( ! $entry ) {
			return null;
		}

		$duration = PTP_Time_Repository::get_live_duration( $entry );

		return array(
			'id'             => (int) $entry->id,
			'project_id'     => $entry->project_id ? (int) $entry->project_id : null,
			'task_id'        => $entry->task_id ? (int) $entry->task_id : null,
			'user_id'        => (int) $entry->user_id,
			'description'    => $entry->description,
			'start_time'     => $entry->start_time,
			'end_time'       => $entry->end_time,
			'resumed_at'     => $entry->resumed_at,
			'entry_date'     => $entry->entry_date,
			'status'         => $entry->status,
			'duration'       => (int) $entry->duration,
			'live_duration'  => $duration,
			'duration_label' => ptp_format_duration( $duration ),
			'created_at'     => $entry->created_at,
			'updated_at'     => $entry->updated_at,
		);
	}
}
