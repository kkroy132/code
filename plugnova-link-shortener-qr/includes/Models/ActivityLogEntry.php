<?php
/**
 * Value object representing a single audit-trail row (a row in wp_qlqr_activity_log).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityLogEntry
 *
 * Immutable DTO. Use Database\ActivityLogRepository to persist/query entries.
 */
final class ActivityLogEntry {

	/**
	 * Constructor.
	 *
	 * @param int         $id          Row ID.
	 * @param string      $object_type One of: link, bio_page, bulk.
	 * @param int|null    $object_id   ID of the affected row, or null for bulk-scope entries.
	 * @param string      $action      Short action key, e.g. created|updated|trashed|restored|deleted.
	 * @param string      $description Human-readable summary shown in the admin table.
	 * @param int         $user_id     WP user ID who performed the action (0 if unknown, e.g. cron).
	 * @param string      $user_name   Display name cached at write time (survives the user later being deleted).
	 * @param string      $created_at  MySQL datetime string.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $object_type,
		public readonly ?int $object_id,
		public readonly string $action,
		public readonly string $description,
		public readonly int $user_id,
		public readonly string $user_name,
		public readonly string $created_at
	) {}

	/**
	 * Build an ActivityLogEntry instance from a raw associative database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb (ARRAY_A).
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) ( $row['object_type'] ?? '' ),
			isset( $row['object_id'] ) && null !== $row['object_id'] ? (int) $row['object_id'] : null,
			(string) ( $row['action'] ?? '' ),
			(string) ( $row['description'] ?? '' ),
			(int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['user_name'] ?? '' ),
			(string) ( $row['created_at'] ?? '' )
		);
	}
}
