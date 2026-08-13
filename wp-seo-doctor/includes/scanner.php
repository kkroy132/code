<?php
/**
 * The scan engine.
 *
 * A scan is a row in the scans table holding a queue of object IDs. Each batch
 * pops N IDs, analyses them, writes issues, and updates progress — so the same
 * engine drives both the AJAX progress bar and the cron-scheduled scan.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Scanner {

    const LOCK_KEY = 'wpsd_scan_lock';

    /** Scan types and the check groups each one runs. */
    const TYPES = [
        'full'      => ['onpage', 'technical', 'content', 'links', 'affiliate', 'woo'],
        'onpage'    => ['onpage'],
        'technical' => ['technical'],
        'content'   => ['content'],
        'links'     => ['links'],
        'affiliate' => ['affiliate'],
        'woo'       => ['woo'],
    ];

    public static function init(): void {
        add_action('wpsd_run_scheduled_scan', [self::class, 'run_scheduled']);
        add_action('wpsd_continue_scan', [self::class, 'continue_in_background'], 10, 1);
    }

    /**
     * Create a scan and build its queue.
     *
     * @param string $type    One of self::TYPES.
     * @param string $trigger manual|cron|api
     * @return array{scan_id:int,total:int}|WP_Error
     */
    public static function start(string $type = 'full', string $trigger = 'manual') {
        global $wpdb;

        if (!isset(self::TYPES[$type])) {
            return new WP_Error('wpsd_bad_type', __('Unknown scan type.', 'wp-seo-doctor'));
        }

        // Only one scan at a time — concurrent scans would double-count issues.
        $running = self::running_scan();
        if ($running) {
            if (strtotime($running->started_at) > time() - HOUR_IN_SECONDS) {
                return new WP_Error(
                    'wpsd_scan_running',
                    __('A scan is already running. Wait for it to finish or cancel it first.', 'wp-seo-doctor')
                );
            }
            // Stale lock from a crashed request — reclaim it.
            self::finish($running->id, 'failed', __('Abandoned: superseded by a new scan.', 'wp-seo-doctor'));
        }

        $ids = self::build_queue($type);

        $wpdb->insert(WPSD_DB::table('scans'), [
            'type'           => $type,
            'trigger_source' => $trigger,
            'status'         => 'running',
            'total_objects'  => count($ids),
            'processed'      => 0,
            'queue'          => wp_json_encode(array_values($ids)),
            'started_at'     => WPSD_Helpers::now(),
        ]);

        $scan_id = (int) $wpdb->insert_id;
        if (!$scan_id) {
            return new WP_Error('wpsd_scan_insert_failed', __('Could not create the scan record.', 'wp-seo-doctor'));
        }

        // Fresh scan, fresh findings: previous open issues are superseded.
        self::clear_open_issues(self::TYPES[$type]);

        // Rebuild the caches the checks depend on so this scan sees current data.
        WPSD_Checks_OnPage::flush_duplicate_index();
        WPSD_Checks_Content::flush_shingle_index();
        WPSD_Internal_Links::flush_graph_cache();

        // Site-wide checks run once, up front.
        if (in_array('technical', self::TYPES[$type], true)) {
            $site = WPSD_Checks::run_site_checks($scan_id);
            if ($site['issues']) {
                WPSD_Issues::add_many($site['issues']);
            }
            self::bump_counters($scan_id, $site['issues'], $site['passed']);
        }

        return ['scan_id' => $scan_id, 'total' => count($ids)];
    }

    /**
     * Process one batch of the queue.
     *
     * @return array{
     *   scan_id:int, processed:int, total:int, done:bool,
     *   percent:int, issues:int, current:string
     * }|WP_Error
     */
    public static function run_batch(int $scan_id, int $batch_size = 0) {
        global $wpdb;

        $scan = self::get_scan($scan_id);
        if (!$scan) {
            return new WP_Error('wpsd_scan_missing', __('Scan not found.', 'wp-seo-doctor'));
        }
        if ($scan->status !== 'running') {
            return [
                'scan_id'   => $scan_id,
                'processed' => (int) $scan->processed,
                'total'     => (int) $scan->total_objects,
                'done'      => true,
                'percent'   => 100,
                'issues'    => (int) $scan->total_issues,
                'current'   => '',
            ];
        }

        $batch_size = $batch_size > 0 ? $batch_size : (int) WPSD_Settings::get('scan_batch_size', 20);
        $queue      = json_decode((string) $scan->queue, true);
        $queue      = is_array($queue) ? $queue : [];

        $batch     = array_splice($queue, 0, $batch_size);
        $issues    = [];
        $passed    = 0;
        $last_url  = '';
        $groups    = self::TYPES[$scan->type] ?? self::TYPES['full'];
        $needs_links = array_intersect(['links', 'affiliate', 'woo'], $groups) !== [];

        foreach ($batch as $post_id) {
            $post = get_post((int) $post_id);
            if (!$post) {
                continue;
            }

            try {
                $context  = new WPSD_Context($post);
                $last_url = $context->url;

                // Keep the link graph in step with the content being scanned.
                if ($needs_links) {
                    WPSD_Internal_Links::index_post($post, $context);
                }

                $result = self::run_groups($context, $groups, $scan_id);
                $issues = array_merge($issues, $result['issues']);
                $passed += $result['passed'];
            } catch (Throwable $e) {
                $issues[] = [
                    'scan_id'     => $scan_id,
                    'check_id'    => 'scan_error',
                    'check_group' => 'technical',
                    'object_type' => $post->post_type,
                    'object_id'   => (int) $post->ID,
                    'url'         => (string) get_permalink($post),
                    'severity'    => 'low',
                    'title'       => __('Page could not be analysed', 'wp-seo-doctor'),
                    'message'     => $e->getMessage(),
                    'data'        => [],
                ];
            }
        }

        if ($issues) {
            WPSD_Issues::add_many($issues);
        }

        $processed = (int) $scan->processed + count($batch);
        $wpdb->update(WPSD_DB::table('scans'), [
            'queue'     => wp_json_encode(array_values($queue)),
            'processed' => $processed,
        ], ['id' => $scan_id]);

        self::bump_counters($scan_id, $issues, $passed);

        $total = max(1, (int) $scan->total_objects);
        $done  = empty($queue);

        if ($done) {
            self::finish($scan_id, 'completed');
        }

        $refreshed = self::get_scan($scan_id);

        return [
            'scan_id'   => $scan_id,
            'processed' => $processed,
            'total'     => (int) $scan->total_objects,
            'done'      => $done,
            'percent'   => (int) min(100, round($processed / $total * 100)),
            'issues'    => $refreshed ? (int) $refreshed->total_issues : count($issues),
            'current'   => $last_url,
        ];
    }

    /**
     * Run only the check groups this scan type covers.
     *
     * @param array<int,string> $groups
     * @return array{issues:array<int,array<string,mixed>>, passed:int}
     */
    private static function run_groups(WPSD_Context $context, array $groups, int $scan_id): array {
        // A full scan is the common case — no filtering needed.
        if (count($groups) === count(self::TYPES['full'])) {
            return WPSD_Checks::run_post_checks($context, $scan_id);
        }

        $issues = [];
        $passed = 0;

        foreach ($groups as $group) {
            foreach (WPSD_Checks::in_group($group) as $check) {
                if ($check['scope'] !== 'post') {
                    continue;
                }
                if ($check['group'] === 'woo' && !$context->is_product()) {
                    continue;
                }

                $single = WPSD_Checks::run_post_checks_for($context, $check, $scan_id);
                if (!$single) {
                    $passed++;
                    continue;
                }
                $issues = array_merge($issues, $single);
            }
        }

        return ['issues' => $issues, 'passed' => $passed];
    }

    /**
     * Mark a scan finished and compute its score.
     */
    public static function finish(int $scan_id, string $status = 'completed', string $notes = ''): void {
        global $wpdb;

        $data = [
            'status'      => $status,
            'finished_at' => WPSD_Helpers::now(),
            'queue'       => wp_json_encode([]),
        ];
        if ($notes !== '') {
            $data['notes'] = $notes;
        }

        if ($status === 'completed') {
            $score          = WPSD_Score::calculate($scan_id);
            $data['score']  = $score['score'];
        }

        $wpdb->update(WPSD_DB::table('scans'), $data, ['id' => $scan_id]);

        if ($status === 'completed') {
            WPSD_Score::snapshot();
            WPSD_Issues::prune((int) WPSD_Settings::get('scan_history_limit', 30));

            /**
             * Fires after a scan completes successfully.
             *
             * @param int $scan_id
             */
            do_action('wpsd_scan_completed', $scan_id);
        }

        delete_transient(self::LOCK_KEY);
    }

    public static function cancel(int $scan_id): void {
        self::finish($scan_id, 'cancelled', __('Cancelled by user.', 'wp-seo-doctor'));
    }

    /**
     * Cron entry point: run a whole scan to completion, batch by batch, with a
     * wall-clock budget so we never blow the PHP time limit.
     */
    public static function run_scheduled(): void {
        $started = self::start('full', 'cron');
        if (is_wp_error($started)) {
            return;
        }

        self::drain($started['scan_id']);
    }

    /**
     * Keep running batches until the queue empties or the time budget is spent.
     * If the budget runs out first, reschedule the remainder.
     */
    public static function drain(int $scan_id, int $budget_seconds = 45): void {
        $deadline = time() + $budget_seconds;

        while (time() < $deadline) {
            $result = self::run_batch($scan_id);
            if (is_wp_error($result) || !empty($result['done'])) {
                return;
            }
        }

        // Out of time — pick up where we left off on the next cron tick.
        if (!wp_next_scheduled('wpsd_continue_scan', [$scan_id])) {
            wp_schedule_single_event(time() + 60, 'wpsd_continue_scan', [$scan_id]);
        }
    }

    public static function continue_in_background(int $scan_id): void {
        $scan = self::get_scan($scan_id);
        if ($scan && $scan->status === 'running') {
            self::drain($scan_id);
        }
    }

    // ─────────────────────────────────────────────────────────── queries ──

    public static function get_scan(int $scan_id): ?object {
        global $wpdb;
        $table = WPSD_DB::table('scans');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $scan_id)) ?: null;
    }

    public static function running_scan(): ?object {
        global $wpdb;
        $table = WPSD_DB::table('scans');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'running' ORDER BY id DESC LIMIT 1") ?: null;
    }

    public static function latest_completed(): ?object {
        global $wpdb;
        $table = WPSD_DB::table('scans');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'completed' ORDER BY finished_at DESC LIMIT 1") ?: null;
    }

    /**
     * Scan history, newest first.
     *
     * @return array<int,object>
     */
    public static function history(int $limit = 30): array {
        global $wpdb;
        $table = WPSD_DB::table('scans');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY started_at DESC LIMIT %d",
            max(1, $limit)
        )) ?: [];
    }

    public static function delete_scan(int $scan_id): void {
        global $wpdb;
        $wpdb->delete(WPSD_DB::table('issues'), ['scan_id' => $scan_id]);
        $wpdb->delete(WPSD_DB::table('scans'), ['id' => $scan_id]);
    }

    // ─────────────────────────────────────────────────────────── helpers ──

    /**
     * Object IDs to scan, ordered so the most important pages are audited first.
     *
     * @return array<int,int>
     */
    private static function build_queue(string $type): array {
        global $wpdb;

        $types = WPSD_Helpers::auditable_post_types();
        if ($type === 'woo') {
            $types = post_type_exists('product') ? ['product'] : [];
        }
        if (!$types) {
            return [];
        }

        $placeholders = WPSD_DB::in_placeholders($types, '%s');
        $sql = "SELECT ID FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ({$placeholders})
                ORDER BY post_modified DESC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, $types));

        $ids = array_map('intval', (array) $ids);

        // Put the front page first so its issues surface immediately.
        $front = (int) get_option('page_on_front');
        if ($front && in_array($front, $ids, true)) {
            $ids = array_merge([$front], array_diff($ids, [$front]));
        }

        /**
         * Filter the list of object IDs a scan will process.
         *
         * @param array<int,int> $ids
         * @param string         $type
         */
        return array_values((array) apply_filters('wpsd_scan_queue', $ids, $type));
    }

    /**
     * Delete the open issues belonging to the groups a new scan will re-test,
     * preserving anything the user explicitly ignored.
     *
     * @param array<int,string> $groups
     */
    private static function clear_open_issues(array $groups): void {
        global $wpdb;
        if (!$groups) {
            return;
        }

        $table        = WPSD_DB::table('issues');
        $placeholders = WPSD_DB::in_placeholders($groups, '%s');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'open' AND check_group IN ({$placeholders})",
            $groups
        ));
    }

    /**
     * Add this batch's counts onto the scan row.
     *
     * @param array<int,array<string,mixed>> $issues
     */
    private static function bump_counters(int $scan_id, array $issues, int $passed): void {
        global $wpdb;

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? 'medium';
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        $table = WPSD_DB::table('scans');
        $sql   = "UPDATE {$table} SET
                    total_issues = total_issues + %d,
                    critical = critical + %d,
                    high = high + %d,
                    medium = medium + %d,
                    low = low + %d,
                    passed = passed + %d
                  WHERE id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            $sql,
            count($issues),
            $counts['critical'],
            $counts['high'],
            $counts['medium'],
            $counts['low'],
            $passed,
            $scan_id
        ));
    }

    /**
     * Re-run every check against a single post — used by the "recheck" button
     * on an individual page.
     *
     * @return array{issues:int,passed:int}
     */
    public static function rescan_post(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return ['issues' => 0, 'passed' => 0];
        }

        WPSD_Issues::delete_for_object($post_id, $post->post_type);

        $context = new WPSD_Context($post);
        WPSD_Internal_Links::index_post($post, $context);

        $result = WPSD_Checks::run_post_checks($context, 0);
        if ($result['issues']) {
            WPSD_Issues::add_many($result['issues']);
        }

        return ['issues' => count($result['issues']), 'passed' => $result['passed']];
    }
}
