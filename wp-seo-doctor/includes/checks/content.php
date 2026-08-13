<?php
/**
 * Content SEO checks: duplication, decay, staleness and readability signals.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Checks_Content {

    const SHINGLE_TRANSIENT = 'wpsd_content_shingles';

    public static function register(): void {
        $add = ['WPSD_Checks', 'add'];

        call_user_func($add, [
            'id'             => 'duplicate_content',
            'group'          => 'content',
            'title'          => __('Duplicate Content Signals', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('This page substantially overlaps another page on the site.', 'wp-seo-doctor'),
            'recommendation' => __('Merge the pages, rewrite one of them, or canonicalise the weaker version to the stronger one.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_duplicate_content'],
        ]);

        call_user_func($add, [
            'id'             => 'content_decay',
            'group'          => 'content',
            'title'          => __('Content Decay Detection', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('Search traffic to this page is falling.', 'wp-seo-doctor'),
            'recommendation' => __('Refresh the content, update statistics and examples, and re-promote it internally.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_content_decay'],
        ]);

        call_user_func($add, [
            'id'             => 'outdated_content',
            'group'          => 'content',
            'title'          => __('Outdated Content Detection', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('The page has not been updated in a long time.', 'wp-seo-doctor'),
            'recommendation' => __('Review the page for accuracy and update it — freshness matters most for time-sensitive topics.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_outdated_content'],
        ]);

        call_user_func($add, [
            'id'             => 'content_optimization',
            'group'          => 'content',
            'title'          => __('Content Optimization Suggestions', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('The page is missing structural elements that help readers and crawlers.', 'wp-seo-doctor'),
            'recommendation' => __('Add subheadings, images, lists and internal links; break up very long paragraphs.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_content_optimization'],
        ]);
    }

    // ─────────────────────────────────────────────────────────── checks ──

    public static function check_duplicate_content(?WPSD_Context $c): array {
        if (!$c || $c->word_count < 150) {
            // Too short to say anything meaningful about overlap.
            return [];
        }

        $threshold = (float) WPSD_Settings::get('duplicate_threshold', 0.75);
        $index     = self::shingle_index();
        $mine      = WPSD_Helpers::shingles($c->content);

        if (!$mine) {
            return [];
        }

        $matches = [];
        foreach ($index as $post_id => $fingerprint) {
            if ((int) $post_id === $c->id || !$fingerprint) {
                continue;
            }
            $intersect = count(array_intersect_key($mine, $fingerprint));
            if ($intersect === 0) {
                continue;
            }
            $similarity = $intersect / max(1, count($mine + $fingerprint));
            if ($similarity >= $threshold) {
                $matches[] = [
                    'id'         => (int) $post_id,
                    'title'      => get_the_title((int) $post_id),
                    'url'        => get_permalink((int) $post_id),
                    'similarity' => round($similarity * 100, 1),
                ];
            }
        }

        if (!$matches) {
            return [];
        }

        usort($matches, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return [[
            'severity' => $matches[0]['similarity'] >= 90 ? 'critical' : 'high',
            'message'  => sprintf(
                /* translators: 1: similarity percentage, 2: other page title */
                __('%1$s%% of this page overlaps "%2$s".', 'wp-seo-doctor'),
                $matches[0]['similarity'],
                WPSD_Helpers::truncate($matches[0]['title'], 50)
            ),
            'data' => ['matches' => array_slice($matches, 0, 5)],
        ]];
    }

    public static function check_content_decay(?WPSD_Context $c): array {
        if (!$c || !WPSD_GSC::has_data()) {
            // Without Search Console history there is no traffic to compare.
            return [];
        }

        $decay = WPSD_GSC::page_trend($c->url);
        if (!$decay || $decay['previous_clicks'] < 10) {
            // Ignore pages that never had meaningful traffic.
            return [];
        }

        $drop = $decay['change_percent'];
        if ($drop > -20) {
            return [];
        }

        return [[
            'severity' => $drop <= -50 ? 'high' : 'medium',
            'message'  => sprintf(
                /* translators: 1: percentage drop, 2: previous clicks, 3: recent clicks */
                __('Clicks fell %1$s%% (%2$d → %3$d) versus the previous period.', 'wp-seo-doctor'),
                abs(round($drop)),
                $decay['previous_clicks'],
                $decay['recent_clicks']
            ),
            'data' => $decay,
        ]];
    }

    public static function check_outdated_content(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $days_threshold = (int) WPSD_Settings::get('outdated_after_days', 365);
        $modified       = strtotime($c->post->post_modified_gmt . ' UTC');
        if (!$modified) {
            return [];
        }

        $age_days = (int) floor((time() - $modified) / DAY_IN_SECONDS);
        if ($age_days < $days_threshold) {
            return [];
        }

        // Pages that still get traffic are lower priority than dormant ones.
        $severity = 'medium';
        if (WPSD_GSC::has_data()) {
            $stats = WPSD_GSC::page_stats($c->url);
            if ($stats && $stats['clicks'] > 50) {
                $severity = 'low';
            }
        }

        return [[
            'severity' => $severity,
            'message'  => sprintf(
                /* translators: 1: age in days, 2: last modified date */
                __('Last updated %1$d days ago (%2$s).', 'wp-seo-doctor'),
                $age_days,
                get_the_modified_date(get_option('date_format'), $c->post)
            ),
            'data' => ['age_days' => $age_days, 'modified' => $c->post->post_modified],
        ]];
    }

    public static function check_content_optimization(?WPSD_Context $c): array {
        if (!$c || $c->word_count < 150) {
            return [];
        }

        $suggestions = [];

        if (!$c->images) {
            $suggestions[] = __('add at least one image', 'wp-seo-doctor');
        }
        if (count($c->internal_links) < (int) WPSD_Settings::get('min_internal_links', 3)) {
            $suggestions[] = __('add more internal links', 'wp-seo-doctor');
        }
        if ($c->word_count >= 800 && !preg_match('#<(ul|ol|table)\b#i', $c->content)) {
            $suggestions[] = __('add a list or table to break up the text', 'wp-seo-doctor');
        }

        // Walls of text hurt readability; flag paragraphs over ~150 words.
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $c->content, $paragraphs)) {
            $long = 0;
            foreach ($paragraphs[1] as $paragraph) {
                if (WPSD_Helpers::word_count($paragraph) > 150) {
                    $long++;
                }
            }
            if ($long > 0) {
                $suggestions[] = sprintf(
                    /* translators: %d: number of long paragraphs */
                    _n('split %d very long paragraph', 'split %d very long paragraphs', $long, 'wp-seo-doctor'),
                    $long
                );
            }
        }

        if ($c->external_links === [] && $c->word_count >= 800) {
            $suggestions[] = __('cite an authoritative external source', 'wp-seo-doctor');
        }

        if (!$suggestions) {
            return [];
        }

        return [[
            'severity' => count($suggestions) >= 3 ? 'medium' : 'low',
            'message'  => sprintf(
                /* translators: %s: comma-separated suggestions */
                __('Content could be improved: %s.', 'wp-seo-doctor'),
                implode(', ', $suggestions)
            ),
            'data' => ['suggestions' => $suggestions],
        ]];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Fingerprint every published post once so duplicate detection is a set
     * intersection instead of an N×N text comparison.
     *
     * @return array<int,array<string,bool>>
     */
    public static function shingle_index(bool $force = false): array {
        static $cache = null;
        if ($cache !== null && !$force) {
            return $cache;
        }

        if (!$force) {
            $stored = get_transient(self::SHINGLE_TRANSIENT);
            if (is_array($stored)) {
                return $cache = $stored;
            }
        }

        global $wpdb;
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        // Cap the corpus: fingerprinting is memory-bound and the long tail of a
        // very large site adds little to duplicate detection.
        $sql = "SELECT ID, post_content FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ({$placeholders})
                ORDER BY post_modified DESC
                LIMIT 3000";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $types));

        $index = [];
        foreach ((array) $rows as $row) {
            $shingles = WPSD_Helpers::shingles((string) $row->post_content);
            if ($shingles) {
                $index[(int) $row->ID] = $shingles;
            }
        }

        set_transient(self::SHINGLE_TRANSIENT, $index, HOUR_IN_SECONDS);

        return $cache = $index;
    }

    public static function flush_shingle_index(): void {
        delete_transient(self::SHINGLE_TRANSIENT);
    }
}
