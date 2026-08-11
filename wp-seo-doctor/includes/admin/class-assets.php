<?php
/**
 * Enqueues the React admin app — and only on WP SEO Doctor's own screens,
 * never site-wide (Step 1 §7/§30).
 *
 * @package SEODoc
 */

namespace SEODoc\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'seodoc' ) ) {
			return;
		}

		$script_path = SEODOC_PLUGIN_DIR . 'admin/build/index.js';
		$asset_path  = SEODOC_PLUGIN_DIR . 'admin/build/index.asset.php';

		if ( ! file_exists( $script_path ) || ! file_exists( $asset_path ) ) {
			add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			'seodoc-admin',
			SEODOC_PLUGIN_URL . 'admin/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// @wordpress/scripts' webpack config names the extracted
		// stylesheet style-[entry].css, not [entry].css — this isn't a
		// stray choice, it's what `npm run build` actually emits.
		if ( file_exists( SEODOC_PLUGIN_DIR . 'admin/build/style-index.css' ) ) {
			wp_enqueue_style(
				'seodoc-admin',
				SEODOC_PLUGIN_URL . 'admin/build/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_localize_script(
			'seodoc-admin',
			'seodocSettings',
			array(
				'restUrl'  => esc_url_raw( rest_url( \SEODoc\Rest_Api\Rest_Controller::REST_NAMESPACE ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'adminUrl' => admin_url( 'admin.php' ),
			)
		);
	}

	public function missing_build_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__(
			'WP SEO Doctor: the admin app has not been built yet. Run `npm install && npm run build` inside the admin/ directory.',
			'wp-seo-doctor'
		) . '</p></div>';
	}
}
