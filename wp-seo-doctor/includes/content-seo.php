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
        $index     = WPSD_Checks_Content::shingle_index();
        $threshold = (float) WPSD_Settings::get('duplicate_threshold', 0.75);

        $ids      = array_keys($index);
        $clusters = [];
        $claimed  = [];

        // O(n²) over fingerprints, but bounded by the 3,000-post cap on the
        // index and short-circuited as soon as a page joins a cluster.
        foreach ($ids as $i => $id_a) {
            if (isset($claimed[$id_a])) {
                continue;
            }
            $group = [];

            for ($j = $i + 1, $count = count($ids); $j < $count; $j++) {
                $id_b = $ids[$j];
                if (isset($claimed[$id_b])) {
                    continue;
                }

                $intersect = count(array_intersect_key($index[$id_a], $index[$id_b]));
                if ($intersect === 0) {
                    continue;
                }
                $similarity = $intersect / max(1, count($index[$id_a] + $index[$id_b]));
                if ($similarity < $threshold) {
                    continue;
                }

                $group[]        = ['id' => $id_b, 'similarity' => round($similarity * 100, 1)];
                $claimed[$id_b] = true;
            }

            if (!$group) {
                continue;
            }

            $claimed[$id_a] = true;

            $pages = [self::describe((int) $id_a, 100.0)];
            foreach ($group as $member) {
                $pages[] = self::describe((int) $member['id'], (float) $member['similarity']);
            }

            $clusters[] = [
                'pages'      => $pages,
                'similarity' => (float) max(array_column($group, 'similarity')),
            ];

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
