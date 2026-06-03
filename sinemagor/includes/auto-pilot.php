<?php
defined('ABSPATH') || exit;

/**
 * Sinemagor Auto Pilot
 *
 * Full pipeline: TMDB fetch → Library add → AI generate → Publish
 * Runs every 6 hours via WP Cron.
 *
 * Log stored in option: sinemagor_autopilot_log (last 50 entries)
 */
class Sinemagor_Auto_Pilot {

    const CRON_HOOK     = 'sinemagor_autopilot_run';
    const CRON_INTERVAL = 'sinemagor_6hours';
    const LOG_KEY       = 'sinemagor_autopilot_log';
    const STATUS_KEY    = 'sinemagor_autopilot_status';

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function init(): void {
        // Register 6-hour cron interval
        add_filter('cron_schedules', [self::class, 'add_interval']);

        // Hook the pipeline to cron event
        add_action(self::CRON_HOOK, [self::class, 'run_pipeline']);

        // Schedule or unschedule based on settings
        add_action('update_option_sinemagor_settings', [self::class, 'sync_schedule'], 10, 2);

        // Schedule on plugin activation if already enabled
        add_action('init', [self::class, 'maybe_schedule'], 5);
    }

    public static function add_interval(array $schedules): array {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 Hours (Sinemagor Auto Pilot)',
        ];
        return $schedules;
    }

    public static function maybe_schedule(): void {
        $enabled = Sinemagor_Settings::get('autopilot_enabled', 0);
        if ($enabled && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public static function sync_schedule($old, $new): void {
        $enabled = $new['autopilot_enabled'] ?? 0;
        if ($enabled) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
                self::log('✅ Auto Pilot scheduled (every 6 hours).');
            }
        } else {
            self::unschedule();
            self::log('⏹ Auto Pilot disabled and unscheduled.');
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    // ── Main Pipeline ─────────────────────────────────────────────────────────

    public static function run_pipeline(): void {
        // Prevent overlapping runs
        if (get_transient('sinemagor_autopilot_running')) {
            self::log('⚠ Skipped — previous run still in progress.');
            return;
        }
        set_transient('sinemagor_autopilot_running', 1, 30 * MINUTE_IN_SECONDS);

        self::set_status('running');
        self::log('🚀 Auto Pilot run started.');

        try {
            // ── Step 1: Read settings ──────────────────────────────────────
            $feed        = Sinemagor_Settings::get('autopilot_feed',       'trending');
            $per_run     = (int) Sinemagor_Settings::get('autopilot_per_run',    10);
            $min_rating  = (float) Sinemagor_Settings::get('autopilot_min_rating', 0);
            $language    = Sinemagor_Settings::get('autopilot_language',   '');
            $auto_publish= (int) Sinemagor_Settings::get('auto_publish',   0);

            self::log("⚙ Settings → Feed: {$feed} | Per run: {$per_run} | Min rating: {$min_rating} | Lang: " . ($language ?: 'any'));

            // ── Step 2: Fetch new movies from TMDB ────────────────────────
            self::log("🎬 Fetching up to {$per_run} new movies from TMDB ({$feed})...");
            $movies = Sinemagor_TMDB_Feeds::fetch_new($feed, $per_run, $min_rating, $language);

            if (empty($movies)) {
                self::log('ℹ No new movies found (all already in library or filtered out).');
                self::set_status('idle');
                delete_transient('sinemagor_autopilot_running');
                return;
            }

            self::log('📥 Found ' . count($movies) . ' new movies to import.');

            // ── Step 3: Import each movie (full TMDB details) ─────────────
            $tmdb      = new Sinemagor_TMDB();
            $imported  = [];
            $import_fail = 0;

            foreach ($movies as $basic) {
                usleep(300000); // 300ms between TMDB calls (rate limit safety)

                $full = $tmdb->get_full((int) $basic['tmdb_id']);
                if (!$full) {
                    self::log("  ✗ TMDB fetch failed: {$basic['title']} (#{$basic['tmdb_id']})");
                    $import_fail++;
                    continue;
                }

                $db_id = Sinemagor_DB::insert_movie($full);
                if ($db_id) {
                    $imported[] = $db_id;
                    self::log("  ✓ Imported: {$basic['title']} (#{$basic['tmdb_id']}) → DB #{$db_id}");
                }
            }

            self::log('📦 Imported: ' . count($imported) . ' | Failed: ' . $import_fail);

            if (empty($imported)) {
                self::log('⚠ No movies were successfully imported. Stopping.');
                self::set_status('idle');
                delete_transient('sinemagor_autopilot_running');
                return;
            }

            // ── Step 4: Generate + Publish each imported movie ────────────
            self::log('✍ Starting AI review generation...');
            $published   = 0;
            $gen_failed  = 0;

            foreach ($imported as $db_id) {
                usleep(500000); // 500ms between AI calls

                $movie = Sinemagor_DB::get_movie($db_id);
                if (!$movie) continue;

                $result = Sinemagor_Post_Publisher::publish($db_id);

                if (is_wp_error($result)) {
                    self::log("  ✗ AI failed for \"{$movie->title}\": " . $result->get_error_message());
                    $gen_failed++;
                } else {
                    $status = $auto_publish ? 'Published' : 'Draft';
                    self::log("  ✓ {$status}: \"{$movie->title}\" → Post #{$result}");
                    $published++;
                }
            }

            // ── Step 5: Summary ───────────────────────────────────────────
            $summary = "✅ Run complete — Imported: " . count($imported) .
                       " | Published/Drafted: {$published}" .
                       " | Gen failed: {$gen_failed}";
            self::log($summary);
            self::set_status('idle', $summary);

        } catch (Throwable $e) {
            self::log('💥 Fatal error: ' . $e->getMessage());
            self::set_status('error', $e->getMessage());
        }

        delete_transient('sinemagor_autopilot_running');
    }

    // ── Manual trigger (from Settings page) ──────────────────────────────────

    public static function trigger_now(): void {
        do_action(self::CRON_HOOK);
    }

    // ── Log helpers ───────────────────────────────────────────────────────────

    public static function log(string $message): void {
        $log   = get_option(self::LOG_KEY, []);
        $log[] = [
            'time' => current_time('Y-m-d H:i:s'),
            'msg'  => $message,
        ];
        // Keep last 100 entries
        if (count($log) > 100) $log = array_slice($log, -100);
        update_option(self::LOG_KEY, $log, false);
    }

    public static function get_log(): array {
        return array_reverse(get_option(self::LOG_KEY, []));
    }

    public static function clear_log(): void {
        delete_option(self::LOG_KEY);
    }

    private static function set_status(string $status, string $message = ''): void {
        update_option(self::STATUS_KEY, [
            'status'     => $status,
            'message'    => $message,
            'updated_at' => current_time('Y-m-d H:i:s'),
        ], false);
    }

    public static function get_status(): array {
        return get_option(self::STATUS_KEY, ['status' => 'idle', 'message' => '', 'updated_at' => '']);
    }

    public static function next_run_time(): string {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if (!$ts) return 'Not scheduled';
        return get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d H:i:s');
    }

    public static function is_enabled(): bool {
        return (bool) Sinemagor_Settings::get('autopilot_enabled', 0);
    }
}
