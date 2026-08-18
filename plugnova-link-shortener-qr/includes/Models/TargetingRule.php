<?php
/**
 * Value object representing one Geo/Device targeting rule for a link
 * (a row in wp_qlqr_targeting_rules).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TargetingRule
 */
final class TargetingRule {

	/**
	 * Constructor.
	 *
	 * @param int    $id              Row ID.
	 * @param int    $link_id         Owning link ID.
	 * @param string $rule_type       country|device.
	 * @param string $match_value     For "country": a 2-letter ISO 3166-1 alpha-2 code. For "device":
	 *                                 one of mobile|desktop|tablet.
	 * @param string $destination_url URL to send matching visitors to.
	 * @param int    $position        Evaluation order (0-indexed); the first matching rule wins.
	 * @param string $status          active|disabled.
	 * @param string $created_at      MySQL datetime string.
	 * @param string $updated_at      MySQL datetime string.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $link_id,
		public readonly string $rule_type,
		public readonly string $match_value,
		public readonly string $destination_url,
		public readonly int $position,
		public readonly string $status,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	/**
	 * Build a TargetingRule instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['link_id'],
			(string) ( $row['rule_type'] ?? 'country' ),
			(string) ( $row['match_value'] ?? '' ),
			(string) ( $row['destination_url'] ?? '' ),
			(int) ( $row['position'] ?? 0 ),
			(string) ( $row['status'] ?? 'active' ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * Whether this rule is currently eligible to be matched against a visitor.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}
}
