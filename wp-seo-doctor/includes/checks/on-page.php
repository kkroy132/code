<?php
/**
 * On-page SEO checks.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Checks_OnPage {

    const DUPLICATE_TRANSIENT = 'wpsd_duplicate_index';

    public static function register(): void {
        $add = ['WPSD_Checks', 'add'];

        call_user_func($add, [
            'id'             => 'seo_title',
            'group'          => 'onpage',
            'title'          => __('SEO Title Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The SEO title is missing or outside the recommended length.', 'wp-seo-doctor'),
            'recommendation' => __('Write a unique title between the configured minimum and maximum length that leads with the primary topic.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_title'],
        ]);

        call_user_func($add, [
            'id'             => 'meta_description',
            'group'          => 'onpage',
            'title'          => __('Meta Description Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The meta description is missing or outside the recommended length.', 'wp-seo-doctor'),
            'recommendation' => __('Write a compelling description that summarises the page and includes the primary keyword.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_description'],
        ]);

        call_user_func($add, [
            'id'             => 'h1',
            'group'          => 'onpage',
            'title'          => __('H1 Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The page has no H1, or more than one.', 'wp-seo-doctor'),
            'recommendation' => __('Use exactly one H1 that describes the page topic.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_h1'],
        ]);

        call_user_func($add, [
            'id'             => 'heading_structure',
            'group'          => 'onpage',
            'title'          => __('Heading Structure Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('Heading levels are skipped or the page has no subheadings.', 'wp-seo-doctor'),
            'recommendation' => __('Nest headings in order (H2 before H3) and break long content into sections.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_heading_structure'],
        ]);

        call_user_func($add, [
            'id'             => 'keyword_usage',
            'group'          => 'onpage',
            'title'          => __('Keyword Usage Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('The focus keyword is missing from key positions, or over-used.', 'wp-seo-doctor'),
            'recommendation' => __('Use the focus keyword in the title, meta description, first paragraph and at least one subheading.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_keyword_usage'],
        ]);

        call_user_func($add, [
            'id'             => 'content_length',
            'group'          => 'onpage',
            'title'          => __('Content Length Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('The page is shorter than the configured minimum word count.', 'wp-seo-doctor'),
            'recommendation' => __('Expand the page so it fully answers the query it targets.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_content_length'],
        ]);

        call_user_func($add, [
            'id'             => 'thin_content',
            'group'          => 'onpage',
            'title'          => __('Thin Content Detection', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The page has very little unique content.', 'wp-seo-doctor'),
            'recommendation' => __('Rewrite, expand, merge into a stronger page, or noindex it.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_thin_content'],
        ]);

        call_user_func($add, [
            'id'             => 'image_alt',
            'group'          => 'onpage',
            'title'          => __('Image ALT Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('Images are missing descriptive ALT text.', 'wp-seo-doctor'),
            'recommendation' => __('Describe each meaningful image in its ALT attribute; leave it empty only for decorative images.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_image_alt'],
        ]);

        call_user_func($add, [
            'id'             => 'duplicate_title',
            'group'          => 'onpage',
            'title'          => __('Duplicate Title Detection', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('Another page uses the same SEO title.', 'wp-seo-doctor'),
            'recommendation' => __('Give every indexable page a distinct title so they do not compete in search.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_duplicate_title'],
        ]);

        call_user_func($add, [
            'id'             => 'duplicate_description',
            'group'          => 'onpage',
            'title'          => __('Duplicate Meta Description Detection', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('Another page uses the same meta description.', 'wp-seo-doctor'),
            'recommendation' => __('Write a unique description for each page.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_duplicate_description'],
        ]);

        call_user_func($add, [
            'id'             => 'canonical',
            'group'          => 'onpage',
            'title'          => __('Canonical Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The canonical URL is missing, malformed, or points somewhere unexpected.', 'wp-seo-doctor'),
            'recommendation' => __('Point the canonical at the preferred version of this page — usually itself.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_canonical'],
        ]);

        call_user_func($add, [
            'id'             => 'noindex',
            'group'          => 'onpage',
            'title'          => __('Noindex Check', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('A published page is set to noindex and cannot rank.', 'wp-seo-doctor'),
            'recommendation' => __('Remove the noindex directive if this page should appear in search results.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_noindex'],
        ]);

        call_user_func($add, [
            'id'             => 'nofollow',
            'group'          => 'onpage',
            'title'          => __('Nofollow Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('Internal links are marked nofollow, wasting internal link equity.', 'wp-seo-doctor'),
            'recommendation' => __('Remove rel="nofollow" from internal links unless you deliberately want them uncrawled.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_nofollow'],
        ]);
    }

    // ─────────────────────────────────────────────────────────── checks ──

    public static function check_title(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $title = trim($c->seo_title);
        $min   = (int) WPSD_Settings::get('title_min', 30);
        $max   = (int) WPSD_Settings::get('title_max', 60);

        if ($title === '') {
            return [[
                'severity' => 'critical',
                'message'  => __('This page has no SEO title.', 'wp-seo-doctor'),
            ]];
        }

        $length = mb_strlen($title);
        $pixels = WPSD_Helpers::pixel_width($title);
        $issues = [];

        if ($length < $min) {
            $issues[] = [
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: 1: current length, 2: minimum length */
                    __('Title is only %1$d characters (recommended minimum %2$d).', 'wp-seo-doctor'),
                    $length,
                    $min
                ),
                'data' => ['title' => $title, 'length' => $length, 'pixels' => $pixels],
            ];
        } elseif ($length > $max) {
            $issues[] = [
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: 1: current length, 2: maximum length */
                    __('Title is %1$d characters and will be truncated in search results (recommended maximum %2$d).', 'wp-seo-doctor'),
                    $length,
                    $max
                ),
                'data' => ['title' => $title, 'length' => $length, 'pixels' => $pixels],
            ];
        }

        return $issues;
    }

    public static function check_description(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $desc = trim($c->seo_description);
        $min  = (int) WPSD_Settings::get('desc_min', 70);
        $max  = (int) WPSD_Settings::get('desc_max', 160);

        if ($desc === '') {
            return [[
                'severity' => 'high',
                'message'  => __('This page has no meta description, so Google will invent one.', 'wp-seo-doctor'),
            ]];
        }

        $length = mb_strlen($desc);

        if ($length < $min) {
            return [[
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: 1: current length, 2: minimum length */
                    __('Meta description is only %1$d characters (recommended minimum %2$d).', 'wp-seo-doctor'),
                    $length,
                    $min
                ),
                'data' => ['description' => $desc, 'length' => $length],
            ]];
        }
        if ($length > $max) {
            return [[
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: 1: current length, 2: maximum length */
                    __('Meta description is %1$d characters and will be cut off (recommended maximum %2$d).', 'wp-seo-doctor'),
                    $length,
                    $max
                ),
                'data' => ['description' => $desc, 'length' => $length],
            ]];
        }

        // A description auto-derived from the excerpt is better than nothing,
        // but it is not a deliberate SERP snippet.
        if ($c->seo_description_source === 'excerpt') {
            return [[
                'severity' => 'low',
                'message'  => __('No meta description is set; the excerpt is being used as a fallback.', 'wp-seo-doctor'),
                'data'     => ['description' => $desc],
            ]];
        }

        return [];
    }

    public static function check_h1(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $h1s = $c->headings_of_level(1);

        // Most themes render the post title as the H1 outside post_content, so
        // zero H1s inside the content is normal and not reported.
        if (count($h1s) > 1) {
            return [[
                'severity' => 'high',
                'message'  => sprintf(
                    /* translators: %d: number of H1 headings */
                    __('The content contains %d H1 headings. Search engines expect one.', 'wp-seo-doctor'),
                    count($h1s)
                ),
                'data' => ['headings' => wp_list_pluck($h1s, 'text')],
            ]];
        }

        if (count($h1s) === 1) {
            $h1    = $h1s[0]['text'];
            $title = $c->seo_title;
            if ($h1 !== '' && $title !== '' && WPSD_Helpers::similarity($h1, $title, 2) < 0.1 && $c->focus_keyword !== '') {
                $keyword = strtolower($c->focus_keyword);
                if (strpos(strtolower($h1), $keyword) === false) {
                    return [[
                        'severity' => 'low',
                        'message'  => __('The H1 does not contain the focus keyword.', 'wp-seo-doctor'),
                        'data'     => ['h1' => $h1, 'keyword' => $c->focus_keyword],
                    ]];
                }
            }
        }

        return [];
    }

    public static function check_heading_structure(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $issues = [];

        // Long content with no subheadings is hard to scan and hard to rank.
        $subheads = array_filter($c->headings, static fn($h) => $h['level'] >= 2);
        if ($c->word_count >= 600 && count($subheads) === 0) {
            $issues[] = [
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: %d: word count */
                    __('%d words with no subheadings. Break the content into sections.', 'wp-seo-doctor'),
                    $c->word_count
                ),
            ];
        }

        // Skipped levels, e.g. H2 straight to H4.
        $previous = 0;
        $skips    = [];
        foreach ($c->headings as $heading) {
            if ($previous > 0 && $heading['level'] > $previous + 1) {
                $skips[] = sprintf('H%d → H%d (%s)', $previous, $heading['level'], WPSD_Helpers::truncate($heading['text'], 40));
            }
            $previous = $heading['level'];
        }
        if ($skips) {
            $issues[] = [
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: %s: list of skipped heading transitions */
                    __('Heading levels are skipped: %s', 'wp-seo-doctor'),
                    implode('; ', array_slice($skips, 0, 5))
                ),
                'data' => ['skips' => $skips],
            ];
        }

        // Empty headings confuse both readers and crawlers.
        $empty = array_filter($c->headings, static fn($h) => trim($h['text']) === '');
        if ($empty) {
            $issues[] = [
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: %d: number of empty headings */
                    __('%d heading(s) contain no text.', 'wp-seo-doctor'),
                    count($empty)
                ),
            ];
        }

        return $issues;
    }

    public static function check_keyword_usage(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $keyword = trim($c->focus_keyword);
        if ($keyword === '') {
            return [[
                'severity' => 'low',
                'message'  => __('No focus keyword is set, so keyword usage cannot be evaluated.', 'wp-seo-doctor'),
            ]];
        }

        $needle  = strtolower($keyword);
        $issues  = [];
        $missing = [];

        if (strpos(strtolower($c->seo_title), $needle) === false) {
            $missing[] = __('SEO title', 'wp-seo-doctor');
        }
        if ($c->seo_description !== '' && strpos(strtolower($c->seo_description), $needle) === false) {
            $missing[] = __('meta description', 'wp-seo-doctor');
        }

        // "First paragraph" ≈ the opening 150 words of visible text.
        $opening = mb_substr($c->text, 0, 800);
        if (strpos(strtolower($opening), $needle) === false) {
            $missing[] = __('opening paragraph', 'wp-seo-doctor');
        }

        $in_subhead = false;
        foreach ($c->headings as $heading) {
            if ($heading['level'] >= 2 && strpos(strtolower($heading['text']), $needle) !== false) {
                $in_subhead = true;
                break;
            }
        }
        if (!$in_subhead && count($c->headings) > 0) {
            $missing[] = __('any subheading', 'wp-seo-doctor');
        }

        if ($missing) {
            $issues[] = [
                'severity' => count($missing) >= 3 ? 'medium' : 'low',
                'message'  => sprintf(
                    /* translators: 1: focus keyword, 2: list of places it is missing */
                    __('Focus keyword "%1$s" is missing from: %2$s.', 'wp-seo-doctor'),
                    $keyword,
                    implode(', ', $missing)
                ),
                'data' => ['keyword' => $keyword, 'missing' => $missing],
            ];
        }

        $density = WPSD_Helpers::keyword_density($c->content, $keyword);
        $max     = (float) WPSD_Settings::get('keyword_density_max', 3.0);
        $min     = (float) WPSD_Settings::get('keyword_density_min', 0.5);

        if ($density > $max) {
            $issues[] = [
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: 1: density percentage, 2: maximum percentage */
                    __('Keyword density is %1$s%% (above the %2$s%% ceiling) — this reads as keyword stuffing.', 'wp-seo-doctor'),
                    $density,
                    $max
                ),
                'data' => ['density' => $density],
            ];
        } elseif ($density < $min && $c->word_count >= 300) {
            $issues[] = [
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: 1: density percentage, 2: minimum percentage */
                    __('Keyword density is %1$s%% (below the %2$s%% floor).', 'wp-seo-doctor'),
                    $density,
                    $min
                ),
                'data' => ['density' => $density],
            ];
        }

        return $issues;
    }

    public static function check_content_length(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $min  = (int) WPSD_Settings::get('content_min_words', 300);
        $thin = (int) WPSD_Settings::get('thin_content_words', 200);

        // Anything below the thin threshold is reported by thin_content instead.
        if ($c->word_count >= $min || $c->word_count < $thin) {
            return [];
        }

        return [[
            'severity' => 'low',
            'message'  => sprintf(
                /* translators: 1: word count, 2: recommended minimum */
                __('%1$d words — below the recommended %2$d for a competitive page.', 'wp-seo-doctor'),
                $c->word_count,
                $min
            ),
            'data' => ['words' => $c->word_count],
        ]];
    }

    public static function check_thin_content(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $thin = (int) WPSD_Settings::get('thin_content_words', 200);
        if ($c->word_count >= $thin) {
            return [];
        }

        // A noindexed page is already excluded from search, so it is not urgent.
        return [[
            'severity' => $c->noindex ? 'low' : 'high',
            'message'  => sprintf(
                /* translators: 1: word count, 2: thin-content threshold */
                __('Only %1$d words of content (thin-content threshold is %2$d).', 'wp-seo-doctor'),
                $c->word_count,
                $thin
            ),
            'data' => ['words' => $c->word_count],
        ]];
    }

    public static function check_image_alt(?WPSD_Context $c): array {
        if (!$c || !$c->images) {
            return [];
        }

        $missing = array_values(array_filter($c->images, static fn($img) => !$img['has_alt']));
        if (!$missing) {
            return [];
        }

        return [[
            'severity' => count($missing) > 3 ? 'medium' : 'low',
            'message'  => sprintf(
                /* translators: 1: images missing alt, 2: total images */
                __('%1$d of %2$d images have no ALT text.', 'wp-seo-doctor'),
                count($missing),
                count($c->images)
            ),
            'data' => [
                'missing' => array_slice(wp_list_pluck($missing, 'src'), 0, 20),
                'total'   => count($c->images),
            ],
        ]];
    }

    public static function check_duplicate_title(?WPSD_Context $c): array {
        if (!$c || trim($c->seo_title) === '') {
            return [];
        }

        $index = self::duplicate_index();
        $key   = md5(strtolower(trim($c->seo_title)));
        $ids   = $index['titles'][$key] ?? [];

        $others = array_values(array_diff($ids, [$c->id]));
        if (!$others) {
            return [];
        }

        return [[
            'severity' => 'high',
            'message'  => sprintf(
                /* translators: 1: title, 2: number of other pages */
                __('The title "%1$s" is also used by %2$d other page(s).', 'wp-seo-doctor'),
                WPSD_Helpers::truncate($c->seo_title, 60),
                count($others)
            ),
            'data' => ['duplicates' => self::describe_posts(array_slice($others, 0, 10))],
        ]];
    }

    public static function check_duplicate_description(?WPSD_Context $c): array {
        if (!$c || trim($c->seo_description) === '' || $c->seo_description_source === 'excerpt') {
            return [];
        }

        $index = self::duplicate_index();
        $key   = md5(strtolower(trim($c->seo_description)));
        $ids   = $index['descriptions'][$key] ?? [];

        $others = array_values(array_diff($ids, [$c->id]));
        if (!$others) {
            return [];
        }

        return [[
            'severity' => 'medium',
            'message'  => sprintf(
                /* translators: %d: number of other pages */
                __('This meta description is also used by %d other page(s).', 'wp-seo-doctor'),
                count($others)
            ),
            'data' => ['duplicates' => self::describe_posts(array_slice($others, 0, 10))],
        ]];
    }

    public static function check_canonical(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $canonical = trim($c->canonical);

        // No explicit canonical is fine — WordPress emits a self-referencing one.
        if ($canonical === '') {
            return [];
        }

        if (!filter_var($canonical, FILTER_VALIDATE_URL)) {
            return [[
                'severity' => 'critical',
                'message'  => sprintf(
                    /* translators: %s: canonical value */
                    __('The canonical URL "%s" is not a valid absolute URL.', 'wp-seo-doctor'),
                    WPSD_Helpers::truncate($canonical, 80)
                ),
                'data' => ['canonical' => $canonical],
            ]];
        }

        $self = WPSD_Helpers::normalize_url($c->url);
        $to   = WPSD_Helpers::normalize_url($canonical);
        if ($self === $to) {
            return [];
        }

        if (!WPSD_Helpers::is_internal_url($canonical)) {
            return [[
                'severity' => 'critical',
                'message'  => sprintf(
                    /* translators: %s: canonical URL */
                    __('The canonical points to an external domain (%s), which de-indexes this page.', 'wp-seo-doctor'),
                    WPSD_Helpers::truncate($canonical, 80)
                ),
                'data' => ['canonical' => $canonical],
            ]];
        }

        return [[
            'severity' => 'medium',
            'message'  => sprintf(
                /* translators: %s: canonical URL */
                __('The canonical points to a different page (%s). Confirm this is intentional.', 'wp-seo-doctor'),
                WPSD_Helpers::truncate($canonical, 80)
            ),
            'data' => ['canonical' => $canonical],
        ]];
    }

    public static function check_noindex(?WPSD_Context $c): array {
        if (!$c || !$c->noindex) {
            return [];
        }
        if ($c->post->post_status !== 'publish') {
            return [];
        }

        return [[
            'severity' => 'critical',
            'message'  => __('This published page is set to noindex and cannot appear in search results.', 'wp-seo-doctor'),
        ]];
    }

    public static function check_nofollow(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $nofollowed = array_values(array_filter($c->internal_links, static fn($l) => $l['nofollow']));
        if (!$nofollowed) {
            return [];
        }

        return [[
            'severity' => 'medium',
            'message'  => sprintf(
                /* translators: %d: number of nofollowed internal links */
                __('%d internal link(s) are marked rel="nofollow", blocking internal link equity.', 'wp-seo-doctor'),
                count($nofollowed)
            ),
            'data' => ['links' => array_slice(wp_list_pluck($nofollowed, 'url'), 0, 20)],
        ]];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Map of title-hash => [post ids] and description-hash => [post ids].
     *
     * Built once and cached; a scan touches every post, so recomputing this
     * per post would be O(n²).
     *
     * @return array{titles:array<string,array<int,int>>, descriptions:array<string,array<int,int>>}
     */
    public static function duplicate_index(bool $force = false): array {
        static $cache = null;
        if ($cache !== null && !$force) {
            return $cache;
        }

        if (!$force) {
            $stored = get_transient(self::DUPLICATE_TRANSIENT);
            if (is_array($stored) && isset($stored['titles'])) {
                return $cache = $stored;
            }
        }

        global $wpdb;
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        $sql = "SELECT ID FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ({$placeholders})
                ORDER BY ID ASC
                LIMIT 20000";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, $types));

        $index = ['titles' => [], 'descriptions' => []];
        foreach ((array) $ids as $id) {
            $id = (int) $id;

            $title = WPSD_Helpers::get_seo_title($id)['value'];
            if (trim($title) !== '') {
                $index['titles'][md5(strtolower(trim($title)))][] = $id;
            }

            $description = WPSD_Helpers::get_seo_description($id);
            // Excerpt fallbacks are not authored descriptions; do not flag them.
            if ($description['source'] !== 'excerpt' && trim($description['value']) !== '') {
                $index['descriptions'][md5(strtolower(trim($description['value'])))][] = $id;
            }
        }

        // Only duplicates matter — drop unique entries to keep the cache small.
        $index['titles']       = array_filter($index['titles'], static fn($v) => count($v) > 1);
        $index['descriptions'] = array_filter($index['descriptions'], static fn($v) => count($v) > 1);

        set_transient(self::DUPLICATE_TRANSIENT, $index, HOUR_IN_SECONDS);

        return $cache = $index;
    }

    public static function flush_duplicate_index(): void {
        delete_transient(self::DUPLICATE_TRANSIENT);
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array{id:int,title:string,url:string}>
     */
    private static function describe_posts(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id'    => (int) $id,
                'title' => (string) get_the_title((int) $id),
                'url'   => (string) get_permalink((int) $id),
            ];
        }
        return $out;
    }
}
