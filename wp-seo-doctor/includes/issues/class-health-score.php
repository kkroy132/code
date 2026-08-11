<?php
/**
 * SEO Health Score: a transparent heuristic, not a guarantee of anything
 * (Step 1's "no fake metrics" principle applies here too, even though
 * it's a first-party score rather than an AI claim) — documented in full
 * below rather than a black-box number.
 *
 * @package SEODoc
 */

namespace SEODoc\Issues;

use SEODoc\DB\Schema;
use SEODoc\Module_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health_Score {

	const SEVERITY_WEIGHTS = array(
		'critical' => 10,
		'high'     => 5,
		'medium'   => 2,
		'low'      => 1,
	);

	/**
	 * score = 100 × (1 − weighted_open_issues / max_possible_weighted_issues)
	 *
	 * weighted_open_issues sums every currently-open issue site-wide
	 * (not just this scan's new findings — a resolved issue drops off
	 * immediately, an issue found last week and still open still counts),
	 * each multiplied by its severity weight above.
	 *
	 * max_possible_weighted_issues is a normalizing ceiling — roughly
	 * "every check, on every scanned object, came back critical" — so a
	 * large site with the same *rate* of problems as a small one gets a
	 * comparable score instead of being penalized just for having more
	 * pages. It's necessarily approximate (scan-level checks don't scale
	 * with object count the way per-object checks do), which is why this
	 * is documented as a heuristic rather than presented as a precise
	 * metric.
	 *
	 * @param int $scan_id The just-completed scan, read only for its
	 *                      total_items count used in normalization.
	 * @return int 0-100
	 */
	public static function compute( $scan_id ) {
		global $wpdb;
		$tables = Schema::table_names( $wpdb );

		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT total_items FROM {$tables['scans']} WHERE id = %d", $scan_id )
		);
		$total_items = max( 1, $total_items );

		$rows = $wpdb->get_results(
			"SELECT severity, COUNT(*) AS c FROM {$tables['issues']} WHERE status = 'open' GROUP BY severity"
		);

		$open_counts = array();
		foreach ( $rows as $row ) {
			$open_counts[ $row->severity ] = (int) $row->c;
		}

		$weighted_open = 0;
		foreach ( self::SEVERITY_WEIGHTS as $severity => $weight ) {
			$weighted_open += $weight * ( isset( $open_counts[ $severity ] ) ? $open_counts[ $severity ] : 0 );
		}

		$check_count = count( Module_Registry::get_checks() ) + count( Module_Registry::get_scan_level_checks() );
		$check_count = max( 1, $check_count );

		$max_weight   = max( self::SEVERITY_WEIGHTS );
		$max_possible = $total_items * $check_count * $max_weight;

		$score = 100 * ( 1 - ( $weighted_open / max( 1, $max_possible ) ) );

		return (int) max( 0, min( 100, round( $score ) ) );
	}
}
