<?php
/**
 * Detects known bots, crawlers, and link-preview fetchers so their visits don't inflate
 * click analytics. This targets the two most common sources of "fake" clicks on short links:
 * (1) chat apps generating a link preview (WhatsApp, Facebook, Slack, Telegram, Discord...) and
 * (2) search engine / SEO / monitoring crawlers.
 *
 * This is necessarily a pattern-matching best effort, not a definitive bot classifier — new bots
 * appear constantly and some are deliberately designed to look like real browsers. Treat the
 * "bot-filtered" numbers as a meaningfully cleaner estimate, not a guarantee.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BotDetector
 */
final class BotDetector {

	/**
	 * Case-insensitive substrings matched against the User-Agent header. Covers link-preview
	 * crawlers from chat/social apps and well-known search/SEO/monitoring bots.
	 *
	 * @var string[]
	 */
	private const SIGNATURES = array(
		// Chat & social link-preview fetchers.
		'facebookexternalhit',
		'facebookcatalog',
		'whatsapp',
		'telegrambot',
		'slackbot',
		'discordbot',
		'skypeuripreview',
		'linkedinbot',
		'twitterbot',
		'pinterest',
		'redditbot',
		'vkshare',
		'viber',
		// Search engines / SEO / uptime & monitoring / generic crawlers.
		'googlebot',
		'bingbot',
		'yandexbot',
		'duckduckbot',
		'baiduspider',
		'ahrefsbot',
		'semrushbot',
		'mj12bot',
		'dotbot',
		'petalbot',
		'applebot',
		'uptimerobot',
		'pingdom',
		'statuscake',
		'site24x7',
		'headlesschrome',
		'phantomjs',
		'bot.htm',
		'bot.php',
		'crawler',
		'spider',
		'scrapy',
		'curl/',
		'wget/',
		'python-requests',
		'python-urllib',
		'go-http-client',
		'java/',
		'libwww-perl',
		'okhttp',
		'postmanruntime',
	);

	/**
	 * Whether the given User-Agent string looks like a bot/crawler rather than a real visitor.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return bool
	 */
	public static function is_bot( string $user_agent ): bool {
		if ( '' === trim( $user_agent ) ) {
			// Real browsers always send a User-Agent; a completely empty one is far more
			// consistent with a script/bot than a person.
			return true;
		}

		$haystack = strtolower( $user_agent );

		foreach ( self::SIGNATURES as $signature ) {
			if ( str_contains( $haystack, $signature ) ) {
				return true;
			}
		}

		return false;
	}
}
