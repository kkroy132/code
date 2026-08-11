<?php
/**
 * Suggests a redirect destination for a 404'd URL. Deliberately computed
 * lazily/on-demand (called from the admin-only REST list endpoint, a
 * handful of rows at a time) rather than eagerly on every 404 hit — doing
 * this work inline in the frontend request would be exactly the
 * "expensive work during normal page requests" Step 1 §30 rules out.
 *
 * Uses WordPress' own post search rather than a bespoke similarity
 * algorithm: the 404 path's slug becomes a search term, matched against
 * published posts. Lightweight, no extra dependency, good enough for the
 * common case ("/best-camera-2024/" → "/best-camera-2026/").
 *
 * @package SEODoc
 */

namespace SEODoc\Monitor_404;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Suggestion_Matcher {

	/**
	 * @param string $url
	 * @return string Empty string when no reasonable match is found.
	 */
	public static function suggest( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '';
		}

		$search_term = trim( preg_replace( '/[^a-z0-9]+/i', ' ', trim( $path, '/' ) ) );
		if ( '' === $search_term ) {
			return '';
		}

		$query = new \WP_Query(
			array(
				's'              => $search_term,
				'post_type'      => array_values( get_post_types( array( 'public' => true ) ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( empty( $query->posts ) ) {
			return '';
		}

		return get_permalink( $query->posts[0] );
	}
}
