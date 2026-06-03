<?php
defined('ABSPATH') || exit;

class Sinemagor_Admin_Menu {

    public static function init(): void {
        add_action('admin_menu',  [self::class, 'register_menu']);
        add_action('admin_init',  [self::class, 'handle_redirects']);
    }

    // Redirect old slug URLs to main page with ?tab= (no submenu entry needed)
    public static function handle_redirects(): void {
        $map = [
            'sinemagor-review'     => 'review',
            'sinemagor-list'       => 'list',
            'sinemagor-comparison' => 'comparison',
            'sinemagor-health'     => 'health',
        ];
        $page = $_GET['page'] ?? '';
        if (isset($map[$page])) {
            wp_redirect(admin_url('admin.php?page=sinemagor&tab=' . $map[$page]));
            exit;
        }
    }

    public static function register_menu(): void {
        add_menu_page(
            'Sinemagor',
            '🎬 Sinemagor',
            'edit_posts',
            'sinemagor',
            [self::class, 'render_page'],
            'dashicons-video-alt2',
            25
        );
        // Only Settings as a visible submenu item
        add_submenu_page('sinemagor', 'Settings', 'Settings', 'manage_options', 'sinemagor-settings', [self::class, 'render_settings_page']);
    }

    public static function render_page(): void {
        $active = sanitize_key($_GET['tab'] ?? 'library');
        $allowed = ['library', 'review', 'list', 'comparison', 'indexnow', 'health'];
        if (!in_array($active, $allowed, true)) $active = 'library';

        // Prepare list posts data for JS
        $list_posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [['key' => '_sinemagor_list_post', 'value' => 1]],
        ]);
        $posts_data = array_map(fn($p) => [
            'title'       => $p->post_title,
            'status'      => $p->post_status,
            'template'    => get_post_meta($p->ID, '_sinemagor_list_template', true),
            'movie_count' => count(json_decode(get_post_meta($p->ID, '_sinemagor_list_movie_ids', true) ?: '[]', true)),
            'date'        => get_the_date('Y-m-d', $p),
            'edit_url'    => get_edit_post_link($p->ID, 'raw'),
            'view_url'    => get_permalink($p->ID),
        ], $list_posts);

        echo '<div class="wrap sg-wrap" id="sg-app" data-tab="' . esc_attr($active) . '">';
        echo '<script>window.sgListPosts = ' . wp_json_encode($posts_data) . ';</script>';

        self::render_tabs($active);

        // ── Library ──
        echo '<div id="sg-panel-library" class="sg-tab-panel"' . ($active !== 'library' ? ' style="display:none"' : '') . '>';
        require_once SINEMAGOR_DIR . 'templates/tab-library.php';
        echo '</div>';

        // ── Review & Publish ──
        echo '<div id="sg-panel-review" class="sg-tab-panel"' . ($active !== 'review' ? ' style="display:none"' : '') . '>';
        require_once SINEMAGOR_DIR . 'templates/tab-review.php';
        echo '</div>';

        // ── List Posts ──
        echo '<div id="sg-panel-list" class="sg-tab-panel"' . ($active !== 'list' ? ' style="display:none"' : '') . '>';
        require_once SINEMAGOR_DIR . 'templates/tab-list.php';
        echo '</div>';

        // ── Comparisons ──
        echo '<div id="sg-panel-comparison" class="sg-tab-panel"' . ($active !== 'comparison' ? ' style="display:none"' : '') . '>';
        require_once SINEMAGOR_DIR . 'templates/tab-comparison.php';
        echo '</div>';

        // ── IndexNow ──
        echo '<div id="sg-panel-indexnow" class="sg-tab-panel"' . ($active !== 'indexnow' ? ' style="display:none"' : '') . '>';
        require_once SINEMAGOR_DIR . 'templates/tab-indexnow.php';
        echo '</div>';

        // ── Post Health ──
        echo '<div id="sg-panel-health" class="sg-tab-panel"' . ($active !== 'health' ? ' style="display:none"' : '') . '>';

        echo '<div id="sg-rebuild-bar" style="display:none;background:#1a1a28;border:1px solid #2e2e45;border-radius:10px;padding:16px 20px;margin-bottom:20px">';
        echo '<strong style="color:#e0e0f0">🔗 Rebuilding Internal Links...</strong> ';
        echo '<span id="sg-rebuild-text" style="color:#aaa">0 / 0</span>';
        echo '<div style="height:6px;background:#2e2e45;border-radius:3px;margin-top:8px"><div id="sg-rebuild-fill" style="height:100%;background:#e8b84b;border-radius:3px;width:0%;transition:width .4s"></div></div>';
        echo '</div>';

        echo '<div style="margin-bottom:16px">';
        echo '<button id="sg-bulk-rebuild-btn" class="sg-btn sg-btn--secondary">🔗 Bulk Rebuild All Internal Links</button>';
        echo '</div>';

        Sinemagor_Post_Health::render();
        echo '</div>';

        echo '</div>'; // .sg-wrap
    }

    public static function render_settings_page(): void {
        echo '<div class="wrap sg-wrap" id="sg-app" data-tab="settings">';
        self::render_tabs('settings');
        Sinemagor_Settings::render();
        echo '</div>';
    }

    private static function render_tabs(string $active): void {
        $tabs = [
            'library'    => ['url' => admin_url('admin.php?page=sinemagor&tab=library'),    'label' => '🎬 Library',          'ajax' => true],
            'review'     => ['url' => admin_url('admin.php?page=sinemagor&tab=review'),     'label' => '📝 Review & Publish', 'ajax' => true],
            'list'       => ['url' => admin_url('admin.php?page=sinemagor&tab=list'),       'label' => '📋 List Posts',       'ajax' => true],
            'comparison' => ['url' => admin_url('admin.php?page=sinemagor&tab=comparison'), 'label' => '⚔️ Comparisons',     'ajax' => true],
            'indexnow'   => ['url' => admin_url('admin.php?page=sinemagor&tab=indexnow'),   'label' => '⚡ IndexNow',          'ajax' => true],
            'health'     => ['url' => admin_url('admin.php?page=sinemagor&tab=health'),     'label' => '🩺 Post Health',      'ajax' => true],
            'settings'   => ['url' => admin_url('admin.php?page=sinemagor-settings'),       'label' => '⚙️ Settings',         'ajax' => false],
        ];

        echo '<h1 class="sg-page-title">🎬 Sinemagor</h1>';
        echo '<nav class="sg-tabs">';
        foreach ($tabs as $key => $tab) {
            $class = $active === $key ? 'sg-tab sg-tab--active' : 'sg-tab';
            $data  = $tab['ajax'] ? ' data-tab="' . esc_attr($key) . '"' : '';
            echo '<a href="' . esc_url($tab['url']) . '" class="' . $class . '"' . $data . '>' . esc_html($tab['label']) . '</a>';
        }
        echo '</nav>';
    }
}
