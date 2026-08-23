<?php
/**
 * REST API endpoints for Files.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Files_REST
 *
 * Registers ptp/v1/files. create_item() expects an attachment_id that was
 * already uploaded/selected via the wp.media() picker in the browser — this
 * endpoint never accepts raw file bytes; that path is handled by
 * media_handle_upload() in PTP_Files_Controller for the no-JS fallback.
 */
class PTP_Files_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'files';

	/**
	 * Capability required for every files endpoint, matching the Files
	 * admin menu item.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Register all files routes. Hooked on 'ptp_register_rest_routes'.
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
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Files_Repository::get_list(
			array(
				'search'       => $request->get_param( 'search' ),
				'project_id'   => $request->get_param( 'project_id' ),
				'task_id'      => $request->get_param( 'task_id' ),
				'milestone_id' => $request->get_param( 'milestone_id' ),
				'note_id'      => $request->get_param( 'note_id' ),
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
		$file = PTP_Files_Repository::get( (int) $request['id'] );

		if ( ! $file ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'File attachment not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $file ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return self::to_rest_error( new WP_Error( 'ptp_forbidden', __( 'You do not have permission to attach files.', 'personal-project-tracker' ) ) );
		}

		$result = PTP_Files_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Files_Repository::get( $result ) ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Files_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
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
	 * @param object|null $file File attachment-association row.
	 * @return array|null
	 */
	private static function prepare_item( $file ) {
		if ( ! $file ) {
			return null;
		}

		return array(
			'id'            => (int) $file->id,
			'project_id'    => $file->project_id ? (int) $file->project_id : null,
			'task_id'       => $file->task_id ? (int) $file->task_id : null,
			'milestone_id'  => $file->milestone_id ? (int) $file->milestone_id : null,
			'note_id'       => $file->note_id ? (int) $file->note_id : null,
			'attachment_id' => (int) $file->attachment_id,
			'file_name'     => $file->file_name,
			'file_type'     => $file->file_type,
			'file_size'     => null !== $file->file_size ? (int) $file->file_size : null,
			'url'           => wp_get_attachment_url( $file->attachment_id ),
			'created_at'    => $file->created_at,
		);
	}
}
