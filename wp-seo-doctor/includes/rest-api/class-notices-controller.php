<?php
/**
 * Persists admin-notice dismissals. Exists because Plugin_Detector's
 * complementary-mode notice checked a "dismissed" option that nothing
 * ever wrote — found during the Step 17 WordPress.org compliance audit:
 * a notice that can never actually be dismissed is a well-known plugin
 * review rejection reason, not just a UX rough edge.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notices_Controller extends Rest_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/notices/dismiss-compat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss_compat_notice' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function dismiss_compat_notice() {
		update_option( 'seodoc_dismissed_compat_notice', 1 );

		return rest_ensure_response( array( 'dismissed' => true ) );
	}
}
