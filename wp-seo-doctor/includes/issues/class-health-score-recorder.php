<?php
/**
 * Writes the computed Health Score back onto the scan row, once all of a
 * scan's issues — including scan-level checks — have actually been
 * recorded. Hooked at priority 20 so it always runs after
 * Scan_Level_Check_Runner's default-priority (10) listener on the same
 * seodoc_scan_completed action; computing the score before duplicate/
 * HTTPS/robots/sitemap issues are written would score against a stale,
 * partial issue set.
 *
 * @package SEODoc
 */

namespace SEODoc\Issues;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health_Score_Recorder {

	public function __construct() {
		add_action( 'seodoc_scan_completed', array( $this, 'record' ), 20 );
	}

	public function record( $scan_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['scans'];

		$score = Health_Score::compute( $scan_id );

		$wpdb->update( $table, array( 'health_score' => $score ), array( 'id' => $scan_id ) );
	}
}
