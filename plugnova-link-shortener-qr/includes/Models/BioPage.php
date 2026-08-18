<?php
/**
 * Value object representing a Smart Bio Link landing page (a row in wp_qlqr_bio_pages).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BioPage
 *
 * Immutable-ish DTO. Use BioPagesRepository to persist changes.
 */
final class BioPage {

	/**
	 * Constructor.
	 *
	 * @param int         $id           Row ID.
	 * @param string      $slug         URL-safe slug.
	 * @param string      $title        Display name shown at the top of the page.
	 * @param string|null $bio_text     Short bio/description shown under the title.
	 * @param string|null $avatar_url   Optional avatar image URL.
	 * @param string      $theme_color  Hex color used for buttons/accents.
	 * @param string      $theme_preset light|dark|gradient|minimal — overall page visual style.
	 * @param string      $button_style rounded|square|pill.
	 * @param bool        $email_capture_enabled Whether visitors must submit an email before the links unlock.
	 * @param string|null $email_capture_heading Custom heading shown on the email-capture gate, or null for a default.
	 * @param string|null $qr_image     Relative path/URL to the generated QR image for this page.
	 * @param string      $status       active|disabled.
	 * @param string|null $password     Hashed password; visitors must enter it before viewing the page if set.
	 * @param bool        $countdown_enabled Whether the countdown timer block is shown.
	 * @param string|null $countdown_label Text shown above the countdown (e.g. "Sale ends in").
	 * @param string|null $countdown_target_at MySQL datetime string the countdown counts down to.
	 * @param bool        $announcement_enabled Whether the announcement bar is shown.
	 * @param string|null $announcement_text Announcement bar text.
	 * @param string      $announcement_bg_color Announcement bar background hex color.
	 * @param string      $announcement_text_color Announcement bar text hex color.
	 * @param string|null $announcement_url Optional URL the announcement bar links to.
	 * @param string|null $starts_at    MySQL datetime string; page shows a "coming soon" gate before this time if set.
	 * @param string|null $ends_at      MySQL datetime string; page shows a "campaign ended" gate after this time if set.
	 * @param int         $total_views  Cached page view counter.
	 * @param int         $qr_views     Cached count of views that arrived via a scanned QR code.
	 * @param int         $created_by   Author user ID.
	 * @param string      $created_at   MySQL datetime string.
	 * @param string      $updated_at   MySQL datetime string.
	 * @param string|null $deleted_at   MySQL datetime string if trashed, else null.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $title,
		public readonly ?string $bio_text,
		public readonly ?string $avatar_url,
		public readonly string $theme_color,
		public readonly string $theme_preset,
		public readonly string $button_style,
		public readonly bool $email_capture_enabled,
		public readonly ?string $email_capture_heading,
		public readonly ?string $qr_image,
		public readonly string $status,
		public readonly ?string $password,
		public readonly bool $countdown_enabled,
		public readonly ?string $countdown_label,
		public readonly ?string $countdown_target_at,
		public readonly bool $announcement_enabled,
		public readonly ?string $announcement_text,
		public readonly string $announcement_bg_color,
		public readonly string $announcement_text_color,
		public readonly ?string $announcement_url,
		public readonly ?string $starts_at,
		public readonly ?string $ends_at,
		public readonly int $total_views,
		public readonly int $qr_views,
		public readonly int $created_by,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $deleted_at
	) {}

	/**
	 * Build a BioPage instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) ( $row['slug'] ?? '' ),
			(string) ( $row['title'] ?? '' ),
			$row['bio_text'] ?? null,
			$row['avatar_url'] ?? null,
			(string) ( $row['theme_color'] ?? '#2271b1' ),
			(string) ( $row['theme_preset'] ?? 'light' ),
			(string) ( $row['button_style'] ?? 'rounded' ),
			(bool) ( $row['email_capture_enabled'] ?? false ),
			$row['email_capture_heading'] ?? null,
			$row['qr_image'] ?? null,
			(string) ( $row['status'] ?? 'active' ),
			$row['password'] ?? null,
			(bool) ( $row['countdown_enabled'] ?? false ),
			$row['countdown_label'] ?? null,
			$row['countdown_target_at'] ?? null,
			(bool) ( $row['announcement_enabled'] ?? false ),
			$row['announcement_text'] ?? null,
			(string) ( $row['announcement_bg_color'] ?? '#2271b1' ),
			(string) ( $row['announcement_text_color'] ?? '#ffffff' ),
			$row['announcement_url'] ?? null,
			$row['starts_at'] ?? null,
			$row['ends_at'] ?? null,
			(int) ( $row['total_views'] ?? 0 ),
			(int) ( $row['qr_views'] ?? 0 ),
			(int) ( $row['created_by'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
			$row['deleted_at'] ?? null
		);
	}

	/**
	 * Whether the page can currently be viewed by the public (independent of its optional
	 * campaign schedule window — see campaign_schedule_status()).
	 *
	 * @return bool
	 */
	public function is_viewable(): bool {
		return 'active' === $this->status && null === $this->deleted_at;
	}

	/**
	 * Whether the page is password protected.
	 *
	 * @return bool
	 */
	public function is_password_protected(): bool {
		return ! empty( $this->password );
	}

	/**
	 * Whether — and where relative to — the page's optional campaign schedule window the current
	 * moment falls. Pages with neither $starts_at nor $ends_at set are always 'active'.
	 *
	 * @return string One of: 'not_started', 'active', 'ended'.
	 */
	public function campaign_schedule_status(): string {
		$now = time();

		if ( $this->starts_at && (int) get_gmt_from_date( $this->starts_at, 'U' ) > $now ) {
			return 'not_started';
		}

		if ( $this->ends_at && (int) get_gmt_from_date( $this->ends_at, 'U' ) < $now ) {
			return 'ended';
		}

		return 'active';
	}

	/**
	 * Whether the countdown timer block should currently be rendered: enabled, with a target in
	 * the future. A past target is simply not rendered, rather than showing a frozen "00:00:00".
	 *
	 * @return bool
	 */
	public function has_active_countdown(): bool {
		return $this->countdown_enabled
			&& $this->countdown_target_at
			&& (int) get_gmt_from_date( $this->countdown_target_at, 'U' ) > time();
	}

	/**
	 * Full public URL for this bio page, e.g. https://site.com/bio/janedoe.
	 *
	 * @return string
	 */
	public function get_public_url(): string {
		$prefix = get_option( 'qlqr_bio_url_prefix', 'bio' );
		return home_url( '/' . trim( (string) $prefix, '/' ) . '/' . $this->slug );
	}

	/**
	 * The public URL with a "?qlqr_src=qr" marker appended — this is what gets encoded into the
	 * generated QR image, so BioController can tell a scanned QR code apart from a directly
	 * clicked/typed link (see Database\ClicksRepository::qr_scan_count() for the short-link
	 * equivalent). The marker is stripped from any outbound link (button clicks never carry it).
	 *
	 * @return string
	 */
	public function get_public_url_for_qr(): string {
		return add_query_arg( 'qlqr_src', 'qr', $this->get_public_url() );
	}
}
