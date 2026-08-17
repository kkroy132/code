<?php
/**
 * Uninstall handler.
 *
 * Nothing is removed unless the administrator explicitly enabled
 * "Remove all WP Site Toolkit data when uninstalling" in the plugin settings.
 * Only data created by this plugin is ever touched: posts, pages, media, users
 * and other plugins' data are left completely alone.
 *
 * @package WP_Site_Toolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every option, table and scheduled event for the current site.
 *
 * @return void
 */
function wpstk_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'wpstk_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}

	foreach ( array( 'wpstk_daily_maintenance', 'wpstk_auto_scan', 'wpstk_continue_scan' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	$tables = array(
		$wpdb->prefix . 'wpstk_checks',
		$wpdb->prefix . 'wpstk_scans',
		$wpdb->prefix . 'wpstk_not_found',
		$wpdb->prefix . 'wpstk_redirects',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix and cannot be bound as parameters.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	delete_option( 'wpstk_settings' );
	delete_option( 'wpstk_db_version' );
	delete_option( 'wpstk_scan_state' );
	delete_option( 'wpstk_redirect_count' );
}

if ( is_multisite() ) {
	$wpstk_offset = 0;

	do {
		$wpstk_site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 100,
				'offset'                 => $wpstk_offset,
				'update_site_meta_cache' => false,
			)
		);

		foreach ( $wpstk_site_ids as $wpstk_site_id ) {
			switch_to_blog( (int) $wpstk_site_id );
			wpstk_uninstall_site();
			restore_current_blog();
		}

		$wpstk_offset += 100;
	} while ( count( $wpstk_site_ids ) === 100 );

	unset( $wpstk_offset, $wpstk_site_ids, $wpstk_site_id );
} else {
	wpstk_uninstall_site();
}
