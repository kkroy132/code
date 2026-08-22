<?php
/**
 * REST API bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_REST_API
 *
 * Registers the ptp/v1 namespace. Individual modules (projects, tasks,
 * finance, search, ...) register their own routes on the
 * 'ptp_register_rest_routes' action in later phases so this class stays a
 * thin bootstrap rather than growing into a god object.
 *
 * Every route registered anywhere in the plugin must supply a
 * 'permission_callback' — see PTP_Security::rest_permission().
 */
class PTP_REST_API {

	/**
	 * REST namespace used by every plugin endpoint.
	 *
	 * @var string
	 */
	const NAMESPACE_NAME = 'ptp/v1';

	/**
	 * Hook registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register core routes and let feature modules add their own.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_data' ),
			)
		);

		/**
		 * Fires after core routes are registered so feature modules can add
		 * their own routes under the same namespace.
		 */
		do_action( 'ptp_register_rest_routes' );
	}

	/**
	 * Simple authenticated health-check endpoint used to verify the REST
	 * scaffold (namespace, auth, capability gate) is wired correctly.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_REST_Response(
			array(
				'plugin'     => 'personal-project-tracker',
				'version'    => PTP_VERSION,
				'db_version' => PTP_DB_VERSION,
			),
			200
		);
	}
}
