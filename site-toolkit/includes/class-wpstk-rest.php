<?php
/**
 * Read-only REST endpoints.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes stored audit results to logged-in users who may view them.
 *
 * The endpoints are read-only and never public: every route checks the same
 * capability the admin screens use.
 *
 * @since 1.0.0
 */
class WPSTK_Rest {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_ROOT = 'site-toolkit/v1';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Declares the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_status' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/scans',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_scans' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
					'args'                => array(
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 10,
							'minimum'           => 1,
							'maximum'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/scans/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_scan' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_view() {
		if ( WPSTK_Security::can( 'view' ) ) {
			return true;
		}

		return new WP_Error(
			'wpstk_forbidden',
			__( 'You do not have permission to read Site Toolkit results.', 'site-toolkit' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns the latest scan summary and whether an audit is running.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status() {
		$latest = WPSTK_Scan_Store::get_latest();

		return rest_ensure_response(
			array(
				'running' => wpstk()->audit()->status(),
				'latest'  => $latest ? self::prepare_scan( $latest ) : null,
			)
		);
	}

	/**
	 * Returns recent scan summaries.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_scans( $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$scans    = WPSTK_Scan_Store::get_recent( $per_page, 0 );
		$out      = array();

		foreach ( $scans as $scan ) {
			$out[] = self::prepare_scan( $scan );
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Returns a single scan with its checks.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_scan( $request ) {
		$scan = WPSTK_Scan_Store::get( (int) $request->get_param( 'id' ) );

		if ( ! $scan ) {
			return new WP_Error(
				'wpstk_scan_not_found',
				__( 'That scan could not be found.', 'site-toolkit' ),
				array( 'status' => 404 )
			);
		}

		$prepared           = self::prepare_scan( $scan );
		$prepared['checks'] = WPSTK_Scan_Store::get_checks( $scan['id'] );

		return rest_ensure_response( $prepared );
	}

	/**
	 * Shapes a scan row for the API.
	 *
	 * @param array $scan Scan row.
	 *
	 * @return array
	 */
	private static function prepare_scan( $scan ) {
		return array(
			'id'          => (int) $scan['id'],
			'started_at'  => (string) $scan['started_at'],
			'finished_at' => (string) $scan['finished_at'],
			'status'      => (string) $scan['status'],
			'trigger'     => (string) $scan['trigger_type'],
			'score'       => $scan['overall_score'],
			'scores'      => (array) $scan['scores'],
			'counts'      => array(
				'critical'       => (int) $scan['critical_count'],
				'warning'        => (int) $scan['warning_count'],
				'recommendation' => (int) $scan['recommendation_count'],
				'passed'         => (int) $scan['passed_count'],
				'skipped'        => (int) $scan['skipped_count'],
				'total'          => (int) $scan['total_checks'],
			),
		);
	}
}
