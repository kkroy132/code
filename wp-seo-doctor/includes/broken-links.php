<?php
/**
 * Broken link checking and the replace / remove / ignore / recheck workflow.
 *
 * Checking runs in batches against the link graph so a site with tens of
 * thousands of links never blocks a single request.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Broken_Links {

    public static function init(): void {
        add_action('wpsd_check_links_batch', [self::class, 'run_scheduled_check']);
    }

    /**
     * Check the next batch of links that are due.
     *
     * @return array{checked:int,broken:int,remaining:int}
     */
    public static function check_batch(int $batch_size = 0, bool $force = false): array {
        global $wpdb;

        $batch_size = $batch_size > 0 ? $batch_size : (int) WPSD_Settings::get('link_check_batch', 25);
        $table      = WPSD_DB::table('links');
        $recheck    = (int) WPSD_Settings::get('link_recheck_days', 14);

        $where  = ["status <> 'ignored'"];
        $params = [];

        if (!WPSD_Settings::get('check_external_links', true)) {
            $where[] = "link_type = 'internal'";
        }

        if (!$force) {
            // Never checked, or checked longer ago than the recheck window.
            $where[]  = '(last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL %d DAY))';
            $params[] = max(1, $recheck);
        }

        $where_sql = implode(' AND ', $where);

        // One row per distinct URL: checking the same URL from 40 posts 40
        // times would be pure waste.
        $sql = "SELECT MIN(id) AS id, target_url, target_hash
                FROM {$table}
                WHERE {$where_sql}
                GROUP BY target_hash, target_url
                ORDER BY id ASC
                LIMIT %d";

        $params[] = $batch_size;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];

        $broken = 0;
        foreach ($rows as $row) {
            if (self::check_url((string) $row->target_url, (string) $row->target_hash)) {
                $broken++;
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT target_hash) FROM {$table}
             WHERE status <> 'ignored'
               AND (last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL %d DAY))",
            max(1, $recheck)
        ));

        return [
            'checked'   => count($rows),
            'broken'    => $broken,
            'remaining' => $remaining,
        ];
    }

    /**
     * Check one URL and write the result to every row that shares it.
     *
     * @return bool True when the URL is broken.
     */
    public static function check_url(string $url, string $hash = ''): bool {
        global $wpdb;

        $hash  = $hash !== '' ? $hash : WPSD_Helpers::url_hash($url);
        $table = WPSD_DB::table('links');

        $trace  = WPSD_Helpers::trace_redirects($url);
        $status = $trace['final_status'];
        $hops   = max(0, count($trace['chain']) - 1);

        if ($trace['loop']) {
            $state = 'broken';
        } elseif ($status === 0) {
            // A connection failure is not always the site's fault (timeouts,
            // rate limits), so record it but treat it as broken for reporting.
            $state = 'broken';
        } elseif ($status >= 400) {
            $state = 'broken';
        } elseif ($hops > 0) {
            $state = 'redirect';
        } else {
            $state = 'ok';
        }

        $wpdb->update(
            $table,
            [
                'http_status'     => $status,
                'redirect_target' => $hops > 0 ? $trace['final_url'] : null,
                'redirect_hops'   => $hops,
                'status'          => $state,
                'last_checked'    => WPSD_Helpers::now(),
            ],
            ['target_hash' => $hash]
        );

        return $state === 'broken';
    }

    /**
     * Cron handler: work through the due links within a time budget.
     */
    public static function run_scheduled_check(): void {
        $deadline = time() + 45;

        while (time() < $deadline) {
            $result = self::check_batch();
            if ($result['checked'] === 0 || $result['remaining'] === 0) {
                return;
            }
        }

        if (!wp_next_scheduled('wpsd_check_links_batch')) {
            wp_schedule_single_event(time() + 120, 'wpsd_check_links_batch');
        }
    }

    // ─────────────────────────────────────────────────────────── queries ──

    /**
     * Broken/redirecting links with source and target detail.
     *
     * @param array<string,mixed> $args
     * @return array{rows:array<int,object>, total:int, pages:int}
     */
    public static function query(array $args = []): array {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'    => 'broken',   // broken|redirect|ok|ignored|all
            'type'      => '',         // internal|external
            'affiliate' => null,       // true to restrict to affiliate links
            'search'    => '',
            'per_page'  => 25,
            'page'      => 1,
            'orderby'   => 'http_status',
            'order'     => 'DESC',
        ]);

        $table = WPSD_DB::table('links');

        $where  = ['1=1'];
        $params = [];

        if ($args['status'] !== '' && $args['status'] !== 'all') {
            $where[]  = 'l.status = %s';
            $params[] = $args['status'];
        }
        if ($args['type'] !== '') {
            $where[]  = 'l.link_type = %s';
            $params[] = $args['type'];
        }
        if ($args['affiliate'] === true) {
            $where[] = 'l.is_affiliate = 1';
        }
        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(l.target_url LIKE %s OR l.anchor LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $allowed   = ['http_status', 'last_checked', 'target_url', 'id', 'redirect_hops'];
        $orderby   = in_array($args['orderby'], $allowed, true) ? $args['orderby'] : 'http_status';
        $order     = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $per_page  = max(1, (int) $args['per_page']);
        $offset    = (max(1, (int) $args['page']) - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} l WHERE {$where_sql}";
        $data_sql  = "SELECT l.*, p.post_title AS source_title
                      FROM {$table} l
                      LEFT JOIN {$wpdb->posts} p ON p.ID = l.source_id
                      WHERE {$where_sql}
                      ORDER BY l.{$orderby} {$order}, l.id DESC
                      LIMIT %d OFFSET %d";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, [$per_page, $offset])));
        // phpcs:enable

        foreach ((array) $rows as $row) {
            $row->edit_url = (int) $row->source_id ? (string) get_edit_post_link((int) $row->source_id, 'raw') : '';
        }

        return [
            'rows'  => $rows ?: [],
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    public static function get_link(int $link_id): ?object {
        global $wpdb;
        $table = WPSD_DB::table('links');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $link_id)) ?: null;
    }

    /**
     * @return array<int,object>
     */
    public static function broken_for_source(int $post_id, bool $affiliate_only = false): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $sql    = "SELECT * FROM {$table} WHERE source_id = %d AND status = 'broken'";
        $params = [$post_id];
        if ($affiliate_only) {
            $sql .= ' AND is_affiliate = 1';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /**
     * @return array<int,object>
     */
    public static function chained_for_source(int $post_id, bool $affiliate_only = false): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $sql    = "SELECT * FROM {$table} WHERE source_id = %d AND status = 'redirect' AND redirect_hops > 1";
        $params = [$post_id];
        if ($affiliate_only) {
            $sql .= ' AND is_affiliate = 1';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    public static function count_broken(bool $affiliate_only = false): int {
        $where = "status = 'broken'";
        if ($affiliate_only) {
            $where .= ' AND is_affiliate = 1';
        }
        return WPSD_DB::count('links', $where);
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status");

        $stats = ['ok' => 0, 'broken' => 0, 'redirect' => 0, 'ignored' => 0, 'unchecked' => 0];
        foreach ((array) $rows as $row) {
            if (isset($stats[$row->status])) {
                $stats[$row->status] = (int) $row->cnt;
            }
        }

        $stats['total']            = array_sum($stats);
        $stats['broken_internal']  = WPSD_DB::count('links', "status = 'broken' AND link_type = 'internal'");
        $stats['broken_external']  = WPSD_DB::count('links', "status = 'broken' AND link_type = 'external'");
        $stats['broken_affiliate'] = WPSD_DB::count('links', "status = 'broken' AND is_affiliate = 1");

        return $stats;
    }

    // ───────────────────────────────────────────────────────── workflow ──

    /**
     * Swap a link's URL everywhere it appears, editing the source posts.
     *
     * @return array{updated:int,posts:array<int,int>}|WP_Error
     */
    public static function replace(int $link_id, string $new_url) {
        global $wpdb;

        $link = self::get_link($link_id);
        if (!$link) {
            return new WP_Error('wpsd_link_missing', __('Link not found.', 'wp-seo-doctor'));
        }

        $new_url = esc_url_raw(trim($new_url));
        if ($new_url === '') {
            return new WP_Error('wpsd_bad_url', __('Please supply a valid replacement URL.', 'wp-seo-doctor'));
        }

        $table = WPSD_DB::table('links');
        // Every post containing this URL, not just the row that was clicked.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT source_id, target_url FROM {$table} WHERE target_hash = %s AND source_id > 0",
            $link->target_hash
        ));

        $updated = 0;
        $posts   = [];

        foreach ((array) $rows as $row) {
            $post = get_post((int) $row->source_id);
            if (!$post) {
                continue;
            }

            $content = str_replace($row->target_url, $new_url, $post->post_content, $count);
            if (!$count) {
                continue;
            }

            $result = wp_update_post(['ID' => $post->ID, 'post_content' => $content], true);
            if (is_wp_error($result)) {
                continue;
            }

            WPSD_Internal_Links::index_post(get_post($post->ID));
            $updated += $count;
            $posts[]  = (int) $post->ID;
        }

        // Verify the replacement so the row reflects reality immediately.
        self::check_url($new_url);

        return ['updated' => $updated, 'posts' => $posts];
    }

    /**
     * Unwrap a link, keeping its anchor text as plain text.
     *
     * @return array{updated:int,posts:array<int,int>}|WP_Error
     */
    public static function remove(int $link_id) {
        global $wpdb;

        $link = self::get_link($link_id);
        if (!$link) {
            return new WP_Error('wpsd_link_missing', __('Link not found.', 'wp-seo-doctor'));
        }

        $table = WPSD_DB::table('links');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT source_id, target_url FROM {$table} WHERE target_hash = %s AND source_id > 0",
            $link->target_hash
        ));

        $updated = 0;
        $posts   = [];

        foreach ((array) $rows as $row) {
            $post = get_post((int) $row->source_id);
            if (!$post) {
                continue;
            }

            $pattern = '#<a\b[^>]*href=["\']' . preg_quote((string) $row->target_url, '#') . '["\'][^>]*>(.*?)</a>#is';
            $content = preg_replace($pattern, '$1', $post->post_content, -1, $count);

            if (!$count || $content === null) {
                continue;
            }

            $result = wp_update_post(['ID' => $post->ID, 'post_content' => $content], true);
            if (is_wp_error($result)) {
                continue;
            }

            WPSD_Internal_Links::index_post(get_post($post->ID));
            $updated += $count;
            $posts[]  = (int) $post->ID;
        }

        // The rows for this URL are gone from those posts; drop any strays.
        $wpdb->delete($table, ['target_hash' => $link->target_hash]);

        return ['updated' => $updated, 'posts' => $posts];
    }

    /**
     * Stop reporting a URL without touching the content.
     */
    public static function ignore(int $link_id): bool {
        global $wpdb;

        $link = self::get_link($link_id);
        if (!$link) {
            return false;
        }

        return (bool) $wpdb->update(
            WPSD_DB::table('links'),
            ['status' => 'ignored'],
            ['target_hash' => $link->target_hash]
        );
    }

    public static function unignore(int $link_id): bool {
        global $wpdb;

        $link = self::get_link($link_id);
        if (!$link) {
            return false;
        }

        $wpdb->update(
            WPSD_DB::table('links'),
            ['status' => 'unchecked', 'last_checked' => null],
            ['target_hash' => $link->target_hash]
        );

        return true;
    }

    /**
     * Re-test a single link right now.
     *
     * @return array{status:string,http_status:int,hops:int}|WP_Error
     */
    public static function recheck(int $link_id) {
        $link = self::get_link($link_id);
        if (!$link) {
            return new WP_Error('wpsd_link_missing', __('Link not found.', 'wp-seo-doctor'));
        }

        self::check_url((string) $link->target_url, (string) $link->target_hash);
        $fresh = self::get_link($link_id);

        return [
            'status'      => $fresh ? (string) $fresh->status : 'unchecked',
            'http_status' => $fresh ? (int) $fresh->http_status : 0,
            'hops'        => $fresh ? (int) $fresh->redirect_hops : 0,
        ];
    }
}
