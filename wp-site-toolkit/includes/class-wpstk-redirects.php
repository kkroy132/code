<?php
/**
 * URL redirect manager.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and serves simple path-to-URL redirects.
 *
 * Redirects are matched on every front-end request, but the lookup is skipped
 * entirely unless at least one redirect exists, so a site that never uses
 * this feature pays nothing for it. When redirects do exist, a single
 * indexed lookup (backed by a short object-cache entry where available)
 * decides the outcome.
 *
 * @since 1.1.0
 */
class WPSTK_Redirects {

	/**
	 * Option tracking how many redirects exist, used to skip the lookup fast.
	 */
	const OPTION_COUNT = 'wpstk_redirect_count';

	/**
	 * Object cache group used for the per-request lookup cache.
	 */
	const CACHE_GROUP = 'wpstk';

	/**
	 * How long a resolved (or missing) redirect is cached for, in seconds.
	 */
	const CACHE_TTL = 300;

	/**
	 * Registers the front-end hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Returns the redirects table name.
	 *
	 * @return string
	 */
	public static function table() {
		return WPSTK_Database::redirects_table();
	}

	/**
	 * Redirects the current request when it matches a stored, enabled redirect.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( (int) get_option( self::OPTION_COUNT, 0 ) <= 0 ) {
			return;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path = self::current_path();

		if ( '' === $path ) {
			return;
		}

		$redirect = self::find_match( $path );

		if ( ! $redirect || empty( $redirect['enabled'] ) ) {
			return;
		}

		self::record_hit( (int) $redirect['id'] );

		$target = self::resolve_target( $redirect['target_url'] );
		$status = in_array( (int) $redirect['status_code'], array( 301, 302, 307 ), true ) ? (int) $redirect['status_code'] : 301;

		wp_safe_redirect( $target, $status );
		exit;
	}

	/**
	 * Returns the path portion of the current request.
	 *
	 * @return string
	 */
	private static function current_path() {
		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '';
	}

	/**
	 * Normalises a source path so equivalent addresses match consistently.
	 *
	 * @param string $path Raw path.
	 *
	 * @return string
	 */
	public static function normalize_source( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return '';
		}

		$parsed = wp_parse_url( $path, PHP_URL_PATH );
		$path   = is_string( $parsed ) && '' !== $parsed ? $parsed : $path;

		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		$path = preg_replace( '#/{2,}#', '/', $path );

