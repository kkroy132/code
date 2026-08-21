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

global $wpdb;

require_once plugin_dir_path(__FILE__) . 'includes/db.php';

// db.php refers to WPSD_VERSION when recording the schema version. Nothing
// here depends on the value, so it is defined as a marker rather than pinned
// to a release that will go stale.
if (!defined('WPSD_VERSION')) {
    define('WPSD_VERSION', 'uninstall');
}

WPSD_DB::drop_all();

delete_option('wpsd_settings');
delete_option('wpsd_db_version');
delete_option('wpsd_gsc_token');
delete_option('wpsd_gsc_last_sync');
delete_option('wpsd_sitemap_url');

foreach ([
    'wpsd_duplicate_index',
    'wpsd_content_shingles',
    'wpsd_crawl_depths',
    'wpsd_boilerplate_targets',
    'wpsd_notices',
    'wpsd_scan_lock',
] as $wpsd_transient) {
    delete_transient($wpsd_transient);
}

// Any transient added by a later version, without touching other plugins'.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_wpsd\_%'
        OR option_name LIKE '\_transient\_timeout\_wpsd\_%'"
);

// Post meta this plugin owns; meta belonging to other SEO plugins is left alone.
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
    'wpsd_retag_affiliate',
] as $wpsd_hook) {
    wp_clear_scheduled_hook($wpsd_hook);
}
