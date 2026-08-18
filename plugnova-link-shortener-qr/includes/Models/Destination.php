<?php
/**
 * Value object representing one URL in a multi-destination rotating link
 * (a row in wp_qlqr_destinations).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Destination
 */
final class Destination {

	/**
	 * Constructor.
	 *
	 * @param int    $id              Row ID.
	 * @param int    $link_id         Owning link ID.
	 * @param string $destination_url Destination URL.
	 * @param int    $weight          Relative weight, used by the weighted-random rotation method.
	 * @param int    $position        Display/round-robin order (0-indexed).
	 * @param int    $clicks          Cached click counter for this specific destination.
	 * @param string $status          active|disabled.
	 * @param string $created_at      MySQL datetime string.
	 * @param string $updated_at      MySQL datetime string.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $link_id,
		public readonly string $destination_url,
		public readonly int $weight,
		public readonly int $position,
		public readonly int $clicks,
		public readonly string $status,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	/**
	 * Build a Destination instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['link_id'],
			(string) $row['destination_url'],
			max( 0, (int) ( $row['weight'] ?? 1 ) ),
			(int) ( $row['position'] ?? 0 ),
			(int) ( $row['clicks'] ?? 0 ),
			(string) ( $row['status'] ?? 'active' ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * Whether this destination is currently eligible to be selected by the rotation.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}
}
