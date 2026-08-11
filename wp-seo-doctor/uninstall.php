<?php
/**
 * Runs only on Delete (not on deactivate). Leaves all data in place unless
 * the site owner has explicitly opted in via Settings > "Delete data on
 * uninstall" — never delete user data without explicit consent.
 *
 * @package SEODoc
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( 'yes' !== get_option( 'seodoc_delete_data_on_uninstall', 'no' ) ) {
	return;
}

require_once __DIR__ . '/includes/db/class-schema.php';

global $wpdb;

foreach ( \SEODoc\DB\Schema::table_names( $wpdb ) as $table ) {
	// Table names come from our own internal map, never from user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'seodoc_db_version' );
delete_option( 'seodoc_delete_data_on_uninstall' );
delete_option( 'seodoc_settings' );
delete_option( 'seodoc_dismissed_compat_notice' );

wp_clear_scheduled_hook( 'seodoc_daily_maintenance' );
