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
	 * Option used as a lock while a migration runs.
	 */
	const LOCK_OPTION = 'lwblc_upgrade_lock';

	/**
	 * Seconds after which an upgrade lock counts as abandoned.
	 */
	const LOCK_TIMEOUT = 300;

	/**
	 * Runs on plugin activation.
	 *
	 * Everything this plugin stores is per site: the table name is built from
	 * `$wpdb->prefix`, and the options and the scan state are site options. On
	 * a network activation only the site being activated is set up here — the
	 * others, and any site created later, install themselves the first time
	 * maybe_upgrade() runs there. Looping over every site at activation would
	 * be unbounded work on a large network, and there is nothing to share
	 * between sites, so no data can cross from one to another.
	 *
	 * @param bool $network_wide Whether the plugin was activated for the network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide ); // Each site installs itself; see the note above.

		self::install_tables();

		// Autoloaded on purpose: maybe_upgrade() reads it on every request.
		update_option( self::DB_VERSION_OPTION, LWBLC_DB_VERSION );

		// Start the recurring link check straight away.
		Checker::maybe_schedule();
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

		// Never run dbDelta() against a `blc_links` table owned by someone else.
		Database::resolve_table_name();

		dbDelta( Database::schema() );

		Database::flush_cache();
	}

	/**
	 * Brings the schema up to the version this code expects.
	 *
	 * Runs on every load, so the common case costs nothing but a comparison of
	 * an autoloaded option. It also covers installs where the activation hook
	 * could not run — a plugin dropped in over FTP, or a site added to a
	 * multisite network after the plugin was network activated.
	 *
	 * Migrations run before dbDelta(): a 1.1.x table still carries the old
	 * unique index under the name dbDelta() wants to reuse, so the index has to
	 * be swapped first. The version is only stored once every step succeeded,
	 * which makes an interrupted upgrade resume on the next request instead of
	 * being recorded as done.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '' );

		if ( LWBLC_DB_VERSION === $installed ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			Database::resolve_table_name();

			if ( ! Migrations::run( (string) $installed ) ) {
				// Incomplete: leave the version alone and pick it up again later.
				return;
			}

			self::install_tables();

			update_option( self::DB_VERSION_OPTION, LWBLC_DB_VERSION );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Takes the upgrade lock so two requests cannot migrate at once.
	 *
	 * A row that add_option() refuses to create already exists, which makes it
	 * an atomic test and set. A lock older than the timeout counts as abandoned.
	 *
	 * @return bool True when this process may migrate.
	 */
	private static function acquire_lock() {
		$existing = get_option( self::LOCK_OPTION );

		if ( false !== $existing ) {
			if ( (int) $existing + self::LOCK_TIMEOUT > time() ) {
				return false;
			}

			// Stale, left behind by a request that died mid-upgrade.
			self::release_lock();
		}

		return add_option( self::LOCK_OPTION, time(), '', 'no' );
	}

	/**
	 * Releases the upgrade lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
