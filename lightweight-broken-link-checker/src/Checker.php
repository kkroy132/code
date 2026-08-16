<?php
/**
 * Link checker.
 *
 * Runs as a recurring Action Scheduler job and verifies a small, fixed number
 * of links per run, so the site never spends more than a few seconds of HTTP
 * wait time in one request.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies stored links over HTTP.
 */
class Checker {

	/**
	 * Seconds between two recurring runs.
	 */
	const INTERVAL = 300;

	/**
	 * Links verified per run.
	 */
	const BATCH_SIZE = 15;

	/**
	 * Age after which an `ok` link is verified again.
	 */
	const RECHECK_AFTER = 604800;

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Consecutive failures required before a link is called broken.
	 */
	const FAIL_THRESHOLD = 3;

	/**
	 * Pause between two requests to the same host, in microseconds.
	 */
	const SAME_HOST_DELAY = 300000;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( Scheduler::HOOK_CHECK_BATCH, array( __CLASS__, 'run_batch' ) );

		/*
		 * Only verified in the admin: the recurring action reschedules itself,
		 * so front end requests never need to pay for this lookup.
		 */
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Makes sure the recurring check job exists.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! Scheduler::is_available() || Scheduler::has_scheduled( Scheduler::HOOK_CHECK_BATCH ) ) {
			return;
		}

		Scheduler::schedule_recurring( time() + self::INTERVAL, self::INTERVAL, Scheduler::HOOK_CHECK_BATCH );
	}

	/**
	 * How many links one run verifies.
	 *
	 * @return int
	 */
	public static function batch_size() {
		/**
		 * Filters the number of links verified per run.
		 *
		 * @param int $size Batch size.
		 */
		$size = (int) apply_filters( 'lwblc_check_batch_size', self::BATCH_SIZE );

		return max( 1, min( 100, $size ) );
	}

	/**
	 * Human readable time until the next scheduled run.
	 *
	 * @return string
	 */
	public static function next_run_label() {
		$next = Scheduler::next_run( Scheduler::HOOK_CHECK_BATCH );

		if ( $next <= 0 ) {
			return __( 'Not scheduled', 'lwblc' );
		}

		if ( $next <= time() ) {
			return __( 'Due now', 'lwblc' );
		}

		/* translators: %s: human readable time difference, e.g. "5 mins". */
		return sprintf( __( 'in %s', 'lwblc' ), human_time_diff( time(), $next ) );
	}

	/**
	 * Selects the links to verify next.
	 *
	 * Priority:
	 *   1. `pending` links, oldest row first — they have never been checked.
	 *   2. `ok` links whose last check is older than a week, oldest
	 *      `post_modified_date` first, so stale content is revisited first.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_due_links( $limit ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );

		if ( ! Database::table_exists() ) {
			return array();
		}

		$table  = Database::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_AFTER );

		/*
		 * `priority` keeps the two groups apart, then each group gets its own
		 * ordering: pending links by id, stale ones by how old the source post
		 * is (NULL dates last).
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb->prefix.
		$sql = $wpdb->prepare(
			"SELECT id, link_url, status, fail_count, http_code
			FROM {$table}
			WHERE status = %s
				OR ( status = %s AND ( last_checked_at IS NULL OR last_checked_at < %s ) )
			ORDER BY
				CASE WHEN status = %s THEN 0 ELSE 1 END ASC,
				CASE WHEN post_modified_date IS NULL THEN 1 ELSE 0 END ASC,
				post_modified_date ASC,
				id ASC
			LIMIT %d",
			Database::STATUS_PENDING,
			Database::STATUS_OK,
			$cutoff,
			Database::STATUS_PENDING,
			$limit
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Verifies one batch of links.
	 *
	 * @return int Number of links verified.
	 */
	public static function run_batch() {
		$links = self::get_due_links( self::batch_size() );

		if ( empty( $links ) ) {
			return 0;
		}

		$seen_hosts = array();
		$checked    = 0;

		foreach ( $links as $link ) {
			$host = strtolower( (string) wp_parse_url( $link['link_url'], PHP_URL_HOST ) );

			// Space out requests that hit the same host inside one batch.
			if ( '' !== $host && isset( $seen_hosts[ $host ] ) ) {
				usleep( self::SAME_HOST_DELAY );
			}

			$seen_hosts[ $host ] = true;

			self::check_link( $link );
			$checked++;
		}

		/**
		 * Fires after a batch of links has been verified.
		 *
		 * @param int $checked Number of links verified.
		 */
		do_action( 'lwblc_check_batch_finished', $checked );

		return $checked;
	}

	/**
	 * Verifies a single link and stores the outcome.
	 *
	 * @param array<string,mixed> $link Row with at least id, link_url, fail_count.
	 * @return array<string,mixed> The stored result.
	 */
	public static function check_link( array $link ) {
		$url        = (string) $link['link_url'];
		$fail_count = isset( $link['fail_count'] ) ? (int) $link['fail_count'] : 0;

		$result = self::request( $url );

		$code   = (int) $result['code'];
		$status = Database::STATUS_OK;

		if ( 0 === $code ) {
			// Transport level failure (DNS, TLS, timeout): count it, do not judge yet.
			$fail_count++;
			$status = $fail_count >= self::FAIL_THRESHOLD ? Database::STATUS_BROKEN : Database::STATUS_PENDING;
		} elseif ( self::is_soft_failure( $code ) ) {
			/*
			 * 403 and 429 usually mean bot protection or rate limiting rather
			 * than a dead link, so only repeated failures confirm it.
			 */
			$fail_count++;
			$status = $fail_count >= self::FAIL_THRESHOLD ? Database::STATUS_BROKEN : Database::STATUS_PENDING;
		} elseif ( $code >= 400 ) {
			$fail_count++;
			$status = $fail_count >= self::FAIL_THRESHOLD ? Database::STATUS_BROKEN : Database::STATUS_PENDING;
		} elseif ( $code >= 300 ) {
			$status     = Database::STATUS_REDIRECT;
			$fail_count = 0;
		} else {
			$status     = Database::STATUS_OK;
			$fail_count = 0;
		}

		self::save_result( (int) $link['id'], $status, $code, $fail_count );

		return array(
			'id'         => (int) $link['id'],
			'status'     => $status,
			'http_code'  => $code,
			'fail_count' => $fail_count,
		);
	}

	/**
	 * Whether a response code should only count as a soft failure.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	public static function is_soft_failure( $code ) {
		return in_array( (int) $code, array( 403, 429 ), true );
	}

	/**
	 * Performs the HTTP check.
	 *
	 * HEAD first because it is cheap; if it fails or is refused, GET decides,
	 * since a fair number of servers answer HEAD with 403/405/500 while the
	 * page itself is perfectly fine.
	 *
	 * @param string $url URL to request.
	 * @return array{code:int,method:string}
	 */
	public static function request( $url ) {
		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 1024,
			'user-agent'          => self::user_agent(),
			'headers'             => array(
				'Accept' => '*/*',
			),
		);

		/**
		 * Filters the arguments used for link requests.
		 *
		 * @param array  $args Request arguments.
		 * @param string $url  URL being checked.
		 */
		$args = apply_filters( 'lwblc_request_args', $args, $url );

		$response = wp_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( self::needs_get_retry( $code ) ) {
			$get_args            = $args;
			$get_args['method']  = 'GET';
			$get_args['headers'] = array( 'Accept' => 'text/html,*/*;q=0.8' );

			$response = wp_remote_get( $url, $get_args );
			$get_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			return array(
				'code'   => $get_code,
				'method' => 'GET',
			);
		}

		return array(
			'code'   => $code,
			'method' => 'HEAD',
		);
	}

	/**
	 * Whether a HEAD result should be double checked with GET.
	 *
	 * @param int $code Response code from the HEAD request, 0 on transport error.
	 * @return bool
	 */
	public static function needs_get_retry( $code ) {
		$code = (int) $code;

		// Transport error (timeout, DNS, TLS) or anything a server may fake for HEAD.
		return 0 === $code || $code >= 400;
	}

	/**
	 * User agent sent with link requests.
	 *
	 * @return string
	 */
	public static function user_agent() {
		/**
		 * Filters the user agent used when checking links.
		 *
		 * @param string $user_agent User agent string.
		 */
		return (string) apply_filters(
			'lwblc_user_agent',
			'Mozilla/5.0 (compatible; LightweightBrokenLinkChecker/' . LWBLC_VERSION . '; +' . home_url() . ')'
		);
	}

	/**
	 * Stores the outcome of a check.
	 *
	 * @param int    $id         Row id.
	 * @param string $status     New status.
	 * @param int    $code       HTTP code, 0 when unreachable.
	 * @param int    $fail_count New failure counter.
	 * @return void
	 */
	private static function save_result( $id, $status, $code, $fail_count ) {
		global $wpdb;

		$now = Plugin::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			Database::table(),
			array(
				'status'          => Database::sanitize_status( $status ),
				'http_code'       => max( 0, (int) $code ),
				'fail_count'      => max( 0, (int) $fail_count ),
				'last_checked_at' => $now,
				'updated_at'      => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Loads a single link row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function get_link( $id ) {
		global $wpdb;

		$table = Database::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb->prefix; value is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Verifies one link immediately, ignoring its schedule.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null Result, or null when the link is gone.
	 */
	public static function recheck( $id ) {
		$link = self::get_link( (int) $id );

		if ( ! $link ) {
			return null;
		}

		// A manual recheck starts from a clean slate.
		$link['fail_count'] = 0;

		return self::check_link( $link );
	}
}
