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
	 * for this object whose check no longer fired.
	 *
	 * @param int      $scan_id
	 * @param string   $object_type
	 * @param int      $object_id
	 * @param Issue[]  $issues
	 */
	public static function record( $scan_id, $object_type, $object_id, array $issues ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['issues'];
		$now   = current_time( 'mysql' );

		$seen_check_ids = array();

		foreach ( $issues as $issue ) {
			$url_hash          = md5( $issue->url );
			$seen_check_ids[]  = $issue->check_id;

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

		self::resolve_missing_for_object( $table, $object_id, $object_type, $seen_check_ids );
	}

	private static function resolve_missing_for_object( $table, $object_id, $object_type, array $seen_check_ids ) {
		global $wpdb;

		$query  = "UPDATE {$table} SET status = 'resolved', resolved_at = %s
		           WHERE object_id = %d AND object_type = %s AND status = 'open'";
		$params = array( current_time( 'mysql' ), $object_id, $object_type );

		if ( ! empty( $seen_check_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $seen_check_ids ), '%s' ) );
			$query       .= " AND check_id NOT IN ({$placeholders})";
			$params       = array_merge( $params, $seen_check_ids );
		}

		$wpdb->query( $wpdb->prepare( $query, $params ) );
	}
}
