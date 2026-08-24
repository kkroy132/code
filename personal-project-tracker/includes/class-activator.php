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
	 * Cron events for reminders/smart alerts are scheduled by
	 * PTP_Notifications_Module on admin_init (guarded by wp_next_scheduled()),
	 * not here — activation only needs to run once, while the admin_init
	 * guard also re-arms events if a table wipe or migration ever clears
	 * them out from under an already-active install.
	 */
	public static function activate() {
		PTP_Database::install();
		PTP_Permissions::add_capabilities();
		PTP_Settings::seed_defaults();

		update_option( 'ptp_version', PTP_VERSION );
	}
}
