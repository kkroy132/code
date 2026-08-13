<?php
/**
 * Issue store: recording, querying and resolving detected SEO problems.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Issues {

    /**
     * Persist one issue.
     *
     * @param array{
     *   scan_id?:int, check_id:string, check_group?:string, object_type?:string,
     *   object_id?:int, url?:string, severity?:string, title:string,
     *   message?:string, recommendation?:string, data?:array
     * } $issue
     */
    public static function add(array $issue): int {
        global $wpdb;

        $severity = $issue['severity'] ?? 'medium';
        if (!in_array($severity, WPSD_Helpers::SEVERITIES, true)) {
            $severity = 'medium';
        }

        $wpdb->insert(WPSD_DB::table('issues'), [
            'scan_id'        => (int) ($issue['scan_id'] ?? 0),
            'check_id'       => substr((string) $issue['check_id'], 0, 64),
            'check_group'    => substr((string) ($issue['check_group'] ?? 'onpage'), 0, 32),
            'object_type'    => substr((string) ($issue['object_type'] ?? 'post'), 0, 20),
            'object_id'      => (int) ($issue['object_id'] ?? 0),
            'url'            => (string) ($issue['url'] ?? ''),
            'severity'       => $severity,
            'title'          => substr((string) $issue['title'], 0, 255),
            'message'        => (string) ($issue['message'] ?? ''),
            'recommendation' => (string) ($issue['recommendation'] ?? ''),
            'data'           => !empty($issue['data']) ? wp_json_encode($issue['data']) : null,
            'status'         => 'open',
            'created_at'     => WPSD_Helpers::now(),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Insert many issues in a single statement — scans generate thousands.
     *
     * @param array<int,array<string,mixed>> $issues
     */
    public static function add_many(array $issues): int {
        global $wpdb;
        if (!$issues) {
            return 0;
        }

        $table = WPSD_DB::table('issues');
        $now   = WPSD_Helpers::now();
        $rows  = [];
        $args  = [];

        foreach ($issues as $issue) {
            if (empty($issue['check_id']) || empty($issue['title'])) {
                continue;
            }
            $severity = $issue['severity'] ?? 'medium';
            if (!in_array($severity, WPSD_Helpers::SEVERITIES, true)) {
                $severity = 'medium';
            }

            $rows[] = '(%d,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s)';
            array_push(
                $args,
                (int) ($issue['scan_id'] ?? 0),
                substr((string) $issue['check_id'], 0, 64),
                substr((string) ($issue['check_group'] ?? 'onpage'), 0, 32),
                substr((string) ($issue['object_type'] ?? 'post'), 0, 20),
                (int) ($issue['object_id'] ?? 0),
                (string) ($issue['url'] ?? ''),
                $severity,
                substr((string) $issue['title'], 0, 255),
                (string) ($issue['message'] ?? ''),
                (string) ($issue['recommendation'] ?? ''),
                !empty($issue['data']) ? (string) wp_json_encode($issue['data']) : '',
                'open',
                $now
            );
        }

        if (!$rows) {
            return 0;
        }

        $sql = "INSERT INTO {$table}
            (scan_id, check_id, check_group, object_type, object_id, url, severity, title, message, recommendation, data, status, created_at)
            VALUES " . implode(',', $rows);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound here.
        $wpdb->query($wpdb->prepare($sql, $args));

        return count($rows);
    }

    /**
     * Query issues with filters and pagination.
     *
     * @param array<string,mixed> $args
     * @return array{rows:array<int,object>, total:int, pages:int}
     */
    public static function query(array $args = []): array {
        global $wpdb;
        $table = WPSD_DB::table('issues');

        $args = wp_parse_args($args, [
            'scan_id'     => 0,
            'severity'    => '',
            'check_id'    => '',
            'check_group' => '',
            'object_id'   => 0,
            'status'      => 'open',
            'search'      => '',
            'per_page'    => 25,
            'page'        => 1,
            'orderby'     => 'severity',
            'order'       => 'ASC',
        ]);

        $where  = ['1=1'];
        $params = [];

        if ((int) $args['scan_id'] > 0) {
            $where[]  = 'scan_id = %d';
            $params[] = (int) $args['scan_id'];
        }
        if ($args['severity'] !== '' && in_array($args['severity'], WPSD_Helpers::SEVERITIES, true)) {
            $where[]  = 'severity = %s';
            $params[] = $args['severity'];
        }
        if ($args['check_id'] !== '') {
            $where[]  = 'check_id = %s';
            $params[] = $args['check_id'];
        }
        if ($args['check_group'] !== '') {
            $where[]  = 'check_group = %s';
            $params[] = $args['check_group'];
        }
        if ((int) $args['object_id'] > 0) {
            $where[]  = 'object_id = %d';
            $params[] = (int) $args['object_id'];
        }
        if ($args['status'] !== '' && $args['status'] !== 'all') {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(title LIKE %s OR message LIKE %s OR url LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        // severity is a string column, so order by its logical weight.
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        if ($args['orderby'] === 'severity') {
            $order_sql = "FIELD(severity,'critical','high','medium','low') {$order}, id DESC";
        } else {
            $allowed   = ['id', 'created_at', 'check_id', 'object_id', 'status'];
            $column    = in_array($args['orderby'], $allowed, true) ? $args['orderby'] : 'id';
            $order_sql = "{$column} {$order}";
        }

        $per_page = max(1, (int) $args['per_page']);
        $offset   = (max(1, (int) $args['page']) - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, [$per_page, $offset])));
        // phpcs:enable

        return [
            'rows'  => $rows ?: [],
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Counts per severity for open issues (optionally scoped to a scan).
     *
     * @return array{critical:int,high:int,medium:int,low:int,total:int}
     */
    public static function severity_counts(int $scan_id = 0): array {
        global $wpdb;
        $table = WPSD_DB::table('issues');

        if ($scan_id > 0) {
            $sql  = "SELECT severity, COUNT(*) AS cnt FROM {$table} WHERE scan_id = %d AND status = 'open' GROUP BY severity";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare($sql, $scan_id));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results("SELECT severity, COUNT(*) AS cnt FROM {$table} WHERE status = 'open' GROUP BY severity");
        }

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total' => 0];
        foreach ((array) $rows as $row) {
            if (isset($counts[$row->severity])) {
                $counts[$row->severity] = (int) $row->cnt;
                $counts['total']       += (int) $row->cnt;
            }
        }

        return $counts;
    }

    /**
     * Issues grouped by check, most severe and most frequent first.
     *
     * @return array<int,object>
     */
    public static function grouped_by_check(int $scan_id = 0, int $limit = 50): array {
        global $wpdb;
        $table = WPSD_DB::table('issues');

        $where  = "status = 'open'";
        $params = [];
        if ($scan_id > 0) {
            $where   .= ' AND scan_id = %d';
            $params[] = $scan_id;
        }

        $sql = "SELECT check_id, check_group, severity, title, recommendation, COUNT(*) AS affected
                FROM {$table}
                WHERE {$where}
                GROUP BY check_id, check_group, severity, title, recommendation
                ORDER BY FIELD(severity,'critical','high','medium','low') ASC, affected DESC
                LIMIT %d";

        $params[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /**
     * The "Fix First" list: highest impact issues, deduplicated by check.
     *
     * @return array<int,object>
     */
    public static function fix_first(int $limit = 10): array {
        $groups = self::grouped_by_check(0, $limit * 2);

        // Rank by severity weight multiplied by how many URLs are affected.
        usort($groups, static function ($a, $b) {
            $score_a = WPSD_Helpers::severity_weight($a->severity) * (int) $a->affected;
            $score_b = WPSD_Helpers::severity_weight($b->severity) * (int) $b->affected;
            return $score_b <=> $score_a;
        });

        return array_slice($groups, 0, $limit);
    }

    /**
     * Change status on a set of issues.
     *
     * @param array<int,int> $ids
     */
    public static function set_status(array $ids, string $status): int {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !in_array($status, ['open', 'ignored', 'fixed'], true)) {
            return 0;
        }

        $table        = WPSD_DB::table('issues');
        $placeholders = WPSD_DB::in_placeholders($ids);
        $sql          = "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query($wpdb->prepare($sql, array_merge([$status], $ids)));
    }

    /** Remove issues attached to a specific object — used before a re-check. */
    public static function delete_for_object(int $object_id, string $object_type = 'post'): void {
        global $wpdb;
        $wpdb->delete(WPSD_DB::table('issues'), [
            'object_id'   => $object_id,
            'object_type' => $object_type,
        ]);
    }

    /** Clear the issues belonging to one or more checks. */
    public static function delete_for_checks(array $check_ids): void {
        global $wpdb;
        $check_ids = array_values(array_filter(array_map('strval', $check_ids)));
        if (!$check_ids) {
            return;
        }
        $table        = WPSD_DB::table('issues');
        $placeholders = WPSD_DB::in_placeholders($check_ids, '%s');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE check_id IN ({$placeholders})", $check_ids));
    }

    /**
     * Discard issues from scans older than the retention limit, keeping the
     * newest N scans' worth of history.
     */
    public static function prune(int $keep_scans = 30): void {
        global $wpdb;
        $scans  = WPSD_DB::table('scans');
        $issues = WPSD_DB::table('issues');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $keep_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$scans} ORDER BY started_at DESC LIMIT %d",
            max(1, $keep_scans)
        ));

        if (!$keep_ids) {
            return;
        }

        $placeholders = WPSD_DB::in_placeholders($keep_ids);
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$issues} WHERE scan_id > 0 AND scan_id NOT IN ({$placeholders})", $keep_ids));
        $wpdb->query($wpdb->prepare("DELETE FROM {$scans} WHERE id NOT IN ({$placeholders})", $keep_ids));
        // phpcs:enable
    }

    /** Decode the JSON payload attached to an issue row. */
    public static function data(object $issue): array {
        if (empty($issue->data)) {
            return [];
        }
        $decoded = json_decode((string) $issue->data, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Human label for an issue's edit target. */
    public static function edit_link(object $issue): string {
        if ((int) $issue->object_id > 0 && $issue->object_type !== 'site') {
            $link = get_edit_post_link((int) $issue->object_id, 'raw');
            if ($link) {
                return $link;
            }
        }
        return (string) $issue->url;
    }
}
