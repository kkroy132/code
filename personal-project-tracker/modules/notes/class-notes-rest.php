<?php
/**
 * REST API endpoints for Notes.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notes_REST
 *
 * Registers ptp/v1/notes.
 */
class PTP_Notes_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'notes';

	/**
	 * Capability required for every notes endpoint, matching the Notes
	 * admin menu item.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Register all notes routes. Hooked on 'ptp_register_rest_routes'.
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
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
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
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);

		foreach ( array( 'pin', 'unpin', 'archive', 'restore' ) as $action ) {
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
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Notes_Repository::get_list(
			array(
				'search'       => $request->get_param( 'search' ),
				'project_id'   => $request->get_param( 'project_id' ),
				'task_id'      => $request->get_param( 'task_id' ),
				'milestone_id' => $request->get_param( 'milestone_id' ),
				'pinned'       => $request->get_param( 'pinned' ),
				'view'         => $request->get_param( 'view' ),
				'orderby'      => $request->get_param( 'orderby' ),
				'order'        => $request->get_param( 'order' ),
				'paged'        => $request->get_param( 'page' ),
				'per_page'     => $request->get_param( 'per_page' ),
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
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ) {
		$note = PTP_Notes_Repository::get( (int) $request['id'] );

		if ( ! $note ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Note not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $note ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Notes_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Notes_Repository::get( $result ) ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Notes_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Notes_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Notes_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pin_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'pin' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unpin_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'unpin' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function archive_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'archive' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_item( WP_REST_Request $request ) {
		return self::run_action( $request, 'restore' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param string           $method  PTP_Notes_Repository method name.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_action( WP_REST_Request $request, $method ) {
		$id     = (int) $request['id'];
		$result = call_user_func( array( 'PTP_Notes_Repository', $method ), $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Notes_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status_map = array( 'ptp_not_found' => 404 );
		$status     = $status_map[ $error->get_error_code() ] ?? 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * @param object|null $note Note row.
	 * @return array|null
	 */
	private static function prepare_item( $note ) {
		if ( ! $note ) {
			return null;
		}

		return array(
			'id'           => (int) $note->id,
			'project_id'   => $note->project_id ? (int) $note->project_id : null,
			'task_id'      => $note->task_id ? (int) $note->task_id : null,
			'milestone_id' => $note->milestone_id ? (int) $note->milestone_id : null,
			'title'        => $note->title,
			'content'      => $note->content,
			'pinned'       => (bool) $note->pinned,
			'archived'     => (bool) $note->archived,
			'tags'         => $note->tags,
			'created_at'   => $note->created_at,
			'updated_at'   => $note->updated_at,
		);
	}
}
