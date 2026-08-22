<?php
/**
 * Calendar module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Calendar_Module
 *
 * Registers everything the Calendar module needs: the admin-post handler
 * for the custom event form, its REST routes, and its own admin script.
 * Unlike Tasks/Milestones this module does not add a
 * ptp_project_detail_sections card — Project detail already links out to
 * its own deadline via the standard Deadline field, and the calendar
 * itself is the cross-module surface, not something a single project's
 * page needs to re-embed.
 */
class PTP_Calendar_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_event', array( 'PTP_Calendar_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Calendar_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the Calendar screen's JS (quick delete + all-day toggle).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-calendar' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-calendar',
			PTP_PLUGIN_URL . 'modules/calendar/assets/calendar.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-calendar',
			'ptpCalendar',
			array(
				'confirmDelete' => __( 'Delete this event? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'   => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}
}
