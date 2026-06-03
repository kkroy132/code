<?php
defined('ABSPATH') || exit;

/**
 * Bulk generate queue using WP Cron.
 * 
 * Flow:
 *  1. Admin selects N movies → Ajax calls sinemagor_bulk_queue
 *  2. Movie IDs saved to option: sinemagor_queue (array)
 *  3. WP Cron event fires every minute → processes BATCH_SIZE items
 *  4. Progress stored in sinemagor_queue_progress option
 *  5. Admin polls Ajax sinemagor_queue_status to show progress bar
 */
class Sinemagor_Queue {

    const QUEUE_KEY    = 'sinemagor_queue';
    const PROGRESS_KEY = 'sinemagor_queue_progress';
    const CRON_HOOK    = 'sinemagor_process_queue';

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'process_batch']);

        // Register custom cron schedule (every 1 minute)
        add_filter('cron_schedules', function ($schedules) {
            $schedules['sinemagor_1min'] = [
                'interval' => 60,
                'display'  => 'Every 1 Minute (Sinemagor)',
            ];
            return $schedules;
        });
    }

    /**
     * Add movie IDs to queue and schedule cron if not already running.
     */
    public static function enqueue(array $movie_ids): void {
        $existing = get_option(self::QUEUE_KEY, []);
        $merged   = array_unique(array_merge($existing, array_map('intval', $movie_ids)));
        update_option(self::QUEUE_KEY, $merged, false);

        // Init/update progress
        $progress = get_option(self::PROGRESS_KEY, []);
        $progress['total']     = count($merged) + ($progress['done'] ?? 0);
        $progress['done']      = $progress['done']    ?? 0;
        $progress['failed']    = $progress['failed']  ?? [];
        $progress['running']   = true;
        $progress['started_at']= $progress['started_at'] ?? current_time('mysql');
        update_option(self::PROGRESS_KEY, $progress, false);

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5, 'sinemagor_1min', self::CRON_HOOK);
        }
    }

    /**
     * Process one batch. Called by WP Cron.
     */
    public static function process_batch(): void {
        $queue = get_option(self::QUEUE_KEY, []);
        if (empty($queue)) {
            self::finish();
            return;
        }

        $batch_size = (int) Sinemagor_Settings::get('bulk_batch_size', 5);
        $batch      = array_splice($queue, 0, $batch_size);

        // Save remaining queue immediately (before processing, so a crash doesn't re-process)
        update_option(self::QUEUE_KEY, $queue, false);

        foreach ($batch as $movie_id) {
            // TMDB rate-limit: wait 300ms between requests
            usleep(300000);

            $result = Sinemagor_Post_Publisher::publish((int) $movie_id);

            $progress = get_option(self::PROGRESS_KEY, []);
            $progress['done'] = ($progress['done'] ?? 0) + 1;

            if (is_wp_error($result)) {
                $progress['failed'][] = [
                    'id'    => $movie_id,
                    'error' => $result->get_error_message(),
                ];
            } else {
                $progress['last_post_id'] = $result;
            }

            update_option(self::PROGRESS_KEY, $progress, false);
        }

        // If queue is now empty, finish
        if (empty($queue)) {
            self::finish();
        }
    }

    /**
     * Mark queue as completed and unschedule cron.
     */
    private static function finish(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);

        $progress = get_option(self::PROGRESS_KEY, []);
        $progress['running']     = false;
        $progress['finished_at'] = current_time('mysql');
        update_option(self::PROGRESS_KEY, $progress, false);

        delete_option(self::QUEUE_KEY);
    }

    /**
     * Get current progress for Ajax polling.
     */
    public static function get_progress(): array {
        $progress = get_option(self::PROGRESS_KEY, []);
        $queue    = get_option(self::QUEUE_KEY, []);

        return [
            'total'      => $progress['total']      ?? 0,
            'done'       => $progress['done']        ?? 0,
            'failed'     => count($progress['failed'] ?? []),
            'remaining'  => count($queue),
            'running'    => $progress['running']     ?? false,
            'errors'     => $progress['failed']      ?? [],
            'percent'    => $progress['total']
                ? round((($progress['done'] ?? 0) / $progress['total']) * 100)
                : 0,
        ];
    }

    /**
     * Clear queue and progress (admin reset).
     */
    public static function clear(): void {
        delete_option(self::QUEUE_KEY);
        delete_option(self::PROGRESS_KEY);
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }
}

// Boot queue system
Sinemagor_Queue::init();
