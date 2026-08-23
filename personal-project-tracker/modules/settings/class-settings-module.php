<?php
/**
 * Settings module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Settings_Module
 *
 * Registers the admin-post handler for the tabbed Settings page. Routing
 * (which tab renders) lives in PTP_Admin_Pages::render_settings(), matching
 * every other module's action-based page routing.
 */
class PTP_Settings_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_settings', array( 'PTP_Settings_Controller', 'handle_save_settings' ) );
	}
}
