<?php
defined('ABSPATH') || exit;

class Sinemagor_Bulk_Rebuild {

    const CRON_HOOK = 'sinemagor_bulk_rebuild_run';

    public static function init(): void {
        add_action(self::CRON_HOOK,             [self::class, 'process_batch']);
        add_action('wp_ajax_sg_bulk_rebuild',   [self::class, 'ajax_start']);
        add_action('wp_ajax_sg_rebuild_status', [self::class, 'ajax_status']);
        // Ensure the 1-minute interval is registered (also registered in queue.php — safe to add twice)
        add_filter('cron_schedules', [self::class, 'add_interval']);
    }

    public static function add_interval(array $schedules): array {
        if (!isset($schedules['sinemagor_1min'])) {
            $schedules['sinemagor_1min'] = [
                'interval' => 60,
                'display'  => 'Every 1 Minute (Sinemagor)',
            ];
        }
        return $schedules;
    }

    /**
     * Kick off bulk rebuild: queue all published SG posts → WP Cron batch.
     */
    public static function start(): array {
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']],
        ]);

        if (empty($posts)) return ['queued' => 0];

        update_option('sg_rebuild_queue',    $posts,  false);
        update_option('sg_rebuild_total',    count($posts), false);
        update_option('sg_rebuild_done',     0,       false);
        update_option('sg_rebuild_running',  1,       false);

        // Schedule immediate first batch
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5, 'sinemagor_1min', self::CRON_HOOK);
        }

        return ['queued' => count($posts)];
    }

    /**
     * Process one batch of 20 posts per cron run.
     */
    public static function process_batch(): void {
        $queue = get_option('sg_rebuild_queue', []);
        if (empty($queue)) {
            self::finish();
            return;
        }

        $batch = array_splice($queue, 0, 20);
        update_option('sg_rebuild_queue', $queue, false);

        foreach ($batch as $post_id) {
            Sinemagor_Internal_Linker::rebuild((int) $post_id);
            usleep(100000); // 100ms pause
        }

        $done = (int) get_option('sg_rebuild_done', 0) + count($batch);
        update_option('sg_rebuild_done', $done, false);

        if (empty($queue)) self::finish();
    }

    private static function finish(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
        update_option('sg_rebuild_running', 0, false);
        delete_option('sg_rebuild_queue');
        Sinemagor_Auto_Pilot::log('🔗 Bulk internal link rebuild completed.');
    }

    public static function get_status(): array {
        return [
            'running' => (bool) get_option('sg_rebuild_running', 0),
            'total'   => (int)  get_option('sg_rebuild_total',   0),
            'done'    => (int)  get_option('sg_rebuild_done',    0),
            'percent' => (function() {
                $total = (int) get_option('sg_rebuild_total', 0);
                $done  = (int) get_option('sg_rebuild_done',  0);
                return $total > 0 ? round($done / $total * 100) : 0;
            })(),
        ];
    }

    public static function ajax_start(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');
        $result = self::start();
        wp_send_json_success($result);
    }

    public static function ajax_status(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        wp_send_json_success(self::get_status());
    }
}
