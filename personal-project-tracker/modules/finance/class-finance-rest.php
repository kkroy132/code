<?php
/**
 * REST API endpoints for Finance.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Finance_REST
 *
 * Registers ptp/v1/finance. Every route requires ptp_manage_finance —
 * financial data must remain private, per the module's requirements, so
 * this uses a dedicated, more restrictive capability than most other
 * modules (which use ptp_manage_data/ptp_manage_tasks/ptp_manage_projects).
 * All calculated figures (summary, report) come from PTP_Finance_Service;
 * this class only handles HTTP shape and permission checks.
 */
class PTP_Finance_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'finance';

	/**
	 * Capability required for every finance endpoint.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_finance';

	/**
	 * Register all finance routes. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		$ns = PTP_REST_API::NAMESPACE_NAME;

		self::register_entity_routes( $ns, 'expenses', 'PTP_Expenses_Repository' );
		self::register_entity_routes( $ns, 'revenue', 'PTP_Revenue_Repository' );

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
			'/' . self::BASE . '/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_report' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);
	}

	/**
	 * Register the CRUD routes shared by /finance/expenses and /finance/revenue.
	 *
	 * @param string $ns         REST namespace.
	 * @param string $slug       'expenses' or 'revenue'.
	 * @param string $repository Repository class name.
	 */
	private static function register_entity_routes( $ns, $slug, $repository ) {
		register_rest_route(
			$ns,
			'/' . self::BASE . '/' . $slug,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $repository ) {
						return self::get_items( $request, $repository );
					},
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $repository ) {
						return self::create_item( $request, $repository );
					},
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/' . $slug . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $repository ) {
						return self::get_item( $request, $repository );
					},
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $repository ) {
						return self::update_item( $request, $repository );
					},
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $repository ) {
						return self::delete_item( $request, $repository );
					},
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request    Request.
	 * @param string           $repository Repository class name.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request, $repository ) {
		$result = $repository::get_list(
			array(
				'search'     => $request->get_param( 'search' ),
				'project_id' => $request->get_param( 'project_id' ),
				'category'   => $request->get_param( 'category' ),
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
				'items'       => array_map( array( __CLASS__, 'prepare_item' ), $result['items'], array_fill( 0, count( $result['items'] ), $repository ) ),
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request    Request.
	 * @param string           $repository Repository class name.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request, $repository ) {
		$row = $repository::get( (int) $request['id'] );

		if ( ! $row ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Record not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $row, $repository ), 200 );
	}

	/**
	 * @param WP_REST_Request $request    Request.
	 * @param string           $repository Repository class name.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request, $repository ) {
		$result = $repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( $repository::get( $result ), $repository ), 201 );
	}

	/**
	 * @param WP_REST_Request $request    Request.
	 * @param string           $repository Repository class name.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request, $repository ) {
		$id     = (int) $request['id'];
		$result = $repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( $repository::get( $id ), $repository ), 200 );
	}

	/**
	 * @param WP_REST_Request $request    Request.
	 * @param string           $repository Repository class name.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request, $repository ) {
		$result = $repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * GET /finance/summary?project_id=X
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_summary( WP_REST_Request $request ) {
		$project_id = (int) $request->get_param( 'project_id' );

		if ( ! $project_id ) {
			return self::to_rest_error( new WP_Error( 'ptp_project_required', __( 'A project_id is required.', 'personal-project-tracker' ) ) );
		}

		$summary = PTP_Finance_Service::get_project_summary(
			$project_id,
			array(
				'date_from' => $request->get_param( 'date_from' ),
				'date_to'   => $request->get_param( 'date_to' ),
				'category'  => $request->get_param( 'category' ),
			)
		);

		if ( ! $summary ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Project not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * GET /finance/report
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_report( WP_REST_Request $request ) {
		$report = PTP_Finance_Service::get_report(
			array(
				'project_id' => $request->get_param( 'project_id' ),
				'category'   => $request->get_param( 'category' ),
				'date_from'  => $request->get_param( 'date_from' ),
				'date_to'    => $request->get_param( 'date_to' ),
			)
		);

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status_map = array(
			'ptp_not_found'        => 404,
			'ptp_project_required' => 400,
		);
		$status     = $status_map[ $error->get_error_code() ] ?? 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * @param object|null $row        Expense or revenue row.
	 * @param string      $repository Repository class name (used only to
	 *                                 pick the date column name).
	 * @return array|null
	 */
	private static function prepare_item( $row, $repository ) {
		if ( ! $row ) {
			return null;
		}

		$date_field = 'PTP_Expenses_Repository' === $repository ? 'expense_date' : 'revenue_date';

		return array(
			'id'          => (int) $row->id,
			'project_id'  => $row->project_id ? (int) $row->project_id : null,
			'amount'      => (float) $row->amount,
			'currency'    => $row->currency,
			'category'    => $row->category,
			'description' => $row->description,
			'date'        => $row->$date_field,
			'created_at'  => $row->created_at,
			'updated_at'  => $row->updated_at,
		);
	}
}
