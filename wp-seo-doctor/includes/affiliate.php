<?php
/**
 * Affiliate SEO reporting.
 *
 * Detection lives in WPSD_Checks_Affiliate; this module aggregates the link
 * graph into the lists the Affiliate SEO screen shows.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Affiliate {

    public static function init(): void {
        add_action('wpsd_retag_affiliate', [self::class, 'continue_retag'], 10, 1);
        // Re-tag affiliate links whenever the detection lists change, so an
        // edited domain list takes effect without a full re-index.
        add_action('update_option_' . WPSD_Settings::OPTION, [self::class, 'maybe_retag'], 10, 2);
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public static function maybe_retag($old, $new): void {
        if (!is_array($old) || !is_array($new)) {
            return;
        }
        $keys = ['affiliate_domains', 'affiliate_prefixes'];
        foreach ($keys as $key) {
            if (($old[$key] ?? '') !== ($new[$key] ?? '')) {
                // Retag what fits in the request, then hand the rest to cron.
                // Saving settings must not hang while a large link table is
                // reprocessed, and it must not stop halfway either.
                $result = self::retag_links(0, 5.0);
                if (empty($result['complete'])) {
                    self::schedule_continuation((int) $result['last_id']);
                }
                return;
            }
        }
    }

    /**
     * Queue the rest of a retag run.
     */
    public static function schedule_continuation(int $after_id): void {
        if (!wp_next_scheduled('wpsd_retag_affiliate', [$after_id])) {
            wp_schedule_single_event(time() + 60, 'wpsd_retag_affiliate', [$after_id]);
        }
    }

    /**
     * Cron handler: continue retagging, rescheduling until the table is done.
     */
    public static function continue_retag(int $after_id = 0): void {
        $result = self::retag_links($after_id, 30.0);

        if (empty($result['complete'])) {
            self::schedule_continuation((int) $result['last_id']);
        }
    }

    /** Rows read per pass. Keeps peak memory flat whatever the table size. */
    const RETAG_BATCH = 2000;

    /**
     * Recompute the is_affiliate flag across the whole link table.
     *
     * Walks the table by primary key rather than reading it in one go: the
     * previous version stopped at 50,000 rows and returned as though it had
     * finished, so a large site's later links kept a stale flag with nothing
     * to indicate it.
     *
     * @param float $budget_seconds Wall-clock budget; 0 means run to the end.
     * @return array{changed:int,scanned:int,complete:bool,last_id:int}
     */
    public static function retag_links(int $after_id = 0, float $budget_seconds = 20.0): array {
        global $wpdb;

        $table    = WPSD_DB::table('links');
        $deadline = $budget_seconds > 0 ? microtime(true) + $budget_seconds : 0.0;

        $changed  = 0;
        $scanned  = 0;
        $last_id  = $after_id;
        $complete = false;

        while (true) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, target_url, is_affiliate FROM {$table}
                 WHERE id > %d ORDER BY id ASC LIMIT %d",
                $last_id,
                self::RETAG_BATCH
            ));

            if (!$rows) {
                $complete = true;
                break;
            }

            foreach ($rows as $row) {
                $last_id = (int) $row->id;
                $scanned++;

                $flag = WPSD_Checks_Affiliate::is_affiliate((string) $row->target_url) ? 1 : 0;
                if ($flag !== (int) $row->is_affiliate) {
                    $wpdb->update($table, ['is_affiliate' => $flag], ['id' => (int) $row->id]);
                    $changed++;
                }
            }

            if (count($rows) < self::RETAG_BATCH) {
                $complete = true;
                break;
            }

            if ($deadline > 0.0 && microtime(true) >= $deadline) {
                // Out of budget with rows still to go; the caller resumes from
                // last_id rather than being told the job is done.
                break;
            }
        }

        return [
            'changed'  => $changed,
            'scanned'  => $scanned,
            'complete' => $complete,
            'last_id'  => $last_id,
        ];
    }

    /**
     * All affiliate links, grouped by destination URL.
     *
     * @param array<string,mixed> $args
     * @return array{rows:array<int,object>, total:int, pages:int}
     */
    public static function query(array $args = []): array {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'   => '',      // broken|redirect|ok|all
            'search'   => '',
            'per_page' => 25,
            'page'     => 1,
        ]);

        $table = WPSD_DB::table('links');

        $where  = ['is_affiliate = 1'];
        $params = [];

        if ($args['status'] !== '' && $args['status'] !== 'all') {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(target_url LIKE %s OR anchor LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $per_page  = max(1, (int) $args['per_page']);
        $offset    = (max(1, (int) $args['page']) - 1) * $per_page;

        // One row per destination, with the number of posts using it.
        //
        // The worst status in the group wins, ranked by severity rather than
        // alphabetically — MIN() over FIELD() gives that with no subquery.
        // GROUP_CONCAT has no LIMIT clause in MySQL, so the id list is trimmed
        // in PHP instead.
        $count_sql = "SELECT COUNT(DISTINCT target_hash) FROM {$table} WHERE {$where_sql}";
        $data_sql  = "SELECT MIN(id) AS id,
                             target_url,
                             target_hash,
                             MIN(FIELD(status,'broken','redirect','unchecked','ok','ignored')) AS status_rank,
                             MAX(http_status) AS http_status,
                             MAX(redirect_hops) AS redirect_hops,
                             MAX(redirect_target) AS redirect_target,
                             MAX(last_checked) AS last_checked,
                             COUNT(DISTINCT source_id) AS used_on,
                             GROUP_CONCAT(DISTINCT source_id ORDER BY source_id) AS source_ids
                      FROM {$table}
                      WHERE {$where_sql}
                      GROUP BY target_hash, target_url
                      ORDER BY status_rank ASC, used_on DESC
                      LIMIT %d OFFSET %d";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, [$per_page, $offset])));
        // phpcs:enable

        $rank_map = [1 => 'broken', 2 => 'redirect', 3 => 'unchecked', 4 => 'ok', 5 => 'ignored'];

        foreach ((array) $rows as $row) {
            $row->status  = $rank_map[(int) $row->status_rank] ?? 'unchecked';
            $row->sources = self::describe_sources((string) $row->source_ids, 10);
            $row->domain  = (string) wp_parse_url((string) $row->target_url, PHP_URL_HOST);
        }

        return [
            'rows'  => $rows ?: [],
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Affiliate destinations that are dead — the ones costing money right now.
     *
     * @return array<int,object>
     */
    public static function dead_products(int $limit = 100): array {
        $result = self::query(['status' => 'broken', 'per_page' => $limit]);
        return $result['rows'];
    }

    /**
     * Affiliate links that redirect, split by hop count.
     *
     * @return array{single:array<int,object>, chains:array<int,object>}
     */
    public static function redirects(int $limit = 100): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $sql = "SELECT MIN(id) AS id, target_url, MAX(redirect_hops) AS redirect_hops,
                       MAX(redirect_target) AS redirect_target, COUNT(DISTINCT source_id) AS used_on
                FROM {$table}
                WHERE is_affiliate = 1 AND status = 'redirect'
                GROUP BY target_hash, target_url
                ORDER BY redirect_hops DESC, used_on DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, $limit))) ?: [];

        $single = [];
        $chains = [];
        foreach ($rows as $row) {
            if ((int) $row->redirect_hops > 1) {
                $chains[] = $row;
            } else {
                $single[] = $row;
            }
        }

        return ['single' => $single, 'chains' => $chains];
    }

    /**
     * Outbound (non-affiliate) external links, grouped by domain.
     *
     * @return array<int,object>
     */
    public static function outbound_domains(int $limit = 50): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        // SUBSTRING_INDEX pulls the host out of the stored URL without a join.
        $sql = "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(target_url, '/', 3), '://', -1) AS domain,
                       COUNT(*) AS links,
                       COUNT(DISTINCT source_id) AS pages,
                       SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) AS broken,
                       SUM(is_affiliate) AS affiliate
                FROM {$table}
                WHERE link_type = 'external'
                GROUP BY domain
                ORDER BY links DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, max(1, $limit))) ?: [];
    }

    /**
     * Affiliate links whose rel attribute is missing the required tokens.
     *
     * @return array<int,object>
     */
    public static function missing_attributes(int $limit = 100): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $required = (array) WPSD_Settings::get('affiliate_required_rel', ['sponsored', 'nofollow']);

        $conditions = [];
        $params     = [];
        foreach ($required as $token) {
            $conditions[] = 'rel NOT LIKE %s';
            $params[]     = '%' . $wpdb->esc_like((string) $token) . '%';
        }
        // A link is a problem only when it has none of the accepted tokens.
        $condition_sql = $conditions ? '(' . implode(' AND ', $conditions) . ')' : '1=1';

        $sql = "SELECT l.*, p.post_title AS source_title
                FROM {$table} l
                LEFT JOIN {$wpdb->posts} p ON p.ID = l.source_id
                WHERE l.is_affiliate = 1 AND {$condition_sql}
                ORDER BY l.source_id ASC
                LIMIT %d";

        $params[] = max(1, $limit);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];

        foreach ($rows as $row) {
            $row->edit_url = (int) $row->source_id ? (string) get_edit_post_link((int) $row->source_id, 'raw') : '';
        }

        return $rows;
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total  = (int) $wpdb->get_var("SELECT COUNT(DISTINCT target_hash) FROM {$table} WHERE is_affiliate = 1");
        $pages  = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_id) FROM {$table} WHERE is_affiliate = 1");
        // phpcs:enable

        return [
            'total_links'       => $total,
            'pages_with_links'  => $pages,
            'broken'            => WPSD_DB::count('links', "is_affiliate = 1 AND status = 'broken'"),
            'redirects'         => WPSD_DB::count('links', "is_affiliate = 1 AND status = 'redirect'"),
            'chains'            => WPSD_DB::count('links', "is_affiliate = 1 AND status = 'redirect' AND redirect_hops > 1"),
            'missing_rel'       => count(self::missing_attributes(1000)),
        ];
    }

    /**
     * Turn a GROUP_CONCAT of post IDs into titles and edit links.
     *
     * @return array<int,array{id:int,title:string,edit_url:string}>
     */
    private static function describe_sources(string $concatenated, int $limit = 10): array {
        $ids = array_slice(array_filter(explode(',', $concatenated)), 0, max(1, $limit));

        $out = [];
        foreach ($ids as $id) {
            $id = (int) trim($id);
            if (!$id) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'title'    => (string) get_the_title($id),
                'edit_url' => (string) get_edit_post_link($id, 'raw'),
            ];
        }
        return $out;
    }
}
