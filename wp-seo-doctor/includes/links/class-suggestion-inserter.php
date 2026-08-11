<?php
/**
 * The "Insert" step of Review → Approve → Insert (Step 1 §6/§34): applies
 * exactly one, explicitly-approved suggestion to exactly one post. Never
 * called in bulk — each call is one user action against one suggestion id.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Suggestion_Inserter {

	/**
	 * @param int $suggestion_id
	 * @return true|\WP_Error
	 */
	public static function approve_and_insert( $suggestion_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['suggestions'];

		$suggestion = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $suggestion_id )
		);

		if ( ! $suggestion ) {
			return new \WP_Error(
				'seodoc_not_found',
				__( 'This suggestion is no longer pending.', 'wp-seo-doctor' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $suggestion->source_object_id );
		if ( ! $post ) {
			return new \WP_Error(
				'seodoc_missing_source',
				__( 'The source page for this suggestion no longer exists.', 'wp-seo-doctor' ),
				array( 'status' => 404 )
			);
		}

		$anchor    = sanitize_text_field( $suggestion->suggested_anchor );
		$url       = esc_url_raw( $suggestion->target_url );
		$link_html = '<p><a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a></p>';

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $post->post_content . "\n\n" . wp_kses_post( $link_html ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$wpdb->update(
			$table,
			array(
				'status'      => 'inserted',
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => $suggestion_id )
		);

		return true;
	}
}
