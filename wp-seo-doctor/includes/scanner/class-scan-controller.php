<?php
/**
 * Starts, pauses, resumes, cancels, and completes scans. This is the
 * single place that touches wp_seodoc_scans rows, so REST controllers
 * (Step 8) call these methods rather than writing to the table directly.
 *
 * @package SEODoc
 */

namespace SEODoc\Scanner;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scan_Controller {

	/**
	 * @param string   $scan_type  'full' | 'single' | 'scheduled'.
	 * @param string   $trigger    'manual' | 'cron' | 'activation'.
	 * @param string[] $post_types
	 * @return int The new scan's id.
	 */
	public static function start( $scan_type = 'full', $trigger = 'manual', array $post_types = array( 'post', 'page' ) ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['scans'];

		$previous_score = $wpdb->get_var(
			"SELECT health_score FROM {$table} WHERE status = 'completed' AND health_score IS NOT NULL ORDER BY finished_at DESC LIMIT 1"
		);

		$wpdb->insert(
			$table,
			array(
				'scan_type'             => $scan_type,
				'trigger_source'        => $trigger,
				'status'                => 'queued',
				'previous_health_score' => $previous_score,
				'initiated_by'          => get_current_user_id() ? get_current_user_id() : null,
				'started_at'            => current_time( 'mysql' ),
			)
		);

		$scan_id = (int) $wpdb->insert_id;

		$total = Queue::build_for_scan( $scan_id, $post_types );

		$wpdb->update(
			$table,
			array(
				'status'      => 'running',
				'total_items' => $total,
			),
			array( 'id' => $scan_id )
		);

		if ( 0 === $total ) {
			self::complete( $scan_id );
		} else {
			Batch_Processor::schedule_next( $scan_id );
		}

		return $scan_id;
	}

	public static function pause( $scan_id ) {
		self::set_status( $scan_id, 'paused' );
	}

	public static function resume( $scan_id ) {
		self::set_status( $scan_id, 'running' );
		Batch_Processor::schedule_next( $scan_id );
	}

	public static function cancel( $scan_id ) {
		self::set_status( $scan_id, 'cancelled' );
		Queue::purge_for_scan( $scan_id );
	}

	/**
	 * Called by Batch_Processor once the queue is empty. Health score
	 * computation is added in Step 7; for now this just closes the scan
	 * out and clears its queue rows per the retention policy (Step 3).
	 */
	public static function complete( $scan_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['scans'];

		$wpdb->update(
			$table,
			array(
				'status'      => 'completed',
				'finished_at' => current_time( 'mysql' ),
			),
			array( 'id' => $scan_id )
		);

		Queue::purge_for_scan( $scan_id );

		do_action( 'seodoc_scan_completed', $scan_id );
	}

	public static function increment_processed( $scan_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['scans'];

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET processed_items = processed_items + 1 WHERE id = %d", $scan_id )
		);
	}

	/**
	 * @param int $scan_id
	 * @return object|null
	 */
	public static function get_scan( $scan_id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['scans'];

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ) );
	}

	private static function set_status( $scan_id, $status ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['scans'];

		$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $scan_id ) );
	}
}
