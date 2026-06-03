<?php
/**
 * Plugin Name: Sinemagor — AI Movie Review
 * Description: AI-powered movie review generator with TMDB integration and OpenRouter
 * Version: 1.6.5
 * Author: Sinemagor
 * Text Domain: sinemagor
 */

defined('ABSPATH') || exit;

define('SINEMAGOR_VERSION', '1.6.5');
define('SINEMAGOR_FILE',    __FILE__);
define('SINEMAGOR_DIR',     plugin_dir_path(__FILE__));
define('SINEMAGOR_URL',     plugin_dir_url(__FILE__));
define('SINEMAGOR_TABLE',   'sinemagor_movies');

// Autoload includes
foreach ([
    'db',
    'settings',
    'tmdb-api',
    'tmdb-feeds',
    'ai-generator',
    'post-publisher',
    'internal-linker',
    'duplicate-detector',
    'auto-regenerate',
    'cost-tracker',
    'bulk-status',
    'faq-generator',
    'model-tester',
    'where-to-watch',
    'sitemap-ping',
    'indexnow',
    'affiliate-engine',
    'star-rating',
    'post-health',
    'bulk-rebuild',
    'list-generator',
    'comparison',
    'toc',
    'cwv',
    'auto-category',
    'content-linker',
    'retro-linker',
    'meta-boxes',
    'schema',
    'admin-menu',
    'ajax',
    'queue',
    'auto-pilot',
    'widgets',
    'shortcode',
    'frontend',
] as $file) {
    require_once SINEMAGOR_DIR . "includes/{$file}.php";
}

// Activation — create all DB tables
register_activation_hook(__FILE__, function () {
    Sinemagor_DB::create_table();
    Sinemagor_Cost_Tracker::create_table();
    Sinemagor_Star_Rating::create_table();
});

// Boot
add_action('plugins_loaded', function () {
    Sinemagor_Admin_Menu::init();
    Sinemagor_Ajax::init();
    Sinemagor_Meta_Boxes::init();
    Sinemagor_Schema::init();
    Sinemagor_Frontend::init();
    Sinemagor_Shortcode::init();
    Sinemagor_Internal_Linker::init();
    Sinemagor_Auto_Pilot::init();
    Sinemagor_Auto_Regenerate::init();
    Sinemagor_Duplicate_Detector::init();
    Sinemagor_Bulk_Status::init();
    Sinemagor_Widgets::init();
    Sinemagor_FAQ_Generator::init();
    Sinemagor_Model_Tester::init();
    Sinemagor_Where_To_Watch::init();
    Sinemagor_Sitemap_Ping::init();
    Sinemagor_IndexNow::init();
    Sinemagor_Affiliate_Engine::init();
    Sinemagor_Star_Rating::init();
    Sinemagor_Post_Health::init();
    Sinemagor_Bulk_Rebuild::init();
    Sinemagor_List_Generator::init();
    Sinemagor_Comparison::init();
    Sinemagor_TOC::init();
    Sinemagor_CWV::init();
    Sinemagor_Auto_Category::init();
    Sinemagor_Content_Linker::init();
    Sinemagor_Retro_Linker::init();
});

// Single post template override (theme-independent)
add_filter('template_include', function ($template) {
    if (!is_single()) return $template;
    if (!get_post_meta(get_the_ID(), '_sinemagor_tmdb_id', true)) return $template;
    $custom = SINEMAGOR_DIR . 'templates/single-movie.php';
    return file_exists($custom) ? $custom : $template;
});

// Load admin assets
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'sinemagor') === false) return;
    wp_enqueue_style('sinemagor-admin',  SINEMAGOR_URL . 'admin/css/admin.css',       [], SINEMAGOR_VERSION);
    wp_enqueue_script('sinemagor-admin', SINEMAGOR_URL . 'admin/js/admin.js',         ['jquery'], SINEMAGOR_VERSION, true);
    wp_enqueue_script('sinemagor-list',  SINEMAGOR_URL . 'admin/js/list-generator.js',['jquery'], SINEMAGOR_VERSION, true);
    wp_localize_script('sinemagor-admin', 'sinemagor', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('sinemagor_nonce'),
    ]);
});

// Load frontend assets
add_action('wp_enqueue_scripts', function () {
    if (!is_singular('post') && !is_page()) return;
    wp_enqueue_style('sinemagor-darkmode', SINEMAGOR_URL . 'public/css/dark-mode.css', [], SINEMAGOR_VERSION);
    wp_enqueue_script('sinemagor-theme',   SINEMAGOR_URL . 'public/js/theme-toggle.js', [], SINEMAGOR_VERSION, false);
});
