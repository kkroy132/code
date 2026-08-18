<?php
/**
 * Shared free-plan cap for how far back analytics data can be viewed. Every analytics surface
 * (the site-wide Analytics tab, the Dashboard chart/widgets, the per-link/per-bio-page analytics
 * modals and CSV exports, and the REST API) routes its day-range value through this class, so the
 * plan check lives in exactly one place. This affects reading/displaying analytics only — it never
 * touches how long click/view data is retained (see Helpers\ActivityLogger / *Repository::prune()).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AnalyticsDays
 */
final class AnalyticsDays {

	/**
	 * How many trailing days of analytics a free-plan account can view.
	 */
	private const FREE_PLAN_DAYS = 7;

	/**
	 * The day-range choices offered by the analytics modals' <select> (and accepted from the
	 * REST API's "days" request param).
	 */
	public const ALLOWED = array( 7, 30, 90 );

	/**
	 * Validate a raw, caller-supplied day-range value (e.g. $_POST['days'] or a REST request
	 * param) against ALLOWED, then apply the free-plan cap. Use this for every surface that has
	 * a user-facing 7/30/90 day-range control.
	 *
	 * @param mixed $raw     Raw submitted value.
	 * @param int   $default Value to use when $raw isn't one of ALLOWED. Default 30, matching
	 *                        every existing day-range <select>'s default selection.
	 * @return int
	 */
	public static function sanitize( mixed $raw, int $default = 30 ): int {
		$days = in_array( $raw, self::ALLOWED, true ) ? (int) $raw : $default;

		return self::cap( $days );
	}

	/**
	 * Apply the free-plan cap to an already-known day-range value. Use this for surfaces with no
	 * user-facing day-range control, where the value passed in is whatever that surface would
	 * show by default — including 0, this codebase's convention for "all-time" (e.g.
	 * ClicksRepository::top_by_dimension()'s $days = 0 default). A Pro account's value is
	 * returned unchanged, so a caller that already asks for fewer than FREE_PLAN_DAYS (Pro or
	 * free) is never widened.
	 *
	 * @param int $days Requested day range, or 0 for all-time.
	 * @return int
	 */
	public static function cap( int $days ): int {
		if ( qlqr_fs()->can_use_premium_code() ) {
			return $days;
		}

		return ( $days <= 0 || $days > self::FREE_PLAN_DAYS ) ? self::FREE_PLAN_DAYS : $days;
	}
}
