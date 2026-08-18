<?php
/**
 * Buckets raw referrer hostnames (as stored by RedirectController::record_click() and
 * BioController::record_view() — just the parsed host, e.g. "l.facebook.com") into a small,
 * human-readable set of traffic-source categories, so the Analytics breakdown shows "Facebook"
 * once instead of "facebook.com", "l.facebook.com", "m.facebook.com", and "lm.facebook.com" as
 * four separate, noisy rows.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReferrerCategorizer
 */
final class ReferrerCategorizer {

	/**
	 * Category label => list of hostname substrings that map to it. Checked in order, first
	 * match wins; a hostname matching none of these falls back to "Other".
	 */
	private const CATEGORIES = array(
		'Google'      => array( 'google.' ),
		'Facebook'    => array( 'facebook.com', 'fb.com', 'fb.me' ),
		'Instagram'   => array( 'instagram.com' ),
		'Twitter/X'   => array( 'twitter.com', 'x.com', 't.co' ),
		'YouTube'     => array( 'youtube.com', 'youtu.be' ),
		'TikTok'      => array( 'tiktok.com' ),
		'LinkedIn'    => array( 'linkedin.com', 'lnkd.in' ),
		'WhatsApp'    => array( 'whatsapp.com', 'wa.me' ),
		'Telegram'    => array( 'telegram.org', 't.me' ),
		'Pinterest'   => array( 'pinterest.com', 'pin.it' ),
		'Reddit'      => array( 'reddit.com' ),
		'Bing'        => array( 'bing.com' ),
		'DuckDuckGo'  => array( 'duckduckgo.com' ),
		'Yahoo'       => array( 'yahoo.com' ),
	);

	/**
	 * Categorize a single referrer hostname.
	 *
	 * Hostnames that match none of the categories above keep their own name rather than being
	 * merged into a single "Other" bucket. Grouping exists to stop one source appearing as four
	 * noisy rows ("facebook.com", "l.facebook.com", "m.facebook.com"…) — but an unrecognized
	 * hostname is a genuinely distinct source, and folding it into "Other" threw away the one
	 * piece of information the row existed to convey. "Other: 2" tells you nothing; "cinepulse.site:
	 * 2" tells you the clicks came from your own pages.
	 *
	 * @param string $hostname Raw hostname, e.g. "l.facebook.com" (may be empty).
	 * @return string Category label, the site's own name for a self-referral, or the hostname itself.
	 */
	public static function categorize( string $hostname ): string {
		$hostname = strtolower( trim( $hostname ) );

		if ( '' === $hostname ) {
			return 'Other';
		}

		foreach ( self::CATEGORIES as $label => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $hostname, $needle ) ) {
					return $label;
				}
			}
		}

		// "www." carries no meaning here and would otherwise split one source across two rows.
		$hostname = preg_replace( '/^www\./', '', $hostname ) ?? $hostname;

		/*
		 * Clicks that came from the site's own pages are the single most commonly misread row —
		 * they are usually the owner testing a link, or readers clicking it inside a post — so they
		 * are labelled as such instead of looking like an anonymous external referrer.
		 */
		$own_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$own_host = preg_replace( '/^www\./', '', $own_host ) ?? $own_host;

		if ( '' !== $own_host && $hostname === $own_host ) {
			/* translators: %s: the site's own domain name. */
			return sprintf( __( 'Your site (%s)', 'plugnova-link-shortener-qr' ), $own_host );
		}

		return $hostname;
	}

	/**
	 * Re-bucket a raw {label: hostname, <count_key>: int} breakdown (as returned by
	 * ClicksRepository::top_by_dimension('referrer', ...) or BioViewsRepository's equivalent)
	 * into categories, summing counts for hostnames that land in the same bucket and re-sorting
	 * descending. Pass a generously large $limit to the underlying query before calling this
	 * (e.g. 100 raw hostnames) so grouping isn't working from an already-truncated top-10 list.
	 *
	 * @param array<int, array<string, mixed>> $rows      Raw per-hostname rows.
	 * @param string                            $count_key The numeric key to sum ('clicks' or 'views').
	 * @param int                                $limit     Max categories to return after grouping.
	 * @return array<int, array<string, mixed>> Same shape as $rows, one row per category.
	 */
	public static function group( array $rows, string $count_key = 'clicks', int $limit = 10 ): array {
		$totals = array();

		foreach ( $rows as $row ) {
			$category = self::categorize( (string) ( $row['label'] ?? '' ) );
			$totals[ $category ] = ( $totals[ $category ] ?? 0 ) + (int) ( $row[ $count_key ] ?? 0 );
		}

		arsort( $totals );

		$grouped = array();
		foreach ( array_slice( $totals, 0, $limit, true ) as $label => $count ) {
			$grouped[] = array(
				'label'    => $label,
				$count_key => $count,
			);
		}

		return $grouped;
	}
}
