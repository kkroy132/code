<?php
/**
 * Provider-agnostic by design (product decision for this step): nothing
 * in this codebase names a specific AI vendor (Claude, GPT, or otherwise).
 * Every AI task is a call to the vendor's own backend — the same proxy
 * pattern as Step 12's GSC OAuth, for the same reason: an AI provider API
 * key shipped inside a distributed plugin is exactly as extractable as an
 * OAuth client secret would be. Which model answers the call is entirely
 * the vendor server's decision, swappable there without a plugin update
 * and without this file ever knowing.
 *
 * Every call sends structured, task-specific data — never a raw
 * free-text prompt assembled client-side. That's a deliberate boundary,
 * not an accident: the brief requires the AI Assistant to "analyze
 * available plugin data and answer using actual detected issues" and to
 * "not invent SEO problems" (Step 1 §13). Sending structured payloads
 * built from this plugin's own real Issue/post data — rather than
 * relaying open-ended text — is what keeps the model's context grounded
 * in what the plugin actually found, and keeps prompt construction (and
 * its iteration) entirely server-side.
 *
 * @package SEODoc
 */

namespace SEODoc\Ai;

use SEODoc\Licensing\License_Manager;
use SEODoc\Vendor_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ai_Client {

	const TASK_ENDPOINTS = array(
		'explain_issue'                => '/ai/explain-issue',
		'generate_title'                => '/ai/generate-title',
		'generate_description'          => '/ai/generate-description',
		'generate_alt_text'              => '/ai/generate-alt-text',
		'generate_action_plan'           => '/ai/generate-action-plan',
		'answer_question'                => '/ai/answer-question',
	);

	/**
	 * @param string $task    One of the TASK_ENDPOINTS keys.
	 * @param array  $payload Task-specific structured input.
	 * @return array|\WP_Error Decoded JSON response.
	 */
	public static function request( $task, array $payload ) {
		if ( ! License_Manager::is_valid_license() ) {
			return new \WP_Error(
				'seodoc_not_licensed',
				__( 'A valid Pro license is required to use the AI Assistant.', 'wp-seo-doctor' ),
				array( 'status' => 402 )
			);
		}

		if ( ! isset( self::TASK_ENDPOINTS[ $task ] ) ) {
			return new \WP_Error( 'seodoc_unknown_ai_task', __( 'Unknown AI task.', 'wp-seo-doctor' ) );
		}

		return Vendor_Api::post_json( self::TASK_ENDPOINTS[ $task ], $payload );
	}
}
