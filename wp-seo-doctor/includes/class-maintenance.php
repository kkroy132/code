<?php
/**
 * Implements the retention policy documented (but never wired up) in
 * Step 3: scan history and 404 log rows are pruned by age, not kept
 * forever. seodoc_daily_maintenance has been scheduled since
 * Activator::activate() in Step 4 with nothing listening to it — found
 * during the Step 15 security audit while checking whether the 404
 * monitor has any real defense against unbounded row growth (a scanner
 * probing thousands of distinct nonexistent paths creates one row per
 * distinct URL; the schema's per-URL aggregation only bounds repeat
 * hits to the *same* URL, not the number of distinct URLs over time).
 *
 * @package SEODoc
 */

namespace SEODoc;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Maintenance {

	const DEFAULT_404_RETENTION_DAYS = 90;

	const DEFAULT_SCAN_RETENTION_DAYS = 90;

	public function __construct() {
		add_action( 'seodoc_daily_maintenance', array( $this, 'run' ) );
	}

	public function run() {
		$this->prune_404s();
		$this->prune_old_scans();
	}

	private function prune_404s() {
		global $wpdb;
		$table  = Schema::table_names( $wpdb )['404'];
		$days   = (int) apply_filters( 'seodoc_404_retention_days', self::DEFAULT_404_RETENTION_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
	}

	/**
	 * Only completed/cancelled scans age out — a queued/running/paused
	 * scan is live state, never pruned regardless of age.
	 */
	private function prune_old_scans() {
		global $wpdb;
		$table  = Schema::table_names( $wpdb )['scans'];
		$days   = (int) apply_filters( 'seodoc_scan_retention_days', self::DEFAULT_SCAN_RETENTION_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('completed', 'cancelled') AND finished_at IS NOT NULL AND finished_at < %s",
				$cutoff
			)
		);
	}
}
