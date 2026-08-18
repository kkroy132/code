<?php
/**
 * Renders the main Dashboard admin page.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

use QuickLinkQRPro\Database\ClicksRepository;
use QuickLinkQRPro\Database\LinksRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DashboardPage
 */
final class DashboardPage {

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_qlqr_links' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		$links_repo  = new LinksRepository();
		$clicks_repo = new ClicksRepository();

		// A free-plan account only ever sees the last 7 days of analytics on this page (chart and
		// widgets alike); a Pro account sees exactly what this page has always shown — a 30-day
		// chart plus all-time widgets. See Helpers\AnalyticsDays for the shared plan check.
		$qlqr_chart_days   = \QuickLinkQRPro\Helpers\AnalyticsDays::cap( 30 );
		$qlqr_history_days = \QuickLinkQRPro\Helpers\AnalyticsDays::cap( 0 );

		$counters      = $links_repo->dashboard_counters();
		$top_links     = $links_repo->top_links( 5 );
		$latest_links  = $links_repo->query( array( 'per_page' => 5, 'orderby' => 'created_at', 'order' => 'DESC' ) )['items'];
		$latest_clicks = $clicks_repo->latest( 8, null, true, $qlqr_history_days );
		$countries     = $clicks_repo->distinct_country_count( $qlqr_history_days );
		$clicks_by_day = $clicks_repo->clicks_by_day( $qlqr_chart_days );
		$by_device     = $clicks_repo->top_by_dimension( 'device', 5, null, true, $qlqr_history_days );
		$by_browser    = $clicks_repo->top_by_dimension( 'browser', 5, null, true, $qlqr_history_days );
		$by_referrer   = $clicks_repo->top_by_dimension( 'referrer', 5, null, true, $qlqr_history_days );

		include QLQR_PLUGIN_DIR . 'templates/admin/dashboard.php';
	}
}
