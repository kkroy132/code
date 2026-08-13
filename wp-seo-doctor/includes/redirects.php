<?php
/**
 * Redirect manager: rule storage, request-time matching, import/export and
 * chain/loop detection across the rule set.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Redirects {

    const CACHE_KEY = 'wpsd_redirect_rules';

    /** Response codes the manager supports. 410 serves no target. */
    const CODES = [301, 302, 307, 308, 410];

    public static function init(): void {
        if (WPSD_Settings::get('redirects_enabled', true)) {
            // Early enough to beat the main query, late enough that plugins
            // registering rewrite rules have loaded.
            add_action('parse_request', [self::class, 'maybe_redirect'], 1);
        }
    }

    // ────────────────────────────────────────────────────── request-time ──

    public static function maybe_redirect(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $request = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        if ($request === '') {
            return;
        }

        $match = self::match($request);
        if (!$match) {
            return;
        }

        [$rule, $target] = $match;

        self::record_hit((int) $rule->id, $request, $target);

        if ((int) $rule->code === 410) {
            status_header(410);
            nocache_headers();
            wp_die(
                esc_html__('This content has been permanently removed.', 'wp-seo-doctor'),
                esc_html__('410 Gone', 'wp-seo-doctor'),
                ['response' => 410]
            );
        }

        if ($target === '') {
            return;
        }

        wp_redirect($target, (int) $rule->code, 'WP SEO Doctor');
        exit;
    }

    /**
     * Find the rule matching a request URI.
     *
     * @return array{0:object,1:string}|null [rule, resolved target]
     */
    public static function match(string $request): ?array {
        $rules = self::active_rules();
        if (!$rules) {
            return null;
        }

        $path       = self::request_path($request);
        $path_hash  = md5(self::normalize_source($path));

        foreach ($rules as $rule) {
            if ($rule->match_type === 'regex') {
                $pattern = self::compile_regex((string) $rule->source);
                if ($pattern === '') {
                    continue;
                }
                if (preg_match($pattern, $path, $matches)) {
                    // $1, $2… in the target are filled from the capture groups.
                    $target = preg_replace_callback(
                        '/\$(\d+)/',
                        static fn($m) => $matches[(int) $m[1]] ?? '',
                        (string) $rule->target
                    );
                    return [$rule, self::absolutize_target((string) $target)];
                }
                continue;
            }

            if ($rule->source_hash === $path_hash) {
                return [$rule, self::absolutize_target((string) $rule->target)];
            }
        }

        return null;
    }

    /**
     * Enabled rules, exact matches first so they win over broad regexes.
     *
     * @return array<int,object>
     */
    public static function active_rules(): array {
        $cached = wp_cache_get(self::CACHE_KEY, 'wpsd');
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $table = WPSD_DB::table('redirects');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rules = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE enabled = 1
             ORDER BY FIELD(match_type,'exact','regex') ASC, id ASC"
        ) ?: [];

        wp_cache_set(self::CACHE_KEY, $rules, 'wpsd', 5 * MINUTE_IN_SECONDS);

        return $rules;
    }

    public static function flush_cache(): void {
        wp_cache_delete(self::CACHE_KEY, 'wpsd');
    }

    private static function record_hit(int $rule_id, string $request, string $target): void {
        global $wpdb;

        $table = WPSD_DB::table('redirects');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
            WPSD_Helpers::now(),
            $rule_id
        ));

        if (!WPSD_Settings::get('log_redirects', true)) {
            return;
        }

        $rule = self::get($rule_id);

        $wpdb->insert(WPSD_DB::table('redirect_log'), [
            'redirect_id' => $rule_id,
            'request_url' => $request,
            'target_url'  => $target,
            'code'        => $rule ? (int) $rule->code : 301,
            'referrer'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT'])
                ? mb_substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255)
                : '',
            'ip'          => WPSD_Helpers::client_ip(),
            'created_at'  => WPSD_Helpers::now(),
        ]);

        // Trim the log occasionally rather than on every hit.
        if (wp_rand(1, 50) === 1) {
            self::trim_log();
        }
    }

    // ─────────────────────────────────────────────────────────────── CRUD ──

    /**
     * @param array{source:string,target?:string,code?:int,match_type?:string,notes?:string,enabled?:bool} $data
     * @return int|WP_Error
     */
    public static function create(array $data) {
        global $wpdb;

        $match_type = ($data['match_type'] ?? 'exact') === 'regex' ? 'regex' : 'exact';

        $source = self::source_key((string) ($data['source'] ?? ''), $match_type);
        if ($source === '') {
            return new WP_Error('wpsd_redirect_source', __('A source URL or path is required.', 'wp-seo-doctor'));
        }

        $code = (int) ($data['code'] ?? 301);
        if (!in_array($code, self::CODES, true)) {
            $code = 301;
        }

        $target = trim((string) ($data['target'] ?? ''));

        if ($code !== 410 && $target === '') {
            return new WP_Error('wpsd_redirect_target', __('A target URL is required for this redirect type.', 'wp-seo-doctor'));
        }

        if ($match_type === 'regex' && self::compile_regex($source) === '') {
            return new WP_Error('wpsd_redirect_regex', __('That regular expression is not valid.', 'wp-seo-doctor'));
        }

        // A rule pointing at itself would loop forever.
        if ($match_type === 'exact' && self::normalize_source($target) === $source) {
            return new WP_Error('wpsd_redirect_self', __('The source and target are the same — that would create a redirect loop.', 'wp-seo-doctor'));
        }

        $existing = self::find_by_source($source, $match_type);
        if ($existing) {
            return new WP_Error(
                'wpsd_redirect_duplicate',
                __('A redirect already exists for that source.', 'wp-seo-doctor'),
                ['id' => (int) $existing->id]
            );
        }

        $now = WPSD_Helpers::now();
        $wpdb->insert(WPSD_DB::table('redirects'), [
            'source'      => $source,
            'source_hash' => md5($source),
            'target'      => $target,
            'code'        => $code,
            'match_type'  => $match_type,
            'enabled'     => !empty($data['enabled']) || !isset($data['enabled']) ? 1 : 0,
            'notes'       => (string) ($data['notes'] ?? ''),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        self::flush_cache();

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     * @return true|WP_Error
     */
    public static function update(int $id, array $data) {
        global $wpdb;

        $rule = self::get($id);
        if (!$rule) {
            return new WP_Error('wpsd_redirect_missing', __('Redirect not found.', 'wp-seo-doctor'));
        }

        $fields = ['updated_at' => WPSD_Helpers::now()];

        // The new match type decides how the source is stored, so resolve it
        // before normalising.
        $new_match_type = isset($data['match_type'])
            ? ($data['match_type'] === 'regex' ? 'regex' : 'exact')
            : (string) $rule->match_type;

        if (isset($data['source'])) {
            $source = self::source_key((string) $data['source'], $new_match_type);
            if ($source === '') {
                return new WP_Error('wpsd_redirect_source', __('A source URL or path is required.', 'wp-seo-doctor'));
            }
            $fields['source']      = $source;
            $fields['source_hash'] = md5($source);
        }
        if (isset($data['target'])) {
            $fields['target'] = trim((string) $data['target']);
        }
        if (isset($data['code'])) {
            $code           = (int) $data['code'];
            $fields['code'] = in_array($code, self::CODES, true) ? $code : 301;
        }
        if (isset($data['match_type'])) {
            $fields['match_type'] = $data['match_type'] === 'regex' ? 'regex' : 'exact';
        }
        if (isset($data['enabled'])) {
            $fields['enabled'] = $data['enabled'] ? 1 : 0;
        }
        if (isset($data['notes'])) {
            $fields['notes'] = (string) $data['notes'];
        }

        $match_type = $fields['match_type'] ?? $rule->match_type;
        $source     = $fields['source'] ?? $rule->source;
        if ($match_type === 'regex' && self::compile_regex((string) $source) === '') {
            return new WP_Error('wpsd_redirect_regex', __('That regular expression is not valid.', 'wp-seo-doctor'));
        }

        $wpdb->update(WPSD_DB::table('redirects'), $fields, ['id' => $id]);
        self::flush_cache();

        return true;
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

        $table        = WPSD_DB::table('redirects');
        $placeholders = WPSD_DB::in_placeholders($ids);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));

        $log = WPSD_DB::table('redirect_log');
        $wpdb->query($wpdb->prepare("DELETE FROM {$log} WHERE redirect_id IN ({$placeholders})", $ids));
        // phpcs:enable

        self::flush_cache();

        return $deleted;
    }

    public static function get(int $id): ?object {
        global $wpdb;
        $table = WPSD_DB::table('redirects');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }

    public static function find_by_source(string $source, string $match_type = 'exact'): ?object {
        global $wpdb;
        $table = WPSD_DB::table('redirects');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_hash = %s AND match_type = %s LIMIT 1",
            md5(self::source_key($source, $match_type)),
            $match_type
        )) ?: null;
    }

    /**
     * @param array<string,mixed> $args
     * @return array{rows:array<int,object>, total:int, pages:int}
     */
    public static function query(array $args = []): array {
        global $wpdb;

        $args = wp_parse_args($args, [
            'search'     => '',
            'code'       => 0,
            'match_type' => '',
            'enabled'    => null,
            'per_page'   => 25,
            'page'       => 1,
            'orderby'    => 'id',
            'order'      => 'DESC',
        ]);

        $table  = WPSD_DB::table('redirects');
        $where  = ['1=1'];
        $params = [];

        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(source LIKE %s OR target LIKE %s OR notes LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ((int) $args['code'] > 0) {
            $where[]  = 'code = %d';
            $params[] = (int) $args['code'];
        }
        if ($args['match_type'] !== '') {
            $where[]  = 'match_type = %s';
            $params[] = $args['match_type'];
        }
        if ($args['enabled'] !== null) {
            $where[]  = 'enabled = %d';
            $params[] = $args['enabled'] ? 1 : 0;
        }

        $where_sql = implode(' AND ', $where);

        $allowed  = ['id', 'source', 'hits', 'last_hit', 'created_at', 'code'];
        $orderby  = in_array($args['orderby'], $allowed, true) ? $args['orderby'] : 'id';
        $order    = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $per_page = max(1, (int) $args['per_page']);
        $offset   = (max(1, (int) $args['page']) - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

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
     * Redirect history for one rule, or site-wide when $rule_id is 0.
     *
     * @return array<int,object>
     */
    public static function history(int $rule_id = 0, int $limit = 100): array {
        global $wpdb;
        $table = WPSD_DB::table('redirect_log');

        if ($rule_id > 0) {
            $sql = "SELECT * FROM {$table} WHERE redirect_id = %d ORDER BY created_at DESC LIMIT %d";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return $wpdb->get_results($wpdb->prepare($sql, $rule_id, max(1, $limit))) ?: [];
        }

        $sql = "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, max(1, $limit))) ?: [];
    }

    private static function trim_log(): void {
        global $wpdb;

        $limit = (int) WPSD_Settings::get('redirect_log_limit', 5000);
        if ($limit <= 0) {
            return;
        }

        $table = WPSD_DB::table('redirect_log');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $cutoff = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
            $limit
        ));

        if ($cutoff) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", (int) $cutoff));
        }
    }

    // ───────────────────────────────────────────── chain & loop analysis ──

    /**
     * Walk the rule set looking for rules whose target is itself a source.
     *
     * @return array{chains:array<int,array<string,mixed>>, loops:array<int,array<string,mixed>>}
     */
    public static function analyse(): array {
        global $wpdb;
        $table = WPSD_DB::table('redirects');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rules = $wpdb->get_results("SELECT * FROM {$table} WHERE enabled = 1 AND match_type = 'exact'") ?: [];

        $by_source = [];
        foreach ($rules as $rule) {
            $by_source[$rule->source_hash] = $rule;
        }

        $chains = [];
        $loops  = [];

        foreach ($rules as $rule) {
            if ((int) $rule->code === 410) {
                continue;
            }

            $path    = [(string) $rule->source];
            $seen    = [$rule->source_hash => true];
            $current = $rule;

            for ($hop = 0; $hop < 10; $hop++) {
                $target_key = md5(self::normalize_source((string) $current->target));

                if (isset($seen[$target_key])) {
                    $path[]  = (string) $current->target;
                    $loops[] = [
                        'id'    => (int) $rule->id,
                        'chain' => $path,
                    ];
                    break;
                }

                if (!isset($by_source[$target_key])) {
                    $path[] = (string) $current->target;
                    break;
                }

                $seen[$target_key] = true;
                $current           = $by_source[$target_key];
                $path[]            = (string) $current->source;
            }

            // 3+ nodes means at least two hops — a chain worth flattening.
            if (count($path) > 2 && !self::already_reported($loops, (int) $rule->id)) {
                $chains[] = [
                    'id'    => (int) $rule->id,
                    'chain' => $path,
                    'hops'  => count($path) - 1,
                    'final' => end($path),
                ];
            }
        }

        return ['chains' => $chains, 'loops' => $loops];
    }

    /**
     * @param array<int,array<string,mixed>> $loops
     */
    private static function already_reported(array $loops, int $id): bool {
        foreach ($loops as $loop) {
            if ((int) $loop['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rewrite chained rules to point straight at their final destination.
     *
     * @return int Number of rules flattened.
     */
    public static function flatten_chains(): int {
        $analysis = self::analyse();
        $fixed    = 0;

        foreach ($analysis['chains'] as $chain) {
            $final = (string) $chain['final'];
            if ($final === '') {
                continue;
            }
            $result = self::update((int) $chain['id'], ['target' => $final]);
            if ($result === true) {
                $fixed++;
            }
        }

        return $fixed;
    }

    // ─────────────────────────────────────────────────── import / export ──

    /**
     * Import redirects from CSV text.
     *
     * Accepts "source,target,code,match_type" with an optional header row, and
     * also reads Redirection-plugin exports (source/target column names).
     *
     * @return array{imported:int,skipped:int,errors:array<int,string>}
     */
    public static function import_csv(string $csv): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        $handle = fopen('php://temp', 'r+');
        if (!$handle) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => [__('Could not open a temporary stream.', 'wp-seo-doctor')]];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $map      = null;
        $line_num = 0;

        // Explicit separator/enclosure/escape: PHP 8.4 deprecates relying on
        // the default escape character.
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line_num++;
            if ($row === [null] || $row === false) {
                continue;
            }

            $row = array_map(static fn($v) => is_string($v) ? trim($v) : '', $row);
            if (implode('', $row) === '') {
                continue;
            }

            // Detect a header row and remember which column is which.
            if ($map === null) {
                $lower = array_map('strtolower', $row);
                if (array_intersect($lower, ['source', 'url', 'source_url', 'from'])) {
                    $map = [
                        'source' => self::column_index($lower, ['source', 'source_url', 'url', 'from']),
                        'target' => self::column_index($lower, ['target', 'target_url', 'destination', 'to', 'action_data']),
                        'code'   => self::column_index($lower, ['code', 'type', 'status', 'http_code', 'action_code']),
                        'match'  => self::column_index($lower, ['match_type', 'match', 'regex']),
                    ];
                    continue;
                }
                // No header — assume positional order.
                $map = ['source' => 0, 'target' => 1, 'code' => 2, 'match' => 3];
            }

            $source = $row[$map['source']] ?? '';
            $target = $map['target'] !== null ? ($row[$map['target']] ?? '') : '';
            $code   = $map['code'] !== null ? (int) ($row[$map['code']] ?? 301) : 301;
            $match  = $map['match'] !== null ? strtolower((string) ($row[$map['match']] ?? '')) : '';

            if ($source === '') {
                $skipped++;
                continue;
            }

            $match_type = ($match === 'regex' || $match === '1' || $match === 'true') ? 'regex' : 'exact';

            $result = self::create([
                'source'     => $source,
                'target'     => $target,
                'code'       => in_array($code, self::CODES, true) ? $code : 301,
                'match_type' => $match_type,
                'notes'      => __('Imported from CSV.', 'wp-seo-doctor'),
            ]);

            if (is_wp_error($result)) {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = sprintf(
                        /* translators: 1: line number, 2: error message */
                        __('Line %1$d: %2$s', 'wp-seo-doctor'),
                        $line_num,
                        $result->get_error_message()
                    );
                }
                continue;
            }

            $imported++;
        }

        fclose($handle);
        self::flush_cache();

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<int,string> $header
     * @param array<int,string> $names
     */
    private static function column_index(array $header, array $names): ?int {
        foreach ($names as $name) {
            $index = array_search($name, $header, true);
            if ($index !== false) {
                return (int) $index;
            }
        }
        return null;
    }

    public static function export_csv(): string {
        global $wpdb;
        $table = WPSD_DB::table('redirects');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results("SELECT source, target, code, match_type, enabled, hits, notes FROM {$table} ORDER BY id ASC");

        $handle = fopen('php://temp', 'r+');
        if (!$handle) {
            return '';
        }

        fputcsv($handle, ['source', 'target', 'code', 'match_type', 'enabled', 'hits', 'notes'], ',', '"', '');
        foreach ((array) $rows as $row) {
            fputcsv($handle, [
                $row->source,
                $row->target,
                $row->code,
                $row->match_type,
                $row->enabled,
                $row->hits,
                $row->notes,
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        global $wpdb;
        $table = WPSD_DB::table('redirects');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $hits  = (int) $wpdb->get_var("SELECT COALESCE(SUM(hits),0) FROM {$table}");
        // phpcs:enable

        $analysis = self::analyse();

        return [
            'total'    => $total,
            'enabled'  => WPSD_DB::count('redirects', 'enabled = 1'),
            'regex'    => WPSD_DB::count('redirects', "match_type = 'regex'"),
            'gone'     => WPSD_DB::count('redirects', 'code = 410'),
            'hits'     => $hits,
            'chains'   => count($analysis['chains']),
            'loops'    => count($analysis['loops']),
        ];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /** Path + query of a request, without the host. */
    private static function request_path(string $request): string {
        $path  = (string) wp_parse_url($request, PHP_URL_PATH);
        $query = (string) wp_parse_url($request, PHP_URL_QUERY);

        return $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * Sources are stored as site-relative paths with a leading slash and no
     * trailing slash, so "/old-post/", "old-post" and the full URL all match.
     */
    public static function normalize_source(string $source): string {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $source)) {
            $path  = (string) wp_parse_url($source, PHP_URL_PATH);
            $query = (string) wp_parse_url($source, PHP_URL_QUERY);
            $source = $query !== '' ? $path . '?' . $query : $path;
        }

        $source = '/' . ltrim($source, '/');
        if (strlen($source) > 1) {
            // Keep the query string intact while dropping a trailing slash.
            [$path, $query] = array_pad(explode('?', $source, 2), 2, null);
            $path   = rtrim($path, '/');
            $path   = $path === '' ? '/' : $path;
            $source = $query !== null && $query !== '' ? $path . '?' . $query : $path;
        }

        return $source;
    }

    /** Relative targets are resolved against the site root. */
    private static function absolutize_target(string $target): string {
        $target = trim($target);
        if ($target === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }
        return home_url('/' . ltrim($target, '/'));
    }

    /**
     * Turn a stored regex source into a safe, delimited pattern.
     *
     * @return string Empty string when the pattern will not compile.
     */
    public static function compile_regex(string $source): string {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        // Only `#…#flags` and `~…~flags` count as pre-delimited. A pattern
        // opening with `/` is indistinguishable from an ordinary path
        // (`/blog/(.+)` means the path, not a delimited empty pattern), so
        // anything else is treated as a bare pattern and delimited here.
        $delimiter = $source[0];
        if (strlen($source) > 2 && ($delimiter === '#' || $delimiter === '~')) {
            $last = strrpos($source, $delimiter);
            if ($last !== false && $last > 0) {
                $flags = substr($source, $last + 1);
                if ($flags === '' || preg_match('/^[imsxuADSUXJn]+$/', $flags)) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    return @preg_match($source, '') === false ? '' : $source;
                }
            }
        }

        $pattern = '#' . str_replace('#', '\#', $source) . '#';

        // Validate before ever handing it to preg_match at request time.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (@preg_match($pattern, '') === false) {
            return '';
        }

        return $pattern;
    }

    /**
     * The stored form of a rule's source.
     *
     * Exact sources are normalised to a site-relative path; regex sources are
     * stored verbatim, since normalising would corrupt the pattern.
     */
    public static function source_key(string $source, string $match_type): string {
        return $match_type === 'regex' ? trim($source) : self::normalize_source($source);
    }

    /**
     * Test a source pattern against a sample URL — powers the "test" button.
     *
     * @return array{matches:bool,target:string,error:string}
     */
    public static function test(string $source, string $target, string $match_type, string $sample): array {
        $sample_path = self::request_path($sample !== '' ? $sample : '/');

        if ($match_type === 'regex') {
            $pattern = self::compile_regex($source);
            if ($pattern === '') {
                return ['matches' => false, 'target' => '', 'error' => __('Invalid regular expression.', 'wp-seo-doctor')];
            }
            if (!preg_match($pattern, $sample_path, $matches)) {
                return ['matches' => false, 'target' => '', 'error' => ''];
            }
            $resolved = preg_replace_callback(
                '/\$(\d+)/',
                static fn($m) => $matches[(int) $m[1]] ?? '',
                $target
            );
            return ['matches' => true, 'target' => self::absolutize_target((string) $resolved), 'error' => ''];
        }

        $matches = self::normalize_source($source) === self::normalize_source($sample_path);

        return [
            'matches' => $matches,
            'target'  => $matches ? self::absolutize_target($target) : '',
            'error'   => '',
        ];
    }
}
