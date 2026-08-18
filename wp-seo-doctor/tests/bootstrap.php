<?php
/**
 * Shared harness bootstrap.
 *
 * Loads the plugin the way WordPress does — by including its main file — so
 * the suites can never drift from the real module list. Keeping a duplicate
 * list here is how a newly added module quietly went missing from three
 * suites at once.
 *
 * @package WP_SEO_Doctor
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wpdb-stub.php';

global $wpdb;
$wpdb = new WPSD_Test_WPDB(
    getenv('WPSD_TEST_SOCKET') ?: '/tmp/wpsd-run/m.sock',
    getenv('WPSD_TEST_DB') ?: 'wpsd_test'
);

// The handful of functions the plugin's main file touches while loading.
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return rtrim(dirname($file), '/') . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/'; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) { return true; }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) { return true; }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style() { return true; }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script() { return true; }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script() { return true; }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'test-nonce'; }
}

require_once dirname(__DIR__) . '/wp-seo-doctor.php';

/**
 * Drop and recreate the plugin's tables plus a minimal wp_posts / wp_postmeta.
 */
function wpsd_test_reset_schema(): void {
    global $wpdb;

    foreach (array_keys(WPSD_DB::TABLES) as $key) {
        $wpdb->query('DROP TABLE IF EXISTS ' . WPSD_DB::table($key));
    }
    $wpdb->query('DROP TABLE IF EXISTS wp_posts, wp_postmeta');

    $wpdb->query("CREATE TABLE wp_posts (
        ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_title TEXT, post_content LONGTEXT, post_excerpt TEXT,
        post_name VARCHAR(200) DEFAULT '', post_type VARCHAR(20) DEFAULT 'post',
        post_status VARCHAR(20) DEFAULT 'publish',
        post_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        post_modified DATETIME DEFAULT CURRENT_TIMESTAMP,
        post_modified_gmt DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ID), KEY idx_name (post_name)
    ) DEFAULT CHARACTER SET utf8mb4");

    $wpdb->query("CREATE TABLE wp_postmeta (
        meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_key VARCHAR(255), meta_value LONGTEXT,
        PRIMARY KEY (meta_id), KEY idx_post (post_id), KEY idx_key (meta_key(191))
    ) DEFAULT CHARACTER SET utf8mb4");

    // Caches must not survive a schema reset.
    $GLOBALS['wpsd_post_cache'] = [];
    $GLOBALS['wpsd_meta_cache'] = [];

    WPSD_DB::install();
}
