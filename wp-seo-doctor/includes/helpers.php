<?php
/**
 * Shared utilities: URLs, HTTP, DOM parsing, text analysis.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Helpers {

    /** Severity ordering, highest first. Used for sorting and weighting. */
    const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * Weight each severity contributes to the health score deduction.
     */
    public static function severity_weight(string $severity): int {
        $weights = ['critical' => 10, 'high' => 5, 'medium' => 2, 'low' => 1];
        return $weights[$severity] ?? 1;
    }

    public static function severity_label(string $severity): string {
        $labels = [
            'critical' => __('Critical', 'wp-seo-doctor'),
            'high'     => __('High', 'wp-seo-doctor'),
            'medium'   => __('Medium', 'wp-seo-doctor'),
            'low'      => __('Low', 'wp-seo-doctor'),
        ];
        return $labels[$severity] ?? ucfirst($severity);
    }

    /** Host of this site, without www. */
    public static function site_host(): string {
        static $host = null;
        if ($host === null) {
            $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host);
        }
        return $host;
    }

    /**
     * Resolve a possibly-relative href against a base URL.
     */
    public static function absolutize(string $url, string $base = ''): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $url)) {
            return strpos($url, '//') === 0 ? 'https:' . $url : $url;
        }
        // Non-http schemes (mailto:, tel:, javascript:, #fragment) are not pages.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) || strpos($url, '#') === 0) {
            return $url;
        }

        $base = $base !== '' ? $base : home_url('/');
        $parts = wp_parse_url($base);
        if (empty($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (strpos($url, '/') === 0) {
            return $origin . $url;
        }

        $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
        return $origin . $path . $url;
    }

    /**
     * Normalise for comparison: lowercase host, strip www, strip fragment,
     * strip trailing slash, drop common tracking params.
     */
    public static function normalize_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return rtrim(strtok($url, '#'), '/');
        }

        $host   = preg_replace('/^www\./', '', strtolower($parts['host']));
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $path   = $parts['path'] ?? '/';
        $path   = $path === '' ? '/' : $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $args);
            foreach (array_keys($args) as $key) {
                if (preg_match('/^(utm_|fbclid|gclid|msclkid|mc_cid|mc_eid|ref|_ga)/i', $key)) {
                    unset($args[$key]);
                }
            }
            ksort($args);
            if ($args) {
                $query = '?' . http_build_query($args);
            }
        }

        return $scheme . '://' . $host . $path . $query;
    }

    /** Stable 32-char key for indexing long URLs. */
    public static function url_hash(string $url): string {
        return md5(self::normalize_url($url));
    }

    public static function is_internal_url(string $url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) {
            // Relative URLs are internal by definition.
            return !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) && strpos($url, '#') !== 0;
        }
        return preg_replace('/^www\./', '', strtolower($host)) === self::site_host();
    }

    /** True for mailto:, tel:, javascript:, data:, #anchor and friends. */
    public static function is_non_http(string $url): bool {
        if ($url === '' || strpos($url, '#') === 0) {
            return true;
        }
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        return $scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * HTTP request tuned for link checking. Never follows redirects so the
     * caller sees the real status code and Location header.
     */
    public static function request(string $url, array $args = []): array {
        $defaults = [
            'method'      => 'HEAD',
            'timeout'     => (int) WPSD_Settings::get('request_timeout', 10),
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'WP SEO Doctor/' . WPSD_VERSION . '; ' . home_url('/'),
            'headers'     => ['Accept' => '*/*'],
        ];
        $args = wp_parse_args($args, $defaults);

        $response = wp_remote_request($url, $args);

        // Some servers reject HEAD outright — retry once with a ranged GET.
        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($args['method'] === 'HEAD' && in_array($code, [400, 403, 405, 406, 501], true)) {
                $args['method']            = 'GET';
                $args['headers']['Range']  = 'bytes=0-2048';
                $response                  = wp_remote_request($url, $args);
            }
        }

        if (is_wp_error($response)) {
            return [
                'status'   => 0,
                'error'    => $response->get_error_message(),
                'location' => '',
                'body'     => '',
                'headers'  => [],
            ];
        }

        return [
            'status'   => (int) wp_remote_retrieve_response_code($response),
            'error'    => '',
            'location' => (string) wp_remote_retrieve_header($response, 'location'),
            'body'     => (string) wp_remote_retrieve_body($response),
            'headers'  => wp_remote_retrieve_headers($response),
        ];
    }

    /**
     * Follow a URL through redirects, returning every hop.
     *
     * @return array{chain:array<int,array{url:string,status:int}>, final_status:int, final_url:string, loop:bool}
     */
    public static function trace_redirects(string $url, int $max_hops = 8): array {
        $chain   = [];
        $seen    = [];
        $current = $url;
        $loop    = false;
        $status  = 0;

        for ($i = 0; $i < $max_hops; $i++) {
            $key = self::normalize_url($current);
            if (isset($seen[$key])) {
                $loop = true;
                break;
            }
            $seen[$key] = true;

            $res     = self::request($current);
            $status  = $res['status'];
            $chain[] = ['url' => $current, 'status' => $status];

            if ($status >= 300 && $status < 400 && $res['location'] !== '') {
                $current = self::absolutize($res['location'], $current);
                continue;
            }
            break;
        }

        return [
            'chain'        => $chain,
            'final_status' => $status,
            'final_url'    => $current,
            'loop'         => $loop,
        ];
    }

    /**
     * Parse HTML into a DOMDocument without spraying warnings.
     */
    public static function dom(string $html): ?DOMDocument {
        if (trim($html) === '') {
            return null;
        }
        if (!class_exists('DOMDocument')) {
            return null;
        }

        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // The meta charset keeps DOMDocument from mangling UTF-8 content.
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $doc;
    }

    /**
     * Extract anchors from a blob of HTML.
     *
     * @return array<int,array{url:string,anchor:string,rel:string,title:string,nofollow:bool}>
     */
    public static function extract_links(string $html, string $base = ''): array {
        $doc = self::dom($html);
        if (!$doc) {
            return [];
        }

        $links = [];
        foreach ($doc->getElementsByTagName('a') as $node) {
            /** @var DOMElement $node */
            $href = trim((string) $node->getAttribute('href'));
            if ($href === '' || self::is_non_http($href)) {
                continue;
            }
            $rel     = strtolower(trim((string) $node->getAttribute('rel')));
            $links[] = [
                'url'      => self::absolutize($href, $base),
                'raw'      => $href,
                'anchor'   => trim(preg_replace('/\s+/', ' ', (string) $node->textContent)),
                'rel'      => $rel,
                'title'    => trim((string) $node->getAttribute('title')),
                'nofollow' => strpos($rel, 'nofollow') !== false,
            ];
        }

        return $links;
    }

    /**
     * @return array<int,array{level:int,text:string}>
     */
    public static function extract_headings(string $html): array {
        $doc = self::dom($html);
        if (!$doc) {
            return [];
        }

        $headings = [];
        $xpath    = new DOMXPath($doc);
        $nodes    = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        if ($nodes) {
            foreach ($nodes as $node) {
                $headings[] = [
                    'level' => (int) substr($node->nodeName, 1),
                    'text'  => trim(preg_replace('/\s+/', ' ', (string) $node->textContent)),
                ];
            }
        }

        return $headings;
    }

    /**
     * @return array<int,array{src:string,alt:string,has_alt:bool,title:string}>
     */
    public static function extract_images(string $html, string $base = ''): array {
        $doc = self::dom($html);
        if (!$doc) {
            return [];
        }

        $images = [];
        foreach ($doc->getElementsByTagName('img') as $node) {
            /** @var DOMElement $node */
            $src = trim((string) $node->getAttribute('src'));
            if ($src === '' && $node->hasAttribute('data-src')) {
                $src = trim((string) $node->getAttribute('data-src'));
            }
            $images[] = [
                'src'     => $src !== '' ? self::absolutize($src, $base) : '',
                'alt'     => trim((string) $node->getAttribute('alt')),
                'has_alt' => $node->hasAttribute('alt') && trim((string) $node->getAttribute('alt')) !== '',
                'title'   => trim((string) $node->getAttribute('title')),
            ];
        }

        return $images;
    }

    /** Visible text only — shortcodes, blocks, scripts and tags removed. */
    public static function plain_text(string $content): string {
        $content = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $content);
        $content = strip_shortcodes((string) $content);
        $content = wp_strip_all_tags((string) $content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $content));
    }

    public static function word_count(string $content): int {
        $text = self::plain_text($content);
        if ($text === '') {
            return 0;
        }
        // Counts CJK characters individually since they are not space-delimited.
        $cjk   = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text);
        $words = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text);
        return (int) max($cjk, $words);
    }

    /** Rough keyword density as a percentage of total words. */
    public static function keyword_density(string $content, string $keyword): float {
        $keyword = trim(strtolower($keyword));
        if ($keyword === '') {
            return 0.0;
        }
        $text  = strtolower(self::plain_text($content));
        $total = self::word_count($content);
        if ($total === 0) {
            return 0.0;
        }
        $hits = substr_count($text, $keyword);
        $kw_words = max(1, count(preg_split('/\s+/', $keyword)));
        return round(($hits * $kw_words / $total) * 100, 2);
    }

    /**
     * Jaccard similarity over word shingles. Cheap, and good enough to flag
     * duplicate-looking content without an external service.
     */
    public static function similarity(string $a, string $b, int $shingle = 4): float {
        $sa = self::shingles($a, $shingle);
        $sb = self::shingles($b, $shingle);
        if (!$sa || !$sb) {
            return 0.0;
        }
        $intersect = count(array_intersect_key($sa, $sb));
        $union     = count($sa + $sb);
        return $union > 0 ? round($intersect / $union, 4) : 0.0;
    }

    /** @return array<string,bool> */
    public static function shingles(string $text, int $size = 4): array {
        $words = preg_split('/\s+/u', strtolower(self::plain_text($text)), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || count($words) < $size) {
            return [];
        }
        $out = [];
        $max = count($words) - $size;
        for ($i = 0; $i <= $max; $i++) {
            $out[md5(implode(' ', array_slice($words, $i, $size)))] = true;
        }
        return $out;
    }

    /** Meaningful terms only, for anchor-text and keyword suggestions. */
    public static function keywords_from_text(string $text, int $limit = 12): array {
        $stop = self::stopwords();
        $words = preg_split('/[^\p{L}\p{N}\'’\-]+/u', strtolower(self::plain_text($text)), -1, PREG_SPLIT_NO_EMPTY);
        $freq = [];
        foreach ((array) $words as $word) {
            if (mb_strlen($word) < 4 || isset($stop[$word])) {
                continue;
            }
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }
        arsort($freq);
        return array_slice($freq, 0, $limit, true);
    }

    /** @return array<string,bool> */
    public static function stopwords(): array {
        static $stop = null;
        if ($stop === null) {
            $list = 'about above after again against all also and any are because been before being below between both but came can come could did does doing down during each few for from further had has have having her here hers herself him himself his how into its itself just like made make many more most much must myself never now off once only other ought our ours ourselves out over own said same should since some such than that the their theirs them themselves then there these they this those through too under until very was way well were what when where which while who whom why will with would you your yours yourself yourselves';
            $stop = array_fill_keys(explode(' ', $list), true);
        }
        return $stop;
    }

    /** Post types the plugin audits, filtered by settings. */
    public static function auditable_post_types(): array {
        $configured = (array) WPSD_Settings::get('post_types', ['post', 'page']);

        /**
         * Filter the post types considered auditable before they are checked
         * against the public post-type list. WooCommerce uses this to add
         * `product` on activation.
         *
         * @param array<int,string> $configured
         */
        $configured = (array) apply_filters('wpsd_default_post_types', $configured);

        $public = get_post_types(['public' => true], 'names');
        $types  = array_values(array_intersect($configured, array_keys($public)));

        return $types ?: ['post', 'page'];
    }

    /**
     * The SEO title for a post, honouring Yoast / Rank Math / AIOSEO / SEOPress
     * meta when present, otherwise the post title.
     *
     * @return array{value:string,source:string}
     */
    public static function get_seo_title(int $post_id): array {
        $keys = [
            '_yoast_wpseo_title'  => 'Yoast',
            'rank_math_title'     => 'Rank Math',
            '_aioseo_title'       => 'AIOSEO',
            '_seopress_titles_title' => 'SEOPress',
            '_wpsd_title'         => 'WP SEO Doctor',
        ];
        foreach ($keys as $key => $source) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return ['value' => self::expand_title_vars(trim($value), $post_id), 'source' => $source];
            }
        }
        return ['value' => get_the_title($post_id), 'source' => 'post_title'];
    }

    /**
     * @return array{value:string,source:string}
     */
    public static function get_seo_description(int $post_id): array {
        $keys = [
            '_yoast_wpseo_metadesc'     => 'Yoast',
            'rank_math_description'     => 'Rank Math',
            '_aioseo_description'       => 'AIOSEO',
            '_seopress_titles_desc'     => 'SEOPress',
            '_wpsd_description'         => 'WP SEO Doctor',
        ];
        foreach ($keys as $key => $source) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return ['value' => self::expand_title_vars(trim($value), $post_id), 'source' => $source];
            }
        }
        $excerpt = get_the_excerpt($post_id);
        return ['value' => is_string($excerpt) ? trim($excerpt) : '', 'source' => 'excerpt'];
    }

    /** Resolve the handful of template variables the major SEO plugins share. */
    public static function expand_title_vars(string $template, int $post_id): string {
        $replacements = [
            '%%title%%'     => get_the_title($post_id),
            '%title%'       => get_the_title($post_id),
            '%%sitename%%'  => get_bloginfo('name'),
            '%sitename%'    => get_bloginfo('name'),
            '%%sep%%'       => '-',
            '%sep%'         => '-',
            '%%page%%'      => '',
            '%%excerpt%%'   => wp_strip_all_tags((string) get_the_excerpt($post_id)),
        ];
        $out = strtr($template, $replacements);
        return trim(preg_replace('/\s{2,}/', ' ', $out));
    }

    /** Focus keyword from whichever SEO plugin set one. */
    public static function get_focus_keyword(int $post_id): string {
        foreach (['_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_aioseo_keywords', '_wpsd_focus_keyword'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && trim($value) !== '') {
                // Rank Math stores a comma-separated list; the first is primary.
                return trim(explode(',', $value)[0]);
            }
        }
        return '';
    }

    public static function is_noindex(int $post_id): bool {
        if ((string) get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
            return true;
        }
        if ((string) get_post_meta($post_id, 'rank_math_robots', true) !== '') {
            $robots = maybe_unserialize(get_post_meta($post_id, 'rank_math_robots', true));
            if (is_array($robots) && in_array('noindex', $robots, true)) {
                return true;
            }
        }
        if ((string) get_post_meta($post_id, '_seopress_robots_index', true) === 'yes') {
            return true;
        }
        if ((string) get_post_meta($post_id, '_wpsd_noindex', true) === '1') {
            return true;
        }
        return false;
    }

    public static function get_canonical(int $post_id): string {
        foreach (['_yoast_wpseo_canonical', 'rank_math_canonical_url', '_aioseo_canonical_url', '_seopress_robots_canonical'] as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    /** Rendered post content with blocks and shortcodes expanded. */
    public static function rendered_content(WP_Post $post): string {
        static $cache = [];
        if (isset($cache[$post->ID])) {
            return $cache[$post->ID];
        }

        $content = $post->post_content;

        // Expand blocks and shortcodes rather than running the full `the_content`
        // filter chain — third-party filters outside the loop are a common source
        // of fatals, and this gives us the markup the checks actually need.
        if (function_exists('has_blocks') && has_blocks($content)) {
            $content = do_blocks($content);
        }
        try {
            $content = do_shortcode($content);
        } catch (Throwable $e) {
            // A misbehaving shortcode should not abort a scan.
            $content = $post->post_content;
        }
        $content = wpautop($content);

        /**
         * Filter the content WP SEO Doctor analyses for a post.
         *
         * @param string  $content Rendered content.
         * @param WP_Post $post    Post being analysed.
         */
        $content = (string) apply_filters('wpsd_rendered_content', $content, $post);

        if (count($cache) > 200) {
            $cache = [];
        }
        return $cache[$post->ID] = $content;
    }

    public static function truncate(string $text, int $length = 80): string {
        $text = trim($text);
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    /** Pixel-ish width estimate for SERP titles — proportional font approximation. */
    public static function pixel_width(string $text): int {
        $narrow = strlen(preg_replace('/[^iljtfrI\.\,\;\:\!\|\(\)\[\]\' ]/', '', $text));
        $wide   = strlen(preg_replace('/[^mwMWQ@]/', '', $text));
        $rest   = max(0, mb_strlen($text) - $narrow - $wide);
        return (int) round($narrow * 4.2 + $wide * 12.5 + $rest * 8.2);
    }

    public static function now(): string {
        return current_time('mysql');
    }

    /** Client IP, anonymised to /24 so we do not store full addresses. */
    public static function client_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        $parts = explode('.', $ip);
        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0" : '';
    }
}
