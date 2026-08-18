<?php
/**
 * Computes the "Smart Link Score" — an at-a-glance health/quality checklist for a link, covering
 * security (HTTPS), reachability (live/broken/redirect-loop/response time, all from
 * Helpers\BrokenLinkChecker), and marketing hygiene (SEO-friendly slug, UTM parameters, a
 * discoverable page title). Every input already lives on the Link row, so scoring never needs an
 * extra database query or network request beyond the broken-link check that already runs.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

use QuickLinkQRPro\Models\Link;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LinkScoreCalculator
 */
final class LinkScoreCalculator {

	/**
	 * Sum of every possible positive check's points — the denominator for the percentage score.
	 * The two "warning" checks (missing UTM / missing meta title) are penalties only and don't
	 * add to this ceiling; a link with neither penalty simply keeps whatever it earned above.
	 */
	private const MAX_SCORE = 75;

	/**
	 * Response time, in milliseconds, at or under which the "Response Time" check passes.
	 */
	private const FAST_RESPONSE_MS = 1000;

	/**
	 * Calculate the full score breakdown for a link.
	 *
	 * @param Link $link Link to score. Multi-destination links are still scored (HTTPS/slug/UTM
	 *                    checks still apply to their primary destination_url), but the
	 *                    reachability-derived checks (Live/Response Time/Redirect Loop/Meta
	 *                    Title/Broken) will always show as "not checked yet", since
	 *                    Helpers\BrokenLinkChecker skips multi-destination links entirely.
	 * @return array{score:int, max_score:int, percentage:int, grade:string, checks:array<int, array{label:string, passed:bool|null, points:int, type:string}>}
	 */
	public static function calculate( Link $link ): array {
		$checked = 'unknown' !== $link->link_status;
		$checks  = array();
		$score   = 0;

		$https = 0 === stripos( $link->destination_url, 'https://' );
		$checks[] = self::bonus( __( 'Uses HTTPS', 'plugnova-link-shortener-qr' ), $https, 10 );
		$score   += $https ? 10 : 0;

		if ( $checked ) {
			$live = 'ok' === $link->link_status;
			$checks[] = self::bonus( __( 'URL is Live', 'plugnova-link-shortener-qr' ), $live, 15 );
			$score   += $live ? 15 : 0;
		} else {
			$checks[] = self::pending( __( 'URL is Live', 'plugnova-link-shortener-qr' ) );
		}

		if ( null !== $link->response_time_ms ) {
			$fast = $link->response_time_ms <= self::FAST_RESPONSE_MS;
			$checks[] = self::bonus(
				sprintf(
					/* translators: %d: response time in milliseconds */
					__( 'Response Time %dms', 'plugnova-link-shortener-qr' ),
					$link->response_time_ms
				),
				$fast,
				10
			);
			$score += $fast ? 10 : 0;
		} else {
			$checks[] = self::pending( __( 'Response Time', 'plugnova-link-shortener-qr' ) );
		}

		if ( $checked ) {
			$no_loop = ! $link->has_redirect_loop;
			$checks[] = self::bonus( __( 'No Redirect Loop', 'plugnova-link-shortener-qr' ), $no_loop, 10 );
			$score   += $no_loop ? 10 : 0;
		} else {
			$checks[] = self::pending( __( 'No Redirect Loop', 'plugnova-link-shortener-qr' ) );
		}

		$seo_friendly = self::is_seo_friendly_slug( $link->short_slug );
		$checks[] = self::bonus( __( 'SEO Friendly Slug', 'plugnova-link-shortener-qr' ), $seo_friendly, 10 );
		$score   += $seo_friendly ? 10 : 0;

		if ( $checked ) {
			$not_broken = ! $link->is_broken();
			$checks[] = self::bonus( __( 'No Broken Link', 'plugnova-link-shortener-qr' ), $not_broken, 20 );
			$score   += $not_broken ? 20 : 0;
		} else {
			$checks[] = self::pending( __( 'No Broken Link', 'plugnova-link-shortener-qr' ) );
		}

		$has_utm = ! empty( $link->utm_source ) || ! empty( $link->utm_medium ) || ! empty( $link->utm_campaign );
		$checks[] = self::penalty(
			__( 'UTM Parameters Set', 'plugnova-link-shortener-qr' ),
			__( 'UTM Parameters missing', 'plugnova-link-shortener-qr' ),
			$has_utm,
			5
		);
		$score += $has_utm ? 0 : -5;

		if ( $checked ) {
			$has_title = ! empty( $link->meta_title );
			$checks[] = self::penalty(
				__( 'Meta Title Found', 'plugnova-link-shortener-qr' ),
				__( 'Meta Title not found', 'plugnova-link-shortener-qr' ),
				$has_title,
				10
			);
			$score += $has_title ? 0 : -10;
		} else {
			$checks[] = self::pending( __( 'Meta Title', 'plugnova-link-shortener-qr' ) );
		}

		$percentage = (int) round( max( 0, min( self::MAX_SCORE, $score ) ) / self::MAX_SCORE * 100 );

		return array(
			'score'      => $score,
			'max_score'  => self::MAX_SCORE,
			'percentage' => $percentage,
			'grade'      => self::grade_for( $percentage ),
			'checks'     => $checks,
		);
	}

