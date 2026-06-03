<?php
defined('ABSPATH') || exit;

/**
 * Sinemagor Smart Retroactive Linker
 *
 * When a new post is published:
 *  1. Find all existing posts that share cast/director with the new post
 *  2. Queue them for re-processing (category + content link update)
 *  3. WP Cron processes queue in batches (no server overload)
 *
 * Also runs nightly to ensure all categories are up-to-date.
 */
class Sinemagor_Retro_Linker {

    const QUEUE_KEY  = 'sg_retro_queue';
    const CRON_HOOK  = 'sinemagor_retro_process';
    const NIGHT_HOOK = 'sinemagor_retro_nightly';

    public static function init(): void {
        // Trigger on new publish
        add_action('transition_post_status', [self::class, 'on_publish'], 50, 3);

        // Cron hooks
        add_action(self::CRON_HOOK,  [self::class, 'process_batch']);
        add_action(self::NIGHT_HOOK, [self::class, 'nightly_rebuild']);

        // Schedule nightly if not scheduled
        add_action('init', [self::class, 'schedule_nightly'], 5);

        // Ajax: manual full rebuild
        add_action('wp_ajax_sg_retro_rebuild', [self::class, 'ajax_rebuild']);
        add_action('wp_ajax_sg_retro_status',  [self::class, 'ajax_status']);
    }

    // ── Schedule nightly cron ─────────────────────────────────────────────────

    public static function schedule_nightly(): void {
        if (!wp_next_scheduled(self::NIGHT_HOOK)) {
            wp_schedule_event(
                strtotime('tomorrow 03:00:00'),
                'daily',
                self::NIGHT_HOOK
            );
        }
    }

    // ── On new post publish: find + queue related old posts ──────────────────

    public static function on_publish(string $new, string $old, WP_Post $post): void {
        if ($new !== 'publish' || $old === 'publish') return;
        if (!get_post_meta($post->ID, '_sinemagor_tmdb_id', true)) return;

        $related = self::find_related_posts($post->ID);
        if (empty($related)) return;

        self::enqueue($related, 'smart');

        Sinemagor_Auto_Pilot::log(
            '🔗 Retro-link queued: ' . count($related) .
            ' related posts after publishing "' . $post->post_title . '"'
        );
    }

    /**
     * Find existing published posts that share cast or director
     * with the given post. Returns array of post IDs.
     */
    public static function find_related_posts(int $new_post_id): array {
        $cast_json = get_post_meta($new_post_id, '_sinemagor_cast',     true);
        $director  = get_post_meta($new_post_id, '_sinemagor_director', true);
        $genre     = get_post_meta($new_post_id, '_sinemagor_genre',    true);

        $found = [];

        // Search by each cast member
        if ($cast_json) {
            $cast  = json_decode($cast_json, true) ?: [];
            $limit = (int) Sinemagor_Settings::get('autocat_cast_limit', 3);
            foreach (array_slice($cast, 0, $limit) as $member) {
                $name = trim($member['name'] ?? '');
                if (!$name) continue;

                $posts = self::get_posts_with_meta('_sinemagor_cast', $name, $new_post_id);
                foreach ($posts as $pid) $found[$pid] = $pid;
            }
        }

        // Search by director
        if ($director) {
            $posts = self::get_posts_with_meta('_sinemagor_director', $director, $new_post_id);
            foreach ($posts as $pid) $found[$pid] = $pid;
        }

        // Search by primary genre
        if ($genre) {
            $primary = trim(explode(',', $genre)[0]);
            $posts   = self::get_posts_with_meta('_sinemagor_genre', $primary, $new_post_id);
            foreach ($posts as $pid) $found[$pid] = $pid;
        }

        return array_values($found);
    }

