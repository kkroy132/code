<?php
/**
 * Reading and writing scan records.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persistence layer for scans and their check results.
 *
 * @since 1.0.0
 */
class WPSTK_Scan_Store {

	/**
	 * Creates a new running scan row.
	 *
	 * @param string $trigger How the scan was started: manual, cron or rest.
	 *
	 * @return int Scan ID, or 0 on failure.
	 */
	public static function create( $trigger = 'manual' ) {
		global $wpdb;

		$trigger = in_array( $trigger, array( 'manual', 'cron', 'rest' ), true ) ? $trigger : 'manual';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$inserted = $wpdb->insert(
			WPSTK_Database::scans_table(),
			array(
				'started_at'   => current_time( 'mysql', true ),
				'status'       => 'running',
				'trigger_type' => $trigger,
			),
			array( '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Stores the results of a finished scan.
	 *
	 * @param int     $scan_id Scan ID.
	 * @param array[] $checks  Flat list of check results.
	 * @param array   $scores  Module scores keyed by module ID.
	 * @param array   $summary Extra summary data stored as JSON.
	 *
	 * @return void
	 */
	public static function complete( $scan_id, $checks, $scores, $summary = array() ) {
		global $wpdb;

		$scan_id = (int) $scan_id;

		if ( $scan_id <= 0 ) {
			return;
		}

		$counts = WPSTK_Check::count_by_status( $checks );
		$table  = WPSTK_Database::checks_table();

		foreach ( $checks as $check ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->insert(
				$table,
				array(
					'scan_id'     => $scan_id,
					'module'      => substr( (string) $check['module'], 0, 32 ),
					'check_id'    => substr( (string) $check['id'], 0, 64 ),
					'status'      => $check['status'],
					'label'       => (string) $check['label'],
					'summary'     => (string) $check['summary'],
					'why'         => (string) $check['why'],
					'action_text' => (string) $check['action'],
					'note'        => (string) $check['note'],
					'items'       => wp_json_encode( $check['items'] ),
					'items_total' => (int) $check['items_total'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		$overall = isset( $scores['overall'] ) ? $scores['overall'] : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->update(
			WPSTK_Database::scans_table(),
			array(
				'finished_at'          => current_time( 'mysql', true ),
				'status'               => 'completed',
				'overall_score'        => null === $overall ? null : (int) $overall,
				'scores'               => wp_json_encode( $scores ),
				'summary'              => wp_json_encode( $summary ),
				'critical_count'       => $counts['critical'],
				'warning_count'        => $counts['warning'],
				'recommendation_count' => $counts['recommendation'],
				'passed_count'         => $counts['passed'],
				'skipped_count'        => $counts['skipped'],
				'total_checks'         => count( $checks ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a scan as cancelled or failed.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status  New status.
	 *
	 * @return void
	 */
	public static function set_status( $scan_id, $status ) {
		global $wpdb;

		$scan_id = (int) $scan_id;
		$status  = in_array( $status, array( 'running', 'cancelled', 'failed', 'completed' ), true ) ? $status : 'failed';

		if ( $scan_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->update(
			WPSTK_Database::scans_table(),
			array(
				'status'      => $status,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns a single scan row.
	 *
	 * @param int $scan_id Scan ID.
	 *
	 * @return array|null
	 */
	public static function get( $scan_id ) {
		global $wpdb;

		$scan_id = (int) $scan_id;

		if ( $scan_id <= 0 ) {
			return null;
		}

		$table = WPSTK_Database::scans_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Returns the most recent completed scan.
	 *
	 * @return array|null
	 */
	public static function get_latest() {
		global $wpdb;

		$table = WPSTK_Database::scans_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status = 'completed' ORDER BY finished_at DESC, id DESC LIMIT 1", ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Returns completed scans, newest first.
	 *
	 * @param int $limit  Maximum rows.
	 * @param int $offset Offset.
	 *
	 * @return array[]
	 */
	public static function get_recent( $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table = WPSTK_Database::scans_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'completed' ORDER BY finished_at DESC, id DESC LIMIT %d OFFSET %d",
				max( 1, (int) $limit ),
				max( 0, (int) $offset )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = self::hydrate( $row );
		}

		return $out;
	}

	/**
	 * Counts completed scans.
	 *
	 * @return int
	 */
	public static function count_completed() {
		global $wpdb;

		$table = WPSTK_Database::scans_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
	}

	/**
	 * Returns the checks belonging to a scan.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $module  Optional module filter.
	 *
	 * @return array[]
	 */
	public static function get_checks( $scan_id, $module = '' ) {
		global $wpdb;

		$scan_id = (int) $scan_id;

		if ( $scan_id <= 0 ) {
			return array();
		}

		$table = WPSTK_Database::checks_table();

		if ( '' !== $module ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id = %d AND module = %s ORDER BY id ASC", $scan_id, $module ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id = %d ORDER BY module ASC, id ASC", $scan_id ),
				ARRAY_A
			);
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$items = json_decode( (string) $row['items'], true );

			$out[] = array(
				'id'          => (string) $row['check_id'],
				'module'      => (string) $row['module'],
				'status'      => (string) $row['status'],
				'label'       => (string) $row['label'],
				'summary'     => (string) $row['summary'],
				'why'         => (string) $row['why'],
				'action'      => (string) $row['action_text'],
				'note'        => (string) $row['note'],
				'items'       => is_array( $items ) ? $items : array(),
				'items_total' => (int) $row['items_total'],
			);
		}

		return $out;
	}

	/**
	 * Sorts checks so the most severe appear first.
	 *
	 * @param array[] $checks Check results.
	 *
	 * @return array[]
	 */
	public static function sort_by_severity( $checks ) {
		$order = array(
			'critical'       => 0,
			'warning'        => 1,
			'recommendation' => 2,
			'skipped'        => 3,
			'passed'         => 4,
		);

		usort(
			$checks,
			static function ( $a, $b ) use ( $order ) {
				$a_rank = isset( $order[ $a['status'] ] ) ? $order[ $a['status'] ] : 5;
				$b_rank = isset( $order[ $b['status'] ] ) ? $order[ $b['status'] ] : 5;

				if ( $a_rank === $b_rank ) {
					return strcmp( (string) $a['label'], (string) $b['label'] );
				}

				return $a_rank < $b_rank ? -1 : 1;
			}
		);

		return $checks;
	}

	/**
	 * Deletes a scan and its checks.
	 *
	 * @param int $scan_id Scan ID.
	 *
	 * @return void
	 */
	public static function delete( $scan_id ) {
		global $wpdb;

		$scan_id = (int) $scan_id;

		if ( $scan_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->delete( WPSTK_Database::checks_table(), array( 'scan_id' => $scan_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->delete( WPSTK_Database::scans_table(), array( 'id' => $scan_id ), array( '%d' ) );
	}

	/**
	 * Applies the retention settings.
	 *
	 * @return int Number of scans removed.
	 */
	public static function prune() {
		global $wpdb;

		$settings = WPSTK_Settings::get_all();
		$table    = WPSTK_Database::scans_table();
		$removed  = array();

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $settings['retention_days'] * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$old = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE started_at < %s", $cutoff ) );

		foreach ( (array) $old as $id ) {
			$removed[] = (int) $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$surplus = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d",
				1000,
				max( 2, (int) $settings['max_scans'] )
			)
		);

		foreach ( (array) $surplus as $id ) {
			$removed[] = (int) $id;
		}

		$removed = array_unique( $removed );

		foreach ( $removed as $id ) {
			self::delete( $id );
		}

		return count( $removed );
	}

	/**
	 * Decodes the JSON columns of a scan row.
	 *
	 * @param array $row Raw row.
	 *
	 * @return array
	 */
	private static function hydrate( $row ) {
		$scores  = json_decode( (string) $row['scores'], true );
		$summary = json_decode( (string) $row['summary'], true );

		$row['scores']        = is_array( $scores ) ? $scores : array();
		$row['summary']       = is_array( $summary ) ? $summary : array();
		$row['id']            = (int) $row['id'];
		$row['overall_score'] = null === $row['overall_score'] ? null : (int) $row['overall_score'];

		foreach ( array( 'critical_count', 'warning_count', 'recommendation_count', 'passed_count', 'skipped_count', 'total_checks' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}

		return $row;
	}
}
