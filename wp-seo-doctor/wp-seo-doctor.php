<?php
/**
 * Plugin Name: WP SEO Doctor
 * Plugin URI:  https://example.com/wp-seo-doctor
 * Description: Complete SEO audit toolkit — on-page & technical audits, internal linking, broken links, 404 monitor, redirect manager, content SEO, Search Console, AI suggestions, affiliate & WooCommerce SEO, and reports.
 * Version:     1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      WP SEO Doctor
 * Text Domain: wp-seo-doctor
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

define('WPSD_VERSION', '1.1.0');
define('WPSD_FILE', __FILE__);
define('WPSD_DIR', plugin_dir_path(__FILE__));
define('WPSD_URL', plugin_dir_url(__FILE__));
define('WPSD_SLUG', 'wp-seo-doctor');

/**
 * Module load order matters: helpers and db first, then the engines, then UI.
 */
foreach ([
    'helpers',
    'db',
    'settings',
    'issues',
    'score',
    'fingerprints',
    'checks/registry',
    'checks/on-page',
    'checks/technical',
    'checks/content',
    'checks/links',
    'checks/affiliate',
    'checks/woocommerce',
    'scanner',
    'internal-links',
    'broken-links',
    'monitor-404',
    'redirects',
    'content-seo',
    'gsc',
    'ai',
    'affiliate',
    'woocommerce',
    'reports',
    'export',
    'admin-menu',
    'ajax',
    'cron',
] as $wpsd_module) {
    require_once WPSD_DIR . "includes/{$wpsd_module}.php";
}

register_activation_hook(__FILE__, ['WPSD_DB', 'install']);
register_deactivation_hook(__FILE__, ['WPSD_Cron', 'clear_all']);

add_action('plugins_loaded', function () {
    // Upgrade routine for existing installs.
    if (get_option('wpsd_db_version') !== WPSD_VERSION) {
        WPSD_DB::install();
    }

    WPSD_Checks::init();
    WPSD_Scanner::init();
    WPSD_Internal_Links::init();
    WPSD_Broken_Links::init();
    WPSD_Monitor_404::init();
    WPSD_Redirects::init();
    WPSD_Content_SEO::init();
    WPSD_GSC::init();
    WPSD_AI::init();
    WPSD_Affiliate::init();
    WPSD_WooCommerce_SEO::init();
    WPSD_Reports::init();
    WPSD_Export::init();
    WPSD_Cron::init();

    if (is_admin()) {
        WPSD_Admin_Menu::init();
        WPSD_Ajax::init();
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, WPSD_SLUG) === false) {
        return;
    }

    wp_enqueue_style('wpsd-admin', WPSD_URL . 'admin/css/admin.css', [], WPSD_VERSION);
    wp_enqueue_script('wpsd-admin', WPSD_URL . 'admin/js/admin.js', ['jquery'], WPSD_VERSION, true);
    wp_localize_script('wpsd-admin', 'wpsdData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('wpsd_nonce'),
        'i18n'    => [
            'confirmDelete' => __('Are you sure? This cannot be undone.', 'wp-seo-doctor'),
            'working'       => __('Working…', 'wp-seo-doctor'),
            'failed'        => __('Request failed. Please try again.', 'wp-seo-doctor'),
            'scanning'      => __('Scanning', 'wp-seo-doctor'),
            'done'          => __('Done', 'wp-seo-doctor'),
        ],
    ]);
});
