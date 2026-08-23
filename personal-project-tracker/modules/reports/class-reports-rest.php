<?php
/**
 * REST API endpoints for Reports.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Reports_REST
 *
 * Registers ptp/v1/reports. Every route is a thin wrapper around
 * PTP_Reports_Service — no calculation happens in this class. The
 * /reports/finance route is gated on ptp_manage_finance specifically
 * (financial data must stay private, per Phase 8), while every other
 * report uses the general ptp_manage_data capability the Reports/
 * Analytics admin pages already use.
 */
class PTP_Reports_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'reports';

	/**
	 * Capability required for most report endpoints.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Capability required for the finance report specifically.
	 *
	 * @var string
	 */
	const FINANCE_CAPABILITY = 'ptp_manage_finance';

	/**
	 * Register all report routes. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		$ns = PTP_REST_API::NAMESPACE_NAME;

		$routes = array(
			'projects'     => 'get_projects',
			'tasks'        => 'get_tasks',
			'milestones'   => 'get_milestones',
			'time'         => 'get_time',
			'productivity' => 'get_productivity',
			'activity'     => 'get_activity',
		);

		foreach ( $routes as $slug => $callback ) {
			register_rest_route(
				$ns,
				'/' . self::BASE . '/' . $slug,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				)
			);
		}

		register_rest_route(
			$ns,
			'/' . self::BASE . '/finance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_finance' ),
				'permission_callback' => PTP_Security::rest_permission( self::FINANCE_CAPABILITY ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array Shared filters, from whichever of these the caller sent.
	 */
	private static function args_from_request( WP_REST_Request $request ) {
		return array(
			'project_id' => $request->get_param( 'project_id' ),
			'status'     => $request->get_param( 'status' ),
			'priority'   => $request->get_param( 'priority' ),
			'category'   => $request->get_param( 'category' ),
			'date_from'  => $request->get_param( 'date_from' ),
			'date_to'    => $request->get_param( 'date_to' ),
		);
	}

	/**
	 * GET /reports/projects
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_projects( WP_REST_Request $request ) {
		$args              = self::args_from_request( $request );
		$args['paged']     = $request->get_param( 'page' );
		$args['per_page']  = $request->get_param( 'per_page' );

		// This route is gated at ptp_manage_data (see register_routes()), but
		// the project report includes revenue/expenses/profit — Finance data
		// that must stay private per ptp_manage_finance everywhere else in
		// the plugin, so it's only included when the caller also holds that
		// capability (the same pattern PTP_Analytics_REST::get_dashboard() uses).
		$include_finance = current_user_can( 'ptp_manage_finance' );

		return new WP_REST_Response( PTP_Reports_Service::get_project_report( $args, $include_finance ), 200 );
	}

	/**
	 * GET /reports/tasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_tasks( WP_REST_Request $request ) {
		return new WP_REST_Response( PTP_Reports_Service::get_task_report( self::args_from_request( $request ) ), 200 );
	}

	/**
	 * GET /reports/milestones
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_milestones( WP_REST_Request $request ) {
		return new WP_REST_Response( PTP_Reports_Service::get_milestone_report( self::args_from_request( $request ) ), 200 );
	}

	/**
	 * GET /reports/time
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_time( WP_REST_Request $request ) {
		$args           = self::args_from_request( $request );
		$args['task_id'] = $request->get_param( 'task_id' );

		return new WP_REST_Response( PTP_Reports_Service::get_time_report( $args ), 200 );
	}

	/**
	 * GET /reports/finance
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_finance( WP_REST_Request $request ) {
		return new WP_REST_Response( PTP_Reports_Service::get_finance_report( self::args_from_request( $request ) ), 200 );
	}

	/**
	 * GET /reports/productivity
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_productivity( WP_REST_Request $request ) {
		return new WP_REST_Response( PTP_Reports_Service::get_productivity_report( self::args_from_request( $request ) ), 200 );
	}

	/**
	 * GET /reports/activity
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_activity( WP_REST_Request $request ) {
		$args                 = self::args_from_request( $request );
		$args['object_type']  = $request->get_param( 'object_type' );
		$args['limit']        = $request->get_param( 'limit' );

		return new WP_REST_Response( PTP_Reports_Service::get_activity_report( $args ), 200 );
	}
}
