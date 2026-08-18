<?php
/**
 * Activity Log admin page view: renders the audit-trail table shell. The table body itself is
 * driven by assets/js/admin.js via AJAX (see AjaxHandler::query_activity_log()).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityLogPage
 */
final class ActivityLogPage {

	/**
	 * Render the page. Same permission gate as the other tabs (Links, Bio Links, Analytics).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_qlqr_links' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		include QLQR_PLUGIN_DIR . 'templates/admin/activity-log.php';
	}
}
