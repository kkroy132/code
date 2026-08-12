<?php
/**
 * Minimal upsert/resolve engine, landed in Step 5 because the scanner
 * needs somewhere to persist Issues to be end-to-end functional. Step 7
 * extends this same class with SEO Health Score computation and Fix
 * First / Action Plan ranking — the storage contract here doesn't change.
 *
 * @package SEODoc
 */

namespace SEODoc\Issues;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Issue_Engine {

	/**
	 * Upserts every Issue found for one object during a scan, keyed by
	 * (check_id, url_hash) so re-scans update existing rows instead of
	 * accumulating duplicates, then resolves any previously-open issue
	 * for this object whose check no longer fired this pass (including
	 * checks that ran and found nothing — their id simply never lands in
	 * $issues, which is exactly what "resolved" means here).
	 *
	 * @param int     $scan_id
	 * @param string  $object_type
	 * @param int     $object_id
	 * @param Issue[] $issues
	 */
	public static function record( $scan_id, $object_type, $object_id, array $issues ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$seen_check_ids = self::upsert_all( $table, $scan_id, $issues );

		self::resolve_missing_for_object( $table, $object_id, $object_type, $seen_check_ids );
	}

	/**
	 * For Scan_Level_Check results (Step 6): site-wide checks and
	 * cross-object detectors like duplicate title/meta-description, where
	 * "resolved" means scoped to this one check_id rather than to a
	 * single object — a duplicate-title detector's issues span many
	 * different posts, so resolving by object doesn't apply here.
	 *
	 * @param int     $scan_id
	 * @param string  $check_id
	 * @param Issue[] $issues
	 */
	public static function record_for_check( $scan_id, $check_id, array $issues ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$seen_url_hashes = array();
		foreach ( $issues as $issue ) {
			$seen_url_hashes[] = md5( $issue->url );
		}

		self::upsert_all( $table, $scan_id, $issues );

		self::resolve_missing_for_check( $table, $check_id, $seen_url_hashes );
	}

	/**
	 * For incremental/async checkers that don't evaluate their whole
	 * domain in one pass — Step 10's Broken_Link_Checker verifies one
	 * batch of URLs per Action Scheduler tick, potentially over many
	 * ticks. record_for_check()'s "resolve everything not in this call's
	 * set" semantics would be wrong here: a small batch would incorrectly
	 * resolve hundreds of still-broken links this tick simply didn't
	 * touch. upsert_single()/resolve_single() operate on exactly one
	 * check_id+url at a time instead, with no bulk resolve side effect.
	 *
	 * @param int|null $scan_id Nullable — this isn't tied to any one scan.
	 */
	public static function upsert_single( $scan_id, Issue $issue ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		self::upsert_all( $table, $scan_id, array( $issue ) );
	}

	public static function resolve_single( $check_id, $url ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];

		$wpdb->update(
			$table,
			array(
				'status'      => 'resolved',
				'resolved_at' => current_time( 'mysql' ),
			),
			array(
				'check_id' => $check_id,
				'url_hash' => md5( $url ),
				'status'   => 'open',
			)
		);
	}

	/**
	 * @return string[] check_ids present in $issues, for the per-object
	 *                   resolve step.
	 */
	private static function upsert_all( $table, $scan_id, array $issues ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$seen_check_ids = array();

		foreach ( $issues as $issue ) {
			$url_hash         = md5( $issue->url );
			$seen_check_ids[] = $issue->check_id;

			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE check_id = %s AND url_hash = %s",
					$issue->check_id,
					$url_hash
				)
			);

			$data = array(
				'check_id'      => $issue->check_id,
				'category'      => $issue->category,
				'severity'      => $issue->severity,
				'object_type'   => $issue->object_type,
				'object_id'     => $issue->object_id,
				'url'           => $issue->url,
				'url_hash'      => $url_hash,
				'title'         => $issue->title,
				'details'       => wp_json_encode( $issue->details ),
				'status'        => 'open',
				'scan_id'       => $scan_id,
				'last_detected' => $now,
			);

			if ( $existing_id ) {
				$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			} else {
				$data['first_detected'] = $now;
				$wpdb->insert( $table, $data );
			}
		}

		return $seen_check_ids;
	}

	/**
	 * Marks this object's 'open' issues 'resolved' when their check_id
	 * isn't in $exclude_check_ids. Previously a generic method taking a
	 * raw WHERE-fragment string parameter shared with
	 * resolve_missing_for_check() — split into two self-contained
	 * methods (each with its own literal query text) after WordPress.org's
	 * Plugin Check flagged the shared version as an unprepared-query risk:
	 * a SQL fragment being passed as a function parameter is harder for
	 * static analysis to trace as safe than a literal written at the call
	 * site, even though both were always fully parameterized before
	 * execution.
	 *
	 * @param string   $table
	 * @param int      $object_id
	 * @param string   $object_type
	 * @param string[] $exclude_check_ids
	 */
	private static function resolve_missing_for_object( $table, $object_id, $object_type, array $exclude_check_ids ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		if ( empty( $exclude_check_ids ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE object_id = %d AND object_type = %s AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own internally computed name (Schema::table_names()), never user input; every value is still parameterized below.
					$now,
					$object_id,
					$object_type
				)
			);
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $exclude_check_ids ), '%s' ) );
		$query        = "UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE object_id = %d AND object_type = %s AND status = 'open' AND check_id NOT IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is internal, not user input; $placeholders is a generated %s-token list (see Step 5's Queue::claim_batch for the same pattern), never a value; every actual value flows through prepare() via $params.
		$params       = array_merge( array( $now, $object_id, $object_type ), $exclude_check_ids );

		$wpdb->query( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is always passed through prepare() here; phpcs can't trace that through the intermediate variable.
	}

	/**
	 * Same shape as resolve_missing_for_object(), scoped to one check_id
	 * across every object it touched instead of one object across every
	 * check_id — see record_for_check()'s docblock for why.
	 *
	 * @param string   $table
	 * @param string   $check_id
	 * @param string[] $exclude_url_hashes
	 */
	private static function resolve_missing_for_check( $table, $check_id, array $exclude_url_hashes ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		if ( empty( $exclude_url_hashes ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE check_id = %s AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, not user input.
					$now,
					$check_id
				)
			);
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $exclude_url_hashes ), '%s' ) );
		$query        = "UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE check_id = %s AND status = 'open' AND url_hash NOT IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is internal; $placeholders is a generated %s-token list, never a value.
		$params       = array_merge( array( $now, $check_id ), $exclude_url_hashes );

		$wpdb->query( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- always passed through prepare(); phpcs can't trace that through the intermediate variable.
	}
}
