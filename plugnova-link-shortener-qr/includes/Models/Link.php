<?php
/**
 * Value object representing a single shortened link (a row in wp_qlqr_links).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Link
 *
 * Immutable-ish DTO. Use LinksRepository to persist changes.
 */
final class Link {

	/**
	 * Constructor.
	 *
	 * @param int         $id              Row ID.
	 * @param string      $title           Display title.
	 * @param string      $destination_url Destination URL to redirect to.
	 * @param string      $short_slug      URL-safe slug.
	 * @param string|null $qr_image        Relative path/URL to the generated QR image.
	 * @param string      $qr_style        square|rounded|circle.
	 * @param string      $qr_fg_color     Hex foreground color.
	 * @param string      $qr_bg_color     Hex background color.
	 * @param int|null    $qr_logo_id      Attachment ID of an overlay logo.
	 * @param string|null $qr_caption_text Optional caption text rendered below the QR image (e.g. "Check Price on Amazon").
	 * @param string      $destination_type single|multiple.
	 * @param string      $rotation_method  round_robin|random|weighted_random. Only relevant when destination_type is "multiple".
	 * @param int         $rotation_cursor  Internal round-robin position counter.
	 * @param string|null $fallback_url     Used when destination_type is "multiple" and no destination is currently active.
	 * @param string      $status          active|disabled.
	 * @param string|null $password        Hashed password, if link is protected.
	 * @param string|null $expires_at      MySQL datetime string, or null.
	 * @param int|null    $click_limit     Max allowed clicks, or null for unlimited.
	 * @param int         $redirect_type   301|302|307.
	 * @param string|null $utm_source      UTM source.
	 * @param string|null $utm_medium      UTM medium.
	 * @param string|null $utm_campaign    UTM campaign.
	 * @param string|null $tags            Comma-separated tags.
	 * @param string|null $category        Single free-text category/group name, or null.
	 * @param string|null $notes           Free-text notes.
	 * @param bool        $is_favorite     Favorite flag.
	 * @param bool        $nofollow        Rel=nofollow flag.
	 * @param bool        $sponsored       Rel=sponsored flag.
	 * @param bool        $new_tab         Open in new tab flag.
	 * @param bool        $has_targeting_rules Whether any Geo/Device targeting rules exist for this link
	 *                                          (cached flag so the redirect controller can skip querying
	 *                                          the targeting rules table for the common case of none).
	 * @param string      $link_status     unknown|ok|broken — last result from Helpers\BrokenLinkChecker.
	 * @param int|null    $http_status     Last observed HTTP status code for destination_url, or null if never checked.
	 * @param int|null    $response_time_ms Last observed response time in milliseconds, or null if never checked.
	 * @param string|null $meta_title      The destination page's &lt;title&gt; tag, captured at last check, or null.
	 * @param bool        $has_redirect_loop Whether the last check hit WordPress's redirect-limit error.
	 * @param string|null $color_label     Optional hex color for the row's visual label, or null.
	 * @param string|null $last_checked_at MySQL datetime string of the last broken-link check, or null.
	 * @param int         $total_clicks    Cached click counter.
	 * @param int         $created_by      Author user ID.
	 * @param string      $created_at      MySQL datetime string.
	 * @param string      $updated_at      MySQL datetime string.
	 * @param string|null $deleted_at      MySQL datetime string if trashed, else null.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $destination_url,
		public readonly string $short_slug,
		public readonly ?string $qr_image,
		public readonly string $qr_style,
		public readonly string $qr_fg_color,
		public readonly string $qr_bg_color,
		public readonly ?int $qr_logo_id,
		public readonly ?string $qr_caption_text,
		public readonly string $destination_type,
		public readonly string $rotation_method,
		public readonly int $rotation_cursor,
		public readonly ?string $fallback_url,
		public readonly string $status,
		public readonly ?string $password,
		public readonly ?string $expires_at,
		public readonly ?int $click_limit,
		public readonly int $redirect_type,
		public readonly ?string $utm_source,
		public readonly ?string $utm_medium,
		public readonly ?string $utm_campaign,
		public readonly ?string $tags,
		public readonly ?string $category,
		public readonly ?string $notes,
		public readonly bool $is_favorite,
		public readonly bool $nofollow,
		public readonly bool $sponsored,
		public readonly bool $new_tab,
		public readonly bool $has_targeting_rules,
		public readonly string $link_status,
		public readonly ?int $http_status,
		public readonly ?int $response_time_ms,
		public readonly ?string $meta_title,
		public readonly bool $has_redirect_loop,
		public readonly ?string $color_label,
		public readonly ?string $last_checked_at,
		public readonly int $total_clicks,
		public readonly int $created_by,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $deleted_at
	) {}

	/**
	 * Build a Link instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) ( $row['title'] ?? '' ),
			(string) ( $row['destination_url'] ?? '' ),
			(string) ( $row['short_slug'] ?? '' ),
			$row['qr_image'] ?? null,
			(string) ( $row['qr_style'] ?? 'square' ),
			(string) ( $row['qr_fg_color'] ?? '#000000' ),
			(string) ( $row['qr_bg_color'] ?? '#ffffff' ),
			isset( $row['qr_logo_id'] ) && null !== $row['qr_logo_id'] ? (int) $row['qr_logo_id'] : null,
			$row['qr_caption_text'] ?? null,
			(string) ( $row['destination_type'] ?? 'single' ),
			(string) ( $row['rotation_method'] ?? 'round_robin' ),
			(int) ( $row['rotation_cursor'] ?? 0 ),
			$row['fallback_url'] ?? null,
			(string) ( $row['status'] ?? 'active' ),
			$row['password'] ?? null,
			$row['expires_at'] ?? null,
			isset( $row['click_limit'] ) && null !== $row['click_limit'] ? (int) $row['click_limit'] : null,
			(int) ( $row['redirect_type'] ?? 301 ),
			$row['utm_source'] ?? null,
			$row['utm_medium'] ?? null,
			$row['utm_campaign'] ?? null,
			$row['tags'] ?? null,
			$row['category'] ?? null,
			$row['notes'] ?? null,
			(bool) ( $row['is_favorite'] ?? false ),
			(bool) ( $row['nofollow'] ?? false ),
			(bool) ( $row['sponsored'] ?? false ),
			(bool) ( $row['new_tab'] ?? true ),
			(bool) ( $row['has_targeting_rules'] ?? false ),
			(string) ( $row['link_status'] ?? 'unknown' ),
			isset( $row['http_status'] ) && null !== $row['http_status'] ? (int) $row['http_status'] : null,
			isset( $row['response_time_ms'] ) && null !== $row['response_time_ms'] ? (int) $row['response_time_ms'] : null,
			$row['meta_title'] ?? null,
			(bool) ( $row['has_redirect_loop'] ?? false ),
			$row['color_label'] ?? null,
			$row['last_checked_at'] ?? null,
			(int) ( $row['total_clicks'] ?? 0 ),
			(int) ( $row['created_by'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
			$row['deleted_at'] ?? null
		);
	}

	/**
	 * Whether the link has passed its expiration date.
	 *
	 * @return bool
	 */
	public function is_expired(): bool {
		if ( empty( $this->expires_at ) ) {
			return false;
		}

		return (int) get_gmt_from_date( $this->expires_at, 'U' ) < time();
	}

