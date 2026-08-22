<?php
/**
 * Removes plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package LWBLC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Removes the table, options and screen option of the current site.
 *
 * @return void
 */
function lwblc_uninstall_site() {
	global $wpdb;

	// Matches the name chosen at activation (see LWBLC\Database::resolve_table_name()).
	$name  = get_option( 'lwblc_table_name', 'blc_links' );
	$table = $wpdb->prefix . ( 'lwblc_links' === $name ? 'lwblc_links' : 'blc_links' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix; dropping the plugin table on uninstall is the point.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	delete_option( 'lwblc_db_version' );
	delete_option( 'lwblc_scan_state' );
	delete_option( 'lwblc_table_name' );
	delete_option( 'lwblc_upgrade_lock' );
}

/*
 * Cancel anything still queued in Action Scheduler. The third argument is the
 * action group, which stays `lwblc` — it is not the text domain.
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'lwblc_scan_batch', null, 'lwblc' );
	as_unschedule_all_actions( 'lwblc_scan_post', null, 'lwblc' );
	as_unschedule_all_actions( 'lwblc_check_batch', null, 'lwblc' );
	as_unschedule_all_actions( 'lwblc_migrate', null, 'lwblc' );
}

/*
 * On a network, each site has its own table and options, so each one has to be
 * cleaned. Sites are walked in pages to keep the work bounded on large
 * networks.
 */
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$lwblc_offset = 0;

	do {
		$lwblc_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $lwblc_offset,
			)
		);

		foreach ( $lwblc_sites as $lwblc_site_id ) {
			switch_to_blog( $lwblc_site_id );
			lwblc_uninstall_site();
			restore_current_blog();
		}

		$lwblc_offset += 100;
	} while ( count( $lwblc_sites ) === 100 );
} else {
	lwblc_uninstall_site();
}

// Per user screen option; user meta is shared across a network.
delete_metadata( 'user', 0, 'lwblc_links_per_page', '', true );
