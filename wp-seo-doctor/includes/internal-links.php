<?php
/**
 * Internal link graph: indexing, analysis, suggestions and the link map.
 *
 * Every anchor found in post content becomes a row in the links table. That
 * table is the single source of truth for orphan detection, incoming/outgoing
 * counts, anchor-text analysis, crawl depth and the visual link map.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Internal_Links {

    const DEPTH_TRANSIENT = 'wpsd_crawl_depths';

    public static function init(): void {
        // Keep the graph fresh as content changes.
        add_action('save_post', [self::class, 'on_save_post'], 20, 2);
        add_action('deleted_post', [self::class, 'on_delete_post']);
        add_action('wpsd_scan_completed', [self::class, 'flush_graph_cache']);
    }

    public static function on_save_post(int $post_id, $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, WPSD_Helpers::auditable_post_types(), true)) {
            return;
        }

        self::index_post($post);
        self::flush_graph_cache();
        WPSD_Checks_OnPage::flush_duplicate_index();
        WPSD_Checks_Content::flush_shingle_index();
    }

    public static function on_delete_post(int $post_id): void {
        global $wpdb;
        $wpdb->delete(WPSD_DB::table('links'), ['source_id' => $post_id]);
        WPSD_Issues::delete_for_object($post_id);
        self::flush_graph_cache();
    }

    /**
     * Replace this post's outgoing links in the graph.
     */
    public static function index_post(WP_Post $post, ?WPSD_Context $context = null): int {
        global $wpdb;

        $context = $context ?: new WPSD_Context($post);
        $table   = WPSD_DB::table('links');
        $now     = WPSD_Helpers::now();

        // Preserve per-link state (last check result, ignore flag) across
        // re-indexing so a re-scan does not reset the broken-link workflow.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $existing_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT target_hash, http_status, redirect_target, redirect_hops, status, last_checked FROM {$table} WHERE source_id = %d",
            $post->ID
        ));
        $existing = [];
        foreach ((array) $existing_rows as $row) {
            $existing[$row->target_hash] = $row;
        }

        $wpdb->delete($table, ['source_id' => $post->ID]);

        $seen  = [];
        $count = 0;

        foreach ($context->links as $link) {
            $url = $link['url'];
            if ($url === '' || WPSD_Helpers::is_non_http($url)) {
                continue;
            }

            $hash = WPSD_Helpers::url_hash($url);
            $key  = $hash . '|' . md5($link['anchor']);
            if (isset($seen[$key])) {
                // The same link repeated with the same anchor adds nothing.
                continue;
            }
            $seen[$key] = true;

            $internal = WPSD_Helpers::is_internal_url($url);
            $prior    = $existing[$hash] ?? null;

            $wpdb->insert($table, [
                'source_id'       => (int) $post->ID,
                'source_url'      => $context->url,
                'target_url'      => $url,
                'target_hash'     => $hash,
                'target_id'       => $internal ? self::resolve_post_id($url) : 0,
                'anchor'          => mb_substr($link['anchor'], 0, 255),
                'rel'             => mb_substr($link['rel'], 0, 120),
                'link_type'       => $internal ? 'internal' : 'external',
                'is_affiliate'    => WPSD_Checks_Affiliate::is_affiliate($url) ? 1 : 0,
                'http_status'     => $prior ? (int) $prior->http_status : 0,
                'redirect_target' => $prior ? $prior->redirect_target : null,
                'redirect_hops'   => $prior ? (int) $prior->redirect_hops : 0,
                'status'          => $prior && $prior->status !== 'unchecked' ? $prior->status : 'unchecked',
                'last_checked'    => $prior ? $prior->last_checked : null,
                'created_at'      => $now,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Rebuild the whole graph. Returns the number of posts indexed.
     */
    public static function rebuild(int $limit = 0, int $offset = 0): int {
        global $wpdb;

        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        $sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) ORDER BY ID ASC";
        $params = $types;

        if ($limit > 0) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        $indexed = 0;
        foreach ((array) $ids as $id) {
            $post = get_post((int) $id);
            if ($post) {
                self::index_post($post);
                $indexed++;
            }
        }

        self::flush_graph_cache();

        return $indexed;
    }

    // ────────────────────────────────────────────────────────── analysis ──

    public static function incoming_count(int $post_id): int {
        $counts = self::incoming_counts();
        return (int) ($counts[$post_id] ?? 0);
    }

    /**
     * post_id => number of distinct pages linking to it.
     *
     * @return array<int,int>
     */
    public static function incoming_counts(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        global $wpdb;
        $table = WPSD_DB::table('links');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT target_id, COUNT(DISTINCT source_id) AS cnt
             FROM {$table}
             WHERE link_type = 'internal' AND target_id > 0 AND target_id <> source_id
             GROUP BY target_id"
        );

        $cache = [];
        foreach ((array) $rows as $row) {
            $cache[(int) $row->target_id] = (int) $row->cnt;
        }

        return $cache;
    }

    /**
     * post_id => number of distinct internal pages it links to.
     *
     * @return array<int,int>
     */
    public static function outgoing_counts(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        global $wpdb;
        $table = WPSD_DB::table('links');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT source_id, COUNT(DISTINCT target_id) AS cnt
             FROM {$table}
             WHERE link_type = 'internal' AND target_id > 0 AND target_id <> source_id
             GROUP BY source_id"
        );

        $cache = [];
        foreach ((array) $rows as $row) {
            $cache[(int) $row->source_id] = (int) $row->cnt;
        }

        return $cache;
    }

    /**
     * Published pages with no incoming internal links.
     *
     * @return array<int,object>
     */
    public static function orphan_pages(int $limit = 100): array {
        global $wpdb;

        $links        = WPSD_DB::table('links');
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');
        $front        = (int) get_option('page_on_front');

        $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_date, p.post_modified
                FROM {$wpdb->posts} p
                LEFT JOIN {$links} l
                       ON l.target_id = p.ID
                      AND l.link_type = 'internal'
                      AND l.source_id <> p.ID
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  AND p.ID <> %d
                  AND l.id IS NULL
                ORDER BY p.post_modified DESC
                LIMIT %d";

        $params = array_merge($types, [$front, max(1, $limit)]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];

        foreach ($rows as $row) {
            $row->url = get_permalink((int) $row->ID);
        }

        return $rows;
    }

    /**
     * Pages with fewer incoming links than the configured minimum.
     *
     * @return array<int,object>
     */
    public static function weakly_linked(int $limit = 100): array {
        global $wpdb;

        $links        = WPSD_DB::table('links');
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');
        $min          = (int) WPSD_Settings::get('min_incoming_links', 2);

        $sql = "SELECT p.ID, p.post_title, p.post_type, COUNT(DISTINCT l.source_id) AS incoming
                FROM {$wpdb->posts} p
                LEFT JOIN {$links} l
                       ON l.target_id = p.ID
                      AND l.link_type = 'internal'
                      AND l.source_id <> p.ID
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                GROUP BY p.ID, p.post_title, p.post_type
                HAVING incoming > 0 AND incoming < %d
                ORDER BY incoming ASC, p.post_modified DESC
                LIMIT %d";

        $params = array_merge($types, [$min, max(1, $limit)]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];

        foreach ($rows as $row) {
            $row->url = get_permalink((int) $row->ID);
        }

        return $rows;
    }

    /**
     * Which pages link to $post_id.
     *
     * @return array<int,object>
     */
    public static function incoming_links(int $post_id, int $limit = 100): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $sql = "SELECT l.*, p.post_title AS source_title
                FROM {$table} l
                LEFT JOIN {$wpdb->posts} p ON p.ID = l.source_id
                WHERE l.target_id = %d AND l.source_id <> %d
                ORDER BY p.post_title ASC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $post_id, $post_id, max(1, $limit))) ?: [];
    }

    /**
     * Which pages $post_id links to.
     *
     * @return array<int,object>
     */
    public static function outgoing_links(int $post_id, string $type = '', int $limit = 200): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $where  = 'source_id = %d';
        $params = [$post_id];
        if ($type !== '') {
            $where   .= ' AND link_type = %s';
            $params[] = $type;
        }
        $params[] = max(1, $limit);

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY link_type ASC, id ASC LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /**
     * Anchor text distribution across the whole site.
     *
     * @return array<int,object>
     */
    public static function anchor_text_report(int $limit = 100): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        $sql = "SELECT anchor, COUNT(*) AS uses, COUNT(DISTINCT target_id) AS targets
                FROM {$table}
                WHERE link_type = 'internal' AND anchor <> ''
                GROUP BY anchor
                ORDER BY uses DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, max(1, $limit))) ?: [];
    }

    /**
     * Breadth-first click depth from the homepage, following internal links.
     *
     * @return array<int,int> post_id => depth
     */
    public static function crawl_depths(bool $force = false): array {
        static $cache = null;
        if ($cache !== null && !$force) {
            return $cache;
        }

        if (!$force) {
            $stored = get_transient(self::DEPTH_TRANSIENT);
            if (is_array($stored)) {
                return $cache = $stored;
            }
        }

        global $wpdb;
        $table = WPSD_DB::table('links');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $edges_raw = $wpdb->get_results(
            "SELECT source_id, target_id FROM {$table}
             WHERE link_type = 'internal' AND target_id > 0 AND source_id > 0 AND target_id <> source_id"
        );

        if (!$edges_raw) {
            return $cache = [];
        }

        $edges = [];
        foreach ($edges_raw as $edge) {
            $edges[(int) $edge->source_id][] = (int) $edge->target_id;
        }

        // The homepage is depth 0. On a posts-page setup there is no post ID for
        // it, so seed with everything the front page links to instead.
        $depths = [];
        $queue  = [];

        $front = (int) get_option('page_on_front');
        if ($front) {
            $depths[$front] = 0;
            $queue[]        = $front;
        } else {
            foreach (self::homepage_link_targets() as $id) {
                $depths[$id] = 1;
                $queue[]     = $id;
            }
        }

        while ($queue) {
            $current = array_shift($queue);
            $depth   = $depths[$current];

            foreach ($edges[$current] ?? [] as $target) {
                if (!isset($depths[$target])) {
                    $depths[$target] = $depth + 1;
                    $queue[]         = $target;
                }
            }
        }

        set_transient(self::DEPTH_TRANSIENT, $depths, HOUR_IN_SECONDS);

        return $cache = $depths;
    }

    /**
     * Post IDs linked from the homepage HTML — the BFS seed when there is no
     * static front page.
     *
     * @return array<int,int>
     */
    private static function homepage_link_targets(): array {
        $response = WPSD_Helpers::request(home_url('/'), ['method' => 'GET']);
        if ($response['status'] !== 200 || $response['body'] === '') {
            // Fall back to the most recent posts, which is what the homepage
            // would list anyway.
            $recent = get_posts([
                'post_type'      => WPSD_Helpers::auditable_post_types(),
                'posts_per_page' => (int) get_option('posts_per_page', 10),
                'fields'         => 'ids',
            ]);
            return array_map('intval', (array) $recent);
        }

        $ids = [];
        foreach (WPSD_Helpers::extract_links($response['body'], home_url('/')) as $link) {
            if (!WPSD_Helpers::is_internal_url($link['url'])) {
                continue;
            }
            $id = self::resolve_post_id($link['url']);
            if ($id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    // ─────────────────────────────────────────────────────── suggestions ──

    /**
     * Pages this post should probably link TO, ranked by topical overlap.
     *
     * @return array<int,array{id:int,title:string,url:string,score:float,anchor:string}>
     */
    public static function suggest_targets(int $post_id, int $limit = 0): array {
        $limit = $limit > 0 ? $limit : (int) WPSD_Settings::get('link_suggestion_limit', 8);
        $post  = get_post($post_id);
        if (!$post) {
            return [];
        }

        $keywords = WPSD_Helpers::keywords_from_text($post->post_title . ' ' . $post->post_content, 8);
        if (!$keywords) {
            return [];
        }

        $already = [];
        foreach (self::outgoing_links($post_id, 'internal') as $link) {
            if ((int) $link->target_id) {
                $already[(int) $link->target_id] = true;
            }
        }

        $candidates = self::search_by_keywords(array_keys($keywords), $post_id, $limit * 4);

        $scored = [];
        foreach ($candidates as $candidate) {
            $id = (int) $candidate->ID;
            if (isset($already[$id]) || $id === $post_id) {
                continue;
            }

            $overlap = self::keyword_overlap($keywords, $candidate->post_title . ' ' . $candidate->post_content);
            if ($overlap <= 0) {
                continue;
            }

            // Prefer under-linked destinations: a suggestion that also fixes a
            // weak page is worth more than one pointing at an already-strong page.
            $incoming = self::incoming_count($id);
            $boost    = $incoming === 0 ? 1.5 : (1 + 1 / (1 + $incoming));

            $scored[] = [
                'id'     => $id,
                'title'  => $candidate->post_title,
                'url'    => (string) get_permalink($id),
                'score'  => round($overlap * $boost, 3),
                'anchor' => self::suggest_anchor($candidate->post_title, array_keys($keywords)),
            ];
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Pages that should probably link TO this post — the inverse suggestion,
     * used to fix orphans.
     *
     * @return array<int,array{id:int,title:string,url:string,score:float,anchor:string,edit_url:string}>
     */
    public static function suggest_sources(int $post_id, int $limit = 5): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $keywords = WPSD_Helpers::keywords_from_text($post->post_title . ' ' . $post->post_content, 8);
        if (!$keywords) {
            return [];
        }

        $linking = [];
        foreach (self::incoming_links($post_id) as $link) {
            $linking[(int) $link->source_id] = true;
        }

        $candidates = self::search_by_keywords(array_keys($keywords), $post_id, $limit * 5);
        $outgoing   = self::outgoing_counts();

        $scored = [];
        foreach ($candidates as $candidate) {
            $id = (int) $candidate->ID;
            if ($id === $post_id || isset($linking[$id])) {
                continue;
            }

            $overlap = self::keyword_overlap($keywords, $candidate->post_title . ' ' . $candidate->post_content);
            if ($overlap <= 0) {
                continue;
            }

            // Well-linked hub pages pass more value than isolated ones.
            $authority = 1 + min(1.0, ((int) ($outgoing[$id] ?? 0)) / 20);

            $scored[] = [
                'id'       => $id,
                'title'    => $candidate->post_title,
                'url'      => (string) get_permalink($id),
                'edit_url' => (string) get_edit_post_link($id, 'raw'),
                'score'    => round($overlap * $authority, 3),
                'anchor'   => self::suggest_anchor($post->post_title, array_keys($keywords)),
            ];
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Site-wide list of the best available linking opportunities.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function opportunities(int $limit = 50): array {
        $out = [];

        // Orphans first — they gain the most from a single link.
        foreach (self::orphan_pages(30) as $orphan) {
            $sources = self::suggest_sources((int) $orphan->ID, 3);
            if (!$sources) {
                continue;
            }
            $out[] = [
                'type'        => 'orphan',
                'target_id'   => (int) $orphan->ID,
                'target'      => $orphan->post_title,
                'target_url'  => $orphan->url,
                'incoming'    => 0,
                'suggestions' => $sources,
                'priority'    => 'high',
            ];
        }

        // Then weakly-linked pages.
        foreach (self::weakly_linked(30) as $weak) {
            if (count($out) >= $limit) {
                break;
            }
            $sources = self::suggest_sources((int) $weak->ID, 3);
            if (!$sources) {
                continue;
            }
            $out[] = [
                'type'        => 'weak',
                'target_id'   => (int) $weak->ID,
                'target'      => $weak->post_title,
                'target_url'  => $weak->url,
                'incoming'    => (int) $weak->incoming,
                'suggestions' => $sources,
                'priority'    => 'medium',
            ];
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Related content for a given post — the reader-facing side of the same
     * relevance scoring.
     *
     * @return array<int,array{id:int,title:string,url:string,score:float}>
     */
    public static function related_content(int $post_id, int $limit = 5): array {
        $suggestions = self::suggest_targets($post_id, $limit * 2);

        return array_slice(array_map(static fn($s) => [
            'id'    => $s['id'],
            'title' => $s['title'],
            'url'   => $s['url'],
            'score' => $s['score'],
        ], $suggestions), 0, $limit);
    }

    /**
     * Nodes and edges for the link map visualisation.
     *
     * @return array{nodes:array<int,array<string,mixed>>, edges:array<int,array{source:int,target:int}>}
     */
    public static function link_map(int $limit = 150): array {
        global $wpdb;

        $links    = WPSD_DB::table('links');
        $incoming = self::incoming_counts();
        $depths   = self::crawl_depths();

        // Take the most-linked pages so the map stays readable.
        arsort($incoming);
        $top = array_slice(array_keys($incoming), 0, $limit, true);

        // Always include pages that link out a lot, even with few inbound links.
        $outgoing = self::outgoing_counts();
        arsort($outgoing);
        foreach (array_slice(array_keys($outgoing), 0, (int) ($limit / 3)) as $id) {
            if (!in_array($id, $top, true)) {
                $top[] = $id;
            }
        }

        $top = array_slice(array_values(array_unique(array_map('intval', $top))), 0, $limit);
        if (!$top) {
            return ['nodes' => [], 'edges' => []];
        }

        $nodes = [];
        foreach ($top as $id) {
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            $nodes[] = [
                'id'       => $id,
                'title'    => WPSD_Helpers::truncate($post->post_title, 40),
                'url'      => (string) get_permalink($id),
                'type'     => $post->post_type,
                'incoming' => (int) ($incoming[$id] ?? 0),
                'outgoing' => (int) ($outgoing[$id] ?? 0),
                'depth'    => $depths[$id] ?? null,
            ];
        }

        $placeholders = WPSD_DB::in_placeholders($top);
        $sql = "SELECT DISTINCT source_id, target_id FROM {$links}
                WHERE link_type = 'internal'
                  AND source_id IN ({$placeholders})
                  AND target_id IN ({$placeholders})
                  AND source_id <> target_id
                LIMIT 2000";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $edge_rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($top, $top)));

        $edges = [];
        foreach ((array) $edge_rows as $edge) {
            $edges[] = ['source' => (int) $edge->source_id, 'target' => (int) $edge->target_id];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Insert a link into a post's content, right after the first mention of the
     * anchor phrase. Returns true when the post was modified.
     */
    public static function insert_link(int $source_id, int $target_id, string $anchor): bool {
        $source = get_post($source_id);
        $target = get_post($target_id);
        if (!$source || !$target) {
            return false;
        }

        $anchor = trim(wp_strip_all_tags($anchor));
        if ($anchor === '') {
            return false;
        }

        $content = $source->post_content;
        $url     = get_permalink($target_id);

        // Only link the first unlinked occurrence, and never inside an existing
        // anchor, heading or attribute.
        $pattern = '#(?<![>\w])(' . preg_quote($anchor, '#') . ')(?![^<]*</a>)(?![^<]*>)#iu';
        $updated = preg_replace(
            $pattern,
            '<a href="' . esc_url($url) . '">$1</a>',
            $content,
            1,
            $replacements
        );

        if (!$replacements || $updated === null || $updated === $content) {
            return false;
        }

        $result = wp_update_post([
            'ID'           => $source_id,
            'post_content' => $updated,
        ], true);

        if (is_wp_error($result)) {
            return false;
        }

        self::index_post(get_post($source_id));
        self::flush_graph_cache();

        return true;
    }

    /**
     * Map an internal URL back to a post ID.
     */
    public static function resolve_post_id(string $url): int {
        static $cache = [];

        $normalized = WPSD_Helpers::normalize_url($url);
        if ($normalized === '') {
            return 0;
        }
        if (isset($cache[$normalized])) {
            return $cache[$normalized];
        }

        // url_to_postid does not understand the normalised form, so try the
        // original URL first and fall back to the normalised one.
        $id = (int) url_to_postid($url);
        if (!$id) {
            $id = (int) url_to_postid($normalized);
        }

        if (count($cache) > 2000) {
            $cache = [];
        }

        return $cache[$normalized] = $id;
    }

    /**
     * Candidate posts matching any of the given keywords.
     *
     * @param array<int,string> $keywords
     * @return array<int,object>
     */
    private static function search_by_keywords(array $keywords, int $exclude_id, int $limit): array {
        global $wpdb;

        $keywords = array_slice(array_filter($keywords), 0, 6);
        if (!$keywords) {
            return [];
        }

        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        $conditions = [];
        $params     = $types;
        foreach ($keywords as $keyword) {
            $conditions[] = '(post_title LIKE %s OR post_content LIKE %s)';
            $like         = '%' . $wpdb->esc_like($keyword) . '%';
            $params[]     = $like;
            $params[]     = $like;
        }

        $params[] = $exclude_id;
        $params[] = max(1, $limit);

        $sql = "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ({$placeholders})
                  AND (" . implode(' OR ', $conditions) . ")
                  AND ID <> %d
                ORDER BY post_modified DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /**
     * Weighted overlap between a keyword frequency map and a body of text.
     *
     * @param array<string,int> $keywords
     */
    private static function keyword_overlap(array $keywords, string $text): float {
        $haystack = strtolower(WPSD_Helpers::plain_text($text));
        if ($haystack === '') {
            return 0.0;
        }

        $score = 0.0;
        $total = max(1, array_sum($keywords));

        foreach ($keywords as $keyword => $weight) {
            $hits = substr_count($haystack, $keyword);
            if ($hits > 0) {
                // Diminishing returns — a keyword repeated 50 times is not 50×
                // more relevant than one used twice.
                $score += ($weight / $total) * min(3, $hits);
            }
        }

        return round($score, 4);
    }

    /**
     * Pick the anchor text to propose for a link.
     *
     * @param array<int,string> $keywords
     */
    private static function suggest_anchor(string $title, array $keywords): string {
        $lower = strtolower($title);
        foreach ($keywords as $keyword) {
            // A keyword phrase already inside the title makes the best anchor.
            if (mb_strlen($keyword) >= 5 && strpos($lower, $keyword) !== false) {
                return $keyword;
            }
        }
        return WPSD_Helpers::truncate($title, 60);
    }

    public static function flush_graph_cache(): void {
        delete_transient(self::DEPTH_TRANSIENT);
    }

    /**
     * Summary numbers for the internal linking dashboard.
     *
     * @return array<string,int>
     */
    public static function stats(): array {
        global $wpdb;
        $table = WPSD_DB::table('links');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $internal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE link_type = 'internal'");
        $external = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE link_type = 'external'");
        $pages    = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_id) FROM {$table}");
        // phpcs:enable

        return [
            'internal_links' => $internal,
            'external_links' => $external,
            'linked_pages'   => $pages,
            'orphans'        => count(self::orphan_pages(1000)),
            'weak'           => count(self::weakly_linked(1000)),
            'avg_outgoing'   => $pages > 0 ? (int) round($internal / $pages) : 0,
        ];
    }
}
