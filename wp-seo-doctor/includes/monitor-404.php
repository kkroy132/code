<?php
/**
 * 404 monitor: log misses, count hits, track referrers and propose redirects.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Monitor_404 {

    public static function init(): void {
        // template_redirect is the earliest point where is_404() is reliable.
        add_action('template_redirect', [self::class, 'maybe_log'], 999);
        add_action('wpsd_prune_404s', [self::class, 'prune']);
    }

    public static function maybe_log(): void {
        if (!WPSD_Settings::get('monitor_404', true)) {
            return;
        }
        if (!is_404() || is_admin()) {
            return;
        }
        // Never log logged-in editors browsing around, or non-GET requests.
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) !== 'GET') {
            return;
        }
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        if ($uri === '' || self::is_ignored($uri)) {
            return;
        }

        $referrer = '';
        if (WPSD_Settings::get('log_404_referrer', true) && isset($_SERVER['HTTP_REFERER'])) {
            $referrer = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        self::record($uri, $referrer, $user_agent);
    }

    /**
     * Insert or increment a 404 record.
     */
    public static function record(string $url, string $referrer = '', string $user_agent = ''): void {
        global $wpdb;

        $table = WPSD_DB::table('notfound');
        $hash  = md5($url);
        $now   = WPSD_Helpers::now();

        // A single upsert avoids the read-then-write race between concurrent
        // requests hitting the same dead URL.
        $sql = "INSERT INTO {$table} (url, url_hash, hits, referrer, user_agent, ip, status, first_seen, last_seen)
                VALUES (%s, %s, 1, %s, %s, %s, 'new', %s, %s)
                ON DUPLICATE KEY UPDATE
                    hits = hits + 1,
                    last_seen = VALUES(last_seen),
                    referrer = IF(VALUES(referrer) <> '', VALUES(referrer), referrer)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            $sql,
            $url,
            $hash,
            $referrer,
            mb_substr($user_agent, 0, 255),
            WPSD_Helpers::client_ip(),
            $now,
            $now
        ));
    }

    /**
     * Should this URL be skipped? Bot noise and asset probes otherwise drown
     * out the 404s that actually matter.
     */
    public static function is_ignored(string $url): bool {
        foreach (WPSD_Settings::lines('ignore_404_patterns') as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // A pattern wrapped in slashes is treated as a regex.
            if (strlen($pattern) > 2 && $pattern[0] === '/' && substr($pattern, -1) === '/' && @preg_match($pattern, '') !== false) {
                if (preg_match($pattern, $url)) {
                    return true;
                }
                continue;
            }
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }

        /**
         * Filter whether a 404 URL is ignored.
         *
         * @param bool   $ignored
         * @param string $url
         */
        return (bool) apply_filters('wpsd_ignore_404', false, $url);
    }

    // ─────────────────────────────────────────────────────────── queries ──

    /**
     * @param array<string,mixed> $args
     * @return array{rows:array<int,object>, total:int, pages:int}
     */
    public static function query(array $args = []): array {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'   => 'new',   // new|redirected|ignored|all
            'search'   => '',
            'per_page' => 25,
            'page'     => 1,
            'orderby'  => 'hits',
            'order'    => 'DESC',
        ]);

        $table  = WPSD_DB::table('notfound');
        $where  = ['1=1'];
        $params = [];

        if ($args['status'] !== '' && $args['status'] !== 'all') {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(url LIKE %s OR referrer LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $allowed  = ['hits', 'last_seen', 'first_seen', 'url', 'id'];
        $orderby  = in_array($args['orderby'], $allowed, true) ? $args['orderby'] : 'hits';
        $order    = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $per_page = max(1, (int) $args['per_page']);
        $offset   = (max(1, (int) $args['page']) - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";

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

    public static function get(int $id): ?object {
        global $wpdb;
        $table = WPSD_DB::table('notfound');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }

    public static function count_active(): int {
        return WPSD_DB::count('notfound', "status = 'new'");
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        global $wpdb;
        $table = WPSD_DB::table('notfound');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $hits  = (int) $wpdb->get_var("SELECT COALESCE(SUM(hits),0) FROM {$table}");
        $week  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        // phpcs:enable

        return [
            'total'      => $total,
            'hits'       => $hits,
            'this_week'  => $week,
            'unresolved' => self::count_active(),
            'redirected' => WPSD_DB::count('notfound', "status = 'redirected'"),
        ];
    }

    // ───────────────────────────────────────────────────────── workflow ──

    /**
     * Suggest where a dead URL should point, best match first.
     *
     * @return array<int,array{url:string,title:string,score:float,reason:string}>
     */
    public static function suggest_redirects(string $url, int $limit = 5): array {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $slug = trim(basename(rtrim($path, '/')));
        $slug = preg_replace('/\.(html?|php|aspx?)$/i', '', $slug);

        if ($slug === '' || $slug === '/') {
            return [];
        }

        $suggestions = [];

        // 1. An exact slug match is almost always the right answer — a post
        //    that moved category, or a permalink structure change.
        $exact = get_page_by_path($slug, OBJECT, WPSD_Helpers::auditable_post_types());
        if ($exact instanceof WP_Post) {
            $suggestions[] = [
                'url'    => (string) get_permalink($exact),
                'title'  => $exact->post_title,
                'score'  => 1.0,
                'reason' => __('Exact slug match', 'wp-seo-doctor'),
            ];
        }

        // 2. Fall back to a keyword search on the slug words.
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower($slug)) ?: [],
            static fn($w) => strlen($w) > 2 && !isset(WPSD_Helpers::stopwords()[$w])
        ));

        if ($words) {
            $found = get_posts([
                'post_type'      => WPSD_Helpers::auditable_post_types(),
                'post_status'    => 'publish',
                'posts_per_page' => $limit * 3,
                's'              => implode(' ', $words),
                'orderby'        => 'relevance',
            ]);

            foreach ($found as $post) {
                if ($exact instanceof WP_Post && $post->ID === $exact->ID) {
                    continue;
                }
                $score = self::slug_similarity($slug, $post->post_name);
                if ($score < 0.25) {
                    continue;
                }
                $suggestions[] = [
                    'url'    => (string) get_permalink($post),
                    'title'  => $post->post_title,
                    'score'  => round($score, 3),
                    'reason' => __('Similar slug and content', 'wp-seo-doctor'),
                ];
            }
        }

        usort($suggestions, static fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Create a redirect for a logged 404 and mark it resolved.
     *
     * @return int|WP_Error Redirect ID.
     */
    public static function create_redirect(int $notfound_id, string $target, int $code = 301) {
        global $wpdb;

        $row = self::get($notfound_id);
        if (!$row) {
            return new WP_Error('wpsd_404_missing', __('404 record not found.', 'wp-seo-doctor'));
        }

        $redirect_id = WPSD_Redirects::create([
            'source' => (string) $row->url,
            'target' => $target,
            'code'   => $code,
            'notes'  => sprintf(
                /* translators: %d: number of hits */
                __('Created from the 404 monitor (%d hits).', 'wp-seo-doctor'),
                (int) $row->hits
            ),
        ]);

        if (is_wp_error($redirect_id)) {
            return $redirect_id;
        }

        $wpdb->update(WPSD_DB::table('notfound'), ['status' => 'redirected'], ['id' => $notfound_id]);

        return $redirect_id;
    }

    /**
     * @param array<int,int> $ids
     */
    public static function set_status(array $ids, string $status): int {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !in_array($status, ['new', 'redirected', 'ignored'], true)) {
            return 0;
        }

        $table        = WPSD_DB::table('notfound');
        $placeholders = WPSD_DB::in_placeholders($ids);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
            array_merge([$status], $ids)
        ));
    }

    /**
     * @param array<int,int> $ids
     */
    public static function delete(array $ids): int {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }

        $table        = WPSD_DB::table('notfound');
        $placeholders = WPSD_DB::in_placeholders($ids);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
    }

    /** Drop records nobody has hit inside the retention window. */
    public static function prune(): void {
        global $wpdb;

        $days = (int) WPSD_Settings::get('notfound_retention', 90);
        if ($days <= 0) {
            return;
        }

        $table = WPSD_DB::table('notfound');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status <> 'redirected' AND last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Similarity between two slugs, on shared word tokens.
     */
    private static function slug_similarity(string $a, string $b): float {
        $tokens_a = array_filter(preg_split('/[^a-z0-9]+/i', strtolower($a)) ?: []);
        $tokens_b = array_filter(preg_split('/[^a-z0-9]+/i', strtolower($b)) ?: []);

        if (!$tokens_a || !$tokens_b) {
            return 0.0;
        }

        $shared = count(array_intersect($tokens_a, $tokens_b));
        $union  = count(array_unique(array_merge($tokens_a, $tokens_b)));

        return $union > 0 ? $shared / $union : 0.0;
    }
}
