<?php
/**
 * Activation, deactivation and schema upgrades.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin lifecycle events.
 */
class Installer {

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'lwblc_db_version';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();

		update_option( self::DB_VERSION_OPTION, LWBLC_DB_VERSION, false );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Data is deliberately kept so a reactivation does not lose scan results.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::unschedule_all();
	}

	/**
	 * Creates or updates the links table.
	 *
	 * @return void
	 */
	public static function install_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Database::schema() );
	}

	/**
	 * Upgrades the schema when the stored version is behind the code version.
	 *
	 * Runs on every load, but only touches the database when the version
	 * differs, which also repairs installs where activation could not run
	 * (for example a plugin dropped in during a multisite network upgrade).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === LWBLC_DB_VERSION && Database::table_exists() ) {
			return;
		}

		self::install_tables();

		update_option( self::DB_VERSION_OPTION, LWBLC_DB_VERSION, false );
	}
}
