<?php
/**
 * "SEO Opportunity Finder" (Step 1 §11): pages ranking just outside
 * page one with meaningful impressions — a documented threshold rule
 * against real GSC data, not a ranking prediction or a guarantee (the
 * brief explicitly rules out "fake guarantees about ranking improvements").
 *
 * @package SEODocPro
 */

namespace SEODocPro\Gsc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opportunity_Finder {

	const MIN_IMPRESSIONS = 500;

	const POSITION_MIN = 8;

	const POSITION_MAX = 20;

	/**
	 * @param int $days Trailing window to query, ending yesterday (GSC
	 *                   data typically lags 1-3 days).
	 * @return array<int, array{page: string, position: float, impressions: int, clicks: int, ctr: float}>
	 */
	public static function find( $days = 28 ) {
		$result = Gsc_Client::query_search_analytics(
			array(
				'start_date' => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
				'end_date'   => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				'dimensions' => array( 'page' ),
				'row_limit'  => 500,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['rows'] ) ) {
			return array();
		}

		$opportunities = array();

		foreach ( $result['rows'] as $row ) {
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 0.0;
			$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;

			if ( $position < self::POSITION_MIN || $position > self::POSITION_MAX ) {
				continue;
			}
			if ( $impressions < self::MIN_IMPRESSIONS ) {
				continue;
			}

			$opportunities[] = array(
				'page'        => isset( $row['keys'][0] ) ? $row['keys'][0] : '',
				'position'    => round( $position, 1 ),
				'impressions' => $impressions,
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? round( $row['ctr'] * 100, 2 ) : 0.0,
			);
		}

		usort(
			$opportunities,
			function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);

		return $opportunities;
	}
}
