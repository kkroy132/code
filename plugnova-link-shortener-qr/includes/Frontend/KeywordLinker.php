<?php
/**
 * Keyword auto-linking: automatically wraps the first occurrence(s) of configured keywords in
 * post content with a link to the matching short link, the way SEO/affiliate "auto-linking"
 * plugins work (e.g. "running shoes" anywhere in a post body becomes a link to your affiliate
 * short link for that product) — without the author having to manually insert the link.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Frontend;

use QuickLinkQRPro\Database\KeywordsRepository;
use QuickLinkQRPro\Helpers\Options;
use QuickLinkQRPro\Models\Keyword;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KeywordLinker
 */
final class KeywordLinker {

	/**
	 * Regex alternation of the elements whose text content must never be auto-linked, because it
	 * is code or literal text rather than prose. Kept as one string so the opening- and
	 * closing-tag patterns in apply_rules() can never fall out of sync.
	 */
	private const RAW_TEXT_ELEMENTS = 'script|style|textarea';

	/**
	 * Register the content filter, if the feature is enabled.
	 */
	public function register(): void {
		if ( ! Options::get( 'qlqr_keyword_autolink_enabled' ) ) {
			return;
		}

		// Priority 20: after most content-shaping filters (e.g. shortcode/block rendering,
		// wpautop) have already run, so keywords are matched against final rendered text rather
		// than raw shortcode/block markup.
		add_filter( 'the_content', array( $this, 'link_content' ), 20 );
	}

	/**
	 * Auto-link keywords found in a single post/page's main content.
	 *
	 * @param string $content Rendered post content.
	 * @return string
	 */
	public function link_content( string $content ): string {
		// Only the main content of a single post/page, in the main loop, on the front end —
		// never widgets, excerpts, REST responses, or admin previews, where auto-inserted links
		// would be surprising or could double up across multiple the_content() calls on one request.
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$rules = ( new KeywordsRepository() )->find_all_linkable();

		if ( empty( $rules ) ) {
			return $content;
		}

		return $this->apply_rules( $content, $rules );
	}

	/**
	 * Walk the content, alternating between HTML tags and text nodes, applying keyword rules
	 * only to text nodes that aren't already inside an <a>...</a>. This avoids a full DOM parser
	 * (which chokes on the imperfect HTML real-world post content often contains) while still
	 * never mangling markup or nesting an auto-link inside an existing link.
	 *
	 * @param string    $content Rendered post content.
	 * @param Keyword[] $rules   Active rules, longest keyword first (see KeywordsRepository::find_all_linkable()).
	 * @return string
	 */
	private function apply_rules( string $content, array $rules ): string {
		$budgets = array();
		foreach ( $rules as $rule ) {
			$budgets[ $rule->id ] = $rule->max_replacements;
		}

		/*
		 * One rule at a time over the whole document, re-splitting the markup between rules.
		 *
		 * Splitting once and then running every rule against each text node looks cheaper, but the
		 * anchor a rule inserts is only a string at that point — the next rule sees it as ordinary
		 * text and happily matches inside it. With rules for "Bluetooth Speaker" and "speaker" that
		 * produced <a ...>Bluetooth <a ...>Speaker</a></a>: nested anchors, which is invalid HTML and
		 * renders as a broken mess. Re-splitting makes each inserted anchor a real tag on the next
		 * pass, so the <a>-depth tracking below protects it like any other link in the post.
		 */
		foreach ( $rules as $rule ) {
			if ( $budgets[ $rule->id ] <= 0 || '' === trim( $rule->keyword ) ) {
				continue;
			}

			$content = $this->apply_one_rule( $content, $rule, $budgets );
		}

		return $content;
	}

	/**
	 * Apply a single rule across the document, touching only text that is neither inside an <a> nor
	 * inside a raw-text element.
	 *
	 * @param string          $content Post content, as left by any previously applied rule.
	 * @param Keyword         $rule    The rule to apply.
	 * @param array<int, int> $budgets Remaining replacement count per rule ID, by reference.
	 * @return string
	 */
	private function apply_one_rule( string $content, Keyword $rule, array &$budgets ): string {
		$segments = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $segments ) {
			return $content;
		}

