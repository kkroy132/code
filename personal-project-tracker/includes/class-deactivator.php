<?php
/**
 * Fired during plugin deactivation.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Deactivator
 */
class PTP_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * Clears only plugin-owned scheduled cron events. Never touches
	 * database tables, options, or user data — deactivation must be fully
	 * reversible by simply reactivating the plugin.
	 */
	public static function deactivate() {
		$hooks = array(
			'ptp_hourly_smart_alerts',
			'ptp_daily_reminder_check',
		);

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
