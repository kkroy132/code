<?php
/**
 * Reads the "effective" SEO title/description for a post: whatever an
 * active SEO plugin (Yoast/Rank Math/AIOSEO) has set, falling back to
 * WordPress' own title/excerpt only when none is active. This is what
 * keeps title/description Checks from computing a value that competes
 * with Yoast/Rank Math/AIOSEO's own (Step 1 §10).
 *
 * @package SEODoc
 */

namespace SEODoc\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seo_Meta_Reader {

	public static function get_title( Scan_Context $context ) {
		$post_id = $context->get_object_id();

		foreach ( self::title_meta_keys() as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== trim( (string) $value ) ) {
				return $value;
			}
		}

		return $context->get_title();
	}

	/**
	 * @return string Empty string when no SEO plugin has set one and
	 *                WordPress core has no equivalent to fall back to.
	 */
	public static function get_description( Scan_Context $context ) {
		$post_id = $context->get_object_id();

		foreach ( self::description_meta_keys() as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== trim( (string) $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private static function title_meta_keys() {
		return array( '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title' );
	}

	private static function description_meta_keys() {
		return array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' );
	}
}
