<?php
/**
 * Convenience wrapper for writing to the admin action audit trail (wp_qlqr_activity_log).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

use QuickLinkQRPro\Database\ActivityLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityLogger
 */
final class ActivityLogger {

	/**
	 * How many days of entries to keep. Older rows are pruned daily so the table can't grow
	 * unbounded on a busy site.
	 */
	private const RETENTION_DAYS = 90;

	/**
	 * Register the daily pruning callback. Piggybacks on BrokenLinkChecker's existing daily
	 * wp-cron event rather than scheduling a second one, since one is already guaranteed to run.
	 */
	public static function register(): void {
		add_action( BrokenLinkChecker::CRON_HOOK, array( self::class, 'prune' ) );
	}

	/**
	 * Record one audit-trail entry. Never throws — a logging failure should never break the
	 * action it's describing, so errors are simply swallowed.
	 *
	 * @param string   $object_type One of: link, bio_page, bulk.
	 * @param int|null $object_id   ID of the affected row, or null for bulk-scope entries.
	 * @param string   $action      Short action key, e.g. created|updated|trashed|restored|deleted.
	 * @param string   $description Human-readable summary shown in the admin table.
	 */
	public static function log( string $object_type, ?int $object_id, string $action, string $description ): void {
		$user      = wp_get_current_user();
		$user_id   = $user && $user->exists() ? (int) $user->ID : 0;
		$user_name = $user && $user->exists() ? $user->display_name : __( 'System', 'plugnova-link-shortener-qr' );

		( new ActivityLogRepository() )->insert(
			array(
				'object_type' => substr( $object_type, 0, 20 ),
				'object_id'   => $object_id,
				'action'      => substr( $action, 0, 40 ),
				'description' => mb_substr( $description, 0, 500 ),
				'user_id'     => $user_id,
				'user_name'   => mb_substr( $user_name, 0, 190 ),
			)
		);
	}

	/**
	 * Delete entries older than the retention window. Hooked to the daily broken-link-check cron.
	 *
	 * The two analytics tables are swept here too, on the same schedule and for the same reason:
	 * they are the only other tables that grow with traffic rather than with the number of things
	 * the user created, and one already-guaranteed daily event is cheaper than scheduling more.
	 * Their window is a user setting (Settings > Analytics Retention) and defaults to keeping
	 * everything for existing installs — see Admin\SettingsPage::sanitize_click_retention().
	 */
	public static function prune(): void {
		( new ActivityLogRepository() )->prune( self::RETENTION_DAYS );

		$months = (int) get_option( 'qlqr_click_retention_months', 0 );

		if ( $months > 0 ) {
			( new \QuickLinkQRPro\Database\ClicksRepository() )->prune( $months );
			( new \QuickLinkQRPro\Database\BioViewsRepository() )->prune( $months );
		}
	}
}
