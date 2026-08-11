<?php
/**
 * Builds and drains the per-scan work queue. Building the queue is itself
 * paginated (never a single get_posts(['numberposts' => -1])) so starting
 * a scan on a large site doesn't do the "one huge request" thing this
 * whole architecture exists to avoid (Step 1 §4).
 *
 * @package SEODoc
 */

namespace SEODoc\Scanner;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Queue {

	const BUILD_PAGE_SIZE = 200;

	const STALE_CLAIM_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * @param int      $scan_id
	 * @param string[] $post_types
	 * @return int Number of rows queued.
	 */
	public static function build_for_scan( $scan_id, array $post_types ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['queue'];

		$total = 0;
		$paged = 1;

		do {
			$query = new \WP_Query(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => self::BUILD_PAGE_SIZE,
					'paged'          => $paged,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $query->posts as $post_id ) {
				$wpdb->insert(
					$table,
					array(
						'scan_id'     => $scan_id,
						'object_type' => 'post',
						'object_id'   => $post_id,
						'status'      => 'pending',
						'created_at'  => current_time( 'mysql' ),
					)
				);
				++$total;
			}

			$fetched = count( $query->posts );
			++$paged;
		} while ( self::BUILD_PAGE_SIZE === $fetched );

		return $total;
	}

	/**
	 * Resets any row stuck in 'processing' past the stale-claim window
	 * (a batch that timed out or was killed mid-run) back to 'pending',
	 * then claims up to $limit pending rows for this run.
	 *
	 * @param int $scan_id
	 * @param int $limit
	 * @return object[] Claimed queue rows.
	 */
	public static function claim_batch( $scan_id, $limit ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['queue'];

		$stale_before = gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_AFTER );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', claimed_at = NULL
				 WHERE scan_id = %d AND status = 'processing' AND claimed_at < %s",
				$scan_id,
				$stale_before
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE scan_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
				$scan_id,
				$limit
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', claimed_at = %s WHERE id IN ({$placeholders})",
				array_merge( array( current_time( 'mysql' ) ), $ids )
			)
		);

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids )
		);
	}

	public static function mark_done( $queue_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['queue'];
		$wpdb->update( $table, array( 'status' => 'done' ), array( 'id' => $queue_id ) );
	}

	/**
	 * A failed row goes back to 'pending' (to retry on the next batch) up
	 * to 3 attempts, then parks as 'error' so a permanently-broken row
	 * can't loop forever.
	 */
	public static function mark_error( $queue_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['queue'];

		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $queue_id )
		);
		++$attempts;

		$wpdb->update(
			$table,
			array(
				'status'   => $attempts >= 3 ? 'error' : 'pending',
				'attempts' => $attempts,
			),
			array( 'id' => $queue_id )
		);
	}

	public static function purge_for_scan( $scan_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['queue'];
		$wpdb->delete( $table, array( 'scan_id' => $scan_id ) );
	}
}
