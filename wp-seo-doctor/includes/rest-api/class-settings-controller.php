<?php
/**
 * Backs the Settings screen. Exposes exactly two options that already
 * existed but had no UI to set them: the opt-in uninstall-data-deletion
 * flag (Step 4 — uninstall.php already reads it, nothing wrote it) and
 * the Pro license key (Steps 12-14 — Vendor_Api/Oauth already read it).
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Controller extends Rest_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	public function get_settings() {
		return rest_ensure_response(
			array(
				'delete_data_on_uninstall' => 'yes' === get_option( 'seodoc_delete_data_on_uninstall', 'no' ),
				'license_key'               => (string) get_option( 'seodoc_pro_license_key', '' ),
			)
		);
	}

	public function update_settings( \WP_REST_Request $request ) {
		$delete = $request->get_param( 'delete_data_on_uninstall' );
		if ( null !== $delete ) {
			update_option( 'seodoc_delete_data_on_uninstall', $delete ? 'yes' : 'no' );
		}

		$license_key = $request->get_param( 'license_key' );
		if ( null !== $license_key ) {
			update_option( 'seodoc_pro_license_key', sanitize_text_field( $license_key ) );
		}

		return rest_ensure_response( array( 'saved' => true ) );
	}
}
