<?php
/**
 * Uninstall script — runs when the plugin is deleted from WP Admin.
 *
 * Removes:
 *   - Custom database table wp_push_subscribers
 *   - All wp_options rows created by the plugin
 *   - Any scheduled WP-Cron events
 */

// Security: only run when WordPress itself calls this file during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── 1. Drop the subscribers table ────────────────────────────────────────────
$table = $wpdb->prefix . 'push_subscribers';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore

// ── 2. Remove all plugin options ─────────────────────────────────────────────
$options = [
    'wnp_vapid_public_key',
    'wnp_vapid_private_key',
    'wnp_vapid_subject',
    'wnp_popup_title',
    'wnp_popup_body',
    'wnp_popup_delay',
    'wnp_send_queue',
    'wnp_send_log',
    'wnp_batch_lock',   // process lock added in v1.0.0
    'wnp_db_version',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── 3. Clear scheduled cron events ───────────────────────────────────────────
wp_clear_scheduled_hook( 'wnp_process_batch' );

// ── 4. Flush rewrite rules so /wnp-service-worker.js 404s cleanly ────────────
flush_rewrite_rules();
