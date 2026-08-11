<?php
/**
 * Backs the 404 Monitor screen. Suggested redirects are computed here,
 * per-row, for exactly the page of results being displayed — not stored,
 * not computed on every 404 hit (see Suggestion_Matcher). "Create 301
 * Redirect" itself is a Step 11 action once Redirect_Manager exists.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\DB\Schema;
use SEODoc\Monitor_404\Suggestion_Matcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Monitor_404_Controller extends Rest_Controller {

	const PER_PAGE = 20;

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/404s',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function get_items( \WP_REST_Request $request ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['404'];

		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'open' ORDER BY hit_count DESC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				$offset
			)
		);

		foreach ( $rows as $row ) {
			$row->suggested_redirect = Suggestion_Matcher::suggest( $row->url );
		}

		return rest_ensure_response( $rows );
	}
}
