<?php
/**
 * Verifies discovered link targets (internal and external) via HTTP,
 * a fixed number per Action Scheduler tick — never inline during the
 * main content scan, since checking every link's live status while
 * scanning every page would multiply scan time (Step 1 §30). Runs on
 * its own schedule, reading/writing wp_seodoc_links directly.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

use SEODoc\DB\Schema;
use SEODoc\Http_Client;
use SEODoc\Issues\Issue;
use SEODoc\Issues\Issue_Engine;
use SEODoc\Scanner\Action_Scheduler_Init;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Broken_Link_Checker {

	const HOOK = 'seodoc_check_broken_links';

	const GROUP = 'seodoc';

	/** HTTP checks are slow relative to DB-only work — keep batches small. */
	const BATCH_SIZE = 15;

	const RECHECK_AFTER = 7 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'process_batch' ) );

		// After suggestion generation (priority 30): kicks off the first
		// verification pass once a scan's link graph is fully written.
		add_action( 'seodoc_scan_completed', array( __CLASS__, 'schedule' ), 40 );
	}

	public static function schedule() {
		if ( ! Action_Scheduler_Init::is_available() ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_single_action( time(), self::HOOK, array(), self::GROUP );
		}
	}

	public function process_batch() {
		$targets = self::get_targets_needing_check( self::BATCH_SIZE );

		if ( empty( $targets ) ) {
			return;
		}

		foreach ( $targets as $target ) {
			self::check_and_record( $target->target_hash, $target->target_url );
		}

		// A full batch likely means more are waiting — keep going.
		if ( count( $targets ) === self::BATCH_SIZE ) {
			self::schedule();
		}
	}

	private static function get_targets_needing_check( $limit ) {
		global $wpdb;
		$table        = Schema::table_names( $wpdb )['links'];
		$stale_before = gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_AFTER );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT target_hash, target_url FROM {$table}
				 WHERE last_checked < %s OR last_checked = first_detected
				 ORDER BY last_checked ASC LIMIT %d",
				$stale_before,
				$limit
			)
		);
	}

	private static function check_and_record( $target_hash, $target_url ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['links'];

		$response = Http_Client::head_no_redirect( $target_url );
		if ( is_wp_error( $response ) ) {
			// Some servers reject HEAD outright; a GET is more expensive
			// but more reliable, so it's only the fallback, not the default.
			$response = Http_Client::get_no_redirect( $target_url );
		}

		if ( is_wp_error( $response ) ) {
			$code             = 0;
			$is_broken        = 1;
			$redirect_target  = null;
		} else {
			$code            = (int) wp_remote_retrieve_response_code( $response );
			$is_broken       = $code >= 400 ? 1 : 0;
			$redirect_target = in_array( $code, array( 301, 302, 303, 307, 308 ), true )
				? wp_remote_retrieve_header( $response, 'location' )
				: null;
		}

		$wpdb->update(
			$table,
			array(
				'is_broken'       => $is_broken,
				'http_status'     => $code,
				'redirect_target' => $redirect_target,
				'last_checked'    => current_time( 'mysql' ),
			),
			array( 'target_hash' => $target_hash )
		);

		self::sync_issue( $table, $target_hash, $target_url, (bool) $is_broken );
	}

	/**
	 * One Issue per broken *target* (not per edge) — a broken URL linked
	 * from 5 pages is one row with 5 source pages listed in details,
	 * matching how Broken Link Intelligence is meant to read (Step 1 §8):
	 * the target is the fact, the sources are context on it.
	 */
	private static function sync_issue( $table, $target_hash, $target_url, $is_broken ) {
		global $wpdb;

		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_url, anchor_text, link_type FROM {$table} WHERE target_hash = %s",
				$target_hash
			)
		);

		if ( empty( $edges ) ) {
			return;
		}

		$link_type = $edges[0]->link_type;
		$check_id  = 'internal' === $link_type ? 'broken-internal-link' : 'broken-external-link';

		if ( ! $is_broken ) {
			Issue_Engine::resolve_single( $check_id, $target_url );
			return;
		}

		$sources = array();
		foreach ( $edges as $edge ) {
			$sources[] = array(
				'url'         => $edge->source_url,
				'anchor_text' => $edge->anchor_text,
			);
		}

		$issue = new Issue(
			array(
				'check_id'    => $check_id,
				'category'    => 'links',
				'severity'    => 'internal' === $link_type ? 'high' : 'medium',
				'object_type' => 'url',
				'object_id'   => null,
				'url'         => $target_url,
				'title'       => sprintf(
					/* translators: %d: number of pages linking to this broken URL. */
					_n(
						'A broken link points to this URL, from %d page.',
						'A broken link points to this URL, from %d pages.',
						count( $sources ),
						'wp-seo-doctor'
					),
					count( $sources )
				),
				'details'     => array( 'source_pages' => $sources ),
			)
		);

		Issue_Engine::upsert_single( null, $issue );
	}
}
