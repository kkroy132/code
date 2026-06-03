<?php
defined('ABSPATH') || exit;

class Sinemagor_Auto_Regenerate {

    const CRON_HOOK = 'sinemagor_regen_run';

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'run']);
        add_action('update_option_sinemagor_settings', [self::class, 'sync_schedule'], 10, 2);
        add_action('init', [self::class, 'maybe_schedule'], 6);
    }

    public static function maybe_schedule(): void {
        $days = (int) Sinemagor_Settings::get('regen_days', 0);
        if ($days > 0 && !wp_next_scheduled(self::CRON_HOOK)) {
            // Run daily at 3 AM
            $next = strtotime('tomorrow 03:00:00');
            wp_schedule_event($next, 'daily', self::CRON_HOOK);
        }
    }

    public static function sync_schedule($old, $new): void {
        $days = (int) ($new['regen_days'] ?? 0);
        $ts   = wp_next_scheduled(self::CRON_HOOK);

        if ($days > 0 && !$ts) {
            wp_schedule_event(strtotime('tomorrow 03:00:00'), 'daily', self::CRON_HOOK);
        } elseif ($days === 0 && $ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * Find posts older than X days and regenerate AI content.
     * Processes max 5 per run to avoid timeout.
     */
    public static function run(): void {
        $days  = (int) Sinemagor_Settings::get('regen_days', 0);
        $limit = (int) Sinemagor_Settings::get('regen_per_run', 3);
        if (!$days) return;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'date_query'     => [['before' => $cutoff, 'column' => 'post_modified']],
            'meta_query'     => [
                ['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS'],
                // Skip posts regenerated recently
                ['key' => '_sinemagor_regen_at', 'compare' => 'NOT EXISTS'],
            ],
            'orderby' => 'modified',
            'order'   => 'ASC',
        ]);

        foreach ($posts as $post) {
            usleep(600000);
            self::regenerate_post($post);
        }
    }

    public static function regenerate_post(WP_Post $post): bool {
        $movie_id = (int) get_post_meta($post->ID, '_sinemagor_movie_id', true);
        if (!$movie_id) return false;

        $movie = Sinemagor_DB::get_movie($movie_id);
        if (!$movie) return false;

        $ai = Sinemagor_AI_Generator::generate($movie);
        if (is_wp_error($ai)) return false;

        // Rebuild content
        $content = self::rebuild_content($post, $movie, $ai);

        wp_update_post([
            'ID'           => $post->ID,
            'post_title'   => wp_strip_all_tags($ai['seo_title'] ?? $post->post_title),
            'post_content' => $content,
        ]);

        // Update meta
        update_post_meta($post->ID, '_sinemagor_editor_rating', $ai['editor_rating'] ?? '');
        update_post_meta($post->ID, '_sinemagor_verdict',       $ai['verdict']       ?? '');
        update_post_meta($post->ID, '_sinemagor_regen_at',      current_time('mysql'));
        update_post_meta($post->ID, '_yoast_wpseo_metadesc',    $ai['meta_description'] ?? '');
        update_post_meta($post->ID, 'rank_math_description',    $ai['meta_description'] ?? '');

        if (!empty($ai['keywords'])) {
            wp_set_post_tags($post->ID, $ai['keywords'], false);
        }

        Sinemagor_Auto_Pilot::log("🔄 Regenerated: \"{$post->post_title}\" (Post #{$post->ID})");
        return true;
    }

    private static function rebuild_content(WP_Post $post, object $movie, array $ai): string {
        return Sinemagor_Post_Publisher::build_content_only($movie, $ai);
    }

    /** Manual trigger from admin — regenerate a single post by post ID. */
    public static function trigger_single(int $post_id): bool {
        $post = get_post($post_id);
        if (!$post) return false;
        // Clear regen lock so it can run again
        delete_post_meta($post_id, '_sinemagor_regen_at');
        return self::regenerate_post($post);
    }

    /** Returns a human-readable string of the next scheduled regen run time. */
    public static function next_run(): string {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if (!$ts) return 'Not scheduled';
        return get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s');
    }
}
