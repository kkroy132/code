<?php
/**
 * Backs the Overview dashboard screen: health score + delta, open-issue
 * counts by severity, and the Fix First list — plus scan start/status,
 * since the Overview screen is also where "Run a new scan" lives.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\DB\Schema;
use SEODoc\Issues\Action_Plan;
use SEODoc\Scanner\Scan_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Overview_Controller extends Rest_Controller {

	const FIX_FIRST_LIMIT = 5;

	const ACTION_PLAN_LIMIT = 20;

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_overview' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/action-plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_action_plan' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/scans',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/scans/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_scan' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	public function get_overview( \WP_REST_Request $request ) {
		global $wpdb;
		$tables = Schema::table_names( $wpdb );

		$latest_scan = $wpdb->get_row(
			"SELECT * FROM {$tables['scans']} WHERE status = 'completed' ORDER BY finished_at DESC LIMIT 1"
		);

		$counts = array(
			'critical' => 0,
			'high'     => 0,
			'medium'   => 0,
			'low'      => 0,
		);

		$counts_raw = $wpdb->get_results(
			"SELECT severity, COUNT(*) AS c FROM {$tables['issues']} WHERE status = 'open' GROUP BY severity"
		);
		foreach ( $counts_raw as $row ) {
			if ( isset( $counts[ $row->severity ] ) ) {
				$counts[ $row->severity ] = (int) $row->c;
			}
		}

		$resolved_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tables['issues']} WHERE status = 'resolved'"
		);

		return rest_ensure_response(
			array(
				'health_score'          => $latest_scan ? (int) $latest_scan->health_score : null,
				'previous_health_score' => $latest_scan ? (int) $latest_scan->previous_health_score : null,
				'last_scan_at'          => $latest_scan ? $latest_scan->finished_at : null,
				'issue_counts'          => $counts,
				'resolved_count'        => $resolved_count,
				'fix_first'             => Action_Plan::get( self::FIX_FIRST_LIMIT ),
			)
		);
	}

	public function get_action_plan() {
		return rest_ensure_response( Action_Plan::get( self::ACTION_PLAN_LIMIT ) );
	}

	public function start_scan( \WP_REST_Request $request ) {
		$scan_id = Scan_Controller::start( 'full', 'manual' );

		return rest_ensure_response( array( 'scan_id' => $scan_id ) );
	}

	public function get_scan( \WP_REST_Request $request ) {
		$scan = Scan_Controller::get_scan( (int) $request['id'] );

		if ( ! $scan ) {
			return new \WP_Error( 'seodoc_not_found', __( 'Scan not found.', 'wp-seo-doctor' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $scan );
	}
}