    private static function get_posts_with_meta(string $key, string $value, int $exclude): array {
        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'post__not_in'   => [$exclude],
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => $key,
                'value'   => $value,
                'compare' => 'LIKE',
            ]],
        ]);
        return $q->posts ?: [];
    }

    // ── Queue management ──────────────────────────────────────────────────────

    public static function enqueue(array $post_ids, string $source = 'manual'): void {
        $existing = get_option(self::QUEUE_KEY, []);
        $merged   = array_unique(array_merge($existing, array_map('intval', $post_ids)));
        update_option(self::QUEUE_KEY, $merged, false);

        // Update progress tracking
        $progress = get_option('sg_retro_progress', []);
        $progress['total']    = count($merged) + ($progress['done'] ?? 0);
        $progress['done']     = $progress['done']   ?? 0;
        $progress['running']  = true;
        $progress['source']   = $source;
        $progress['started']  = $progress['started'] ?? current_time('mysql');
        update_option('sg_retro_progress', $progress, false);

        // Schedule immediate cron if not already running
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 10, 'sinemagor_1min', self::CRON_HOOK);
        }
    }

    // ── Process batch ─────────────────────────────────────────────────────────

    public static function process_batch(): void {
        $queue = get_option(self::QUEUE_KEY, []);
        if (empty($queue)) {
            self::finish();
            return;
        }

        // Process 10 posts per batch
        $batch = array_splice($queue, 0, 10);
        update_option(self::QUEUE_KEY, $queue, false);

        foreach ($batch as $post_id) {
            usleep(100000); // 100ms pause between posts

            // 1. Re-assign categories (picks up any new ones)
            if (class_exists('Sinemagor_Auto_Category')) {
                Sinemagor_Auto_Category::process((int) $post_id);
            }

            // 2. Rebuild internal links
            if (class_exists('Sinemagor_Internal_Linker')) {
                Sinemagor_Internal_Linker::rebuild((int) $post_id);
            }

            // 3. Content linker cache clear
            // (content linker reads live, no cache to clear)
        }

        // Update done count
        $progress         = get_option('sg_retro_progress', []);
        $progress['done'] = ($progress['done'] ?? 0) + count($batch);
        update_option('sg_retro_progress', $progress, false);

        Sinemagor_Auto_Pilot::log(
            '🔗 Retro batch: ' . count($batch) . ' posts updated. ' .
            count($queue) . ' remaining.'
        );

        if (empty($queue)) self::finish();
    }

    private static function finish(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);

        $progress            = get_option('sg_retro_progress', []);
        $progress['running'] = false;
        $progress['done_at'] = current_time('mysql');
        update_option('sg_retro_progress', $progress, false);
        delete_option(self::QUEUE_KEY);

        Sinemagor_Auto_Pilot::log(
            '✅ Retro-link complete. ' . ($progress['done'] ?? 0) . ' posts updated.'
        );
    }

    // ── Nightly full rebuild ──────────────────────────────────────────────────

    public static function nightly_rebuild(): void {
        Sinemagor_Auto_Pilot::log('🌙 Nightly retro-link rebuild started.');

        // Get all published sinemagor posts
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']],
        ]);

        if (empty($posts)) {
            Sinemagor_Auto_Pilot::log('ℹ Nightly: no posts found.');
            return;
        }

        self::enqueue($posts, 'nightly');
        Sinemagor_Auto_Pilot::log('🌙 Nightly: ' . count($posts) . ' posts queued.');
    }

    // ── Progress ──────────────────────────────────────────────────────────────

    public static function get_progress(): array {
        $progress = get_option('sg_retro_progress', []);
        $queue    = get_option(self::QUEUE_KEY, []);
        $total    = $progress['total']   ?? 0;
        $done     = $progress['done']    ?? 0;

        return [
            'running'   => (bool) ($progress['running'] ?? false),
            'total'     => $total,
            'done'      => $done,
            'remaining' => count($queue),
            'percent'   => $total > 0 ? round($done / $total * 100) : 0,
            'source'    => $progress['source']  ?? '',
            'started'   => $progress['started'] ?? '',
            'done_at'   => $progress['done_at'] ?? '',
        ];
    }

    // ── Ajax ──────────────────────────────────────────────────────────────────

    public static function ajax_rebuild(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        // Reset progress
        delete_option('sg_retro_progress');
        delete_option(self::QUEUE_KEY);
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);

        self::nightly_rebuild();
        wp_send_json_success(self::get_progress());
    }

    public static function ajax_status(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        wp_send_json_success(self::get_progress());
    }
}
