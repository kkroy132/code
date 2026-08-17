<?php
/**
 * Check results and the scoring model.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds normalised check results and turns them into scores.
 *
 * A check is a single named test, such as "meta descriptions". It has one
 * status and an optional list of affected items. Scores are calculated from
 * check statuses, never from the number of affected items, so a site with one
 * problem repeated on 500 pages is not scored as 500 separate failures.
 *
 * @since 1.0.0
 */
class WPSTK_Check {

	/**
	 * Maximum number of affected items stored per check.
	 */
	const MAX_ITEMS = 25;

	/**
	 * Returns the supported statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'critical', 'warning', 'recommendation', 'passed', 'skipped' );
	}

	/**
	 * Returns the score weight of each status.
	 *
	 * @return array
	 */
	public static function status_weights() {
		return array(
			'passed'         => 1.0,
			'recommendation' => 0.85,
			'warning'        => 0.5,
			'critical'       => 0.0,
		);
	}

	/**
	 * Returns the translated label for a status.
	 *
	 * @param string $status Status key.
	 *
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case 'critical':
				return __( 'Critical', 'wp-site-toolkit' );
			case 'warning':
				return __( 'Warning', 'wp-site-toolkit' );
			case 'recommendation':
				return __( 'Recommendation', 'wp-site-toolkit' );
			case 'passed':
				return __( 'Passed', 'wp-site-toolkit' );
			default:
				return __( 'Not checked', 'wp-site-toolkit' );
		}
	}

	/**
	 * Creates a normalised check result.
	 *
	 * @param array $args {
	 *     Check definition.
	 *
	 *     @type string $id      Check identifier, unique within the module.
	 *     @type string $module  Module identifier.
	 *     @type string $status  One of critical, warning, recommendation, passed, skipped.
	 *     @type string $label   Human readable check name.
	 *     @type string $summary What was found ("what is wrong?").
	 *     @type string $why     Why it matters.
	 *     @type string $action  What to do about it.
	 *     @type string $note    Optional caveat shown in smaller text.
	 *     @type array  $items   Affected items.
	 *     @type int    $items_total Total number of affected items before truncation.
	 *     @type float  $weight  Relative weight in the module score.
	 * }
	 *
	 * @return array
	 */
	public static function make( $args ) {
		$defaults = array(
			'id'          => '',
			'module'      => '',
			'status'      => 'passed',
			'label'       => '',
			'summary'     => '',
			'why'         => '',
			'action'      => '',
			'note'        => '',
			'items'       => array(),
			'items_total' => null,
			'weight'      => 1.0,
		);

		$check = array_merge( $defaults, is_array( $args ) ? $args : array() );

		if ( ! in_array( $check['status'], self::statuses(), true ) ) {
			$check['status'] = 'passed';
		}

		$items = array();

		foreach ( (array) $check['items'] as $item ) {
			$items[] = self::item( $item );
		}

		if ( null === $check['items_total'] ) {
			$check['items_total'] = count( $items );
		}

		$check['items_total'] = max( 0, (int) $check['items_total'] );
		$check['items']       = array_slice( $items, 0, self::MAX_ITEMS );
		$check['weight']      = max( 0.1, (float) $check['weight'] );

		return $check;
	}

	/**
	 * Normalises a single affected item.
	 *
	 * @param array $item Raw item.
	 *
	 * @return array
	 */
	public static function item( $item ) {
		$defaults = array(
			'label'      => '',
			'detail'     => '',
			'url'        => '',
			'link'       => '',
			'link_label' => '',
		);

		$item = array_merge( $defaults, is_array( $item ) ? $item : array() );

		$item['label']      = (string) $item['label'];
		$item['detail']     = (string) $item['detail'];
		$item['url']        = (string) $item['url'];
		$item['link']       = (string) $item['link'];
		$item['link_label'] = (string) $item['link_label'];

		return $item;
	}

	/**
	 * Builds an item that points at the post editor.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Post title.
	 * @param string $detail  Extra detail.
	 *
	 * @return array
	 */
	public static function post_item( $post_id, $title, $detail = '' ) {
		$post_id = (int) $post_id;
		$title   = '' !== trim( (string) $title ) ? $title : __( '(no title)', 'wp-site-toolkit' );

		return self::item(
			array(
				'label'      => $title,
				'detail'     => $detail,
				'url'        => (string) get_permalink( $post_id ),
				'link'       => (string) get_edit_post_link( $post_id, 'raw' ),
				'link_label' => __( 'Edit', 'wp-site-toolkit' ),
			)
		);
	}

	/**
	 * Calculates a 0-100 score from a list of checks.
	 *
	 * Skipped checks are excluded from the calculation.
	 *
	 * @param array[] $checks Check results.
	 *
	 * @return int|null Null when nothing could be scored.
	 */
	public static function score( $checks ) {
		$weights = self::status_weights();
		$earned  = 0.0;
		$total   = 0.0;

		foreach ( (array) $checks as $check ) {
			$status = isset( $check['status'] ) ? $check['status'] : 'passed';

			if ( ! isset( $weights[ $status ] ) ) {
				continue;
			}

			$weight  = isset( $check['weight'] ) ? (float) $check['weight'] : 1.0;
			$total  += $weight;
			$earned += $weight * $weights[ $status ];
		}

		if ( $total <= 0 ) {
			return null;
		}

		return (int) max( 0, min( 100, round( ( $earned / $total ) * 100 ) ) );
	}

	/**
	 * Counts checks by status.
	 *
	 * @param array[] $checks Check results.
	 *
	 * @return array
	 */
	public static function count_by_status( $checks ) {
		$counts = array(
			'critical'       => 0,
			'warning'        => 0,
			'recommendation' => 0,
			'passed'         => 0,
			'skipped'        => 0,
		);

		foreach ( (array) $checks as $check ) {
			$status = isset( $check['status'] ) ? $check['status'] : 'passed';

			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Returns a grade band for a score.
	 *
	 * @param int|null $score Score between 0 and 100.
	 *
	 * @return array {
	 *     @type string $key         Band key: excellent, good, attention, poor, unknown.
	 *     @type string $label       Short label.
	 *     @type string $description Sentence describing the band.
	 * }
	 */
	public static function grade( $score ) {
		if ( null === $score ) {
			return array(
				'key'         => 'unknown',
				'label'       => __( 'Not checked yet', 'wp-site-toolkit' ),
				'description' => __( 'Run a full site audit to see where your site stands.', 'wp-site-toolkit' ),
			);
		}

		$score = (int) $score;

		if ( $score >= 90 ) {
			return array(
				'key'         => 'excellent',
				'label'       => __( 'Excellent', 'wp-site-toolkit' ),
				'description' => __( 'Everything the toolkit checks looks healthy.', 'wp-site-toolkit' ),
			);
		}

		if ( $score >= 75 ) {
			return array(
				'key'         => 'good',
				'label'       => __( 'Good', 'wp-site-toolkit' ),
				'description' => __( 'Good — but some issues need attention.', 'wp-site-toolkit' ),
			);
		}

		if ( $score >= 50 ) {
			return array(
				'key'         => 'attention',
				'label'       => __( 'Needs attention', 'wp-site-toolkit' ),
				'description' => __( 'Several checks did not pass. Start with the critical items.', 'wp-site-toolkit' ),
			);
		}

		return array(
			'key'         => 'poor',
			'label'       => __( 'Needs work', 'wp-site-toolkit' ),
			'description' => __( 'Many checks did not pass. The list below is ordered by severity.', 'wp-site-toolkit' ),
		);
	}
}
