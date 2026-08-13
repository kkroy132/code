<?php
/**
 * Internal linking checks.
 *
 * These read the link graph built by WPSD_Internal_Links, so they need the
 * graph to be current — the scanner refreshes each post's links before running
 * its checks.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Checks_Links {

    public static function register(): void {
        $add = ['WPSD_Checks', 'add'];

        call_user_func($add, [
            'id'             => 'few_outgoing_links',
            'group'          => 'links',
            'title'          => __('Weak Internal Link Detection', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('The page links out to very few other pages on the site.', 'wp-seo-doctor'),
            'recommendation' => __('Link to related articles so crawlers and readers can move deeper into the site.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_outgoing'],
        ]);

        call_user_func($add, [
            'id'             => 'orphan_page',
            'group'          => 'links',
            'title'          => __('Orphan Page Finder', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('No other page on the site links to this one.', 'wp-seo-doctor'),
            'recommendation' => __('Add internal links from related, well-linked pages.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_orphan'],
        ]);

        call_user_func($add, [
            'id'             => 'few_incoming_links',
            'group'          => 'links',
            'title'          => __('Incoming Link Analysis', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('The page has fewer incoming internal links than recommended.', 'wp-seo-doctor'),
            'recommendation' => __('Build more internal links to this page from relevant content.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_incoming'],
        ]);

        call_user_func($add, [
            'id'             => 'generic_anchor_text',
            'group'          => 'links',
            'title'          => __('Anchor Text Analysis', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('Internal links use uninformative anchor text.', 'wp-seo-doctor'),
            'recommendation' => __('Replace "click here" and bare URLs with descriptive anchor text.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_anchor_text'],
        ]);

        call_user_func($add, [
            'id'             => 'self_link',
            'group'          => 'links',
            'title'          => __('Internal Link Analysis', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('The page links to itself.', 'wp-seo-doctor'),
            'recommendation' => __('Remove self-referencing links; they add no crawl or ranking value.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_self_links'],
        ]);

        call_user_func($add, [
            'id'             => 'excessive_outbound',
            'group'          => 'links',
            'title'          => __('Outgoing Link Analysis', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('The page links out to an unusually large number of external domains.', 'wp-seo-doctor'),
            'recommendation' => __('Trim external links, or mark commercial ones rel="sponsored" / rel="nofollow".', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_outbound'],
        ]);
    }

    // ─────────────────────────────────────────────────────────── checks ──

    public static function check_outgoing(?WPSD_Context $c): array {
        if (!$c || $c->word_count < 200) {
            return [];
        }

        $min   = (int) WPSD_Settings::get('min_internal_links', 3);
        $count = count(self::unique_internal_targets($c));

        if ($count >= $min) {
            return [];
        }

        return [[
            'severity' => $count === 0 ? 'medium' : 'low',
            'message'  => $count === 0
                ? __('This page contains no internal links at all.', 'wp-seo-doctor')
                : sprintf(
                    /* translators: 1: current count, 2: recommended minimum */
                    __('Only %1$d internal link(s); at least %2$d recommended.', 'wp-seo-doctor'),
                    $count,
                    $min
                ),
            'data' => [
                'count'       => $count,
                'suggestions' => WPSD_Internal_Links::suggest_targets($c->id, 5),
            ],
        ]];
    }

    public static function check_orphan(?WPSD_Context $c): array {
        if (!$c || $c->post->post_status !== 'publish') {
            return [];
        }
        // The front page is reached directly, never via an internal link.
        if ((int) get_option('page_on_front') === $c->id) {
            return [];
        }

        $incoming = WPSD_Internal_Links::incoming_count($c->id);
        if ($incoming > 0) {
            return [];
        }

        return [[
            'severity' => 'high',
            'message'  => __('No internal links point to this page, so crawlers can only find it via the sitemap.', 'wp-seo-doctor'),
            'data'     => ['sources' => WPSD_Internal_Links::suggest_sources($c->id, 5)],
        ]];
    }

    public static function check_incoming(?WPSD_Context $c): array {
        if (!$c || $c->post->post_status !== 'publish') {
            return [];
        }
        if ((int) get_option('page_on_front') === $c->id) {
            return [];
        }

        $min      = (int) WPSD_Settings::get('min_incoming_links', 2);
        $incoming = WPSD_Internal_Links::incoming_count($c->id);

        // Zero incoming is the orphan check's job.
        if ($incoming === 0 || $incoming >= $min) {
            return [];
        }

        return [[
            'severity' => 'low',
            'message'  => sprintf(
                /* translators: 1: incoming link count, 2: recommended minimum */
                __('Only %1$d incoming internal link(s); at least %2$d recommended.', 'wp-seo-doctor'),
                $incoming,
                $min
            ),
            'data' => [
                'incoming' => $incoming,
                'sources'  => WPSD_Internal_Links::suggest_sources($c->id, 5),
            ],
        ]];
    }

    public static function check_anchor_text(?WPSD_Context $c): array {
        if (!$c || !$c->internal_links) {
            return [];
        }

        $generic = self::generic_anchors();
        $bad     = [];

        foreach ($c->internal_links as $link) {
            $anchor = strtolower(trim($link['anchor']));
            if ($anchor === '') {
                // An image link carries its ALT text instead — not an issue.
                continue;
            }
            if (isset($generic[$anchor]) || preg_match('#^https?://#i', $anchor)) {
                $bad[] = $link['anchor'];
            }
        }

        if (!$bad) {
            return [];
        }

        return [[
            'severity' => 'low',
            'message'  => sprintf(
                /* translators: 1: number of links, 2: sample anchors */
                __('%1$d internal link(s) use non-descriptive anchor text (e.g. %2$s).', 'wp-seo-doctor'),
                count($bad),
                implode(', ', array_map(static fn($a) => '"' . WPSD_Helpers::truncate($a, 30) . '"', array_slice($bad, 0, 3)))
            ),
            'data' => ['anchors' => array_slice($bad, 0, 20)],
        ]];
    }

    public static function check_self_links(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $self  = WPSD_Helpers::normalize_url($c->url);
        $count = 0;
        foreach ($c->internal_links as $link) {
            if (WPSD_Helpers::normalize_url($link['url']) === $self) {
                $count++;
            }
        }

        if ($count === 0) {
            return [];
        }

        return [[
            'severity' => 'low',
            'message'  => sprintf(
                /* translators: %d: number of self links */
                __('%d link(s) on this page point back to the same page.', 'wp-seo-doctor'),
                $count
            ),
            'data' => ['count' => $count],
        ]];
    }

    public static function check_outbound(?WPSD_Context $c): array {
        if (!$c || !$c->external_links) {
            return [];
        }

        $domains = [];
        foreach ($c->external_links as $link) {
            $host = wp_parse_url($link['url'], PHP_URL_HOST);
            if ($host) {
                $domains[strtolower($host)] = true;
            }
        }

        // 25 distinct external domains on one page is well past editorial.
        if (count($domains) < 25) {
            return [];
        }

        return [[
            'severity' => 'low',
            'message'  => sprintf(
                /* translators: 1: number of links, 2: number of domains */
                __('%1$d external links across %2$d domains — review whether they are all editorial.', 'wp-seo-doctor'),
                count($c->external_links),
                count($domains)
            ),
            'data' => ['domains' => array_slice(array_keys($domains), 0, 30)],
        ]];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Distinct internal destinations, excluding self-links.
     *
     * @return array<int,string>
     */
    private static function unique_internal_targets(WPSD_Context $c): array {
        $self    = WPSD_Helpers::normalize_url($c->url);
        $targets = [];
        foreach ($c->internal_links as $link) {
            $normalized = WPSD_Helpers::normalize_url($link['url']);
            if ($normalized !== '' && $normalized !== $self) {
                $targets[$normalized] = true;
            }
        }
        return array_keys($targets);
    }

    /** @return array<string,bool> */
    private static function generic_anchors(): array {
        static $list = null;
        if ($list === null) {
            $phrases = [
                'click here', 'here', 'read more', 'more', 'this', 'link', 'this link',
                'this page', 'this post', 'this article', 'learn more', 'find out more',
                'see more', 'continue reading', 'go', 'download', 'info', 'website',
            ];
            /**
             * Filter the anchor phrases treated as non-descriptive.
             *
             * @param array<int,string> $phrases
             */
            $phrases = (array) apply_filters('wpsd_generic_anchors', $phrases);
            $list    = array_fill_keys(array_map('strtolower', $phrases), true);
        }
        return $list;
    }
}
