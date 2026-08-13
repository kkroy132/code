<?php
/**
 * Affiliate SEO checks.
 *
 * Affiliate links are identified by the domain and path-prefix lists in
 * settings, which covers both direct network links (amzn.to) and cloaked
 * links (/go/, /recommends/).
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Checks_Affiliate {

    public static function register(): void {
        $add = ['WPSD_Checks', 'add'];

        call_user_func($add, [
            'id'             => 'affiliate_attributes',
            'group'          => 'affiliate',
            'title'          => __('Affiliate Link Attribute Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('Affiliate links are missing rel="sponsored" or rel="nofollow".', 'wp-seo-doctor'),
            'recommendation' => __('Mark every monetised link rel="sponsored" (or at minimum rel="nofollow") to comply with Google\'s link guidelines.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_attributes'],
        ]);

        call_user_func($add, [
            'id'             => 'affiliate_broken',
            'group'          => 'affiliate',
            'title'          => __('Broken Affiliate Link Detection', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('An affiliate link on this page is dead or points at a removed product.', 'wp-seo-doctor'),
            'recommendation' => __('Replace the link with a live product, or remove the recommendation.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_broken'],
        ]);

        call_user_func($add, [
            'id'             => 'affiliate_redirect_chain',
            'group'          => 'affiliate',
            'title'          => __('Affiliate Redirect Chain Detection', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('An affiliate link passes through several redirects before landing.', 'wp-seo-doctor'),
            'recommendation' => __('Point the cloaked URL directly at the merchant destination — every extra hop loses conversions and can drop tracking.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_redirect_chain'],
        ]);

        call_user_func($add, [
            'id'             => 'affiliate_density',
            'group'          => 'affiliate',
            'title'          => __('Affiliate Link Analysis', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('The ratio of affiliate links to content is unusually high.', 'wp-seo-doctor'),
            'recommendation' => __('Add more original analysis between offers so the page reads as a review rather than a link list.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_density'],
        ]);
    }

    // ─────────────────────────────────────────────────────────── checks ──

    public static function check_attributes(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $required = (array) WPSD_Settings::get('affiliate_required_rel', ['sponsored', 'nofollow']);
        $bad      = [];

        foreach (self::affiliate_links($c) as $link) {
            $rel = strtolower($link['rel']);
            $ok  = false;
            foreach ($required as $token) {
                if (strpos($rel, strtolower($token)) !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $bad[] = $link['url'];
            }
        }

        if (!$bad) {
            return [];
        }

        return [[
            'severity' => 'high',
            'message'  => sprintf(
                /* translators: 1: number of links, 2: required rel values */
                __('%1$d affiliate link(s) are missing rel="%2$s".', 'wp-seo-doctor'),
                count($bad),
                implode('" or rel="', $required)
            ),
            'data' => ['links' => array_slice($bad, 0, 20)],
        ]];
    }

    public static function check_broken(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $broken = WPSD_Broken_Links::broken_for_source($c->id, true);
        if (!$broken) {
            return [];
        }

        $samples = [];
        foreach (array_slice($broken, 0, 10) as $row) {
            $samples[] = [
                'url'    => $row->target_url,
                'status' => (int) $row->http_status,
                'anchor' => $row->anchor,
            ];
        }

        return [[
            'severity' => 'critical',
            'message'  => sprintf(
                /* translators: %d: number of broken affiliate links */
                __('%d affiliate link(s) on this page are dead — every click is lost revenue.', 'wp-seo-doctor'),
                count($broken)
            ),
            'data' => ['links' => $samples],
        ]];
    }

    public static function check_redirect_chain(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $chained = WPSD_Broken_Links::chained_for_source($c->id, true);
        if (!$chained) {
            return [];
        }

        $samples = [];
        foreach (array_slice($chained, 0, 10) as $row) {
            $samples[] = [
                'url'   => $row->target_url,
                'hops'  => (int) $row->redirect_hops,
                'final' => $row->redirect_target,
            ];
        }

        return [[
            'severity' => 'medium',
            'message'  => sprintf(
                /* translators: %d: number of links */
                __('%d affiliate link(s) redirect through multiple hops before reaching the merchant.', 'wp-seo-doctor'),
                count($chained)
            ),
            'data' => ['links' => $samples],
        ]];
    }

    public static function check_density(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $affiliate = self::affiliate_links($c);
        if (count($affiliate) < 5 || $c->word_count === 0) {
            return [];
        }

        // One affiliate link per 100 words is the point where a review starts
        // reading like a landing page.
        $per_hundred = ($c->word_count > 0) ? (count($affiliate) / ($c->word_count / 100)) : 0;
        if ($per_hundred < 1.0) {
            return [];
        }

        return [[
            'severity' => $per_hundred >= 2.0 ? 'medium' : 'low',
            'message'  => sprintf(
                /* translators: 1: number of affiliate links, 2: word count, 3: links per 100 words */
                __('%1$d affiliate links in %2$d words (%3$s per 100 words).', 'wp-seo-doctor'),
                count($affiliate),
                $c->word_count,
                round($per_hundred, 1)
            ),
            'data' => ['count' => count($affiliate), 'words' => $c->word_count],
        ]];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Affiliate links found in a context's markup.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function affiliate_links(WPSD_Context $c): array {
        return array_values(array_filter($c->links, static fn($link) => self::is_affiliate($link['url'])));
    }

    /**
     * Does this URL look like a monetised link?
     */
    public static function is_affiliate(string $url): bool {
        static $domains = null;
        static $prefixes = null;

        if ($domains === null) {
            $domains  = array_map('strtolower', WPSD_Settings::lines('affiliate_domains'));
            $prefixes = array_map('strtolower', WPSD_Settings::lines('affiliate_prefixes'));
        }

        $lower = strtolower($url);
        $host  = (string) wp_parse_url($url, PHP_URL_HOST);
        $path  = (string) wp_parse_url($url, PHP_URL_PATH);

        foreach ($domains as $needle) {
            if ($needle !== '' && $host !== '' && strpos($host, $needle) !== false) {
                return true;
            }
        }

        // Cloaked links live on our own domain behind a known prefix.
        if ($path !== '' && WPSD_Helpers::is_internal_url($url)) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && strpos(strtolower($path), $prefix) === 0) {
                    return true;
                }
            }
        }

        // Common affiliate query parameters.
        if (preg_match('/[?&](tag|aff(iliate)?_?id|ref|refid|utm_source=affiliate|irclickid|clickref)=/i', $lower)) {
            return true;
        }

        /**
         * Filter the affiliate-link verdict for a URL.
         *
         * @param bool   $is_affiliate
         * @param string $url
         */
        return (bool) apply_filters('wpsd_is_affiliate_link', false, $url);
    }
}
