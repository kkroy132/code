<?php
/**
 * Front-end CSS enqueued only when a plugin shortcode is present on the page.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Frontend;

use QuickLinkQRPro\Helpers\AssetVersion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 */
final class Assets {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue front-end styles only on posts/pages containing one of our shortcodes,
	 * to avoid loading unnecessary CSS site-wide.
	 */
	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$shortcodes = array( 'qlqr', 'qlqr_qr', 'qlqr_button', 'qlqr_stats', 'qlqr_bio', 'qlqr_bio_qr' );
		$has_shortcode = false;

		foreach ( $shortcodes as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				$has_shortcode = true;
				break;
			}
		}

		if ( ! $has_shortcode ) {
			return;
		}

		wp_enqueue_style(
			'qlqr-frontend',
			QLQR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			AssetVersion::for_file( 'assets/css/frontend.css' )
		);
	}
}
