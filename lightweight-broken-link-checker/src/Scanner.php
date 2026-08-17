<?php
/**
 * Content scanner.
 *
 * Walks published content in small batches and stores every outbound link it
 * finds. Batches are chained through Action Scheduler, so only one batch is ever
 * in flight and the site never pays for a full scan in a single request.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

use DOMDocument;

/**
 * Discovers links inside post content.
 */
class Scanner {

	/**
	 * Option holding the state of the current/last scan.
	 */
	const STATE_OPTION = 'lwblc_scan_state';

	/**
	 * Posts handled per batch.
	 */
	const BATCH_SIZE = 50;

	/**
	 * URL schemes that are never checked.
	 *
	 * @var string[]
	 */
	private static $ignored_schemes = array( 'mailto', 'tel', 'sms', 'javascript', 'data', 'skype', 'callto', 'whatsapp', 'ftp' );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( Scheduler::HOOK_SCAN_BATCH, array( __CLASS__, 'run_batch' ), 10, 1 );
		add_action( Scheduler::HOOK_SCAN_POST, array( __CLASS__, 'scan_post' ), 10, 1 );

		/*
		 * Covers publishing, editing, unpublishing and trashing in one place:
		 * wp_trash_post() goes through wp_update_post(), which fires this hook
		 * with the new status and the previous post object.
		 */
		add_action( 'wp_after_insert_post', array( __CLASS__, 'queue_post_scan' ), 10, 4 );

