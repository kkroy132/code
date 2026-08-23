<?php
/**
 * Backup / Export / Import / Restore module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Backup_Module
 *
 * Registers every admin-post handler this module needs. All routing (the
 * Backup tab, the Import preview step) lives in PTP_Admin_Pages'
 * render_settings(), matching every other module's page routing.
 */
class PTP_Backup_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_create_backup', array( 'PTP_Backup_Controller', 'handle_create_backup' ) );
		add_action( 'admin_post_ptp_delete_backup', array( 'PTP_Backup_Controller', 'handle_delete_backup' ) );
		add_action( 'admin_post_ptp_download_backup', array( 'PTP_Backup_Controller', 'handle_download_backup' ) );
		add_action( 'admin_post_ptp_export_json', array( 'PTP_Backup_Controller', 'handle_export_json' ) );
		add_action( 'admin_post_ptp_export_csv', array( 'PTP_Backup_Controller', 'handle_export_csv' ) );
		add_action( 'admin_post_ptp_import_upload', array( 'PTP_Backup_Controller', 'handle_import_upload' ) );
		add_action( 'admin_post_ptp_import_confirm', array( 'PTP_Backup_Controller', 'handle_import_confirm' ) );
		add_action( 'admin_post_ptp_restore_backup', array( 'PTP_Backup_Controller', 'handle_restore_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the Backup tab's JS (export scope toggling, restore/delete confirmations).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']/['tab']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'ptp-settings' !== $page ) {
			return;
		}

		wp_enqueue_script(
			'ptp-backup',
			PTP_PLUGIN_URL . 'modules/backup/assets/backup.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);
	}
}
