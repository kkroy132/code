<?php
defined('ABSPATH') || exit;

class Sinemagor_IndexNow {

    const ENDPOINT_INDEXNOW = 'https://api.indexnow.org/indexnow';
    const ENDPOINT_BING     = 'https://www.bing.com/indexnow';
    const LOG_KEY           = 'sinemagor_indexnow_log';
    const META_SUBMITTED    = '_sinemagor_indexnow_at';
    const META_STATUS       = '_sinemagor_indexnow_status';

    public static function init(): void {
        add_action('transition_post_status',        [self::class, 'on_publish'], 30, 3);
        add_action('sinemagor_indexnow_submit_url', [self::class, 'submit_by_post_id']);
        add_action('init',                          [self::class, 'maybe_serve_key_file']);
        add_action('wp_ajax_sg_indexnow_submit',    [self::class, 'ajax_submit_single']);
        add_action('wp_ajax_sg_indexnow_bulk',      [self::class, 'ajax_bulk']);
        add_action('wp_ajax_sg_indexnow_posts',     [self::class, 'ajax_get_posts']);
        add_action('wp_ajax_sg_indexnow_clear_log', [self::class, 'ajax_clear_log']);
    }

    // Serve /{key}.txt required by IndexNow protocol
    public static function maybe_serve_key_file(): void {
        $key = self::get_key();
        if (!$key) return;
        $uri = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        if ($uri === '/' . $key . '.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html($key);
            exit;
        }
    }

    // Auto-submit on Sinemagor post publish
    public static function on_publish(string $new, string $old, WP_Post $post): void {
        if ($new !== 'publish') return;
        if (!get_post_meta($post->ID, '_sinemagor_tmdb_id', true)) return;
        if (!self::get_key()) return;
        wp_schedule_single_event(time() + 10, 'sinemagor_indexnow_submit_url', [$post->ID]);
    }

    // Submit by post ID (stores meta per post)
    public static function submit_by_post_id(int $post_id): array {
        $url = get_permalink($post_id);
        if (!$url) return ['error' => 'Invalid post ID.'];
        $result = self::submit($url);
        $status = (!isset($result['error']) && in_array($result['indexnow'], [200, 202])) ? 'success' : 'failed';
        update_post_meta($post_id, self::META_SUBMITTED, current_time('mysql'));
        update_post_meta($post_id, self::META_STATUS, $status);
        return $result;
    }

