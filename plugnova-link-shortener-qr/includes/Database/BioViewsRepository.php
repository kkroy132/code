<?php
/**
 * Data access layer for the wp_qlqr_bio_views table (page-view events for a Smart Bio Link page,
 * as opposed to wp_qlqr_bio_links.clicks which tallies clicks on individual buttons).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This file is the data-access layer for the plugin's own custom tables, so two findings are
 * structural here rather than defects, and are declared once at file level instead of being
 * repeated on every query:
 *
 *  - DirectDatabaseQuery.DirectQuery  — custom tables have no WP_Query/get_posts() equivalent;
 *    direct $wpdb access is the only way to read them.
 *  - DirectDatabaseQuery.NoCaching    — these back click/view analytics that must reflect writes
 *    immediately; a stale object-cache read would report wrong numbers.
 *
 * Table names (from Installer::*_table(), i.e. $wpdb->prefix plus a hardcoded suffix — never
 * caller-supplied) and the GROUP BY/dimension column in top_by_dimension() are bound through
 * prepare()'s %i identifier placeholder rather than interpolated into the query string, so every
 * query here is genuinely prepared and no PreparedSQL suppression is needed.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class BioViewsRepository
 */
final class BioViewsRepository {

	/**
	 * Record a single page-view event.
	 *
	 * @param array<string, mixed> $data Column => value pairs, already sanitized by the caller.
	 * @return int Inserted view ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$defaults = array(
			'viewed_at' => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		$inserted = $wpdb->insert( Installer::bio_views_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Views grouped by day for the last N days, for a single bio page (the "Views over time" chart).
	 *
	 * @param int  $bio_page_id  Bio page ID.
	 * @param int  $days         Number of days to look back.
	 * @param bool $exclude_bots Whether to exclude detected bot/crawler visits. Default true.
	 * @return array<int, array{date:string, views:int}>
	 */
	public function views_by_day( int $bio_page_id, int $days = 30, bool $exclude_bots = true ): array {
		global $wpdb;
		$table = Installer::bio_views_table();

		$since = current_datetime()->modify( '-' . max( 0, $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );

		// $exclude_bots selects one of two complete literal queries rather than concatenating an
		// "AND is_bot = 0" fragment onto a shared string.
		if ( $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(viewed_at) AS d, COUNT(id) AS c FROM %i WHERE bio_page_id = %d AND viewed_at >= %s AND is_bot = 0 GROUP BY d ORDER BY d ASC',
					$table,
					$bio_page_id,
					$since
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(viewed_at) AS d, COUNT(id) AS c FROM %i WHERE bio_page_id = %d AND viewed_at >= %s GROUP BY d ORDER BY d ASC',
					$table,
					$bio_page_id,
					$since
				),
				ARRAY_A
			);
		}

