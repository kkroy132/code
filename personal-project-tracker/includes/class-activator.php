<?php
/**
 * Fired during plugin activation.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Activator
 */
class PTP_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * - Creates/updates database tables (idempotent).
	 * - Grants plugin capabilities to the Administrator role.
	 * - Seeds default settings without overwriting existing ones.
	 * - Records the currently installed plugin version.
	 *
	 * Cron events for reminders/notifications/smart alerts are registered by
	 * their owning modules in a later phase, once there is a handler for
	 * them to call — scheduling an event with no listener would be dead
	 * weight and risks duplicate or orphaned events.
	 */
	public static function activate() {
		PTP_Database::install();
		PTP_Permissions::add_capabilities();
		PTP_Settings::seed_defaults();

		update_option( 'ptp_version', PTP_VERSION );
	}
}
