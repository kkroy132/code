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
	 * Age after which a settled `broken` or `redirect` link is verified again.
	 *
	 * Dead domains come back and servers get fixed, so a link must be able to
	 * leave the broken list without someone pressing a button. This runs at the
	 * lowest priority, well behind links that were never checked.
	 */
	const RECHECK_SETTLED_AFTER = 2592000;

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
			return __( 'Not scheduled', 'lightweight-broken-link-checker' );
		}

		if ( $next <= time() ) {
			return __( 'Due now', 'lightweight-broken-link-checker' );
		}

		/* translators: %s: human readable time difference, e.g. "5 mins". */
		return sprintf( __( 'in %s', 'lightweight-broken-link-checker' ), human_time_diff( time(), $next ) );
	}

	/**
	 * Selects the links to verify next.
	 *
	 * Priority, highest first:
	 *   1. `pending` links — they have never been checked, or a check is in
	 *      progress after a failure.
	 *   2. `ok` links whose last check is older than a week.
	 *   3. `broken` and `redirect` links that have been settled for a month, so
	 *      a link that got fixed can find its way back to `ok` on its own.
	 *
	 * Within tiers 1 and 2 the oldest `post_modified_date` comes first, so
	 * stale content is revisited before freshly edited content; tier 3 goes by
	 * how long ago the link was last checked. MySQL sorts NULLs first, which is
	 * what we want anyway — an unknown date counts as the oldest.
	 *
	 * Each tier is its own query on purpose. A single query would need
	 * `ORDER BY CASE ...`, which no index can satisfy, forcing MySQL to sort
	 * the whole table on every run. Each tier only asks for the slots the tiers
	 * above it left free, so a full first tier costs exactly one query.
	 *
	 * @param int $limit Maximum rows to return in total.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_due_links( $limit ) {
		$limit = max( 1, (int) $limit );

		if ( ! Database::table_exists() ) {
			return array();
		}

		$links = self::query_pending( $limit );

		$remaining = $limit - count( $links );

		if ( $remaining > 0 ) {
			$links = array_merge( $links, self::query_stale_ok( $remaining ) );
		}

		$remaining = $limit - count( $links );

		if ( $remaining > 0 ) {
			$links = array_merge( $links, self::query_settled( $remaining ) );
		}

		return $links;
	}

	/**
	 * Tier 1: links that have never been checked.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_pending( $limit ) {
		global $wpdb;

		$table = esc_sql( Database::table() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, name escaped above; every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, link_url, status, fail_count, http_code
				FROM {$table}
				WHERE status = %s
				ORDER BY post_modified_date ASC, id ASC
				LIMIT %d",
				Database::STATUS_PENDING,
				(int) $limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Tier 2: working links that have not been verified for a week.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_stale_ok( $limit ) {
		global $wpdb;

		$table = esc_sql( Database::table() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, name escaped above; every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, link_url, status, fail_count, http_code
				FROM {$table}
				WHERE status = %s AND ( last_checked_at IS NULL OR last_checked_at < %s )
				ORDER BY post_modified_date ASC, id ASC
				LIMIT %d",
				Database::STATUS_OK,
				gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_AFTER ),
				(int) $limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Tier 3: broken and redirecting links that settled a month ago.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_settled( $limit ) {
		global $wpdb;

		$table = esc_sql( Database::table() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, name escaped above; every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, link_url, status, fail_count, http_code
				FROM {$table}
				WHERE status IN ( %s, %s, %s ) AND ( last_checked_at IS NULL OR last_checked_at < %s )
				ORDER BY last_checked_at ASC, id ASC
				LIMIT %d",
				Database::STATUS_BROKEN,
				Database::STATUS_REDIRECT,
				Database::STATUS_SKIPPED,
				gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_SETTLED_AFTER ),
				(int) $limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
			++$checked;
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
		$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
		$status = Database::STATUS_OK;

		/*
		 * The guard refused to send the request at all. That says nothing about
		 * whether the target works, so it is recorded as skipped and the
		 * failure counter is left alone rather than marching towards `broken`.
		 */
		if ( in_array( $reason, array( 'blocked', 'unsupported_scheme', 'invalid_url' ), true ) ) {
			self::save_result( (int) $link['id'], Database::STATUS_SKIPPED, 0, 0, $reason );

			return array(
				'id'         => (int) $link['id'],
				'status'     => Database::STATUS_SKIPPED,
				'http_code'  => 0,
				'fail_count' => 0,
				'reason'     => $reason,
			);
		}

		if ( 0 === $code ) {
			// Transport level failure (DNS, TLS, timeout): count it, do not judge yet.
			++$fail_count;
			$status = $fail_count >= self::FAIL_THRESHOLD ? Database::STATUS_BROKEN : Database::STATUS_PENDING;
		} elseif ( self::is_soft_failure( $code ) ) {
			/*
			 * 403 and 429 usually mean bot protection or rate limiting rather
			 * than a dead link, so only repeated failures confirm it.
			 */
			++$fail_count;
			$status = $fail_count >= self::FAIL_THRESHOLD ? Database::STATUS_BROKEN : Database::STATUS_PENDING;
		} elseif ( $code >= 400 ) {
			++$fail_count;
			$status = $fail_count >= self::FAIL_THRESHOLD ? Database::STATUS_BROKEN : Database::STATUS_PENDING;
		} elseif ( $code >= 300 ) {
			$status     = Database::STATUS_REDIRECT;
			$fail_count = 0;
		} else {
			$status     = Database::STATUS_OK;
			$fail_count = 0;
		}

		self::save_result( (int) $link['id'], $status, $code, $fail_count, $reason );

		return array(
			'id'         => (int) $link['id'],
			'status'     => $status,
			'http_code'  => $code,
			'fail_count' => $fail_count,
			'reason'     => $reason,
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
		$destination = Url_Guard::validate( $url );

		if ( is_wp_error( $destination ) ) {
			return array(
				'code'   => 0,
				'method' => '',
				'reason' => self::reason_from_error( $destination ),
			);
		}

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

		/*
		 * Set after the filter, never before: following a redirect would send
		 * the request to an address that was never validated, so a 3xx is
		 * reported rather than chased. The marker lets the transport hook
		 * recognise our own requests.
		 */
		$args['redirection'] = 0;
		$args['lwblc']       = true;

		Url_Guard::pin( $destination );

		try {
			$outcome = self::interpret( wp_remote_head( $url, $args ) );

			if ( ! self::needs_get_retry( $outcome['code'] ) ) {
				$outcome['method'] = 'HEAD';

				return $outcome;
			}

			$get_args            = $args;
			$get_args['method']  = 'GET';
			$get_args['headers'] = array( 'Accept' => 'text/html,*/*;q=0.8' );

			$outcome           = self::interpret( wp_remote_get( $url, $get_args ) );
			$outcome['method'] = 'GET';

			return $outcome;
		} finally {
			Url_Guard::unpin();
		}
	}

	/**
	 * Turns an HTTP response or transport error into a code and a reason.
	 *
	 * @param array|\WP_Error $response Response from the HTTP API.
	 * @return array{code:int,method:string,reason:string}
	 */
	private static function interpret( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'code'   => 0,
				'method' => '',
				'reason' => self::reason_from_error( $response ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'code'   => $code,
			'method' => '',
			'reason' => self::reason_from_code( $code ),
		);
	}

	/**
	 * Names the outcome behind an HTTP status code.
	 *
	 * @param int $code Response code.
	 * @return string
	 */
	public static function reason_from_code( $code ) {
		$code = (int) $code;

		if ( $code >= 200 && $code < 300 ) {
			return 'ok';
		}

		if ( $code >= 300 && $code < 400 ) {
			return 'redirect';
		}

		switch ( $code ) {
			case 401:
			case 403:
				return 'forbidden';
			case 404:
				return 'not_found';
			case 410:
				return 'gone';
			case 429:
				return 'rate_limited';
		}

		if ( $code >= 500 ) {
			return 'server_error';
		}

		if ( $code >= 400 ) {
			return 'client_error';
		}

		return 'connection';
	}

	/**
	 * Names the outcome behind a transport error.
	 *
	 * @param \WP_Error $error Error from the guard or the HTTP API.
	 * @return string
	 */
	public static function reason_from_error( $error ) {
		switch ( $error->get_error_code() ) {
			case 'lwblc_unsupported_scheme':
				return 'unsupported_scheme';
			case 'lwblc_blocked_host':
			case 'lwblc_blocked_ip':
			case 'lwblc_blocked_port':
				return 'blocked';
			case 'lwblc_invalid_url':
				return 'invalid_url';
			case 'lwblc_dns_failure':
				return 'dns';
		}

		$message = strtolower( (string) $error->get_error_message() );

		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return 'timeout';
		}

		if ( false !== strpos( $message, 'resolve host' ) || false !== strpos( $message, 'name or service not known' ) ) {
			return 'dns';
		}

		if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'certificate' ) ) {
			return 'ssl';
		}

		return 'connection';
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
	 * @param string $reason     Machine readable reason for the outcome.
	 * @return void
	 */
	private static function save_result( $id, $status, $code, $fail_count, $reason = '' ) {
		global $wpdb;

		$now = Plugin::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			Database::table(),
			array(
				'status'          => Database::sanitize_status( $status ),
				'status_reason'   => self::sanitize_reason( $reason ),
				'http_code'       => max( 0, (int) $code ),
				// The column is a tinyint; a link rechecked for years must not overflow it.
				'fail_count'      => min( 255, max( 0, (int) $fail_count ) ),
				'last_checked_at' => $now,
				'updated_at'      => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Keeps the stored reason to a short known token.
	 *
	 * The column only ever holds one of these machine readable words. Nothing
	 * from the remote server, and no network detail, is written to it.
	 *
	 * @param string $reason Candidate reason.
	 * @return string
	 */
	public static function sanitize_reason( $reason ) {
		$allowed = array(
			'ok',
			'redirect',
			'forbidden',
			'not_found',
			'gone',
			'rate_limited',
			'client_error',
			'server_error',
			'timeout',
			'dns',
			'ssl',
			'connection',
			'blocked',
			'unsupported_scheme',
			'invalid_url',
		);

		$reason = is_string( $reason ) ? strtolower( trim( $reason ) ) : '';

		return in_array( $reason, $allowed, true ) ? $reason : '';
	}

	/**
	 * Loads a single link row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function get_link( $id ) {
		global $wpdb;

		$table = esc_sql( Database::table() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, prepared above.
		$row = $wpdb->get_row( $sql, ARRAY_A );

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