		$by_date = array();
		foreach ( (array) $rows as $row ) {
			$by_date[ $row['d'] ] = (int) $row['c'];
		}

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = current_datetime()->modify( "-{$i} days" )->format( 'Y-m-d' );
			$series[] = array(
				'date'  => $date,
				'views' => $by_date[ $date ] ?? 0,
			);
		}

		return $series;
	}

	/**
	 * The "viewed_at >=" cutoff timestamp for the last N days, or null for all-time — mirrors
	 * ClicksRepository::since_value() so a date-range picker scopes bio-page stat tiles the same
	 * way it scopes short-link ones. Callers branch on null-ness to choose between two complete
	 * literal queries rather than concatenating a "AND viewed_at >= %s" fragment onto a shared one.
	 *
	 * @param int $days Number of trailing days, or 0 for all-time.
	 * @return string|null
	 */
	private function since_value( int $days ): ?string {
		if ( $days <= 0 ) {
			return null;
		}

		return current_datetime()->modify( '-' . max( 0, $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );
	}

	/**
	 * Aggregate view counts for a bio page grouped by a dimension column (country, device,
	 * browser, operating_system, referrer, source, utm_source, utm_medium, utm_campaign).
	 *
	 * @param int    $bio_page_id  Bio page ID.
	 * @param string $column       Column to group by. Must be in the allow-list.
	 * @param int    $limit        Max rows to return.
	 * @param bool   $exclude_bots Whether to exclude detected bot/crawler visits. Default true.
	 * @param int    $days         Restrict to the last N days, or 0 for all-time (default). See
	 *                               ClicksRepository::top_by_dimension()'s matching parameter.
	 * @return array<int, array{label:string, views:int}>
	 */
	public function top_by_dimension( int $bio_page_id, string $column, int $limit = 10, bool $exclude_bots = true, int $days = 0 ): array {
		global $wpdb;

		$allowed = array( 'country', 'device', 'browser', 'operating_system', 'referrer', 'source', 'utm_source', 'utm_medium', 'utm_campaign' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table = Installer::bio_views_table();
		$since = $this->since_value( $days );

		// $column is bound via %i (validated against the $allowed list above, kept as defence in
		// depth); $exclude_bots/$days select one of four complete literal queries rather than
		// concatenating an "AND is_bot = 0" / "AND viewed_at >= %s" fragment onto a shared string.
		if ( $exclude_bots && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS views FROM %i WHERE bio_page_id = %d AND %i IS NOT NULL AND %i != '' AND is_bot = 0 AND viewed_at >= %s GROUP BY %i ORDER BY views DESC LIMIT %d",
					$column,
					$table,
					$bio_page_id,
					$column,
					$column,
					$since,
					$column,
					$limit
				),
				ARRAY_A
			);
		} elseif ( $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS views FROM %i WHERE bio_page_id = %d AND %i IS NOT NULL AND %i != '' AND is_bot = 0 GROUP BY %i ORDER BY views DESC LIMIT %d",
					$column,
					$table,
					$bio_page_id,
					$column,
					$column,
					$column,
					$limit
				),
				ARRAY_A
			);
		} elseif ( null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS views FROM %i WHERE bio_page_id = %d AND %i IS NOT NULL AND %i != '' AND viewed_at >= %s GROUP BY %i ORDER BY views DESC LIMIT %d",
					$column,
					$table,
					$bio_page_id,
					$column,
					$column,
					$since,
					$column,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS views FROM %i WHERE bio_page_id = %d AND %i IS NOT NULL AND %i != '' GROUP BY %i ORDER BY views DESC LIMIT %d",
					$column,
					$table,
					$bio_page_id,
					$column,
					$column,
					$column,
					$limit
				),
				ARRAY_A
			);
		}

		return array_map(
			static fn( array $row ): array => array(
				'label' => (string) $row['label'],
				'views' => (int) $row['views'],
			),
			(array) $rows
		);
	}

	/**
	 * Count of non-bot views for a bio page with no referrer at all ("Direct" traffic) — mirrors
	 * ClicksRepository::direct_referrer_count() for the same reason (top_by_dimension('referrer',
	 * ...) excludes empty-referrer rows entirely).
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @param int $days        Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function direct_referrer_count( int $bio_page_id, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::bio_views_table();
		$since = $this->since_value( $days );

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND (referrer IS NULL OR referrer = '') AND source != 'qr' AND is_bot = 0 AND viewed_at >= %s", $table, $bio_page_id, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND (referrer IS NULL OR referrer = '') AND source != 'qr' AND is_bot = 0", $table, $bio_page_id )
		);
	}

	/**
	 * Views that arrived from a scanned QR code and carried no referrer — the bio page's
	 * counterpart to ClicksRepository::qr_referrer_count(); see that method for why these are split
	 * out of "Direct".
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @param int $days        Restrict to the last N days, or 0 for all-time.
	 * @return int
	 */
	public function qr_referrer_count( int $bio_page_id, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::bio_views_table();
		$since = $this->since_value( $days );

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND (referrer IS NULL OR referrer = '') AND source = 'qr' AND is_bot = 0 AND viewed_at >= %s", $table, $bio_page_id, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND (referrer IS NULL OR referrer = '') AND source = 'qr' AND is_bot = 0", $table, $bio_page_id )
		);
	}

	/**
	 * Total non-bot views for a bio page, optionally restricted to one traffic source
	 * ('qr' = came from a scanned QR code, 'link' = direct link visit).
	 *
	 * @param int         $bio_page_id Bio page ID.
	 * @param string|null $source      'qr', 'link', or null for all sources.
	 * @param int         $days        Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function count( int $bio_page_id, ?string $source = null, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::bio_views_table();
		$since = $this->since_value( $days );

		if ( null !== $source && null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND source = %s AND is_bot = 0 AND viewed_at >= %s', $table, $bio_page_id, $source, $since )
			);
		}

		if ( null !== $source ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND source = %s AND is_bot = 0', $table, $bio_page_id, $source )
			);
		}

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND is_bot = 0 AND viewed_at >= %s', $table, $bio_page_id, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND is_bot = 0', $table, $bio_page_id )
		);
	}

	/**
	 * Distinct-visitor view count for a bio page (unique by IP hash), the "Unique Views" headline
	 * figure — simpler than ClicksRepository's lifetime-unique is_unique flag since a fresh
	 * COUNT(DISTINCT) per request is cheap enough at bio-page traffic volumes.
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @param int  $days       Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function unique_view_count( int $bio_page_id, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::bio_views_table();
		$since = $this->since_value( $days );

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(DISTINCT ip_hash) FROM %i WHERE bio_page_id = %d AND is_bot = 0 AND ip_hash IS NOT NULL AND ip_hash != '' AND viewed_at >= %s", $table, $bio_page_id, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT ip_hash) FROM %i WHERE bio_page_id = %d AND is_bot = 0 AND ip_hash IS NOT NULL AND ip_hash != ''", $table, $bio_page_id )
		);
	}

	/**
	 * Total bot/crawler views recorded for a bio page (logged for transparency but excluded from
	 * headline counts) — the bio-page equivalent of ClicksRepository::bot_click_count(), which
	 * previously had no counterpart here.
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @param int  $days       Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function bot_view_count( int $bio_page_id, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::bio_views_table();
		$since = $this->since_value( $days );

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND is_bot = 1 AND viewed_at >= %s', $table, $bio_page_id, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d AND is_bot = 1', $table, $bio_page_id )
		);
	}

	/**
	 * Delete all view records for a bio page. Called when a bio page is permanently deleted.
	 *
	 * @param int $bio_page_id Bio page ID.
	 */
	public function delete_for_page( int $bio_page_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::bio_views_table(), array( 'bio_page_id' => $bio_page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete view rows older than the retention window, in bounded batches.
	 *
	 * This table is the one that grows without limit: a busy link earns a row per visit forever,
	 * and nothing ever removed them. At a thousand views a day that is over a third of a million
	 * rows a year, which eventually makes both the analytics queries and the site's backups slow.
	 *
	 * Deleting in batches rather than one statement keeps the lock short enough not to stall the
	 * requests happening at the same time — the first sweep on a site that has been running for a
	 * while may have a great deal to remove, and a single DELETE of that size can block the table
	 * for seconds.
	 *
	 * Headline totals are unaffected: each bio page carries its own total_views/qr_views counters on wp_qlqr_bio_pages so old rows can go without changing any number
	 * the user sees as a lifetime figure. What is lost is the per-day and per-dimension detail
	 * beyond the window.
	 *
	 * @param int $months Retention window in months. 0 or less keeps everything and does nothing.
	 * @return int Rows deleted.
	 */
	public function prune( int $months ): int {
		if ( $months <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table  = Installer::bio_views_table();
		$cutoff = current_datetime()->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );

		$deleted = 0;

		// Capped so a single cron run cannot spend unbounded time here; whatever is left is taken
		// by the next day's run.
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$removed = $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE viewed_at < %s LIMIT 1000', $table, $cutoff )
			);

			if ( ! $removed ) {
				break;
			}

			$deleted += (int) $removed;
		}

		return $deleted;
	}
}
