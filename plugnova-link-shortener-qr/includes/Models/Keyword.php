<?php
/**
 * Value object representing one keyword auto-linking rule (a row in wp_qlqr_keywords).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Keyword
 */
final class Keyword {

	/**
	 * Constructor.
	 *
	 * @param int    $id                Row ID.
	 * @param int    $link_id           Link this keyword auto-links to.
	 * @param string $keyword           The text to match in post content.
	 * @param bool   $case_sensitive    Whether matching is case-sensitive.
	 * @param int    $max_replacements  Max occurrences replaced per page/post.
	 * @param string $status            active|disabled.
	 * @param string $created_at        MySQL datetime string.
	 * @param string $updated_at        MySQL datetime string.
	 * @param string|null $link_title      Title of the linked link — only present on rows returned
	 *                                     by KeywordsRepository queries, which join it.
	 * @param string|null $link_short_slug Short slug of the linked link — same caveat as $link_title.
	 * @param bool        $link_nofollow   Whether the linked link is configured with rel="nofollow".
	 * @param bool        $link_sponsored  Whether the linked link is configured with rel="sponsored".
	 * @param bool        $link_new_tab    Whether the linked link is configured to open in a new tab.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $link_id,
		public readonly string $keyword,
		public readonly bool $case_sensitive,
		public readonly int $max_replacements,
		public readonly string $status,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $link_title = null,
		public readonly ?string $link_short_slug = null,
		public readonly bool $link_nofollow = false,
		public readonly bool $link_sponsored = false,
		public readonly bool $link_new_tab = true
	) {}

	/**
	 * Build a Keyword instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['link_id'],
			(string) ( $row['keyword'] ?? '' ),
			(bool) ( $row['case_sensitive'] ?? false ),
			max( 1, (int) ( $row['max_replacements'] ?? 1 ) ),
			(string) ( $row['status'] ?? 'active' ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
			isset( $row['link_title'] ) ? (string) $row['link_title'] : null,
			isset( $row['link_short_slug'] ) ? (string) $row['link_short_slug'] : null,
			(bool) ( $row['link_nofollow'] ?? false ),
			(bool) ( $row['link_sponsored'] ?? false ),
			(bool) ( $row['link_new_tab'] ?? true )
		);
	}

	/**
	 * Whether this rule is currently eligible to auto-link.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}
}