		$link_depth = 0;
		$raw_depth  = 0;
		$output     = '';

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( '<' === $segment[0] ) {
				if ( preg_match( '/^<a[\s>]/i', $segment ) ) {
					++$link_depth;
				} elseif ( preg_match( '/^<\/a\s*>/i', $segment ) ) {
					$link_depth = max( 0, $link_depth - 1 );
				} elseif ( preg_match( '/^<(' . self::RAW_TEXT_ELEMENTS . ')[\s>]/i', $segment ) && ! str_ends_with( rtrim( $segment ), '/>' ) ) {
					++$raw_depth;
				} elseif ( preg_match( '/^<\/(' . self::RAW_TEXT_ELEMENTS . ')\s*>/i', $segment ) ) {
					$raw_depth = max( 0, $raw_depth - 1 );
				}

				$output .= $segment;
				continue;
			}

			/*
			 * Two reasons to leave a text node alone:
			 *  - it is already inside an <a>, so auto-linking would nest a link inside a link — both
			 *    links the author wrote and links an earlier rule inserted;
			 *  - it is inside <script>/<style>/<textarea>, whose contents are code or literal text,
			 *    not prose. Those are not caught by the <a> depth tracking above, and injecting an
			 *    <a> tag into a script block is a JavaScript syntax error that takes down every
			 *    other script on the page.
			 */
			$output .= ( 0 === $link_depth && 0 === $raw_depth )
				? $this->replace_in_text( $segment, $rule, $budgets )
				: $segment;
		}

		return $output;
	}

	/**
	 * Replace up to the rule's remaining budget of occurrences within one plain-text segment.
	 *
	 * @param string          $text    Plain-text segment (no HTML tags).
	 * @param Keyword         $rule    The rule to apply.
	 * @param array<int, int> $budgets Remaining replacement count per rule ID, by reference.
	 * @return string
	 */
	private function replace_in_text( string $text, Keyword $rule, array &$budgets ): string {
		if ( $budgets[ $rule->id ] <= 0 ) {
			return $text;
		}

		// \p{L}/\p{N} (Unicode letter/number), not \w/\b, so "word boundary" is correctly
		// script-aware for non-Latin keywords (e.g. Bengali) — \b only recognizes ASCII word
		// characters as boundaries even under the /u modifier.
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $rule->keyword, '/' ) . '(?![\p{L}\p{N}])/u' . ( $rule->case_sensitive ? '' : 'i' );

		$url  = $this->build_url( $rule );
		$rel  = $this->build_rel( $rule );
		$attr = $rel ? ' rel="' . esc_attr( $rel ) . '"' : '';
		$attr .= $rule->link_new_tab ? ' target="_blank"' : '';

		$replaced = 0;
		$result   = preg_replace_callback(
			$pattern,
			static fn( array $matches ): string => '<a href="' . esc_url( $url ) . '" class="qlqr-auto-link"' . $attr . '>' . esc_html( $matches[0] ) . '</a>',
			$text,
			$budgets[ $rule->id ],
			$replaced
		);

		// preg_replace_callback() returns null on a PCRE error (e.g. malformed UTF-8 reaching
		// the /u pattern, or backtrack-limit exhaustion on a very long paragraph). Assigning
		// that straight back would silently delete the visitor's content, so a failed rule is
		// skipped and the text left exactly as it was.
		if ( null === $result ) {
			return $text;
		}

		$budgets[ $rule->id ] -= $replaced;

		return $result;
	}

	/**
	 * Build the short URL for a keyword's linked link, from its joined slug (no extra query).
	 *
	 * @param Keyword $rule Keyword rule.
	 * @return string
	 */
	private function build_url( Keyword $rule ): string {
		$prefix = get_option( 'qlqr_url_prefix', 'go' );
		return home_url( '/' . trim( (string) $prefix, '/' ) . '/' . $rule->link_short_slug );
	}

	/**
	 * Build the rel="" attribute value matching the linked link's own nofollow/sponsored flags.
	 *
	 * @param Keyword $rule Keyword rule.
	 * @return string Space-separated rel tokens, or '' for none.
	 */
	private function build_rel( Keyword $rule ): string {
		$tokens = array();
		if ( $rule->link_nofollow ) {
			$tokens[] = 'nofollow';
		}
		if ( $rule->link_sponsored ) {
			$tokens[] = 'sponsored';
		}
		if ( $rule->link_new_tab ) {
			$tokens[] = 'noopener';
		}

		return implode( ' ', $tokens );
	}
}
