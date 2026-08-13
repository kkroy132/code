<?php
/**
 * Uninstall routine — removes every trace of the plugin.
 *
 * Only runs when the user deletes the plugin from the Plugins screen, not on
 * deactivation.
 *
 * @package WP_SEO_Doctor
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once plugin_dir_path(__FILE__) . 'includes/db.php';

// Constants normally defined by the main plugin file are not available here.
if (!defined('WPSD_VERSION')) {
    define('WPSD_VERSION', '1.0.0');
}

WPSD_DB::drop_all();

delete_option('wpsd_settings');
delete_option('wpsd_db_version');
delete_option('wpsd_gsc_token');
delete_option('wpsd_gsc_last_sync');
delete_option('wpsd_sitemap_url');

delete_transient('wpsd_duplicate_index');
delete_transient('wpsd_content_shingles');
delete_transient('wpsd_crawl_depths');
delete_transient('wpsd_notices');
delete_transient('wpsd_scan_lock');

// Post meta this plugin owns; meta belonging to other SEO plugins is left alone.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
     WHERE meta_key IN ('_wpsd_title', '_wpsd_description', '_wpsd_focus_keyword', '_wpsd_noindex')"
);

foreach ([
    'wpsd_run_scheduled_scan',
    'wpsd_continue_scan',
    'wpsd_check_links_batch',
    'wpsd_sync_gsc',
    'wpsd_send_email_report',
    'wpsd_daily_housekeeping',
    'wpsd_prune_404s',
] as $wpsd_hook) {
    wp_clear_scheduled_hook($wpsd_hook);
}
