<?php
/**
 * Reports + Analytics module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Reports_Module
 *
 * Registers the CSV export admin-post handler and both REST namespaces
 * (ptp/v1/reports, ptp/v1/analytics). This module is read-only against
 * every other module's data — it never adds its own tables or its own
 * detail-page sections, since a report is a view over data other modules
 * already own.
 */
class PTP_Reports_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_export_report_csv', array( 'PTP_Reports_Controller', 'handle_export_csv' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Reports_REST', 'register_routes' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Analytics_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the Reports/Analytics screens' JS (report-type + filter form
	 * helpers; no REST writes happen from this module, so no delete/quick-
	 * action wiring is needed).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $page, array( 'ptp-reports', 'ptp-analytics' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'ptp-reports',
			PTP_PLUGIN_URL . 'modules/reports/assets/reports.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);
	}
}
