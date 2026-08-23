<?php
/**
 * REST API endpoint for global search.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Search_REST
 *
 * Registers ptp/v1/search. Gated at ptp_manage_data (the same capability
 * the Search admin page itself requires); per-source visibility (e.g.
 * Finance results additionally requiring ptp_manage_finance) is enforced
 * inside PTP_Search_Service itself, so the admin page and this endpoint
 * can never disagree about what a given user is allowed to see.
 */
class PTP_Search_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'search';

	/**
	 * Register the route. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		register_rest_route(
			PTP_REST_API::NAMESPACE_NAME,
			'/' . self::BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_results' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_data' ),
				'args'                => self::collection_args(),
			)
		);
	}

	/**
	 * Arg schema for GET /search.
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
			'type'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			),
			'project_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
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
	 * GET /search
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_results( WP_REST_Request $request ) {
		$result = PTP_Search_Service::search(
			array(
				'search'     => $request->get_param( 'search' ),
				'type'       => $request->get_param( 'type' ),
				'project_id' => $request->get_param( 'project_id' ),
				'status'     => $request->get_param( 'status' ),
				'priority'   => $request->get_param( 'priority' ),
				'date_from'  => $request->get_param( 'date_from' ),
				'date_to'    => $request->get_param( 'date_to' ),
				'paged'      => $request->get_param( 'page' ),
				'per_page'   => $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items'       => $result['items'],
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
			),
			200
		);
	}
}
