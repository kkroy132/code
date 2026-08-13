<?php
/**
 * SEO Health Score.
 *
 * The score is a weighted penalty model: every open issue subtracts points
 * scaled by its severity, normalised against how many checks actually ran so
 * that a 5-page site and a 5,000-page site are comparable.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Score {

    /**
     * Compute the score for a scan (or site-wide when $scan_id is 0).
     *
     * @return array{
     *   score:int, grade:string, label:string,
     *   counts:array{critical:int,high:int,medium:int,low:int,total:int},
     *   passed:int, checked:int, penalty:float
     * }
     */
    public static function calculate(int $scan_id = 0): array {
        $counts = WPSD_Issues::severity_counts($scan_id);

        $checked = 0;
        $passed  = 0;
        if ($scan_id > 0) {
            $scan = WPSD_Scanner::get_scan($scan_id);
            if ($scan) {
                $checked = (int) $scan->processed * max(1, count(WPSD_Checks::all()));
                $passed  = (int) $scan->passed;
            }
        } else {
            $latest = WPSD_Scanner::latest_completed();
            if ($latest) {
                $checked = (int) $latest->processed * max(1, count(WPSD_Checks::all()));
                $passed  = (int) $latest->passed;
            }
        }

        // Nothing scanned yet — no score to report.
        if ($checked <= 0) {
            return [
                'score'   => 0,
                'grade'   => 'n/a',
                'label'   => __('Not scanned yet', 'wp-seo-doctor'),
                'counts'  => $counts,
                'passed'  => 0,
                'checked' => 0,
                'penalty' => 0.0,
            ];
        }

        $penalty = 0.0;
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $penalty += $counts[$severity] * WPSD_Helpers::severity_weight($severity);
        }

        // Normalise: the worst realistic case is every check failing at medium
        // weight, so divide by that instead of by an arbitrary constant.
        $max_penalty = max(1, $checked * WPSD_Helpers::severity_weight('medium'));
        $ratio       = min(1.0, $penalty / $max_penalty);
        $score       = (int) round((1 - $ratio) * 100);

        // A single critical issue should never leave a site looking perfect.
        if ($counts['critical'] > 0) {
            $score = min($score, 89);
        }
        if ($counts['critical'] >= 10) {
            $score = min($score, 69);
        }
        $score = max(0, min(100, $score));

        return [
            'score'   => $score,
            'grade'   => self::grade($score),
            'label'   => self::label($score),
            'counts'  => $counts,
            'passed'  => $passed,
            'checked' => $checked,
            'penalty' => round($penalty, 2),
        ];
    }

    public static function grade(int $score): string {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    public static function label(int $score): string {
        if ($score >= 90) return __('Excellent', 'wp-seo-doctor');
        if ($score >= 80) return __('Good', 'wp-seo-doctor');
        if ($score >= 70) return __('Needs work', 'wp-seo-doctor');
        if ($score >= 60) return __('Poor', 'wp-seo-doctor');
        return __('Critical', 'wp-seo-doctor');
    }

    /** Colour token used by the dashboard gauge. */
    public static function color(int $score): string {
        if ($score >= 90) return '#16a34a';
        if ($score >= 80) return '#65a30d';
        if ($score >= 70) return '#ca8a04';
        if ($score >= 60) return '#ea580c';
        return '#dc2626';
    }

    /**
     * Per-group sub-scores so the dashboard can show where the damage is.
     *
     * @return array<string,array{score:int,issues:int,label:string}>
     */
    public static function by_group(int $scan_id = 0): array {
        global $wpdb;
        $table = WPSD_DB::table('issues');

        $where  = "status = 'open'";
        $params = [];
        if ($scan_id > 0) {
            $where   .= ' AND scan_id = %d';
            $params[] = $scan_id;
        }

        $sql = "SELECT check_group, severity, COUNT(*) AS cnt FROM {$table} WHERE {$where} GROUP BY check_group, severity";
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        // phpcs:enable

        $groups = WPSD_Checks::groups();
        $out    = [];
        foreach ($groups as $key => $label) {
            $out[$key] = ['score' => 100, 'issues' => 0, 'label' => $label, 'penalty' => 0];
        }

        foreach ((array) $rows as $row) {
            $key = $row->check_group;
            if (!isset($out[$key])) {
                $out[$key] = ['score' => 100, 'issues' => 0, 'label' => ucfirst($key), 'penalty' => 0];
            }
            $out[$key]['issues']  += (int) $row->cnt;
            $out[$key]['penalty'] += (int) $row->cnt * WPSD_Helpers::severity_weight($row->severity);
        }

        $scan      = $scan_id > 0 ? WPSD_Scanner::get_scan($scan_id) : WPSD_Scanner::latest_completed();
        $processed = $scan ? max(1, (int) $scan->processed) : 1;

        foreach ($out as $key => $data) {
            $checks_in_group = max(1, count(WPSD_Checks::in_group($key)));
            $max             = max(1, $processed * $checks_in_group * WPSD_Helpers::severity_weight('medium'));
            $out[$key]['score'] = (int) max(0, round((1 - min(1.0, $data['penalty'] / $max)) * 100));
            unset($out[$key]['penalty']);
        }

        return $out;
    }

    /**
     * Write today's snapshot so the trend report has something to plot.
     * Idempotent — running twice in a day updates the same row.
     */
    public static function snapshot(): void {
        global $wpdb;

        $score  = self::calculate();
        $counts = $score['counts'];

        $data = [
            'snapshot_date' => current_time('Y-m-d'),
            'score'         => (int) $score['score'],
            'total_issues'  => (int) $counts['total'],
            'critical'      => (int) $counts['critical'],
            'high'          => (int) $counts['high'],
            'medium'        => (int) $counts['medium'],
            'low'           => (int) $counts['low'],
            'passed'        => (int) $score['passed'],
            'broken_links'  => WPSD_Broken_Links::count_broken(),
            'notfound_urls' => WPSD_Monitor_404::count_active(),
            'orphan_pages'  => count(WPSD_Internal_Links::orphan_pages(500)),
        ];

        $table   = WPSD_DB::table('trends');
        $columns = implode(',', array_keys($data));
        $holders = implode(',', array_fill(0, count($data), '%s'));

        $updates = [];
        foreach (array_keys($data) as $column) {
            if ($column === 'snapshot_date') {
                continue;
            }
            $updates[] = "{$column} = VALUES({$column})";
        }

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$holders})
                ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare($sql, array_values($data)));
    }

    /**
     * Trend rows, oldest first, for the last N days.
     *
     * @return array<int,object>
     */
    public static function trend(int $days = 30): array {
        global $wpdb;
        $table = WPSD_DB::table('trends');

        $sql = "SELECT * FROM {$table} WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) ORDER BY snapshot_date ASC";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, max(1, $days))) ?: [];
    }

    /**
     * Score delta versus the oldest snapshot in the window.
     *
     * @return array{delta:int,direction:string,from:int,to:int}
     */
    public static function trend_delta(int $days = 30): array {
        $rows = self::trend($days);
        if (count($rows) < 2) {
            return ['delta' => 0, 'direction' => 'flat', 'from' => 0, 'to' => 0];
        }
        $from  = (int) $rows[0]->score;
        $to    = (int) end($rows)->score;
        $delta = $to - $from;

        return [
            'delta'     => $delta,
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'from'      => $from,
            'to'        => $to,
        ];
    }
}
