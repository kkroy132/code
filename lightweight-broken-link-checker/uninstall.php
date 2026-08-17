<?php
/**
 * Removes plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package LWBLC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * Cancel anything still queued in Action Scheduler. The third argument is the
 * action group, which stays `lwblc` — it is not the text domain.
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'lwblc_scan_batch', null, 'lwblc' );
	as_unschedule_all_actions( 'lwblc_scan_post', null, 'lwblc' );
	as_unschedule_all_actions( 'lwblc_check_batch', null, 'lwblc' );
}

// Matches the name chosen at activation (see LWBLC\Database::resolve_table_name()).
$lwblc_table_name = get_option( 'lwblc_table_name', 'blc_links' );
$lwblc_table      = $wpdb->prefix . ( 'lwblc_links' === $lwblc_table_name ? 'lwblc_links' : 'blc_links' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix; dropping the plugin table on uninstall is the point.
$wpdb->query( "DROP TABLE IF EXISTS {$lwblc_table}" );

delete_option( 'lwblc_db_version' );
delete_option( 'lwblc_scan_state' );
delete_option( 'lwblc_table_name' );

// Per user screen option.
delete_metadata( 'user', 0, 'lwblc_links_per_page', '', true );