	/**
	 * Whether the link has reached its configured click limit.
	 *
	 * @return bool
	 */
	public function has_reached_click_limit(): bool {
		if ( null === $this->click_limit ) {
			return false;
		}

		return $this->total_clicks >= $this->click_limit;
	}

	/**
	 * Whether the link is password protected.
	 *
	 * @return bool
	 */
	public function is_password_protected(): bool {
		return ! empty( $this->password );
	}

	/**
	 * Whether the destination_url failed its most recent broken-link check.
	 * Multi-destination links aren't checked (each destination would need its own check), so
	 * this only ever reflects single-destination links.
	 *
	 * @return bool
	 */
	public function is_broken(): bool {
		return 'broken' === $this->link_status;
	}

	/**
	 * Whether the link can currently accept traffic and redirect.
	 *
	 * @return bool
	 */
	public function is_redirectable(): bool {
		return 'active' === $this->status
			&& null === $this->deleted_at
			&& ! $this->is_expired()
			&& ! $this->has_reached_click_limit();
	}

	/**
	 * Full public short URL for this link, e.g. https://site.com/go/abc123.
	 *
	 * @return string
	 */
	public function get_short_url(): string {
		$prefix = get_option( 'qlqr_url_prefix', 'go' );
		return home_url( '/' . trim( (string) $prefix, '/' ) . '/' . $this->short_slug );
	}

	/**
	 * The short URL with a "?qlqr_src=qr" marker appended — this, not get_short_url(), is what
	 * gets encoded into the generated QR image, so RedirectController can tell a scanned QR code
	 * apart from a directly-clicked/typed short link in click analytics (see
	 * Database\ClicksRepository::qr_scan_count()). The marker is stripped before the visitor is
	 * redirected onward, so it never leaks into the destination URL.
	 *
	 * @return string
	 */
	public function get_short_url_for_qr(): string {
		return add_query_arg( 'qlqr_src', 'qr', $this->get_short_url() );
	}

	/**
	 * The destination URL with UTM parameters appended, if configured.
	 * Only meaningful when destination_type is "single" — multi-destination links resolve their
	 * target via Helpers\DestinationRotator instead (see with_utm() below for building that URL).
	 *
	 * @return string
	 */
	public function get_destination_with_utm(): string {
		return $this->with_utm( $this->destination_url );
	}

	/**
	 * Whether this link rotates between multiple destination URLs rather than redirecting to a
	 * single fixed destination_url.
	 *
	 * @return bool
	 */
	public function is_multi_destination(): bool {
		return 'multiple' === $this->destination_type;
	}

	/**
	 * Append this link's configured UTM parameters (if any) to an arbitrary URL. Used both for
	 * the single-destination case (via get_destination_with_utm()) and for whichever URL the
	 * rotation algorithm picks in multi-destination mode.
	 *
	 * @param string $url Base URL to append UTM parameters to.
	 * @return string
	 */
	public function with_utm( string $url ): string {
		$params = array_filter(
			array(
				'utm_source'   => $this->utm_source,
				'utm_medium'   => $this->utm_medium,
				'utm_campaign' => $this->utm_campaign,
			)
		);

		if ( empty( $params ) ) {
			return $url;
		}

		return add_query_arg( $params, $url );
	}
}
