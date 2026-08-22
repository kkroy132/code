<?php
/**
 * Projects module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Projects_Module
 *
 * Registers everything the Projects module needs: the admin-post handler
 * for the create/edit form, its REST routes, and its own admin script
 * (loaded only on the Projects screen, as a dependent of the shared
 * 'ptp-admin' handle so ptpAdmin's REST URL/nonce are already available).
 */
class PTP_Projects_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_project', array( 'PTP_Projects_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Projects_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the Projects screen's JS (quick actions: archive/restore/delete).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-projects' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-projects',
			PTP_PLUGIN_URL . 'modules/projects/assets/projects.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-projects',
			'ptpProjects',
			array(
				'confirmDelete'  => __( 'Delete this project permanently? This cannot be undone.', 'personal-project-tracker' ),
				'confirmArchive' => __( 'Archive this project?', 'personal-project-tracker' ),
				'loadingText'    => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'   => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}
}
