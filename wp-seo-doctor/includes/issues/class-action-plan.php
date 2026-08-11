<?php
/**
 * "Fix First": ranks open issues grouped by check, by impact, so the
 * dashboard (Step 8) can show "fix these first" instead of a flat list of
 * every problem (Step 1 §5/§17).
 *
 * @package SEODoc
 */

namespace SEODoc\Issues;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Action_Plan {

	const SEVERITY_WEIGHTS = array(
		'critical' => 10,
		'high'     => 5,
		'medium'   => 2,
		'low'      => 1,
	);

	const SAMPLE_URL_LIMIT = 5;

	/**
	 * @param int $limit
	 * @return array<int, array{
	 *     check_id: string, category: string, severity: string,
	 *     sample_title: string, affected_count: int, impact_score: int,
	 *     sample_urls: string[]
	 * }>
	 */
	public static function get( $limit = 10 ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		// MIN(title) rather than a bare `title` column: every open issue
		// for a check has its own instance-specific message (counts,
		// urls baked into the text), so it can't be part of GROUP BY —
		// this picks one representative example, not an aggregate value
		// meant to be authoritative.
		$rows = $wpdb->get_results(
			"SELECT check_id, category, severity, MIN(title) AS sample_title, COUNT(*) AS affected_count
			 FROM {$table}
			 WHERE status = 'open'
			 GROUP BY check_id, category, severity"
		);

		$groups = array();

		foreach ( $rows as $row ) {
			$weight = isset( self::SEVERITY_WEIGHTS[ $row->severity ] ) ? self::SEVERITY_WEIGHTS[ $row->severity ] : 1;

			$groups[] = array(
				'check_id'       => $row->check_id,
				'category'       => $row->category,
				'severity'       => $row->severity,
				'sample_title'   => $row->sample_title,
				'affected_count' => (int) $row->affected_count,
				'impact_score'   => $weight * (int) $row->affected_count,
				'sample_urls'    => self::get_sample_urls( $table, $row->check_id ),
			);
		}

		usort(
			$groups,
			function ( $a, $b ) {
				return $b['impact_score'] <=> $a['impact_score'];
			}
		);

		return array_slice( $groups, 0, $limit );
	}

	/**
	 * @return string[]
	 */
	private static function get_sample_urls( $table, $check_id ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url FROM {$table} WHERE check_id = %s AND status = 'open' ORDER BY last_detected DESC LIMIT %d",
				$check_id,
				self::SAMPLE_URL_LIMIT
			)
		);
	}
}
