<?php
/**
 * Value object representing one entry in a Smart Bio Link page's social-icon row
 * (a row in wp_qlqr_bio_socials).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BioSocial
 */
final class BioSocial {

	/**
	 * Recognized platform keys mapped to a monochrome inline SVG icon shown in the circular icon
	 * button. Inline (not a separate icon font file) so no extra asset request is added to every
	 * public bio page view — consistent with the rest of the plugin's "no external icon library"
	 * approach (see Bio Link's per-button emoji icon field).
	 *
	 * Deliberately not emoji: mixing colorful, OS-rendered emoji (🌐📷🐦) with plain letters ('f',
	 * 'in') looked inconsistent — each browser/OS draws emoji in its own multi-color style, clashing
	 * with the flat single-color circular buttons. Every icon here uses stroke/fill="currentColor"
	 * so it always matches the bio page's theme text color, in both light and dark themes.
	 */
	public const PLATFORMS = array(
		'website'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
		'facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
		'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
		'twitter'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="4" x2="20" y2="20"/><line x1="20" y1="4" x2="4" y2="20"/></svg>',
		'youtube'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="4"/><polygon points="10 9 16 12 10 15"/></svg>',
		'tiktok'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
		'linkedin'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>',
		'whatsapp'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
		'email'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
		'phone'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
		'telegram'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
	);

	/**
	 * Constructor.
	 *
	 * @param int    $id          Row ID.
	 * @param int    $bio_page_id Owning bio page ID.
	 * @param string $platform    One of PLATFORMS' keys.
	 * @param string $url         Target URL (or "mailto:"/"tel:" for email/phone-style platforms).
	 * @param int    $position    Display order (0-indexed).
	 * @param string $status      active|disabled.
	 * @param bool   $is_floating Whether this renders as a fixed-position floating action button
	 *                             instead of an inline icon in the social row (see templates/bio-page.php).
	 * @param string $created_at  MySQL datetime string.
	 * @param string $updated_at  MySQL datetime string.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $bio_page_id,
		public readonly string $platform,
		public readonly string $url,
		public readonly int $position,
		public readonly string $status,
		public readonly bool $is_floating,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	/**
	 * Build a BioSocial instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['bio_page_id'],
			(string) ( $row['platform'] ?? 'website' ),
			(string) ( $row['url'] ?? '' ),
			(int) ( $row['position'] ?? 0 ),
			(string) ( $row['status'] ?? 'active' ),
			(bool) ( $row['is_floating'] ?? false ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * Whether this icon is currently shown to visitors.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * The trusted, plugin-authored inline SVG markup shown inside this icon's circular button.
	 * Never influenced by user input — every possible return value is one of the fixed strings in
	 * PLATFORMS above — but callers still must not echo it raw: pass it through
	 * wp_kses( $social->icon_svg(), BioSocial::icon_svg_allowed_html() ) so the output stays
	 * verifiably escaped even though this string itself is trusted.
	 *
	 * @return string
	 */
	public function icon_svg(): string {
		return self::PLATFORMS[ $this->platform ] ?? self::PLATFORMS['website'];
	}

	/**
	 * The wp_kses() allowed-tags array for icon_svg()'s output — every tag and attribute actually
	 * used across PLATFORMS above, and nothing more. Shared here so every output site uses the
	 * same allowlist instead of each echo site duplicating (and risking drifting) its own copy.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function icon_svg_allowed_html(): array {
		$presentation_attrs = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
		);

		return array(
			'svg'      => array_merge(
				$presentation_attrs,
				array(
					'viewbox' => true, // wp_kses matches attribute names lower-cased; output keeps the original "viewBox" casing.
					'xmlns'   => true,
				)
			),
			'path'     => array_merge( $presentation_attrs, array( 'd' => true ) ),
			'circle'   => array_merge(
				$presentation_attrs,
				array(
					'cx' => true,
					'cy' => true,
					'r'  => true,
				)
			),
			'rect'     => array_merge(
				$presentation_attrs,
				array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
					'ry'     => true,
				)
			),
			'line'     => array_merge(
				$presentation_attrs,
				array(
					'x1' => true,
					'y1' => true,
					'x2' => true,
					'y2' => true,
				)
			),
			'polygon'  => array_merge( $presentation_attrs, array( 'points' => true ) ),
			'polyline' => array_merge( $presentation_attrs, array( 'points' => true ) ),
		);
	}
}
