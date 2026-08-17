<?php
/**
 * 404 request monitoring.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records the addresses that end in a 404 page.
 *
 * Only the requested path, the referring URL and a hit counter are stored. No
 * IP address, user agent, cookie or any other visitor detail is recorded.
 *
 * @since 1.0.0
 */
class WPSTK_Not_Found_Monitor {

	/**
	 * Maximum number of distinct addresses kept in the log.
	 */
	const MAX_ROWS = 500;

	/**
	 * Registers the front-end hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ), 999 );
	}

	/**
	 * Logs the current request when it is a genuine front-end 404.
	 *
	 * @return void
	 */
	public static function maybe_log() {
		if ( ! is_404() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( ! WPSTK_Settings::get( 'monitor_404' ) ) {
			return;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$request = esc_url_raw( $request );

		if ( '' === $request || strlen( $request ) > 255 ) {
			return;
		}

		$referrer = '';

		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referrer = esc_url_raw( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
			$referrer = strlen( $referrer ) > 255 ? '' : $referrer;
		}

		self::record( $request, $referrer );
	}

	/**
	 * Inserts or updates a log row.
	 *
	 * @param string $url      Requested path.
	 * @param string $referrer Referring URL.
	 *
	 * @return void
	 */
	public static function record( $url, $referrer = '' ) {
		global $wpdb;

		$table = esc_sql( WPSTK_Database::not_found_table() );
		$hash  = md5( $url );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped above.
				$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_seen = %s WHERE id = %d", $now, (int) $existing )
			);

			return;
		}

		if ( self::count_all() >= self::MAX_ROWS ) {
			// The log is full; stop recording new addresses rather than growing without limit.
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->insert(
			$table,
			array(
				'url_hash'   => $hash,
				'url'        => $url,
				'referrer'   => $referrer,
				'hits'       => 1,
				'first_seen' => $now,
				'last_seen'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Counts every stored address.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;

		$table = esc_sql( WPSTK_Database::not_found_table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Counts addresses seen within a number of days.
	 *
	 * @param int $days Look-back window.
	 *
	 * @return int
	 */
	public static function count_recent( $days = 30 ) {
		global $wpdb;

		$table  = esc_sql( WPSTK_Database::not_found_table() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE last_seen >= %s", $cutoff ) );
	}

	/**
	 * Returns log rows ordered by hit count.
	 *
	 * @param int $limit  Maximum rows.
	 * @param int $offset Offset.
	 * @param int $days   Optional look-back window, 0 for everything.
	 *
	 * @return array[]
	 */
	public static function get_recent( $limit = 50, $offset = 0, $days = 0 ) {
		global $wpdb;

		$table  = esc_sql( WPSTK_Database::not_found_table() );
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped above.
					"SELECT * FROM {$table} WHERE last_seen >= %s ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d",
					$cutoff,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped above.
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes a single log row.
	 *
	 * @param int $id Row ID.
	 *
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->delete( WPSTK_Database::not_found_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = esc_sql( WPSTK_Database::not_found_table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Removes rows that have not been seen within the retention window.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;

		$days   = (int) WPSTK_Settings::get( 'retention_days', 90 );
		$table  = esc_sql( WPSTK_Database::not_found_table() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
	}
}