	/**
	 * Letter grade for a percentage score, for a compact table-row badge.
	 *
	 * @param int $percentage 0-100.
	 * @return string
	 */
	private static function grade_for( int $percentage ): string {
		return match ( true ) {
			$percentage >= 90 => 'A',
			$percentage >= 75 => 'B',
			$percentage >= 50 => 'C',
			$percentage >= 25 => 'D',
			default            => 'F',
		};
	}

	/**
	 * A checklist entry that earns points when passed and nothing when it doesn't (never negative).
	 *
	 * @param string $label           Human-readable description.
	 * @param bool   $passed          Whether the check passed.
	 * @param int    $points_possible Points earned if $passed is true.
	 * @return array{label:string, passed:bool, points:int, type:string}
	 */
	private static function bonus( string $label, bool $passed, int $points_possible ): array {
		return array(
			'label'  => $label,
			'passed' => $passed,
			'points' => $passed ? $points_possible : 0,
			'type'   => 'bonus',
		);
	}

	/**
	 * A checklist entry that costs points when it *fails* (e.g. a missing recommended field),
	 * and costs nothing when it passes. Takes separate pass/fail labels (rather than one label
	 * plus a checkmark) so the passing case reads as a positive statement instead of an odd
	 * double-negative like "✅ UTM Parameters missing".
	 *
	 * @param string $ok_label      Label shown when the check passes (field is present).
	 * @param string $missing_label Label shown when the check fails (field is missing) — this is
	 *                               the one that actually names the penalty being applied.
	 * @param bool   $passed        Whether the field/condition being checked for IS present.
	 * @param int    $penalty       Points deducted (as a positive number) when $passed is false.
	 * @return array{label:string, passed:bool, points:int, type:string}
	 */
	private static function penalty( string $ok_label, string $missing_label, bool $passed, int $penalty ): array {
		return array(
			'label'  => $passed ? $ok_label : $missing_label,
			'passed' => $passed,
			'points' => $passed ? 0 : -$penalty,
			'type'   => 'penalty',
		);
	}

	/**
	 * A checklist entry that can't be evaluated yet (destination has never been checked).
	 *
	 * @param string $label Human-readable description.
	 * @return array{label:string, passed:null, points:int, type:string}
	 */
	private static function pending( string $label ): array {
		return array(
			'label'  => $label,
			'passed' => null,
			'points' => 0,
			'type'   => 'pending',
		);
	}

	/**
	 * Best-effort heuristic for "reads like a deliberate, human-chosen slug" rather than a random
	 * auto-generated one. Helpers\SlugGenerator's random alphabet mixes upper/lowercase letters
	 * and digits with no hyphens, so an all-lowercase slug — especially a hyphenated multi-word
	 * one — is a strong (if not perfectly certain) signal that an admin typed a custom slug.
	 *
	 * @param string $slug Short slug to evaluate.
	 * @return bool
	 */
	private static function is_seo_friendly_slug( string $slug ): bool {
		if ( '' === $slug || $slug !== strtolower( $slug ) ) {
			return false;
		}

		if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
			return false;
		}

		return str_contains( $slug, '-' ) || strlen( $slug ) >= 8;
	}
}
