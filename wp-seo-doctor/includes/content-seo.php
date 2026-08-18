<?php
/**
 * Content SEO reporting: decay, staleness, duplication and opportunities.
 *
 * The per-page verdicts live in the checks; this module assembles them into
 * the site-wide lists the Content SEO screen renders.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Content_SEO {

    public static function init(): void {
        // Nothing to hook: this module is queried on demand by the admin UI
        // and the report builders.
    }

    /**
     * Pages below the thin-content threshold.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function thin_pages(int $limit = 100): array {
        global $wpdb;

        $threshold    = (int) WPSD_Settings::get('thin_content_words', 200);
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        $sql = "SELECT ID, post_title, post_type, post_content, post_modified
                FROM {$wpdb->posts}
                WHERE post_status = 'publish' AND post_type IN ({$placeholders})
                ORDER BY post_modified DESC
                LIMIT 5000";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $types));

        $out = [];
        foreach ((array) $rows as $row) {
            $words = WPSD_Helpers::word_count((string) $row->post_content);
            if ($words >= $threshold) {
                continue;
            }
            $out[] = [
                'id'       => (int) $row->ID,
                'title'    => $row->post_title,
                'type'     => $row->post_type,
                'url'      => (string) get_permalink((int) $row->ID),
                'edit_url' => (string) get_edit_post_link((int) $row->ID, 'raw'),
                'words'    => $words,
                'modified' => $row->post_modified,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        usort($out, static fn($a, $b) => $a['words'] <=> $b['words']);

        return $out;
    }

    /**
     * Clusters of pages that substantially overlap each other.
     *
     * @return array<int,array{pages:array<int,array<string,mixed>>, similarity:float}>
     */
    public static function duplicate_clusters(int $limit = 50): array {
        global $wpdb;

        // Works before the first scan: fill in any missing fingerprints.
        WPSD_Fingerprints::ensure_built();

        $threshold = (float) WPSD_Settings::get('duplicate_threshold', 0.75);
        $shingles  = WPSD_DB::table('shingles');

        // Every overlapping pair in one pass. Comparing fingerprints in PHP
        // was O(n²) over the whole corpus; the join lets the index do the work
        // and only pairs that already share sketch entries come back.
        // Shingles that appear on a large share of the site are boilerplate —
        // a shared header sentence, a standard disclaimer — and carry no
        // evidence of duplication. Excluding them is not just an optimisation:
        // without it, a templated corpus turns this self-join into a near
        // cross-product. Measured on 2,000 templated posts it was the
        // difference between 113 seconds and well under one.
        $common_cutoff = WPSD_Fingerprints::common_shingle_cutoff();

        $sql = "SELECT a.post_id AS a_id,
                       b.post_id AS b_id,
                       COUNT(*) AS shared,
                       ta.total AS a_total,
                       tb.total AS b_total
                FROM (
                    SELECT shingle FROM {$shingles} GROUP BY shingle HAVING COUNT(*) <= %d
                ) AS rare
                JOIN {$shingles} a ON a.shingle = rare.shingle
                JOIN {$shingles} b
                  ON b.shingle = rare.shingle
                 AND b.post_id > a.post_id
                JOIN (SELECT post_id, COUNT(*) AS total FROM {$shingles} GROUP BY post_id) ta
                  ON ta.post_id = a.post_id
                JOIN (SELECT post_id, COUNT(*) AS total FROM {$shingles} GROUP BY post_id) tb
                  ON tb.post_id = b.post_id
                GROUP BY a.post_id, b.post_id, ta.total, tb.total
                HAVING shared >= %d
                ORDER BY shared DESC
                LIMIT %d";

        // Pairs below this cannot reach the threshold whatever their totals.
        $minimum_shared = max(2, (int) floor(WPSD_Fingerprints::MIN_SHINGLES * $threshold));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $pairs = $wpdb->get_results($wpdb->prepare($sql, $common_cutoff, $minimum_shared, $limit * 20));

        $clusters = [];
        $claimed  = [];

        foreach ((array) $pairs as $pair) {
            $a = (int) $pair->a_id;
            $b = (int) $pair->b_id;

            if (isset($claimed[$b])) {
                continue;
            }

            $shared = (int) $pair->shared;
            $union  = (int) $pair->a_total + (int) $pair->b_total - $shared;
            if ($union <= 0) {
                continue;
            }

            $similarity = $shared / $union;
            if ($similarity < $threshold) {
                continue;
            }

            $percent = round($similarity * 100, 1);

            if (isset($claimed[$a])) {
                // Grow the cluster this page already anchors.
                $index = $claimed[$a];
                $clusters[$index]['pages'][]    = self::describe($b, $percent);
                $clusters[$index]['similarity'] = max($clusters[$index]['similarity'], $percent);
                $claimed[$b] = $index;
                continue;
            }

            $clusters[] = [
                'pages'      => [self::describe($a, 100.0), self::describe($b, $percent)],
                'similarity' => $percent,
            ];

            $index       = count($clusters) - 1;
            $claimed[$a] = $index;
            $claimed[$b] = $index;

            if (count($clusters) >= $limit) {
                break;
            }
        }

        usort($clusters, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return $clusters;
    }

    /**
     * Pages whose search traffic is falling. Requires Search Console data.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function decaying_pages(int $limit = 50): array {
        if (!WPSD_GSC::has_data()) {
            return [];
        }

        $rows = WPSD_GSC::declining_pages($limit * 2);
        $out  = [];

        foreach ($rows as $row) {
            $post_id = WPSD_Internal_Links::resolve_post_id((string) $row['page']);
            $out[]   = [
                'id'             => $post_id,
                'title'          => $post_id ? get_the_title($post_id) : $row['page'],
                'url'            => $row['page'],
                'edit_url'       => $post_id ? (string) get_edit_post_link($post_id, 'raw') : '',
                'recent_clicks'  => $row['recent_clicks'],
                'previous_clicks' => $row['previous_clicks'],
                'change_percent' => $row['change_percent'],
                'modified'       => $post_id ? get_post_field('post_modified', $post_id) : '',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Pages not touched inside the staleness window.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function outdated_pages(int $limit = 100): array {
        global $wpdb;

        $days         = (int) WPSD_Settings::get('outdated_after_days', 365);
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        $sql = "SELECT ID, post_title, post_type, post_modified, post_date
                FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ({$placeholders})
                  AND post_modified < DATE_SUB(NOW(), INTERVAL %d DAY)
                ORDER BY post_modified ASC
                LIMIT %d";

        $params = array_merge($types, [max(1, $days), max(1, $limit)]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

        $out = [];
        foreach ((array) $rows as $row) {
            $id       = (int) $row->ID;
            $modified = strtotime($row->post_modified);
            $url      = (string) get_permalink($id);
            $stats    = WPSD_GSC::has_data() ? WPSD_GSC::page_stats($url) : null;

            $out[] = [
                'id'       => $id,
                'title'    => $row->post_title,
                'type'     => $row->post_type,
                'url'      => $url,
                'edit_url' => (string) get_edit_post_link($id, 'raw'),
                'modified' => $row->post_modified,
                'age_days' => $modified ? (int) floor((time() - $modified) / DAY_IN_SECONDS) : 0,
                'clicks'   => $stats['clicks'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Where new or expanded content would pay off most.
     *
     * Combines three signals: high-impression/low-CTR pages, queries ranking
     * just off page one, and thin pages that still attract impressions.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function opportunities(int $limit = 50): array {
        $out = [];

        if (WPSD_GSC::has_data()) {
            foreach (WPSD_GSC::striking_distance(20) as $row) {
                $post_id = WPSD_Internal_Links::resolve_post_id((string) $row['page']);
                $out[]   = [
                    'type'        => 'striking_distance',
                    'label'       => __('Almost page one', 'wp-seo-doctor'),
                    'title'       => $post_id ? get_the_title($post_id) : $row['page'],
                    'url'         => $row['page'],
                    'edit_url'    => $post_id ? (string) get_edit_post_link($post_id, 'raw') : '',
                    'detail'      => sprintf(
                        /* translators: 1: query, 2: average position, 3: impressions */
                        __('"%1$s" ranks at position %2$s with %3$d impressions.', 'wp-seo-doctor'),
                        $row['query'],
                        round((float) $row['position'], 1),
                        (int) $row['impressions']
                    ),
                    'action'   => __('Strengthen this section and add internal links using the query as anchor text.', 'wp-seo-doctor'),
                    'priority' => 'high',
                ];
            }

            foreach (WPSD_GSC::ctr_opportunities(15) as $row) {
                $post_id = WPSD_Internal_Links::resolve_post_id((string) $row['page']);
                $out[]   = [
                    'type'     => 'low_ctr',
                    'label'    => __('Low click-through rate', 'wp-seo-doctor'),
                    'title'    => $post_id ? get_the_title($post_id) : $row['page'],
                    'url'      => $row['page'],
                    'edit_url' => $post_id ? (string) get_edit_post_link($post_id, 'raw') : '',
                    'detail'   => sprintf(
                        /* translators: 1: CTR, 2: impressions, 3: position */
                        __('%1$s%% CTR across %2$d impressions at position %3$s.', 'wp-seo-doctor'),
                        round((float) $row['ctr'], 2),
                        (int) $row['impressions'],
                        round((float) $row['position'], 1)
                    ),
                    'action'   => __('Rewrite the title and meta description to match search intent.', 'wp-seo-doctor'),
                    'priority' => 'medium',
                ];
            }
        }

        foreach (self::thin_pages(15) as $page) {
            $out[] = [
                'type'     => 'thin',
                'label'    => __('Thin content', 'wp-seo-doctor'),
                'title'    => $page['title'],
                'url'      => $page['url'],
                'edit_url' => $page['edit_url'],
                'detail'   => sprintf(
                    /* translators: %d: word count */
                    __('Only %d words.', 'wp-seo-doctor'),
                    $page['words']
                ),
                'action'   => __('Expand, merge into a stronger page, or noindex.', 'wp-seo-doctor'),
                'priority' => 'medium',
            ];
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($out, static fn($a, $b) => ($order[$a['priority']] ?? 3) <=> ($order[$b['priority']] ?? 3));

        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        return [
            'thin'       => count(self::thin_pages(1000)),
            'outdated'   => count(self::outdated_pages(1000)),
            'duplicates' => count(self::duplicate_clusters(200)),
            'decaying'   => WPSD_GSC::has_data() ? count(self::decaying_pages(200)) : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function describe(int $post_id, float $similarity): array {
        return [
            'id'         => $post_id,
            'title'      => (string) get_the_title($post_id),
            'url'        => (string) get_permalink($post_id),
            'edit_url'   => (string) get_edit_post_link($post_id, 'raw'),
            'words'      => WPSD_Helpers::word_count((string) get_post_field('post_content', $post_id)),
            'similarity' => $similarity,
        ];
    }
}
