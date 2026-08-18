<?php
/**
 * Checks whether a link's destination_url is still reachable, and records the result on the
 * link row (link_status, http_status, last_checked_at). Runs on a daily wp-cron schedule and can
 * also be triggered on demand from the Links admin screen.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

use QuickLinkQRPro\Database\LinksRepository;
use QuickLinkQRPro\Models\Link;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BrokenLinkChecker
 */
final class BrokenLinkChecker {

	/**
	 * The wp-cron hook name for the recurring check.
	 */
	public const CRON_HOOK = 'qlqr_broken_link_check';

	/**
	 * Upper bound on how many links a single run (cron or manual "Check All") processes. Keeps
	 * one run within typical shared-hosting execution time limits; sites with more links than
	 * this simply finish the rest on the next scheduled run.
	 */
	private const MAX_PER_RUN = 200;

	/**
	 * Register the wp-cron hook. Scheduling/unscheduling the event itself happens when the
	 * "Automatically check for broken links" setting is turned on/off (see
	 * Admin\SettingsPage::reschedule_broken_link_check()) and, for a reactivated site that already
	 * had it on, on plugin activation (see plugnova-link-shortener-qr.php).
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_scheduled_check' ) );
	}

	/**
	 * Cron entry point — re-checks the setting immediately before running, not just at schedule
	 * time, so a stray leftover scheduled event (e.g. one left over from before this setting
	 * existed) can never run a check the admin hasn't actually turned on. Each run is an outbound
	 * request to every link's own third-party destination server, so this must stay opt-in.
	 *
	 * The manual "Check Now" admin action calls check_all() directly instead of through here,
	 * since clicking that button is itself the explicit, one-time consent for that single run.
	 */
	public static function run_scheduled_check(): void {
		if ( ! get_option( 'qlqr_broken_link_check_enabled', 0 ) ) {
			return;
		}

		self::check_all();
	}

	/**
	 * Check every eligible link's destination_url (up to MAX_PER_RUN, oldest-checked-first so
	 * every link eventually gets a turn even on sites with more links than the cap), persisting
	 * results as it goes.
	 *
	 * @return array{checked:int, broken:int}
	 */
	public static function check_all(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- guarded by function_exists() and silenced; raising the ceiling is the point, and hosts that forbid it are unaffected, WordPress.PHP.NoSilencedErrors -- best-effort; some hosts disable this entirely.
		}

		$repository = new LinksRepository();
		$links      = $repository->find_all_checkable();

		// Oldest-checked (or never-checked) first, so a site with more links than MAX_PER_RUN
		// still cycles through all of them over successive runs instead of only ever checking
		// the same first N.
		usort(
			$links,
			static fn( Link $a, Link $b ): int => strcmp( (string) $a->last_checked_at, (string) $b->last_checked_at )
		);

		$links = array_slice( $links, 0, self::MAX_PER_RUN );

		$broken = 0;
		foreach ( $links as $link ) {
			$result = self::check_link( $link );
			$repository->record_check_result( $link->id, $result );

			if ( 'broken' === $result['status'] ) {
				++$broken;
			}
		}

		return array(
			'checked' => count( $links ),
			'broken'  => $broken,
		);
	}

	/**
	 * Check a single link, without persisting the result (callers decide what to do with it).
	 *
	 * Always issues a GET (not a HEAD-first-then-GET-fallback, as earlier versions did) because
	 * the response body is needed anyway to capture the destination page's &lt;title&gt; for the
	 * Smart Link Score's "Meta Title" check — `limit_response_size` caps how much of the body is
	 * actually downloaded, so this stays cheap even for large pages.
	 *
	 * @param Link $link Link to check.
	 * @return array{status:string, http_status:int|null, response_time_ms:int|null, meta_title:string|null, has_redirect_loop:bool} status is "ok" or "broken".
	 */
	public static function check_link( Link $link ): array {
		$args = array(
			'timeout'             => 10,
			'redirection'         => 5,
			'sslverify'           => false,
			'limit_response_size' => 200000,
			// Some servers reject requests with no UA (treating them as bots), so a
			// normal-looking browser UA matters for getting a representative response. Deliberately
			// no home_url() or other site-identifying detail here — the destination server doesn't
			// need to know which site is checking it.
			'user-agent'          => 'Mozilla/5.0 (compatible; PlugnovaLinkShortenerQR-BrokenLinkChecker/1.0)',
		);

		$start    = microtime( true );
		$response = wp_remote_get( $link->destination_url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			// WordPress's HTTP transports (cURL and streams alike) surface an exceeded redirect
			// count as a WP_Error whose message mentions "redirect" — the practical way to detect
			// a redirect loop without manually following Location headers ourselves.
			$is_loop = false !== stripos( $response->get_error_message(), 'redirect' );

			return array(
				'status'            => 'broken',
				'http_status'       => null,
				'response_time_ms'  => $is_loop ? $elapsed : null,
				'meta_title'        => null,
				'has_redirect_loop' => $is_loop,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$meta_title = null;
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $matches ) ) {
			$decoded = trim( html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES ) );
			$meta_title = '' !== $decoded ? mb_substr( $decoded, 0, 255 ) : null;
		}

		return array(
			'status'            => ( $code >= 200 && $code < 400 ) ? 'ok' : 'broken',
			'http_status'       => $code,
			'response_time_ms'  => $elapsed,
			'meta_title'        => $meta_title,
			'has_redirect_loop' => false,
		);
	}
}
