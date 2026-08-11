<?php
/**
 * Capability + nonce helpers shared by every REST controller and admin
 * screen, so the "which capability gates this plugin" decision lives in
 * one filterable place (Step 1 §9).
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	/**
	 * @return string The capability required to view/manage WP SEO Doctor.
	 *                Defaults to manage_options; filterable so agencies
	 *                can delegate to a custom capability without granting
	 *                full admin access.
	 */
	public static function required_capability() {
		return apply_filters( 'seodoc_manage_capability', 'manage_options' );
	}

	public static function current_user_can_manage() {
		return current_user_can( self::required_capability() );
	}

	/**
	 * For non-REST state-changing requests (e.g. admin-post.php actions).
	 * REST routes get nonce checking for free from WP core's cookie-auth
	 * handling of the X-WP-Nonce header when permission_callback checks
	 * current_user_can(); this helper exists for the handful of paths
	 * that aren't REST.
	 */
	public static function verify_nonce( $nonce, $action ) {
		return (bool) wp_verify_nonce( $nonce, $action );
	}
}
