<?php
/**
 * Builds the structured, data-grounded payloads Ai_Client sends —
 * covering the brief's §13 feature list: explain SEO problems, generate
 * title/meta description/ALT text, create SEO action plans, and the
 * "why is this page not optimized" Q&A example. Every method here
 * assembles its payload from this plugin's own real data (Issue rows,
 * post content, Action_Plan's severity-weighted ranking) — never from
 * open-ended user text alone.
 *
 * @package SEODocPro
 */

namespace SEODocPro\Ai;

use SEODoc\DB\Schema;
use SEODoc\Issues\Action_Plan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ai_Assistant {

	/**
	 * @param object $issue_row A row from wp_seodoc_issues.
	 * @return string|\WP_Error
	 */
	public static function explain_issue( $issue_row ) {
		$result = Ai_Client::request(
			'explain_issue',
			array(
				'check_id' => $issue_row->check_id,
				'category' => $issue_row->category,
				'severity' => $issue_row->severity,
				'title'    => $issue_row->title,
				'url'      => $issue_row->url,
				'details'  => json_decode( (string) $issue_row->details, true ),
			)
		);

		return self::extract( $result, 'explanation' );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function generate_title( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'seodoc_missing_post', __( 'Post not found.', 'wp-seo-doctor-pro' ) );
		}

		$result = Ai_Client::request(
			'generate_title',
			array(
				'post_id'         => $post_id,
				'current_title'   => get_the_title( $post ),
				'content_excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 120 ),
			)
		);

		return self::extract( $result, 'title' );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function generate_description( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'seodoc_missing_post', __( 'Post not found.', 'wp-seo-doctor-pro' ) );
		}

		$result = Ai_Client::request(
			'generate_description',
			array(
				'post_id'         => $post_id,
				'title'           => get_the_title( $post ),
				'content_excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 120 ),
			)
		);

		return self::extract( $result, 'description' );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function generate_alt_text( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return new \WP_Error( 'seodoc_missing_attachment', __( 'Image not found.', 'wp-seo-doctor-pro' ) );
		}

		$result = Ai_Client::request(
			'generate_alt_text',
			array(
				'attachment_id' => $attachment_id,
				'image_url'     => $url,
				'context_title' => get_the_title( $attachment_id ),
			)
		);

		return self::extract( $result, 'alt_text' );
	}

	/**
	 * Applies generated ALT text to the attachment — the one AI output
	 * this step wires all the way to "apply," mirroring Step 9's
	 * Suggestion_Inserter precedent (one object, one explicit action).
	 * Title/description "apply" is intentionally not built yet — which
	 * meta key to write depends on which SEO plugin (if any) is active,
	 * a real decision deferred rather than guessed at; see docs/13.
	 *
	 * @return true|\WP_Error
	 */
	public static function apply_alt_text( $attachment_id, $alt_text ) {
		if ( ! get_post( $attachment_id ) ) {
			return new \WP_Error( 'seodoc_missing_attachment', __( 'Image not found.', 'wp-seo-doctor-pro' ) );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

		return true;
	}

	/**
	 * "Create SEO action plans" (§13) — a plain-language summary layered
	 * on top of Free's Action_Plan::get(), never a replacement for it.
	 * The ranking itself is still Step 7's real severity-weighted impact
	 * score; the model only explains it in words.
	 *
	 * @return string|\WP_Error
	 */
	public static function summarize_action_plan( $limit = 10 ) {
		$groups = Action_Plan::get( $limit );
		if ( empty( $groups ) ) {
			return '';
		}

		$result = Ai_Client::request( 'generate_action_plan', array( 'issue_groups' => $groups ) );

		return self::extract( $result, 'summary' );
	}

	/**
	 * The brief's own example: "Why is this page not SEO optimized?" —
	 * answered using this page's actual open Issues, not general
	 * knowledge about SEO.
	 *
	 * @return string|\WP_Error
	 */
	public static function answer_question( $post_id, $question ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$issues = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT check_id, category, severity, title FROM {$table} WHERE object_id = %d AND status = 'open'",
				$post_id
			)
		);

		$result = Ai_Client::request(
			'answer_question',
			array(
				'post_id'  => $post_id,
				'url'      => get_permalink( $post_id ),
				'question' => sanitize_textarea_field( $question ),
				'issues'   => $issues,
			)
		);

		return self::extract( $result, 'answer' );
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function extract( $result, $key ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result[ $key ] ) ? $result[ $key ] : '';
	}
}
