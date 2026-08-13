<?php
/**
 * Admin menu, page routing and shared UI partials.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Admin_Menu {

    const CAPABILITY = 'manage_options';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register']);
        add_action('admin_init', [self::class, 'handle_post_actions']);
        add_action('admin_notices', [self::class, 'render_notices']);
        add_filter('plugin_action_links_' . plugin_basename(WPSD_FILE), [self::class, 'plugin_action_links']);
    }

    /**
     * @return array<string,array{title:string,label:string,template:string,condition?:callable}>
     */
    public static function pages(): array {
        return [
            ''                 => [
                'title'    => __('SEO Dashboard', 'wp-seo-doctor'),
                'label'    => __('Dashboard', 'wp-seo-doctor'),
                'template' => 'dashboard',
            ],
            '-audit'           => [
                'title'    => __('SEO Audit', 'wp-seo-doctor'),
                'label'    => __('SEO Audit', 'wp-seo-doctor'),
                'template' => 'audit',
            ],
            '-issues'          => [
                'title'    => __('Issues', 'wp-seo-doctor'),
                'label'    => __('Issues', 'wp-seo-doctor'),
                'template' => 'issues',
            ],
            '-internal-links'  => [
                'title'    => __('Internal Linking', 'wp-seo-doctor'),
                'label'    => __('Internal Links', 'wp-seo-doctor'),
                'template' => 'internal-links',
            ],
            '-broken-links'    => [
                'title'    => __('Broken Links', 'wp-seo-doctor'),
                'label'    => __('Broken Links', 'wp-seo-doctor'),
                'template' => 'broken-links',
            ],
            '-404-monitor'     => [
                'title'    => __('404 Monitor', 'wp-seo-doctor'),
                'label'    => __('404 Monitor', 'wp-seo-doctor'),
                'template' => 'monitor-404',
            ],
            '-redirects'       => [
                'title'    => __('Redirect Manager', 'wp-seo-doctor'),
                'label'    => __('Redirects', 'wp-seo-doctor'),
                'template' => 'redirects',
            ],
            '-content'         => [
                'title'    => __('Content SEO', 'wp-seo-doctor'),
                'label'    => __('Content SEO', 'wp-seo-doctor'),
                'template' => 'content-seo',
            ],
            '-search-console'  => [
                'title'    => __('Google Search Console', 'wp-seo-doctor'),
                'label'    => __('Search Console', 'wp-seo-doctor'),
                'template' => 'search-console',
            ],
            '-ai'              => [
                'title'    => __('AI SEO', 'wp-seo-doctor'),
                'label'    => __('AI SEO', 'wp-seo-doctor'),
                'template' => 'ai',
            ],
            '-affiliate'       => [
                'title'    => __('Affiliate SEO', 'wp-seo-doctor'),
                'label'    => __('Affiliate SEO', 'wp-seo-doctor'),
                'template' => 'affiliate',
            ],
            '-woocommerce'     => [
                'title'     => __('WooCommerce SEO', 'wp-seo-doctor'),
                'label'     => __('WooCommerce SEO', 'wp-seo-doctor'),
                'template'  => 'woocommerce',
                'condition' => ['WPSD_WooCommerce_SEO', 'is_active'],
            ],
            '-reports'         => [
                'title'    => __('Reports', 'wp-seo-doctor'),
                'label'    => __('Reports', 'wp-seo-doctor'),
                'template' => 'reports',
            ],
            '-settings'        => [
                'title'    => __('Settings', 'wp-seo-doctor'),
                'label'    => __('Settings', 'wp-seo-doctor'),
                'template' => 'settings',
            ],
        ];
    }

    public static function register(): void {
        $counts = WPSD_Issues::severity_counts();
        $urgent = $counts['critical'] + $counts['high'];

        $menu_title = __('SEO Doctor', 'wp-seo-doctor');
        if ($urgent > 0) {
            $menu_title .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
                $urgent
            );
        }

        add_menu_page(
            __('WP SEO Doctor', 'wp-seo-doctor'),
            $menu_title,
            self::CAPABILITY,
            WPSD_SLUG,
            [self::class, 'render'],
            'dashicons-chart-area',
            58
        );

        foreach (self::pages() as $suffix => $page) {
            if (isset($page['condition']) && !call_user_func($page['condition'])) {
                continue;
            }
            add_submenu_page(
                WPSD_SLUG,
                $page['title'],
                $page['label'],
                self::CAPABILITY,
                WPSD_SLUG . $suffix,
                [self::class, 'render']
            );
        }
    }

    /**
     * Single render callback for every page — resolves the slug to a template.
     */
    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'wp-seo-doctor'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
        $slug   = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : WPSD_SLUG;
        $suffix = substr($slug, strlen(WPSD_SLUG));

        $pages = self::pages();
        $page  = $pages[$suffix] ?? $pages[''];

        $file = WPSD_DIR . 'templates/' . $page['template'] . '.php';
        if (!file_exists($file)) {
            $file = WPSD_DIR . 'templates/dashboard.php';
        }

        echo '<div class="wrap wpsd-wrap">';
        self::render_header($page['title']);
        settings_errors('wpsd');

        require $file;

        echo '</div>';
    }

    private static function render_header(string $title): void {
        $score   = WPSD_Score::calculate();
        $running = WPSD_Scanner::running_scan();

        echo '<div class="wpsd-header">';
        echo '<div class="wpsd-header__title">';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<span class="wpsd-header__sub">' . esc_html__('WP SEO Doctor', 'wp-seo-doctor') . '</span>';
        echo '</div>';

        echo '<div class="wpsd-header__meta">';
        if ($score['checked'] > 0) {
            printf(
                '<span class="wpsd-pill" style="--wpsd-pill:%1$s">%2$s <strong>%3$d/100</strong></span>',
                esc_attr(WPSD_Score::color((int) $score['score'])),
                esc_html__('Health', 'wp-seo-doctor'),
                (int) $score['score']
            );
        }
        if ($running) {
            echo '<span class="wpsd-pill wpsd-pill--info">' . esc_html__('Scan in progress', 'wp-seo-doctor') . '</span>';
        }
        printf(
            '<button type="button" class="button button-primary" id="wpsd-start-scan" data-type="full">%s</button>',
            esc_html__('Run full scan', 'wp-seo-doctor')
        );
        echo '</div>';
        echo '</div>';

        self::render_scan_bar();
    }

    public static function render_scan_bar(): void {
        $running = WPSD_Scanner::running_scan();
        $hidden  = $running ? '' : ' style="display:none"';
        $percent = 0;
        if ($running && (int) $running->total_objects > 0) {
            $percent = (int) round((int) $running->processed / (int) $running->total_objects * 100);
        }

        echo '<div class="wpsd-scanbar" id="wpsd-scanbar"' . $hidden . ' data-scan-id="' . esc_attr((string) ($running->id ?? '')) . '">';
        echo '<div class="wpsd-scanbar__top">';
        echo '<strong>' . esc_html__('Scanning…', 'wp-seo-doctor') . '</strong> ';
        echo '<span id="wpsd-scan-status">';
        if ($running) {
            printf(
                /* translators: 1: processed count, 2: total count */
                esc_html__('%1$d of %2$d pages', 'wp-seo-doctor'),
                (int) $running->processed,
                (int) $running->total_objects
            );
        }
        echo '</span>';
        echo '<button type="button" class="button-link wpsd-scanbar__cancel" id="wpsd-cancel-scan">' . esc_html__('Cancel', 'wp-seo-doctor') . '</button>';
        echo '</div>';
        echo '<div class="wpsd-progress"><div class="wpsd-progress__fill" id="wpsd-scan-fill" style="width:' . esc_attr((string) $percent) . '%"></div></div>';
        echo '<div class="wpsd-scanbar__current" id="wpsd-scan-current"></div>';
        echo '</div>';
    }

    // ────────────────────────────────────────────── shared UI partials ──

    /**
     * @param array<int,array{label:string,value:string|int,tone?:string,href?:string}> $cards
     */
    public static function stat_cards(array $cards): void {
        echo '<div class="wpsd-stats">';
        foreach ($cards as $card) {
            $tone = isset($card['tone']) ? ' wpsd-stat--' . sanitize_html_class($card['tone']) : '';
            $tag  = !empty($card['href']) ? 'a' : 'div';
            $href = !empty($card['href']) ? ' href="' . esc_url($card['href']) . '"' : '';

            printf(
                '<%1$s class="wpsd-stat%2$s"%3$s><span class="wpsd-stat__value">%4$s</span><span class="wpsd-stat__label">%5$s</span></%1$s>',
                esc_attr($tag),
                esc_attr($tone),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                $href,
                esc_html((string) $card['value']),
                esc_html((string) $card['label'])
            );
        }
        echo '</div>';
    }

    public static function severity_badge(string $severity): string {
        return sprintf(
            '<span class="wpsd-badge wpsd-badge--%1$s">%2$s</span>',
            esc_attr($severity),
            esc_html(WPSD_Helpers::severity_label($severity))
        );
    }

    /**
     * Simple pagination control.
     */
    public static function pagination(int $current, int $pages, array $extra_args = []): void {
        if ($pages < 2) {
            return;
        }

        $base = add_query_arg(array_merge($extra_args, ['paged' => '%#%']));

        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo wp_kses_post((string) paginate_links([
            'base'      => $base,
            'format'    => '',
            'current'   => max(1, $current),
            'total'     => $pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]));
        echo '</div></div>';
    }

    public static function empty_state(string $message, string $hint = ''): void {
        echo '<div class="wpsd-empty">';
        echo '<p class="wpsd-empty__message">' . esc_html($message) . '</p>';
        if ($hint !== '') {
            echo '<p class="wpsd-empty__hint">' . esc_html($hint) . '</p>';
        }
        echo '</div>';
    }

    /** Current page number from the query string. */
    public static function current_page(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
        return isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
    }

    /** A sanitised GET parameter for list-table filters. */
    public static function query_arg(string $key, string $default = ''): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
        return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : $default;
    }

    // ───────────────────────────────────────────────────── form actions ──

    /**
     * Handle non-AJAX form posts (settings, redirect CRUD, imports).
     */
    public static function handle_post_actions(): void {
        if (!is_admin() || !current_user_can(self::CAPABILITY)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each branch verifies its own nonce.
        $action = isset($_POST['wpsd_action']) ? sanitize_key(wp_unslash($_POST['wpsd_action'])) : '';
        if ($action === '') {
            return;
        }

        check_admin_referer('wpsd_' . $action);

        switch ($action) {
            case 'save_settings':
                self::save_settings();
                break;

            case 'save_redirect':
                self::save_redirect();
                break;

            case 'import_redirects':
                self::import_redirects();
                break;

            case 'gsc_disconnect':
                WPSD_GSC::disconnect();
                self::notice('gsc_disconnected', __('Search Console has been disconnected.', 'wp-seo-doctor'), 'success');
                break;
        }
    }

    private static function save_settings(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_post_actions().
        $raw = isset($_POST['wpsd']) ? wp_unslash($_POST['wpsd']) : [];
        if (!is_array($raw)) {
            $raw = [];
        }

        update_option(WPSD_Settings::OPTION, WPSD_Settings::sanitize($raw));

        // Threshold changes invalidate the cached analyses.
        WPSD_Checks_OnPage::flush_duplicate_index();
        WPSD_Checks_Content::flush_shingle_index();
        WPSD_Internal_Links::flush_graph_cache();

        self::notice('settings_saved', __('Settings saved.', 'wp-seo-doctor'), 'success');
    }

    private static function save_redirect(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post_actions().
        $id     = isset($_POST['redirect_id']) ? absint(wp_unslash($_POST['redirect_id'])) : 0;
        $fields = [
            'source'     => isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '',
            'target'     => isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '',
            'code'       => isset($_POST['code']) ? absint(wp_unslash($_POST['code'])) : 301,
            'match_type' => isset($_POST['match_type']) && $_POST['match_type'] === 'regex' ? 'regex' : 'exact',
            'notes'      => isset($_POST['notes']) ? sanitize_text_field(wp_unslash($_POST['notes'])) : '',
            'enabled'    => !empty($_POST['enabled']),
        ];
        // phpcs:enable

        $result = $id > 0
            ? WPSD_Redirects::update($id, $fields)
            : WPSD_Redirects::create($fields);

        if (is_wp_error($result)) {
            self::notice('redirect_error', $result->get_error_message(), 'error');
            return;
        }

        self::notice(
            'redirect_saved',
            $id > 0 ? __('Redirect updated.', 'wp-seo-doctor') : __('Redirect created.', 'wp-seo-doctor'),
            'success'
        );
    }

    private static function import_redirects(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_post_actions().
        $pasted = isset($_POST['csv']) ? wp_unslash($_POST['csv']) : '';
        $csv    = is_string($pasted) ? $pasted : '';

        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $contents = file_get_contents(sanitize_text_field($_FILES['csv_file']['tmp_name']));
            if (is_string($contents) && $contents !== '') {
                $csv = $contents;
            }
        }

        if (trim($csv) === '') {
            self::notice('import_empty', __('No CSV content was supplied.', 'wp-seo-doctor'), 'error');
            return;
        }

        $result = WPSD_Redirects::import_csv($csv);

        $message = sprintf(
            /* translators: 1: imported count, 2: skipped count */
            __('Imported %1$d redirects, skipped %2$d.', 'wp-seo-doctor'),
            $result['imported'],
            $result['skipped']
        );
        if ($result['errors']) {
            $message .= ' ' . implode(' ', array_slice($result['errors'], 0, 3));
        }

        self::notice('import_done', $message, $result['imported'] > 0 ? 'success' : 'warning');
    }

    private static function notice(string $code, string $message, string $type = 'info'): void {
        add_settings_error('wpsd', 'wpsd_' . $code, $message, $type);
        set_transient('wpsd_notices', get_settings_errors('wpsd'), 60);
    }

    public static function render_notices(): void {
        $notices = get_transient('wpsd_notices');
        if (!is_array($notices)) {
            return;
        }
        delete_transient('wpsd_notices');

        // Settings pages render these through settings_errors(); elsewhere we
        // print them ourselves.
        $screen = get_current_screen();
        if ($screen && strpos((string) $screen->id, WPSD_SLUG) !== false) {
            return;
        }

        foreach ($notices as $notice) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($notice['type'] ?? 'info'),
                esc_html($notice['message'] ?? '')
            );
        }
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public static function plugin_action_links(array $links): array {
        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . WPSD_SLUG . '-settings')),
                esc_html__('Settings', 'wp-seo-doctor')
            ),
            sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . WPSD_SLUG)),
                esc_html__('Dashboard', 'wp-seo-doctor')
            )
        );

        return $links;
    }

    public static function page_url(string $suffix = '', array $args = []): string {
        return add_query_arg($args, admin_url('admin.php?page=' . WPSD_SLUG . $suffix));
    }
}
