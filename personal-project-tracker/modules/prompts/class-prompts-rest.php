<?php
/**
 * REST API endpoints for AI Prompt Studio.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Prompts_REST
 *
 * Registers ptp/v1/prompts. Every route requires ptp_manage_data, matching
 * the AI Prompt Studio admin menu item's capability. /prompts/generate is
 * stateless (never persists anything) so a preview can be regenerated
 * freely; saving a prompt document is a separate, explicit write.
 */
class PTP_Prompts_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'prompts';

	/**
	 * Capability required for every prompt endpoint.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Register all prompt routes. Hooked on 'ptp_register_rest_routes'.
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
			'/' . self::BASE . '/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'generate' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_templates' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_template' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_template' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_template' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_template' ),
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

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_item' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/favorite',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'favorite_item' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/(?P<id>\d+)/unfavorite',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'unfavorite_item' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ) {
		$result = PTP_Prompt_Documents_Repository::get_list(
			array(
				'search'       => $request->get_param( 'search' ),
				'project_id'   => $request->get_param( 'project_id' ),
				'context_type' => $request->get_param( 'context_type' ),
				'favorite'     => $request->get_param( 'favorite' ),
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
		$document = PTP_Prompt_Documents_Repository::get( (int) $request['id'] );

		if ( ! $document ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Prompt not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $document ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$result = PTP_Prompt_Documents_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Prompt_Documents_Repository::get( $result ) ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Prompt_Documents_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Prompt_Documents_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$result = PTP_Prompt_Documents_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_item( WP_REST_Request $request ) {
		$result = PTP_Prompt_Documents_Repository::duplicate( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Prompt_Documents_Repository::get( $result ) ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function favorite_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Prompt_Documents_Repository::favorite( $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Prompt_Documents_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unfavorite_item( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Prompt_Documents_Repository::unfavorite( $id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Prompt_Documents_Repository::get( $id ) ), 200 );
	}

	/**
	 * POST /prompts/generate — stateless prompt assembly, never persisted.
	 * Used by the "Generate" button (and every Quick Action) to produce
	 * preview text the user can then edit and, separately, Save.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function generate( WP_REST_Request $request ) {
		$params = $request->get_params();

		$config = PTP_Prompt_Generator::sanitize_config( is_array( $params['config'] ?? null ) ? $params['config'] : $params );

		if ( is_wp_error( $config ) ) {
			return self::to_rest_error( $config );
		}

		$goal = trim( (string) ( $params['goal'] ?? '' ) );

		if ( '' === $goal ) {
			return self::to_rest_error( new WP_Error( 'ptp_goal_required', __( 'A goal ("What should the AI help you with?") is required.', 'personal-project-tracker' ) ) );
		}

		$project_id = isset( $params['project_id'] ) ? absint( $params['project_id'] ) : 0;

		if ( $project_id && ! PTP_Projects_Repository::exists( $project_id ) ) {
			return self::to_rest_error( new WP_Error( 'ptp_project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) ) );
		}

		$role   = isset( $params['role'] ) ? sanitize_key( wp_unslash( (string) $params['role'] ) ) : 'custom';
		$format = isset( $params['output_format'] ) ? sanitize_key( wp_unslash( (string) $params['output_format'] ) ) : 'plain_text';

		$result = PTP_Prompt_Generator::generate(
			array_merge(
				$config,
				array(
					'role'            => array_key_exists( $role, PTP_Prompt_Generator::ROLES ) ? $role : 'custom',
					'goal'            => $goal,
					'output_format'   => array_key_exists( $format, PTP_Prompt_Generator::OUTPUT_FORMATS ) ? $format : 'plain_text',
					'project_id'      => $project_id,
					'finance_allowed' => current_user_can( 'ptp_manage_finance' ),
				)
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_templates( WP_REST_Request $request ) {
		$result = PTP_Prompt_Templates_Repository::get_list(
			array(
				'search'   => $request->get_param( 'search' ),
				'category' => $request->get_param( 'category' ),
				'orderby'  => $request->get_param( 'orderby' ),
				'order'    => $request->get_param( 'order' ),
				'paged'    => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items'       => array_map( array( __CLASS__, 'prepare_template' ), $result['items'] ),
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
	public static function get_template( WP_REST_Request $request ) {
		$template = PTP_Prompt_Templates_Repository::get( (int) $request['id'] );

		if ( ! $template ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Template not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_template( $template ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_template( WP_REST_Request $request ) {
		$result = PTP_Prompt_Templates_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_template( PTP_Prompt_Templates_Repository::get( $result ) ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_template( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Prompt_Templates_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_template( PTP_Prompt_Templates_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_template( WP_REST_Request $request ) {
		$result = PTP_Prompt_Templates_Repository::delete( (int) $request['id'] );

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
			'ptp_not_found'      => 404,
			'ptp_goal_required'  => 400,
			'ptp_project_invalid' => 400,
		);
		$status     = $status_map[ $error->get_error_code() ] ?? 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * @param object|null $document Prompt document row.
	 * @return array|null
	 */
	private static function prepare_item( $document ) {
		if ( ! $document ) {
			return null;
		}

		return array(
			'id'            => (int) $document->id,
			'title'         => $document->title,
			'content'       => $document->content,
			'context_type'  => $document->context_type,
			'project_id'    => $document->project_id ? (int) $document->project_id : null,
			'favorite'      => (bool) $document->favorite,
			'role'          => $document->role,
			'goal'          => $document->goal,
			'output_format' => $document->output_format,
			'config'        => PTP_Prompt_Documents_Repository::get_config( $document ),
			'created_at'    => $document->created_at,
			'updated_at'    => $document->updated_at,
		);
	}

	/**
	 * @param object|null $row Template row.
	 * @return array|null
	 */
	private static function prepare_template( $row ) {
		if ( ! $row ) {
			return null;
		}

		return array_merge(
			array(
				'id'         => (int) $row->id,
				'name'       => $row->name,
				'category'   => $row->category,
				'created_at' => $row->created_at,
				'updated_at' => $row->updated_at,
			),
			PTP_Prompt_Templates_Repository::get_template_data( $row )
		);
	}
}
