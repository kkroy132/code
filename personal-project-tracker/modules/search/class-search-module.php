<?php
/**
 * Global Search module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Search_Module
 *
 * The Search admin page itself (admin/views/search/search-page.php) calls
 * PTP_Search_Service directly, exactly like every other list page in this
 * plugin calls its own repository directly — the REST route this module
 * registers is a separate, additional interface, not something the admin
 * page routes through internally.
 */
class PTP_Search_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'ptp_register_rest_routes', array( 'PTP_Search_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the Search page's small progressive-enhancement script
	 * (auto-submitting filter selects, mirroring the pattern already used
	 * by e.g. the Backup tab's export-scope toggling).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'ptp-search' !== $page ) {
			return;
		}

		wp_enqueue_script(
			'ptp-search',
			PTP_PLUGIN_URL . 'modules/search/assets/search.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);
	}
}
