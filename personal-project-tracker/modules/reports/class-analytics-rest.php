<?php
/**
 * REST API endpoints for Analytics.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Analytics_REST
 *
 * Registers ptp/v1/analytics. The dashboard endpoint requires only
 * ptp_manage_data (matching the Analytics admin page's own capability),
 * but omits the finance section unless the caller also holds
 * ptp_manage_finance — the dashboard must stay usable for a
 * finance-restricted user, it just shows less.
 */
class PTP_Analytics_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'analytics';

	/**
	 * Capability required to view the dashboard at all.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Register all analytics routes. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		register_rest_route(
			PTP_REST_API::NAMESPACE_NAME,
			'/' . self::BASE . '/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_dashboard' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);
	}

	/**
	 * GET /analytics/dashboard
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_dashboard( WP_REST_Request $request ) {
		$args = array(
			'date_from' => $request->get_param( 'date_from' ),
			'date_to'   => $request->get_param( 'date_to' ),
		);

		$include_finance = current_user_can( 'ptp_manage_finance' );

		return new WP_REST_Response( PTP_Analytics_Service::get_dashboard( $args, $include_finance ), 200 );
	}
}