		return (string) $path;
	}

	/**
	 * Hashes a normalised path for indexed lookup.
	 *
	 * @param string $path Normalised path.
	 *
	 * @return string
	 */
	private static function hash( $path ) {
		return md5( $path );
	}

	/**
	 * Looks up a redirect for a path, trying the trailing-slash variant too.
	 *
	 * @param string $path Path to match, as normalised by normalize_source().
	 *
	 * @return array|null
	 */
	public static function find_match( $path ) {
		$candidates = array( $path );

		if ( '/' === substr( $path, -1 ) && '/' !== $path ) {
			$candidates[] = untrailingslashit( $path );
		} elseif ( '/' !== substr( $path, -1 ) ) {
			$candidates[] = trailingslashit( $path );
		}

		foreach ( $candidates as $candidate ) {
			$row = self::get_by_hash( self::hash( $candidate ) );

			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Reads a redirect row by its path hash, using the object cache when available.
	 *
	 * @param string $hash Path hash.
	 *
	 * @return array|null
	 */
	private static function get_by_hash( $hash ) {
		global $wpdb;

		$cache_key = 'match_' . $hash;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return array() === $cached ? null : $cached;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s", $hash ), ARRAY_A );

		wp_cache_set( $cache_key, $row ? $row : array(), self::CACHE_GROUP, self::CACHE_TTL );

		return $row ? $row : null;
	}

	/**
	 * Clears the cached lookup for a path hash.
	 *
	 * @param string $hash Path hash.
	 *
	 * @return void
	 */
	private static function flush_cache( $hash ) {
		wp_cache_delete( 'match_' . $hash, self::CACHE_GROUP );
	}

	/**
	 * Resolves a stored target into an absolute URL.
	 *
	 * @param string $target Stored target, absolute URL or site-relative path.
	 *
	 * @return string
	 */
	public static function resolve_target( $target ) {
		$target = (string) $target;

		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}

		return home_url( $target );
	}

	/**
	 * Increments the hit counter for a redirect.
	 *
	 * @param int $id Redirect ID.
	 *
	 * @return void
	 */
	public static function record_hit( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET hits = hits + 1, last_used_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				(int) $id
			)
		);
	}

	/**
	 * Creates a redirect.
	 *
	 * @param string $source      Source path.
	 * @param string $target      Target URL or path.
	 * @param int    $status_code HTTP status code: 301, 302 or 307.
	 *
	 * @return int|WP_Error Redirect ID, or an error describing why it was rejected.
	 */
	public static function create( $source, $target, $status_code = 301 ) {
		global $wpdb;

		$validated = self::validate( $source, $target, $status_code );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		list( $source, $target, $status_code ) = $validated;

		$hash = self::hash( $source );

		if ( self::exists( $hash ) ) {
			return new WP_Error( 'wpstk_redirect_exists', __( 'A redirect for that address already exists.', 'wp-site-toolkit' ) );
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$inserted = $wpdb->insert(
			$table,
			array(
				'source_path' => $source,
				'source_hash' => $hash,
				'target_url'  => $target,
				'status_code' => $status_code,
				'enabled'     => 1,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'wpstk_redirect_failed', __( 'The redirect could not be saved.', 'wp-site-toolkit' ) );
		}

		self::bump_count( 1 );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing redirect.
	 *
	 * @param int    $id          Redirect ID.
	 * @param string $source      Source path.
	 * @param string $target      Target URL or path.
	 * @param int    $status_code HTTP status code.
	 * @param bool   $enabled     Whether the redirect is active.
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, $source, $target, $status_code = 301, $enabled = true ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return new WP_Error( 'wpstk_redirect_missing', __( 'That redirect could not be found.', 'wp-site-toolkit' ) );
		}

		$validated = self::validate( $source, $target, $status_code );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		list( $source, $target, $status_code ) = $validated;

		$hash  = self::hash( $source );
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, source_hash FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $existing ) {
			return new WP_Error( 'wpstk_redirect_missing', __( 'That redirect could not be found.', 'wp-site-toolkit' ) );
		}

		if ( self::exists( $hash, $id ) ) {
			return new WP_Error( 'wpstk_redirect_exists', __( 'A redirect for that address already exists.', 'wp-site-toolkit' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->update(
			$table,
			array(
				'source_path' => $source,
				'source_hash' => $hash,
				'target_url'  => $target,
				'status_code' => $status_code,
				'enabled'     => $enabled ? 1 : 0,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		self::flush_cache( $existing['source_hash'] );
		self::flush_cache( $hash );

		return true;
	}

	/**
	 * Validates and normalises redirect fields.
	 *
	 * @param string $source      Source path.
	 * @param string $target      Target URL or path.
	 * @param int    $status_code HTTP status code.
	 *
	 * @return array|WP_Error Array of [source, target, status_code], or an error.
	 */
	private static function validate( $source, $target, $status_code ) {
		$source      = self::normalize_source( $source );
		$target      = esc_url_raw( trim( (string) $target ) );
		$status_code = in_array( (int) $status_code, array( 301, 302, 307 ), true ) ? (int) $status_code : 301;

		if ( '' === $source || '' === $target ) {
			return new WP_Error( 'wpstk_redirect_invalid', __( 'A source path and a target address are both required.', 'wp-site-toolkit' ) );
		}

		if ( self::resolve_target( $target ) === home_url( $source ) ) {
			return new WP_Error( 'wpstk_redirect_loop', __( 'The target address is the same as the source, which would create a redirect loop.', 'wp-site-toolkit' ) );
		}

		return array( $source, $target, $status_code );
	}

	/**
	 * Whether a redirect with the given source hash already exists.
	 *
	 * @param string $hash       Source hash.
	 * @param int    $exclude_id Redirect ID to exclude from the check.
	 *
	 * @return bool
	 */
	private static function exists( $hash, $exclude_id = 0 ) {
		global $wpdb;

		$table = self::table();

		if ( $exclude_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_hash = %s AND id != %d", $hash, (int) $exclude_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_hash = %s", $hash ) );
		}

		return (bool) $found;
	}

	/**
	 * Deletes a redirect.
	 *
	 * @param int $id Redirect ID.
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return false;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT source_hash FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( $row ) {
			self::flush_cache( $row['source_hash'] );
		}

		if ( $deleted ) {
			self::bump_count( -1 );
		}

		return (bool) $deleted;
	}

	/**
	 * Enables or disables a redirect.
	 *
	 * @param int  $id      Redirect ID.
	 * @param bool $enabled Whether the redirect should be active.
	 *
	 * @return bool
	 */
	public static function toggle( $id, $enabled ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return false;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT source_hash FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->update( $table, array( 'enabled' => $enabled ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );

		if ( $row ) {
			self::flush_cache( $row['source_hash'] );
		}

		return true;
	}

	/**
	 * Returns a single redirect.
	 *
	 * @param int $id Redirect ID.
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Returns redirects, newest first.
	 *
	 * @param int $limit  Maximum rows.
	 * @param int $offset Offset.
	 *
	 * @return array[]
	 */
	public static function get_all( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				max( 1, (int) $limit ),
				max( 0, (int) $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts every redirect.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Counts enabled redirects.
	 *
	 * @return int
	 */
	public static function count_enabled() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" );
	}

	/**
	 * Sums how many times every redirect has been used.
	 *
	 * @return int
	 */
	public static function total_hits() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		return (int) $wpdb->get_var( "SELECT SUM(hits) FROM {$table}" );
	}

	/**
	 * Adjusts the cached redirect count used to short-circuit the front-end lookup.
	 *
	 * @param int $delta Change in count.
	 *
	 * @return void
	 */
	private static function bump_count( $delta ) {
		$count = max( 0, (int) get_option( self::OPTION_COUNT, 0 ) + (int) $delta );

		update_option( self::OPTION_COUNT, $count, true );
	}

	/**
	 * Recalculates the redirect count option from the database.
	 *
	 * @return void
	 */
	public static function recount() {
		update_option( self::OPTION_COUNT, self::count_all(), true );
	}
}
