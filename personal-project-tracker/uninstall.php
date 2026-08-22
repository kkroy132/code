<?php
/**
 * Fired when the plugin is uninstalled (deleted) via the Plugins screen.
 *
 * By default this file does NOT remove any plugin data — only the explicit
 * "Delete all plugin data when uninstalling" setting (off by default, see
 * PTP_Settings::get_defaults()) opts a user into full data removal.
 *
 * @package Personal_Project_Tracker
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ptp_settings = get_option( 'ptp_settings', array() );
$ptp_delete   = is_array( $ptp_settings ) && ! empty( $ptp_settings['delete_data_on_uninstall'] );

if ( ! $ptp_delete ) {
	return;
}

global $wpdb;

$ptp_tables = array(
	'ptp_projects',
	'ptp_tasks',
	'ptp_subtasks',
	'ptp_milestones',
	'ptp_calendar_events',
	'ptp_time_entries',
	'ptp_notes',
	'ptp_project_links',
	'ptp_project_files',
	'ptp_expenses',
	'ptp_revenues',
	'ptp_notifications',
	'ptp_reminders',
	'ptp_prompt_documents',
	'ptp_prompt_templates',
	'ptp_activity_logs',
	'ptp_settings',
);

foreach ( $ptp_tables as $ptp_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit, opt-in uninstall cleanup of plugin-owned tables only.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $ptp_table );
}

delete_option( 'ptp_settings' );
delete_option( 'ptp_version' );
delete_option( 'ptp_db_version' );

$ptp_role = get_role( 'administrator' );

if ( $ptp_role ) {
	foreach ( array( 'ptp_manage_projects', 'ptp_manage_tasks', 'ptp_manage_finance', 'ptp_manage_settings', 'ptp_manage_data' ) as $ptp_cap ) {
		$ptp_role->remove_cap( $ptp_cap );
	}
}
