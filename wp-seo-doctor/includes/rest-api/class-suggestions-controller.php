<?php
/**
 * Backs the Links screen's suggestion review flow: list pending
 * suggestions, approve (inserts the link into the source page), or
 * dismiss. This is the only REST path that mutates post content, and it
 * only ever touches one post per call, gated behind an explicit approve
 * request (Step 1 §6/§34 — never bulk, never automatic).
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\DB\Schema;
use SEODoc\Links\Suggestion_Inserter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Suggestions_Controller extends Rest_Controller {

	const LIST_LIMIT = 50;

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/suggestions/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/suggestions/(?P<id>\d+)/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function get_items( \WP_REST_Request $request ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['suggestions'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY relevance_score DESC, created_at DESC LIMIT %d",
				self::LIST_LIMIT
			)
		);

		return rest_ensure_response( $rows );
	}

	public function approve_item( \WP_REST_Request $request ) {
		$result = Suggestion_Inserter::approve_and_insert( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'inserted' => true ) );
	}

	public function dismiss_item( \WP_REST_Request $request ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['suggestions'];

		$updated = $wpdb->update(
			$table,
			array(
				'status'      => 'dismissed',
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $request['id'] )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'seodoc_update_failed', __( 'Could not update this suggestion.', 'wp-seo-doctor' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'dismissed' => true ) );
	}
}
