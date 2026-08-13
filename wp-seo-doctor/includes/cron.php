<?php
/**
 * Scheduled tasks: audits, link checks, Search Console sync, housekeeping.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Cron {

    /** Setting key => cron hook. */
    const SCHEDULES = [
        'scan_schedule'       => 'wpsd_run_scheduled_scan',
        'link_check_schedule' => 'wpsd_check_links_batch',
        'gsc_sync_schedule'   => 'wpsd_sync_gsc',
        'email_schedule'      => 'wpsd_send_email_report',
    ];

    const HOUSEKEEPING = 'wpsd_daily_housekeeping';

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'register_intervals']);
        add_action(self::HOUSEKEEPING, [self::class, 'housekeeping']);

        // Keep the schedule in step with the settings without needing a
        // re-activation after every change.
        add_action('update_option_' . WPSD_Settings::OPTION, [self::class, 'schedule_from_settings']);
    }

    /**
     * @param array<string,array{interval:int,display:string}> $schedules
     * @return array<string,array{interval:int,display:string}>
     */
    public static function register_intervals(array $schedules): array {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once weekly', 'wp-seo-doctor'),
            ];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once monthly', 'wp-seo-doctor'),
            ];
        }
        return $schedules;
    }

    /**
     * Reconcile WP-Cron with the current settings.
     */
    public static function schedule_from_settings(): void {
        foreach (self::SCHEDULES as $setting => $hook) {
            $recurrence = (string) WPSD_Settings::get($setting, 'disabled');
            self::reschedule($hook, $recurrence);
        }

        if (!wp_next_scheduled(self::HOUSEKEEPING)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOUSEKEEPING);
        }
    }

    private static function reschedule(string $hook, string $recurrence): void {
        $existing = wp_next_scheduled($hook);

        if ($recurrence === 'disabled' || $recurrence === '') {
            if ($existing) {
                wp_clear_scheduled_hook($hook);
            }
            return;
        }

        // Already scheduled at the right cadence — leave it alone so we do not
        // keep pushing the next run into the future on every settings save.
        if ($existing) {
            $event = wp_get_scheduled_event($hook);
            if ($event && $event->schedule === $recurrence) {
                return;
            }
            wp_clear_scheduled_hook($hook);
        }

        // Start off-peak rather than immediately.
        $start = strtotime('tomorrow 03:00', current_time('timestamp'));
        if (!$start || $start < time()) {
            $start = time() + HOUR_IN_SECONDS;
        }

        wp_schedule_event($start, $recurrence, $hook);
    }

    public static function clear_all(): void {
        foreach (self::SCHEDULES as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        wp_clear_scheduled_hook(self::HOUSEKEEPING);
        wp_clear_scheduled_hook('wpsd_prune_404s');
        wp_clear_scheduled_hook('wpsd_continue_scan');
    }

    /**
     * Daily maintenance: prune old data and refresh the trend snapshot.
     */
    public static function housekeeping(): void {
        WPSD_Monitor_404::prune();
        WPSD_Issues::prune((int) WPSD_Settings::get('scan_history_limit', 30));
        WPSD_Score::snapshot();

        // Release a scan that died mid-run so the next one is not blocked.
        $running = WPSD_Scanner::running_scan();
        if ($running && strtotime((string) $running->started_at) < time() - (6 * HOUR_IN_SECONDS)) {
            WPSD_Scanner::finish(
                (int) $running->id,
                'failed',
                __('Timed out: no progress for six hours.', 'wp-seo-doctor')
            );
        }
    }

    /**
     * Next-run timestamps for the settings screen.
     *
     * @return array<string,int>
     */
    public static function next_runs(): array {
        $out = [];
        foreach (self::SCHEDULES as $setting => $hook) {
            $out[$setting] = (int) wp_next_scheduled($hook);
        }
        return $out;
    }
}
