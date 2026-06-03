<?php
defined('ABSPATH') || exit;

class Sinemagor_Sitemap_Ping {

    const PINGED_KEY = 'sinemagor_sitemap_pinged';

    public static function init(): void {
        add_action('transition_post_status', [self::class, 'on_publish'], 30, 3);
    }

    public static function on_publish(string $new, string $old, WP_Post $post): void {
        if ($new !== 'publish' || $old === 'publish') return;
        if (!get_post_meta($post->ID, '_sinemagor_tmdb_id', true)) return;
        // Defer to avoid slowing down the publish request
        wp_schedule_single_event(time() + 10, 'sinemagor_do_ping', [$post->ID]);
    }

    public static function do_ping(int $post_id): void {
        self::ping_all($post_id);
    }

    /**
     * Ping Google and Bing with the sitemap URL.
     */
    public static function ping_all(int $post_id = 0): array {
        $sitemap_url = self::get_sitemap_url();
        if (!$sitemap_url) return ['error' => 'No sitemap found.'];

        $post_url = $post_id ? get_permalink($post_id) : '';
        $results  = [];

        // Google ping
        $g_url = 'https://www.google.com/ping?sitemap=' . urlencode($sitemap_url);
        $g_resp= wp_remote_get($g_url, ['timeout' => 8]);
        $results['google'] = is_wp_error($g_resp) ? 'failed' : wp_remote_retrieve_response_code($g_resp);

        // Bing ping
        $b_url = 'https://www.bing.com/ping?sitemap=' . urlencode($sitemap_url);
        $b_resp= wp_remote_get($b_url, ['timeout' => 8]);
        $results['bing'] = is_wp_error($b_resp) ? 'failed' : wp_remote_retrieve_response_code($b_resp);

        // Log
        $log   = get_option('sinemagor_ping_log', []);
        $log[] = ['time' => current_time('Y-m-d H:i:s'), 'post_id' => $post_id, 'post_url' => $post_url, 'results' => $results];
        if (count($log) > 50) $log = array_slice($log, -50);
        update_option('sinemagor_ping_log', $log, false);

        if ($post_id) update_post_meta($post_id, '_sinemagor_pinged_at', current_time('mysql'));

        Sinemagor_Auto_Pilot::log('🏓 Sitemap pinged — Google: '.$results['google'].', Bing: '.$results['bing'].' | Post: '.($post_url ?: 'N/A'));
        return $results;
    }

    private static function get_sitemap_url(): string {
        // Yoast SEO sitemap
        if (defined('WPSEO_VERSION')) return home_url('/sitemap_index.xml');
        // RankMath sitemap
        if (class_exists('RankMath')) return home_url('/sitemap_index.xml');
        // WordPress core sitemap (WP 5.5+)
        return home_url('/wp-sitemap.xml');
    }

    public static function get_log(): array {
        return array_reverse(get_option('sinemagor_ping_log', []));
    }
}

// Register the deferred cron action
add_action('sinemagor_do_ping', function ($post_id) {
    Sinemagor_Sitemap_Ping::ping_all($post_id);
});
