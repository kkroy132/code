<?php
/**
 * Backs the AI Assistant screen. Every response is a generated
 * suggestion, not an applied change — the one exception is
 * apply-alt-text, an explicit separate action, same "generate, then a
 * distinct approve/apply step" shape as Step 9's suggestions flow.
 *
 * @package SEODocPro
 */

namespace SEODocPro\Rest_Api;

use SEODoc\DB\Schema;
use SEODoc\Rest_Api\Rest_Controller;
use SEODocPro\Ai\Ai_Assistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ai_Controller extends Rest_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/explain-issue/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explain_issue' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/generate-title/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_title' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/generate-description/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_description' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/generate-alt-text/(?P<attachment_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_alt_text' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/apply-alt-text/(?P<attachment_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_alt_text' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/action-plan-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'action_plan_summary' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/ask/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function explain_issue( \WP_REST_Request $request ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$issue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $issue ) {
			return new \WP_Error( 'seodoc_not_found', __( 'Issue not found.', 'wp-seo-doctor-pro' ), array( 'status' => 404 ) );
		}

		return $this->respond( Ai_Assistant::explain_issue( $issue ), 'explanation' );
	}

	public function generate_title( \WP_REST_Request $request ) {
		return $this->respond( Ai_Assistant::generate_title( (int) $request['post_id'] ), 'title' );
	}

	public function generate_description( \WP_REST_Request $request ) {
		return $this->respond( Ai_Assistant::generate_description( (int) $request['post_id'] ), 'description' );
	}

	public function generate_alt_text( \WP_REST_Request $request ) {
		return $this->respond( Ai_Assistant::generate_alt_text( (int) $request['attachment_id'] ), 'alt_text' );
	}

	public function apply_alt_text( \WP_REST_Request $request ) {
		$result = Ai_Assistant::apply_alt_text( (int) $request['attachment_id'], (string) $request->get_param( 'alt_text' ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'applied' => true ) );
	}

	public function action_plan_summary() {
		return $this->respond( Ai_Assistant::summarize_action_plan(), 'summary' );
	}

	public function ask( \WP_REST_Request $request ) {
		$answer = Ai_Assistant::answer_question( (int) $request['post_id'], (string) $request->get_param( 'question' ) );

		return $this->respond( $answer, 'answer' );
	}

	private function respond( $result, $key ) {
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		return rest_ensure_response( array( $key => $result ) );
	}
}