		// Keep the table from collecting links whose source post is gone.
		add_action( 'deleted_post', array( __CLASS__, 'delete_post_links' ), 10, 1 );
	}

	/**
	 * Whether saved posts are rescanned automatically.
	 *
	 * @return bool
	 */
	public static function auto_scan_enabled() {
		/**
		 * Filters whether saving a post queues a rescan of that post.
		 *
		 * @param bool $enabled Enabled by default.
		 */
		return (bool) apply_filters( 'lwblc_auto_scan_on_save', true );
	}

	/**
	 * Queues a rescan of a single post after it was saved.
	 *
	 * The work itself is deferred to Action Scheduler so the editor never waits
	 * for link extraction.
	 *
	 * @param int           $post_id     Saved post ID.
	 * @param \WP_Post      $post        Saved post object.
	 * @param bool          $update      Whether this was an update.
	 * @param \WP_Post|null $post_before Post before the update, null on insert.
	 * @return void
	 */
	public static function queue_post_scan( $post_id, $post, $update = false, $post_before = null ) {
		$post_id = (int) $post_id;

		if ( ! self::auto_scan_enabled() || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			/*
			 * Unpublished, trashed or scheduled again: its links must not stay
			 * on the list. Only worth a query if the post used to be published.
			 */
			if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
				self::delete_post_links( $post_id );
			}

			return;
		}

		// `unique` keeps repeated saves of the same post to a single queued job.
		Scheduler::schedule_single( time(), Scheduler::HOOK_SCAN_POST, array( $post_id ), true );
	}

	/**
	 * Rescans one post and syncs its stored links.
	 *
	 * @param int $post_id Post to scan.
	 * @return int Number of links newly stored.
	 */
	public static function scan_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			self::delete_post_links( $post_id );

			return 0;
		}

		$links = self::extract_links( (string) $post->post_content );
		$urls  = array();
		$found = 0;

		foreach ( $links as $link ) {
			$urls[] = $link['url'];

			$stored = self::store_link(
				$link['url'],
				$link['text'],
				$post_id,
				(string) $post->post_title,
				(string) $post->post_modified_gmt
			);

			if ( $stored ) {
				++$found;
			}
		}

		self::prune_post_links( $post_id, $urls );

		return $found;
	}

	/**
	 * Post types included in a scan.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		unset( $types['attachment'] );

		/**
		 * Filters the post types included in a scan.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = apply_filters( 'lwblc_scan_post_types', array_values( $types ) );

		return array_values( array_filter( array_map( 'strval', (array) $types ) ) );
	}

	/**
	 * Default (empty) scan state.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_state() {
		return array(
			'running'       => false,
			'offset'        => 0,
			'scanned'       => 0,
			'total'         => 0,
			'links_found'   => 0,
			'started_at'    => '',
			'finished_at'   => '',
			'last_batch_at' => '',
		);
	}

	/**
	 * Returns the current scan state.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_merge( self::default_state(), $state );
	}

	/**
	 * Persists the scan state.
	 *
	 * @param array<string,mixed> $state State to store.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, array_merge( self::default_state(), $state ), false );
	}

	/**
	 * Whether a scan is currently in progress.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::get_state();

		return ! empty( $state['running'] );
	}

	/**
	 * Starts a fresh scan and queues the first batch.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function start() {
		if ( ! Scheduler::is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Action Scheduler is not available, so the scan cannot be queued.', 'lightweight-broken-link-checker' ),
			);
		}

		if ( self::is_running() ) {
			return array(
				'success' => false,
				'message' => __( 'A scan is already running.', 'lightweight-broken-link-checker' ),
			);
		}

		// Drop any stale batch left behind by an interrupted scan.
		Scheduler::unschedule( Scheduler::HOOK_SCAN_BATCH );

		self::save_state(
			array(
				'running'    => true,
				'offset'     => 0,
				'scanned'    => 0,
				'total'      => self::count_scannable_posts(),
				'started_at' => Plugin::now(),
			)
		);

		Scheduler::schedule_single( time(), Scheduler::HOOK_SCAN_BATCH, array( 0 ) );

		return array(
			'success' => true,
			'message' => __( 'Scan started. Progress updates automatically.', 'lightweight-broken-link-checker' ),
		);
	}

	/**
	 * Cancels a running scan.
	 *
	 * @return void
	 */
	public static function cancel() {
		Scheduler::unschedule( Scheduler::HOOK_SCAN_BATCH );

		$state                = self::get_state();
		$state['running']     = false;
		$state['finished_at'] = Plugin::now();

		self::save_state( $state );
	}

	/**
	 * Counts posts a scan would visit.
	 *
	 * @return int
	 */
	public static function count_scannable_posts() {
		global $wpdb;

		$post_types = self::post_types();

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds only %s tokens; values are passed as an array.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ({$placeholders})",
			array_merge( array( 'publish' ), $post_types )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Processes one batch of posts and chains the next one.
	 *
	 * @param int $offset Offset of the batch to process.
	 * @return void
	 */
	public static function run_batch( $offset = 0 ) {
		global $wpdb;

		$offset = max( 0, (int) $offset );
		$state  = self::get_state();

		if ( empty( $state['running'] ) ) {
			return;
		}

		$post_types = self::post_types();

		if ( empty( $post_types ) ) {
			self::finish( $state );
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$args         = array_merge( array( 'publish' ), $post_types, array( self::BATCH_SIZE, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds only %s tokens; values are passed as an array.
		$sql = $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_modified_gmt
			FROM {$wpdb->posts}
			WHERE post_status = %s AND post_type IN ({$placeholders})
			ORDER BY ID ASC
			LIMIT %d OFFSET %d",
			$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		$posts = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $posts ) ) {
			self::finish( $state );
			return;
		}

		$found = 0;

		foreach ( $posts as $post ) {
			$links = self::extract_links( (string) $post['post_content'] );
			$urls  = array();

			foreach ( $links as $link ) {
				$urls[] = $link['url'];

				$stored = self::store_link(
					$link['url'],
					$link['text'],
					(int) $post['ID'],
					(string) $post['post_title'],
					(string) $post['post_modified_gmt']
				);

				if ( $stored ) {
					++$found;
				}
			}

			// Links that were edited out of the post must not stay on the list.
			self::prune_post_links( (int) $post['ID'], $urls );
		}

		$state['offset']        = $offset + count( $posts );
		$state['scanned']       = $state['offset'];
		$state['links_found']   = (int) $state['links_found'] + $found;
		$state['last_batch_at'] = Plugin::now();

		if ( count( $posts ) < self::BATCH_SIZE ) {
			self::finish( $state );
			return;
		}

		self::save_state( $state );

		Scheduler::schedule_single( time(), Scheduler::HOOK_SCAN_BATCH, array( $state['offset'] ) );
	}

	/**
	 * Marks a scan as finished.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return void
	 */
	private static function finish( array $state ) {
		$state['running']     = false;
		$state['finished_at'] = Plugin::now();

		// Posts can be deleted mid-scan, so trust the number actually walked.
		$state['total'] = max( (int) $state['scanned'], 0 );

		self::save_state( $state );

		/**
		 * Fires when a full content scan completes.
		 *
		 * @param array $state Final scan state.
		 */
		do_action( 'lwblc_scan_finished', $state );
	}

	/**
	 * Extracts checkable links from a piece of HTML.
	 *
	 * @param string $content Post content.
	 * @return array<int,array{url:string,text:string}>
	 */
	public static function extract_links( $content ) {
		$content = trim( (string) $content );

		if ( '' === $content || false === strpos( $content, '<a' ) ) {
			return array();
		}

		// ext-dom is bundled with virtually every PHP build, but never assume it.
		if ( ! class_exists( 'DOMDocument' ) ) {
			return array();
		}

		$document = new DOMDocument();

		$previous = libxml_use_internal_errors( true );

		// The meta tag forces UTF-8 parsing; DOMDocument assumes ISO-8859-1 otherwise.
		$loaded = $document->loadHTML(
			'<?xml encoding="utf-8" ?><html><body>' . $content . '</body></html>'
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array();
		}

		$links = array();
		$seen  = array();

		foreach ( $document->getElementsByTagName( 'a' ) as $anchor ) {
			$href = trim( (string) $anchor->getAttribute( 'href' ) );

			$url = self::normalize_url( $href );

			if ( '' === $url ) {
				continue;
			}

			$key = strtolower( $url );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
			$text = trim( preg_replace( '/\s+/u', ' ', (string) $anchor->textContent ) );

			$links[] = array(
				'url'  => $url,
				'text' => $text,
			);
		}

		return $links;
	}

	/**
	 * Normalises a href and rejects everything that cannot be HTTP checked.
	 *
	 * Rejects mailto:, tel: and friends, in-page anchors, empty hrefs and
	 * protocol-less fragments. Protocol relative URLs are upgraded to https.
	 *
	 * @param string $href Raw href attribute.
	 * @return string Checkable absolute URL, or an empty string.
	 */
	public static function normalize_url( $href ) {
		$href = trim( (string) $href );

		if ( '' === $href || '#' === $href[0] ) {
			return '';
		}

		// Strip the fragment; it never affects reachability.
		$hash = strpos( $href, '#' );

		if ( false !== $hash ) {
			$href = substr( $href, 0, $hash );
			$href = trim( $href );

			if ( '' === $href ) {
				return '';
			}
		}

		// Protocol relative URL.
		if ( 0 === strpos( $href, '//' ) ) {
			$href = 'https:' . $href;
		}

		// Root or document relative URL: resolve against the site address.
		if ( 0 === strpos( $href, '/' ) ) {
			$href = untrailingslashit( home_url() ) . $href;
		}

		$scheme = wp_parse_url( $href, PHP_URL_SCHEME );

		if ( empty( $scheme ) ) {
			return '';
		}

		$scheme = strtolower( $scheme );

		if ( in_array( $scheme, self::$ignored_schemes, true ) ) {
			return '';
		}

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( ! wp_parse_url( $href, PHP_URL_HOST ) ) {
			return '';
		}

		if ( strlen( $href ) > 2048 ) {
			return '';
		}

		/**
		 * Filters a discovered URL before it is stored.
		 *
		 * Return an empty string to skip the link.
		 *
		 * @param string $href Normalised URL.
		 */
		return (string) apply_filters( 'lwblc_normalize_url', $href );
	}

	/**
	 * Inserts a link, or refreshes the source metadata of an existing one.
	 *
	 * An existing row keeps its status, http_code and fail_count: only the
	 * information that comes from the post is refreshed. New rows start as
	 * `pending`.
	 *
	 * @param string $url           Link URL.
	 * @param string $text          Anchor text.
	 * @param int    $post_id       Source post ID.
	 * @param string $post_title    Source post title.
	 * @param string $post_modified Source post modified date (GMT).
	 * @return bool True when a new link was inserted.
	 */
	public static function store_link( $url, $text, $post_id, $post_title, $post_modified ) {
		global $wpdb;

		$table = Database::table();
		$now   = Plugin::now();

		$url           = (string) $url;
		$text          = self::trim_length( wp_strip_all_tags( (string) $text ), 255 );
		$post_id       = (int) $post_id;
		$post_title    = self::trim_length( wp_strip_all_tags( (string) $post_title ), 255 );
		$post_modified = self::sanitize_datetime( $post_modified );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
		$lookup_sql = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source_post_id = %d AND link_url = %s",
			$post_id,
			$url
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, prepared above.
		$existing_id = $wpdb->get_var( $lookup_sql );

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->update(
				$table,
				array(
					'link_text'          => $text,
					'source_post_title'  => $post_title,
					'post_modified_date' => $post_modified,
					'updated_at'         => $now,
				),
				array( 'id' => (int) $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$inserted = $wpdb->insert(
			$table,
			array(
				'link_url'           => $url,
				'link_text'          => $text,
				'source_post_id'     => $post_id,
				'source_post_title'  => $post_title,
				'post_modified_date' => $post_modified,
				'last_checked_at'    => null,
				'status'             => Database::STATUS_PENDING,
				'http_code'          => 0,
				'fail_count'         => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * Removes stored links of a post that its content no longer contains.
	 *
	 * @param int      $post_id   Source post ID.
	 * @param string[] $keep_urls URLs found in the current content.
	 * @return int Rows removed.
	 */
	public static function prune_post_links( $post_id, array $keep_urls ) {
		global $wpdb;

		$post_id = (int) $post_id;

		if ( $post_id < 1 ) {
			return 0;
		}

		$table = Database::table();

		if ( empty( $keep_urls ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
			$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE source_post_id = %d", $post_id );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $keep_urls ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table comes from $wpdb->prefix, $placeholders holds only %s tokens.
			$sql = $wpdb->prepare(
				"DELETE FROM {$table} WHERE source_post_id = %d AND link_url NOT IN ({$placeholders})",
				array_merge( array( $post_id ), array_values( $keep_urls ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, prepared above.
		$removed = (int) $wpdb->query( $sql );

		if ( $removed > 0 ) {
			Database::flush_cache();
		}

		return $removed;
	}

	/**
	 * Removes every stored link of a deleted post.
	 *
	 * @param int $post_id Deleted post ID.
	 * @return void
	 */
	public static function delete_post_links( $post_id ) {
		self::prune_post_links( (int) $post_id, array() );
	}

	/**
	 * Trims a string to a maximum length, multibyte safe.
	 *
	 * @param string $value  Input string.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function trim_length( $value, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Validates a MySQL datetime string.
	 *
	 * @param string $value Candidate datetime.
	 * @return string|null Valid datetime, or null.
	 */
	private static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}
