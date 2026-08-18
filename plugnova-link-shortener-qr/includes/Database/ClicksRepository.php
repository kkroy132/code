<?php
/**
 * Data access layer for the wp_qlqr_clicks table.
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
 * query here is genuinely prepared and no PreparedSQL suppression is needed — except the two
 * *_multi() batch lookups below, whose IN (...) placeholder count is inherently dynamic (one %d
 * per ID in the caller-supplied array) and so cannot be statically counted by PHPCS; those keep a
 * narrowly-scoped PreparedSQLPlaceholders ignore explaining exactly why.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class ClicksRepository
 */
final class ClicksRepository {

	/**
	 * Record a single click event.
	 *
	 * @param array<string, mixed> $data Column => value pairs, already sanitized by the caller.
	 * @return int Inserted click ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$defaults = array(
			'clicked_at' => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		$inserted = $wpdb->insert( Installer::clicks_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Whether this ip_hash has already generated a non-bot click on this link before now.
	 * Used to determine "is_unique" at insert time (lifetime-unique, not per-day).
	 *
	 * @param int    $link_id Link ID.
	 * @param string $ip_hash Salted IP hash (see Helpers\IpHash).
	 * @return bool
	 */
	public function has_prior_click( int $link_id, string $ip_hash ): bool {
		if ( '' === $ip_hash ) {
			// No usable IP (exotic proxy setup) — can't dedupe, so treat every visit as unique
			// rather than silently under-counting.
			return false;
		}

		global $wpdb;
		$table = Installer::clicks_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(id) FROM %i WHERE link_id = %d AND ip_hash = %s AND is_bot = 0 LIMIT 1',
				$table,
				$link_id,
				$ip_hash
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Clicks grouped by day for the last N days (for the "Clicks by Day" chart).
	 *
	 * @param int      $days          Number of days to look back.
	 * @param int|null $link_id       Optionally restrict to a single link.
	 * @param bool     $exclude_bots  Whether to exclude detected bot/crawler visits. Default true.
	 * @return array<int, array{date:string, clicks:int}>
	 */
	public function clicks_by_day( int $days = 30, ?int $link_id = null, bool $exclude_bots = true ): array {
		global $wpdb;
		$table = Installer::clicks_table();

		$since = current_datetime()->modify( '-' . max( 0, $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );

		// $link_id/$exclude_bots select one of four complete literal queries rather than
		// concatenating "AND link_id = %d" / "AND is_bot = 0" fragments onto a shared string.
		if ( $link_id && $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(clicked_at) AS d, COUNT(id) AS c FROM %i WHERE clicked_at >= %s AND link_id = %d AND is_bot = 0 GROUP BY d ORDER BY d ASC',
					$table,
					$since,
					$link_id
				),
				ARRAY_A
			);
		} elseif ( $link_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(clicked_at) AS d, COUNT(id) AS c FROM %i WHERE clicked_at >= %s AND link_id = %d GROUP BY d ORDER BY d ASC',
					$table,
					$since,
					$link_id
				),
				ARRAY_A
			);
		} elseif ( $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(clicked_at) AS d, COUNT(id) AS c FROM %i WHERE clicked_at >= %s AND is_bot = 0 GROUP BY d ORDER BY d ASC',
					$table,
					$since
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(clicked_at) AS d, COUNT(id) AS c FROM %i WHERE clicked_at >= %s GROUP BY d ORDER BY d ASC',
					$table,
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
			$date            = current_datetime()->modify( "-{$i} days" )->format( 'Y-m-d' );
			$series[]        = array(
				'date'   => $date,
				'clicks' => $by_date[ $date ] ?? 0,
			);
		}

		return $series;
	}

	/**
	 * Aggregate click counts grouped by a dimension column (country, device, browser, referrer,
	 * operating_system, utm_source, utm_medium, utm_campaign, source).
	 *
	 * @param string   $column       Column to group by. Must be in the allow-list.
	 * @param int      $limit        Max rows to return.
	 * @param int|null $link_id      Optionally restrict to a single link.
	 * @param bool     $exclude_bots Whether to exclude detected bot/crawler visits. Default true.
	 * @param int      $days         Restrict to the last N days, or 0 for all-time (default —
	 *                                 preserves this method's original lifetime-total behavior for
	 *                                 any caller that doesn't pass it). When non-zero, uses the same
	 *                                 rolling window as clicks_by_day(), so a breakdown table and the
	 *                                 chart above it always describe the same period.
	 * @return array<int, array{label:string, clicks:int}>
	 */
	public function top_by_dimension( string $column, int $limit = 10, ?int $link_id = null, bool $exclude_bots = true, int $days = 0 ): array {
		global $wpdb;

		$allowed = array( 'country', 'device', 'browser', 'operating_system', 'referrer', 'source', 'utm_source', 'utm_medium', 'utm_campaign' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table = Installer::clicks_table();
		$since = $days > 0 ? current_datetime()->modify( '-' . max( 0, $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' ) : null;

		// $column is bound via %i (validated against the $allowed list above, kept as defence in
		// depth); $link_id/$exclude_bots/$since select one of eight complete literal queries rather
		// than concatenating "AND link_id = %d" / "AND is_bot = 0" / "AND clicked_at >= %s"
		// fragments onto a shared string.
		if ( $link_id && $exclude_bots && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE link_id = %d AND %i IS NOT NULL AND %i != '' AND is_bot = 0 AND clicked_at >= %s GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
					$link_id,
					$column,
					$column,
					$since,
					$column,
					$limit
				),
				ARRAY_A
			);
		} elseif ( $link_id && $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE link_id = %d AND %i IS NOT NULL AND %i != '' AND is_bot = 0 GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
					$link_id,
					$column,
					$column,
					$column,
					$limit
				),
				ARRAY_A
			);
		} elseif ( $link_id && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE link_id = %d AND %i IS NOT NULL AND %i != '' AND clicked_at >= %s GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
					$link_id,
					$column,
					$column,
					$since,
					$column,
					$limit
				),
				ARRAY_A
			);
		} elseif ( $link_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE link_id = %d AND %i IS NOT NULL AND %i != '' GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
					$link_id,
					$column,
					$column,
					$column,
					$limit
				),
				ARRAY_A
			);
		} elseif ( $exclude_bots && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE %i IS NOT NULL AND %i != '' AND is_bot = 0 AND clicked_at >= %s GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
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
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE %i IS NOT NULL AND %i != '' AND is_bot = 0 GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
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
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE %i IS NOT NULL AND %i != '' AND clicked_at >= %s GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
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
					"SELECT %i AS label, COUNT(id) AS clicks FROM %i WHERE %i IS NOT NULL AND %i != '' GROUP BY %i ORDER BY clicks DESC LIMIT %d",
					$column,
					$table,
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
				'label'  => (string) $row['label'],
				'clicks' => (int) $row['clicks'],
			),
			(array) $rows
		);
	}

	/**
	 * Count of non-bot clicks with no referrer at all ("Direct" traffic — typed/bookmarked URLs,
	 * or a referrer the browser stripped) — top_by_dimension('referrer', ...) excludes these rows
	 * entirely (it filters referrer != ''), so this is how the Referrer breakdown gets its "Direct"
	 * row instead of silently omitting a large chunk of real traffic.
	 *
	 * @param int|null $link_id Optionally restrict to a single link.
	 * @param int      $days    Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function direct_referrer_count( ?int $link_id = null, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		if ( $link_id && null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_id = %d AND (referrer IS NULL OR referrer = '') AND source != 'qr' AND is_bot = 0 AND clicked_at >= %s", $table, $link_id, $since )
			);
		}

		if ( $link_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_id = %d AND (referrer IS NULL OR referrer = '') AND source != 'qr' AND is_bot = 0", $table, $link_id )
			);
		}

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE (referrer IS NULL OR referrer = '') AND source != 'qr' AND is_bot = 0 AND clicked_at >= %s", $table, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE (referrer IS NULL OR referrer = '') AND source != 'qr' AND is_bot = 0", $table )
		);
	}

	/**
	 * Count of non-bot clicks that arrived from a scanned QR code and carried no referrer — the
	 * "QR scan" row in the Referrer breakdown.
	 *
	 * A camera app sends no Referer header, so before this every QR scan landed in "Direct"
	 * alongside typed URLs and bookmarks, and the breakdown could not answer "how much of this
	 * link's traffic came from the printed code?". The three buckets are mutually exclusive and
	 * together cover every non-bot click: a referrer host, this, or Direct.
	 *
	 * @param int|null $link_id Optionally restrict to a single link.
	 * @param int      $days    Restrict to the last N days, or 0 for all-time.
	 * @return int
	 */
	public function qr_referrer_count( ?int $link_id = null, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		if ( $link_id && null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_id = %d AND (referrer IS NULL OR referrer = '') AND source = 'qr' AND is_bot = 0 AND clicked_at >= %s", $table, $link_id, $since )
			);
		}

		if ( $link_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_id = %d AND (referrer IS NULL OR referrer = '') AND source = 'qr' AND is_bot = 0", $table, $link_id )
			);
		}

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE (referrer IS NULL OR referrer = '') AND source = 'qr' AND is_bot = 0 AND clicked_at >= %s", $table, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE (referrer IS NULL OR referrer = '') AND source = 'qr' AND is_bot = 0", $table )
		);
	}

	/**
	 * Latest N raw click events, optionally for a single link (used in the dashboard "Latest Clicks" widget).
	 *
	 * @param int      $limit        Max rows.
	 * @param int|null $link_id      Optionally restrict to a single link.
	 * @param bool     $exclude_bots Whether to exclude detected bot/crawler visits. Default true.
	 * @param int      $days         Restrict to the last N days, or 0 for all-time (default).
	 * @return array<int, array<string, mixed>>
	 */
	public function latest( int $limit = 10, ?int $link_id = null, bool $exclude_bots = true, int $days = 0 ): array {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		// $link_id/$exclude_bots/$since select one of eight complete literal queries rather than
		// concatenating "AND link_id = %d" / "AND is_bot = 0" / "AND clicked_at >= %s" fragments
		// onto a shared string.
		if ( $link_id && $exclude_bots && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d AND is_bot = 0 AND clicked_at >= %s ORDER BY clicked_at DESC LIMIT %d', $table, $link_id, $since, $limit ),
				ARRAY_A
			);
		} elseif ( $link_id && $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d AND is_bot = 0 ORDER BY clicked_at DESC LIMIT %d', $table, $link_id, $limit ),
				ARRAY_A
			);
		} elseif ( $link_id && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d AND clicked_at >= %s ORDER BY clicked_at DESC LIMIT %d', $table, $link_id, $since, $limit ),
				ARRAY_A
			);
		} elseif ( $link_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d ORDER BY clicked_at DESC LIMIT %d', $table, $link_id, $limit ),
				ARRAY_A
			);
		} elseif ( $exclude_bots && null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE is_bot = 0 AND clicked_at >= %s ORDER BY clicked_at DESC LIMIT %d', $table, $since, $limit ),
				ARRAY_A
			);
		} elseif ( $exclude_bots ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE is_bot = 0 ORDER BY clicked_at DESC LIMIT %d', $table, $limit ),
				ARRAY_A
			);
		} elseif ( null !== $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE clicked_at >= %s ORDER BY clicked_at DESC LIMIT %d', $table, $since, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY clicked_at DESC LIMIT %d', $table, $limit ),
				ARRAY_A
			);
		}

		return (array) $rows;
	}

	/**
	 * Count of distinct countries that have generated at least one non-bot click.
	 *
	 * @param int $days Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function distinct_country_count( int $days = 0 ): int {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(DISTINCT country) FROM %i WHERE country IS NOT NULL AND country != '' AND is_bot = 0 AND clicked_at >= %s", $table, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT country) FROM %i WHERE country IS NOT NULL AND country != '' AND is_bot = 0", $table )
		);
	}

	/**
	 * The "clicked_at >=" cutoff timestamp for the last N days, or null for all-time — shared by
	 * every stat-tile count method below so a date-range picker can scope them all the same way
	 * clicks_by_day()/top_by_dimension() already are. Callers branch on null-ness to choose
	 * between two complete literal queries rather than concatenating a "AND clicked_at >= %s"
	 * fragment onto a shared one.
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
	 * Total unique visitors (lifetime-unique by IP hash) across all links, or one link.
	 *
	 * @param int|null $link_id Optionally restrict to a single link.
	 * @param int      $days    Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function unique_click_count( ?int $link_id = null, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		if ( $link_id && null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE link_id = %d AND is_unique = 1 AND is_bot = 0 AND clicked_at >= %s', $table, $link_id, $since )
			);
		}

		if ( $link_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE link_id = %d AND is_unique = 1 AND is_bot = 0', $table, $link_id )
			);
		}

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_unique = 1 AND is_bot = 0 AND clicked_at >= %s', $table, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_unique = 1 AND is_bot = 0', $table )
		);
	}

	/**
	 * Total bot/crawler visits recorded (logged for transparency but excluded from headline counts).
	 *
	 * @param int|null $link_id Optionally restrict to a single link.
	 * @param int      $days    Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function bot_click_count( ?int $link_id = null, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		if ( $link_id && null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE link_id = %d AND is_bot = 1 AND clicked_at >= %s', $table, $link_id, $since )
			);
		}

		if ( $link_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE link_id = %d AND is_bot = 1', $table, $link_id )
			);
		}

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_bot = 1 AND clicked_at >= %s', $table, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_bot = 1', $table )
		);
	}

	/**
	 * Total non-bot clicks that arrived via a scanned QR code (source = 'qr'), as opposed to a
	 * directly-clicked short link. See Helpers\QrCodeGenerator's ?qlqr_src=qr marker and
	 * Controllers\RedirectController::record_click().
	 *
	 * @param int|null $link_id Optionally restrict to a single link.
	 * @param int      $days    Restrict to the last N days, or 0 for all-time (default).
	 * @return int
	 */
	public function qr_scan_count( ?int $link_id = null, int $days = 0 ): int {
		global $wpdb;
		$table = Installer::clicks_table();
		$since = $this->since_value( $days );

		if ( $link_id && null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_id = %d AND source = 'qr' AND is_bot = 0 AND clicked_at >= %s", $table, $link_id, $since )
			);
		}

		if ( $link_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_id = %d AND source = 'qr' AND is_bot = 0", $table, $link_id )
			);
		}

		if ( null !== $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE source = 'qr' AND is_bot = 0 AND clicked_at >= %s", $table, $since )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE source = 'qr' AND is_bot = 0", $table )
		);
	}

	/**
	 * QR-scan click counts for a batch of links in one query — used by the Links list table's
	 * "Traffic Source" badge and QR-scan subtext, so rendering a page of N rows costs one query
	 * instead of N.
	 *
	 * @param array<int, int> $link_ids Link IDs to look up.
	 * @param int             $days     Restrict to the last N days, or 0 for all-time (default).
	 * @return array<int, int> link_id => QR-scan click count. Links with zero QR scans are omitted.
	 */
	public function qr_scan_count_multi( array $link_ids, int $days = 0 ): array {
		$link_ids = array_values( array_filter( array_map( 'intval', $link_ids ) ) );
		if ( empty( $link_ids ) ) {
			return array();
		}

		global $wpdb;
		$table        = Installer::clicks_table();
		$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
		$since        = $this->since_value( $days );

		// {$placeholders} is a generated run of "%d" markers — one per ID — not data; every actual
		// ID (plus $table and, when present, $since) is bound through prepare() below. The IN (...)
		// placeholder count is inherently dynamic (it tracks count($link_ids)), which PHPCS cannot
		// verify statically — hence the ignores below, on the actual prepare()/get_results() call
		// rather than on this assignment, matching where PHPCS itself reports them.
		if ( null !== $since ) {
			$sql    = "SELECT link_id, COUNT(id) AS c FROM %i WHERE link_id IN ({$placeholders}) AND source = 'qr' AND is_bot = 0 AND clicked_at >= %s GROUP BY link_id";
			$params = array_merge( array( $table ), $link_ids, array( $since ) );
		} else {
			$sql    = "SELECT link_id, COUNT(id) AS c FROM %i WHERE link_id IN ({$placeholders}) AND source = 'qr' AND is_bot = 0 GROUP BY link_id";
			$params = array_merge( array( $table ), $link_ids );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$by_link = array();
		foreach ( (array) $rows as $row ) {
			$by_link[ (int) $row['link_id'] ] = (int) $row['c'];
		}

		return $by_link;
	}

	/**
	 * Non-bot click counts per day for a batch of links, over the last N days — used by the Links
	 * list table's mini "Trend" sparkline. One grouped query for the whole page of rows, bucketed
	 * in PHP afterward, rather than one clicks_by_day() call per row.
	 *
	 * @param array<int, int> $link_ids Link IDs to look up.
	 * @param int             $days     Number of trailing days to include (including today).
	 * @return array<int, array<int, array{date:string, clicks:int}>> link_id => ordered day series
	 *         (oldest first), with every link ID in $link_ids present even if all-zero.
	 */
	public function clicks_by_day_multi( array $link_ids, int $days = 7 ): array {
		$link_ids = array_values( array_filter( array_map( 'intval', $link_ids ) ) );

		$dates = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$dates[] = current_datetime()->modify( "-{$i} days" )->format( 'Y-m-d' );
		}

		$series_by_link = array();
		foreach ( $link_ids as $link_id ) {
			$series_by_link[ $link_id ] = array();
			foreach ( $dates as $date ) {
				$series_by_link[ $link_id ][ $date ] = 0;
			}
		}

		if ( empty( $link_ids ) ) {
			return array();
		}

		global $wpdb;
		$table        = Installer::clicks_table();
		$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
		$since        = current_datetime()->modify( '-' . max( 0, $days - 1 ) . ' days' )->format( 'Y-m-d 00:00:00' );

		// As in qr_scan_count_multi(): {$placeholders} is a generated run of "%d" markers, and every
		// ID (plus $table and $since) is bound through prepare() below — the IN (...) placeholder
		// count tracks count($link_ids) and so cannot be verified statically, hence the ignores on
		// the call itself rather than this assignment.
		$sql    = "SELECT link_id, DATE(clicked_at) AS d, COUNT(id) AS c FROM %i WHERE link_id IN ({$placeholders}) AND clicked_at >= %s AND is_bot = 0 GROUP BY link_id, d";
		$params = array_merge( array( $table ), $link_ids, array( $since ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( (array) $rows as $row ) {
			$link_id = (int) $row['link_id'];
			if ( isset( $series_by_link[ $link_id ][ $row['d'] ] ) ) {
				$series_by_link[ $link_id ][ $row['d'] ] = (int) $row['c'];
			}
		}

		$result = array();
		foreach ( $series_by_link as $link_id => $by_date ) {
			$result[ $link_id ] = array();
			foreach ( $by_date as $date => $clicks ) {
				$result[ $link_id ][] = array(
					'date'   => $date,
					'clicks' => $clicks,
				);
			}
		}

		return $result;
	}

	/**
	 * Delete click rows older than the retention window, in bounded batches.
	 *
	 * This table is the one that grows without limit: a busy link earns a row per visit forever,
	 * and nothing ever removed them. At a thousand clicks a day that is over a third of a million
	 * rows a year, which eventually makes both the analytics queries and the site's backups slow.
	 *
	 * Deleting in batches rather than one statement keeps the lock short enough not to stall the
	 * requests happening at the same time — the first sweep on a site that has been running for a
	 * while may have a great deal to remove, and a single DELETE of that size can block the table
	 * for seconds.
	 *
	 * Headline totals are unaffected: each link carries its own total_clicks counter on wp_qlqr_links so old rows can go without changing any number
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
		$table  = Installer::clicks_table();
		$cutoff = current_datetime()->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );

		$deleted = 0;

		// Capped so a single cron run cannot spend unbounded time here; whatever is left is taken
		// by the next day's run.
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$removed = $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE clicked_at < %s LIMIT 1000', $table, $cutoff )
			);

			if ( ! $removed ) {
				break;
			}

			$deleted += (int) $removed;
		}

		return $deleted;
	}
}
