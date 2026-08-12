<?php
/**
 * "Content Decay" (Step 1 §12): compares GSC click volume between two
 * equal trailing windows per page, flags a significant decline. A plain
 * percentage-change rule against real data — same "no fake metrics"
 * principle as Opportunity_Finder.
 *
 * @package SEODocPro
 */

namespace SEODocPro\Gsc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Decay {

	const DECLINE_THRESHOLD_PERCENT = 30;

	/** Ignore pages with too little prior traffic for a % change to be meaningful. */
	const MIN_PRIOR_CLICKS = 20;

	/**
	 * @param int $window_days Length of each comparison window.
	 * @return array<int, array{page: string, prior_clicks: int, recent_clicks: int, change_percent: float}>
	 */
	public static function find( $window_days = 90 ) {
		$recent = self::clicks_by_page( 0, $window_days );
		$prior  = self::clicks_by_page( $window_days, $window_days );

		if ( is_wp_error( $recent ) || is_wp_error( $prior ) ) {
			return array();
		}

		$decayed = array();

		foreach ( $prior as $page => $prior_clicks ) {
			if ( $prior_clicks < self::MIN_PRIOR_CLICKS ) {
				continue;
			}

			$recent_clicks = isset( $recent[ $page ] ) ? $recent[ $page ] : 0;
			$change        = ( $recent_clicks - $prior_clicks ) / $prior_clicks * 100;

			if ( $change <= -self::DECLINE_THRESHOLD_PERCENT ) {
				$decayed[] = array(
					'page'           => $page,
					'prior_clicks'   => $prior_clicks,
					'recent_clicks'  => $recent_clicks,
					'change_percent' => round( $change, 1 ),
				);
			}
		}

		usort(
			$decayed,
			function ( $a, $b ) {
				return $a['change_percent'] <=> $b['change_percent'];
			}
		);

		return $decayed;
	}

	/**
	 * @return array<string, int>|\WP_Error page URL => total clicks.
	 */
	private static function clicks_by_page( $offset_days, $window_days ) {
		$start = $offset_days + $window_days;
		$end   = $offset_days + 1;

		$result = Gsc_Client::query_search_analytics(
			array(
				'start_date' => gmdate( 'Y-m-d', strtotime( "-{$start} days" ) ),
				'end_date'   => gmdate( 'Y-m-d', strtotime( "-{$end} days" ) ),
				'dimensions' => array( 'page' ),
				'row_limit'  => 500,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$clicks = array();
		foreach ( ( isset( $result['rows'] ) ? $result['rows'] : array() ) as $row ) {
			$page = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
			if ( '' === $page ) {
				continue;
			}
			$clicks[ $page ] = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
		}

		return $clicks;
	}
}
