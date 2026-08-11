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

		self::resolve_missing(
			$table,
			$seen_check_ids,
			'object_id = %d AND object_type = %s',
			array( $object_id, $object_type )
		);
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

		if ( empty( $seen_url_hashes ) ) {
			self::resolve_missing( $table, array(), 'check_id = %s', array( $check_id ) );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $seen_url_hashes ), '%s' ) );
		self::resolve_missing(
			$table,
			array(),
			"check_id = %s AND url_hash NOT IN ({$placeholders})",
			array_merge( array( $check_id ), $seen_url_hashes )
		);
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
	 * Marks 'open' issues 'resolved' when they match $where but their
	 * check_id isn't in $exclude_check_ids (used by the per-object path;
	 * the per-check path instead encodes its own exclusion directly into
	 * $where/$where_params, since it excludes by url_hash, not check_id).
	 *
	 * @param string   $table
	 * @param string[] $exclude_check_ids
	 * @param string   $where
	 * @param array    $where_params
	 */
	private static function resolve_missing( $table, array $exclude_check_ids, $where, array $where_params ) {
		global $wpdb;

		$query  = "UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE {$where} AND status = 'open'";
		$params = array_merge( array( current_time( 'mysql' ) ), $where_params );

		if ( ! empty( $exclude_check_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude_check_ids ), '%s' ) );
			$query       .= " AND check_id NOT IN ({$placeholders})";
			$params       = array_merge( $params, $exclude_check_ids );
		}

		$wpdb->query( $wpdb->prepare( $query, $params ) );
	}
}
