<?php
/**
 * Custom table creation and maintenance.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the three plugin tables: scans, checks and the 404 log.
 *
 * @since 1.0.0
 */
class WPSTK_Database {

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'wpstk_db_version';

	/**
	 * Returns the scans table name.
	 *
	 * @return string
	 */
	public static function scans_table() {
		global $wpdb;

		return $wpdb->prefix . 'wpstk_scans';
	}

	/**
	 * Returns the checks table name.
	 *
	 * @return string
	 */
	public static function checks_table() {
		global $wpdb;

		return $wpdb->prefix . 'wpstk_checks';
	}

	/**
	 * Returns the 404 log table name.
	 *
	 * @return string
	 */
	public static function not_found_table() {
		global $wpdb;

		return $wpdb->prefix . 'wpstk_not_found';
	}

	/**
	 * Creates or updates the plugin tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$scans           = self::scans_table();
		$checks          = self::checks_table();
		$not_found       = self::not_found_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$scans} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	started_at datetime NOT NULL,
	finished_at datetime DEFAULT NULL,
	status varchar(20) NOT NULL DEFAULT 'running',
	trigger_type varchar(20) NOT NULL DEFAULT 'manual',
	overall_score smallint(5) unsigned DEFAULT NULL,
	scores longtext,
	summary longtext,
	critical_count int(10) unsigned NOT NULL DEFAULT 0,
	warning_count int(10) unsigned NOT NULL DEFAULT 0,
	recommendation_count int(10) unsigned NOT NULL DEFAULT 0,
	passed_count int(10) unsigned NOT NULL DEFAULT 0,
	skipped_count int(10) unsigned NOT NULL DEFAULT 0,
	total_checks int(10) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY status_started (status,started_at)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$checks} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	scan_id bigint(20) unsigned NOT NULL,
	module varchar(32) NOT NULL DEFAULT '',
	check_id varchar(64) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'passed',
	label text,
	summary text,
	why text,
	action_text text,
	note text,
	items longtext,
	items_total int(10) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY scan_module (scan_id,module),
	KEY scan_status (scan_id,status)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$not_found} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	url_hash char(32) NOT NULL DEFAULT '',
	url varchar(255) NOT NULL DEFAULT '',
	referrer varchar(255) NOT NULL DEFAULT '',
	hits bigint(20) unsigned NOT NULL DEFAULT 1,
	first_seen datetime NOT NULL,
	last_seen datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY url_hash (url_hash),
	KEY last_seen (last_seen)
) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::VERSION_OPTION, WPSTK_DB_VERSION, false );
	}

	/**
	 * Runs the installer when the stored schema version is out of date.
	 *
	 * Also covers multisite sites that were created after network activation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) === WPSTK_DB_VERSION ) {
			return;
		}

		self::install();
		WPSTK_Settings::install_defaults();
		WPSTK_Cron::schedule_events();
	}

	/**
	 * Whether every plugin table exists.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( array( self::scans_table(), self::checks_table(), self::not_found_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, not cacheable.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Removes every row created by the plugin without dropping the tables.
	 *
	 * @return void
	 */
	public static function truncate_all() {
		global $wpdb;

		foreach ( array( self::checks_table(), self::scans_table(), self::not_found_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}
}