    // Core submit: send URL to IndexNow + Bing
    public static function submit(string $url): array {
        $key = self::get_key();
        if (!$key) return ['error' => 'IndexNow key not set.'];

        $host    = wp_parse_url(home_url(), PHP_URL_HOST);
        $body    = wp_json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url('/' . $key . '.txt'),
            'urlList'     => [$url],
        ]);
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];

        $r1 = wp_remote_post(self::ENDPOINT_INDEXNOW, ['timeout' => 10, 'headers' => $headers, 'body' => $body]);
        $r2 = wp_remote_post(self::ENDPOINT_BING,     ['timeout' => 10, 'headers' => $headers, 'body' => $body]);

        $result = [
            'url'      => $url,
            'indexnow' => is_wp_error($r1) ? 'error' : wp_remote_retrieve_response_code($r1),
            'bing'     => is_wp_error($r2) ? 'error' : wp_remote_retrieve_response_code($r2),
        ];

        // Append to log
        $log   = get_option(self::LOG_KEY, []);
        $log[] = ['time' => current_time('Y-m-d H:i:s'), 'url' => $url, 'indexnow' => $result['indexnow'], 'bing' => $result['bing']];
        if (count($log) > 200) $log = array_slice($log, -200);
        update_option(self::LOG_KEY, $log, false);

        Sinemagor_Auto_Pilot::log('⚡ IndexNow: ' . $url . ' → ' . $result['indexnow'] . ' / Bing:' . $result['bing']);
        return $result;
    }

    // Bulk submit all published Sinemagor posts
    public static function bulk_submit(): array {
        $key = self::get_key();
        if (!$key) return ['error' => 'IndexNow key not set.'];

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- selecting only plugin-generated movie posts is this function's core purpose.
        ]);
        if (empty($posts)) return ['submitted' => 0, 'indexnow' => 0, 'bing' => 0];

        $urls = array_values(array_filter(array_map('get_permalink', $posts)));

        $host    = wp_parse_url(home_url(), PHP_URL_HOST);
        $body    = wp_json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url('/' . $key . '.txt'),
            'urlList'     => $urls,
        ]);
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];

        $r1 = wp_remote_post(self::ENDPOINT_INDEXNOW, ['timeout' => 30, 'headers' => $headers, 'body' => $body]);
        $r2 = wp_remote_post(self::ENDPOINT_BING,     ['timeout' => 30, 'headers' => $headers, 'body' => $body]);

        $c1 = is_wp_error($r1) ? 'error' : wp_remote_retrieve_response_code($r1);
        $c2 = is_wp_error($r2) ? 'error' : wp_remote_retrieve_response_code($r2);

        // Mark all posts as submitted
        $now    = current_time('mysql');
        $status = (in_array($c1, [200, 202]) || in_array($c2, [200, 202])) ? 'success' : 'failed';
        foreach ($posts as $pid) {
            update_post_meta($pid, self::META_SUBMITTED, $now);
            update_post_meta($pid, self::META_STATUS, $status);
        }

        $log   = get_option(self::LOG_KEY, []);
        $log[] = ['time' => $now, 'url' => 'BULK (' . count($urls) . ' URLs)', 'indexnow' => $c1, 'bing' => $c2];
        if (count($log) > 200) $log = array_slice($log, -200);
        update_option(self::LOG_KEY, $log, false);

        Sinemagor_Auto_Pilot::log('⚡ IndexNow Bulk: ' . count($urls) . ' URLs → IndexNow:' . $c1 . ' Bing:' . $c2);
        return ['submitted' => count($urls), 'indexnow' => $c1, 'bing' => $c2];
    }

    // Get all Sinemagor posts with IndexNow status for the tab table
    public static function get_posts_status(int $page = 1, int $per_page = 30, string $filter = 'all'): array {
        $meta_query = [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']];

        if ($filter === 'submitted') {
            $meta_query[] = ['key' => self::META_SUBMITTED, 'compare' => 'EXISTS'];
        } elseif ($filter === 'not_submitted') {
            $meta_query[] = ['key' => self::META_SUBMITTED, 'compare' => 'NOT EXISTS'];
        }

        $total = (int) (new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering plugin-generated movie posts by submission status is this function's core purpose.
        ]))->found_posts;

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'offset'         => ($page - 1) * $per_page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see justification above.
        ]);

        $rows = [];
        foreach ($posts as $post) {
            $submitted_at = get_post_meta($post->ID, self::META_SUBMITTED, true);
            $status       = get_post_meta($post->ID, self::META_STATUS, true);
            $rows[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'url'          => get_permalink($post->ID),
                'date'         => get_the_date('Y-m-d', $post),
                'submitted_at' => $submitted_at ?: '',
                'status'       => $status ?: 'not_submitted',
            ];
        }

        return [
            'rows'      => $rows,
            'total'     => $total,
            'pages'     => ceil($total / $per_page),
            'page'      => $page,
            'per_page'  => $per_page,
        ];
    }

    // Stats for the tab header
    public static function get_stats(): array {
        $all = (int) (new WP_Query([
            'post_type' => 'post', 'post_status' => 'publish',
            'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- selecting only plugin-generated movie posts is this function's core purpose.
        ]))->found_posts;

        $submitted = (int) (new WP_Query([
            'post_type' => 'post', 'post_status' => 'publish',
            'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see justification above.
                ['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS'],
                ['key' => self::META_SUBMITTED,  'compare' => 'EXISTS'],
            ],
        ]))->found_posts;

        $success = (int) (new WP_Query([
            'post_type' => 'post', 'post_status' => 'publish',
            'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see justification above.
                ['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS'],
                ['key' => self::META_STATUS, 'value' => 'success'],
            ],
        ]))->found_posts;

        return [
            'total'         => $all,
            'submitted'     => $submitted,
            'not_submitted' => $all - $submitted,
            'success'       => $success,
            'failed'        => $submitted - $success,
        ];
    }

    public static function get_key(): string {
        return (string) Sinemagor_Settings::get('indexnow_key', '');
    }

    public static function get_log(): array {
        return array_reverse(get_option(self::LOG_KEY, []));
    }

    // ── AJAX handlers ───────────────────────────────────────────────────────────

    public static function ajax_submit_single(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        if (!$post_id) wp_send_json_error('Invalid post ID.');
        wp_send_json_success(self::submit_by_post_id($post_id));
    }

    public static function ajax_bulk(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
        wp_send_json_success(self::bulk_submit());
    }

    public static function ajax_get_posts(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        $page   = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;
        $filter = sanitize_key($_POST['filter'] ?? 'all');
        wp_send_json_success(self::get_posts_status($page, 30, $filter));
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
        delete_option(self::LOG_KEY);
        wp_send_json_success();
    }
}
