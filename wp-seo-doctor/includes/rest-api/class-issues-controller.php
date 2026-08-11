<?php
/**
 * Lists/filters open issues (the SEO Audit screen's data source) and
 * lets a user dismiss one ("Ignore" in the Broken Link Intelligence /
 * issue-detail actions described in the brief).
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Issues_Controller extends Rest_Controller {

	const DEFAULT_PER_PAGE = 20;

	const MAX_PER_PAGE = 100;

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/issues/(?P<id>\d+)/ignore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ignore_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function get_items( \WP_REST_Request $request ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page ? min( self::MAX_PER_PAGE, max( 1, $per_page ) ) : self::DEFAULT_PER_PAGE;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( "status = 'open'" );
		$params = array();

		$severity = $request->get_param( 'severity' );
		if ( $severity ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}

		$category = $request->get_param( 'category' );
		if ( $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		$where_sql = implode( ' AND ', $where );

		$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total       = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_query, $params ) ) : $wpdb->get_var( $count_query ) );

		$rows_query  = "SELECT * FROM {$table} WHERE {$where_sql}
		                ORDER BY FIELD(severity,'critical','high','medium','low'), last_detected DESC
		                LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_query, $rows_params ) );

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	public function ignore_item( \WP_REST_Request $request ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$updated = $wpdb->update(
			$table,
			array( 'status' => 'ignored' ),
			array( 'id' => (int) $request['id'] )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'seodoc_update_failed', __( 'Could not update this issue.', 'wp-seo-doctor' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'updated' => true ) );
	}
}
