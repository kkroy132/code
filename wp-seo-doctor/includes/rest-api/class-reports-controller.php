<?php
/**
 * Backs the Reports screen's CSV export (Step 1 §3's Free "CSV export"
 * feature, unbuilt since Step 2 — added here). Returns structured JSON
 * rows rather than a raw text/csv response: the browser-side download is
 * built client-side from this data (a Blob + temporary link), which
 * avoids REST content-type/auth complications with a direct file
 * response and reuses the same apiFetch auth path as every other screen.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports_Controller extends Rest_Controller {

	/** Bounded export size — a safety cap, not a real-world limit. */
	const EXPORT_LIMIT = 2000;

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/reports/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_issues_for_export' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function get_issues_for_export() {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT severity, category, title, url, status, first_detected FROM {$table} WHERE status = 'open' ORDER BY FIELD(severity,'critical','high','medium','low'), last_detected DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own internally computed name (Schema::table_names()), never user input.
				self::EXPORT_LIMIT
			)
		);

		return rest_ensure_response( $rows );
	}
}
