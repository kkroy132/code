<?php
/**
 * Removes plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package LWBLC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$lwblc_table = $wpdb->prefix . 'blc_links';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix; DROP cannot be prepared.
$wpdb->query( "DROP TABLE IF EXISTS {$lwblc_table}" );

delete_option( 'lwblc_db_version' );
delete_option( 'lwblc_scan_state' );
delete_option( 'lwblc_settings' );
