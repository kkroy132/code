<?php
/**
 * Value object representing a single button on a Smart Bio Link page
 * (a row in wp_qlqr_bio_links).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BioLink
 */
final class BioLink {

	/**
	 * Constructor.
	 *
	 * @param int    $id          Row ID.
	 * @param int    $bio_page_id Owning bio page ID.
	 * @param string $label       Button text (for a group header, this is the group's title).
	 * @param string $url         Destination URL (ignored for a group header).
	 * @param string|null $icon   Optional single emoji shown before the label (used when image_url is empty).
	 * @param string|null $image_url Optional thumbnail image URL; when set, the button renders as a
	 *                                "product card" (thumbnail + label) instead of a plain text row,
	 *                                the whole card still being a single link to $url.
	 * @param int    $position    Display order (0-indexed).
	 * @param int    $clicks      Cached click counter for this specific button.
	 * @param string $status      active|disabled.
	 * @param string|null $starts_at MySQL datetime string; button is hidden before this time if set.
	 * @param string|null $ends_at   MySQL datetime string; button is hidden after this time if set.
	 * @param string $item_type   link|group — a 'group' row is an accordion header; its own url/icon/
	 *                             image_url/schedule/visibility are ignored, and any 'link' row whose
	 *                             parent_group_key matches its group_key renders nested underneath it.
	 * @param string|null $group_key For a 'group' row: its own stable identifier (client-generated,
	 *                                 e.g. "g_xxxxx"). Null for 'link' rows.
	 * @param string|null $parent_group_key For a 'link' row: the group_key of the group it belongs
	 *                                        to, or null if it's a top-level (ungrouped) button.
	 * @param string|null $visible_countries Comma-separated uppercase ISO country codes this button
	 *                                         is restricted to, or null/empty for "all countries".
	 * @param string|null $visible_devices Comma-separated of mobile/desktop/tablet this button is
	 *                                       restricted to, or null/empty for "all devices".
	 * @param string $created_at  MySQL datetime string.
	 * @param string $updated_at  MySQL datetime string.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $bio_page_id,
		public readonly string $label,
		public readonly string $url,
		public readonly ?string $icon,
		public readonly ?string $image_url,
		public readonly int $position,
		public readonly int $clicks,
		public readonly string $status,
		public readonly ?string $starts_at,
		public readonly ?string $ends_at,
		public readonly string $item_type,
		public readonly ?string $group_key,
		public readonly ?string $parent_group_key,
		public readonly ?string $visible_countries,
		public readonly ?string $visible_devices,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	/**
	 * Build a BioLink instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['bio_page_id'],
			(string) ( $row['label'] ?? '' ),
			(string) ( $row['url'] ?? '' ),
			$row['icon'] ?? null,
			$row['image_url'] ?? null,
			(int) ( $row['position'] ?? 0 ),
			(int) ( $row['clicks'] ?? 0 ),
			(string) ( $row['status'] ?? 'active' ),
			$row['starts_at'] ?? null,
			$row['ends_at'] ?? null,
			(string) ( $row['item_type'] ?? 'link' ),
			$row['group_key'] ?? null,
			$row['parent_group_key'] ?? null,
			$row['visible_countries'] ?? null,
			$row['visible_devices'] ?? null,
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * Whether this button is active (independent of its schedule window — see is_currently_visible()).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Whether this row is a group (accordion) header rather than an individual link button.
	 *
	 * @return bool
	 */
	public function is_group_header(): bool {
		return 'group' === $this->item_type;
	}

	/**
	 * Whether this button should currently be shown to a public visitor: active, within its
	 * schedule window (if any), and — when a $country/$device are supplied — matching its
	 * Country/Device visibility restriction (if any).
	 *
	 * @param string|null $country Visitor's 2-letter ISO country code, or null if unknown.
	 * @param string|null $device  Visitor's device type (mobile|desktop|tablet), or null to skip that check.
	 * @return bool
	 */
	public function is_currently_visible( ?string $country = null, ?string $device = null ): bool {
		if ( ! $this->is_active() ) {
			return false;
		}

		$now = time();

		if ( $this->starts_at && (int) get_gmt_from_date( $this->starts_at, 'U' ) > $now ) {
			return false;
		}

		if ( $this->ends_at && (int) get_gmt_from_date( $this->ends_at, 'U' ) < $now ) {
			return false;
		}

		if ( $this->visible_countries ) {
			$allowed = array_map( 'trim', explode( ',', $this->visible_countries ) );
			if ( ! $country || ! in_array( strtoupper( $country ), $allowed, true ) ) {
				return false;
			}
		}

		if ( $this->visible_devices ) {
			$allowed = array_map( 'trim', explode( ',', $this->visible_devices ) );
			if ( ! $device || ! in_array( $device, $allowed, true ) ) {
				return false;
			}
		}

		return true;
	}
}
