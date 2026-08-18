<?php
/**
 * Renders the site-wide Analytics admin page.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

use QuickLinkQRPro\Database\ClicksRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AnalyticsPage
 */
final class AnalyticsPage {

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_qlqr_links' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		$clicks_repo = new ClicksRepository();

		// A free-plan account only ever sees the last 7 days here; a Pro account sees exactly what
		// this page has always shown — a 30-day chart plus all-time breakdowns. See
		// Helpers\AnalyticsDays for the shared plan check.
		$qlqr_chart_days   = \QuickLinkQRPro\Helpers\AnalyticsDays::cap( 30 );
		$qlqr_history_days = \QuickLinkQRPro\Helpers\AnalyticsDays::cap( 0 );

		$clicks_by_day = $clicks_repo->clicks_by_day( $qlqr_chart_days );
		$by_country    = $clicks_repo->top_by_dimension( 'country', 10, null, true, $qlqr_history_days );
		$by_device     = $clicks_repo->top_by_dimension( 'device', 10, null, true, $qlqr_history_days );
		$by_browser    = $clicks_repo->top_by_dimension( 'browser', 10, null, true, $qlqr_history_days );
		$by_os         = $clicks_repo->top_by_dimension( 'operating_system', 10, null, true, $qlqr_history_days );
		$by_referrer   = $clicks_repo->top_by_dimension( 'referrer', 10, null, true, $qlqr_history_days );

		$source_labels = array(
			'qr'   => __( 'QR Code Scan', 'plugnova-link-shortener-qr' ),
			'link' => __( 'Direct Link Click', 'plugnova-link-shortener-qr' ),
		);
		$by_source = array_map(
			static function ( array $row ) use ( $source_labels ): array {
				$row['label'] = $source_labels[ $row['label'] ] ?? $row['label'];
				return $row;
			},
			$clicks_repo->top_by_dimension( 'source', 10, null, true, $qlqr_history_days )
		);

		include QLQR_PLUGIN_DIR . 'templates/admin/analytics.php';
	}
}
