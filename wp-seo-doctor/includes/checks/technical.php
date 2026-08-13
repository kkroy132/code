<?php
/**
 * Technical SEO checks.
 *
 * Site-scoped checks run once per scan; post-scoped checks run per URL and,
 * where they need real response headers, reuse the single fetch cached on the
 * context.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Checks_Technical {

    public static function register(): void {
        $add = ['WPSD_Checks', 'add'];

        // ── Site-scoped ──

        call_user_func($add, [
            'id'             => 'xml_sitemap',
            'group'          => 'technical',
            'scope'          => 'site',
            'title'          => __('XML Sitemap Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('No reachable XML sitemap was found.', 'wp-seo-doctor'),
            'recommendation' => __('Publish an XML sitemap and reference it from robots.txt.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_sitemap'],
        ]);

        call_user_func($add, [
            'id'             => 'robots_txt',
            'group'          => 'technical',
            'scope'          => 'site',
            'title'          => __('Robots.txt Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('robots.txt is missing, unreachable, or blocking the whole site.', 'wp-seo-doctor'),
            'recommendation' => __('Serve a robots.txt that allows crawling and points to your sitemap.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_robots'],
        ]);

        call_user_func($add, [
            'id'             => 'https',
            'group'          => 'technical',
            'scope'          => 'site',
            'title'          => __('HTTPS Check', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('The site is not served over HTTPS, or HTTP does not redirect to it.', 'wp-seo-doctor'),
            'recommendation' => __('Install a TLS certificate and 301-redirect all HTTP traffic to HTTPS.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_https'],
        ]);

        call_user_func($add, [
            'id'             => 'site_indexability',
            'group'          => 'technical',
            'scope'          => 'site',
            'title'          => __('Indexability Check', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('WordPress is configured to discourage search engines.', 'wp-seo-doctor'),
            'recommendation' => __('Uncheck "Discourage search engines from indexing this site" in Settings → Reading.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_site_indexability'],
        ]);

        call_user_func($add, [
            'id'             => 'pagination',
            'group'          => 'technical',
            'scope'          => 'site',
            'title'          => __('Pagination Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('Paginated archives are misconfigured.', 'wp-seo-doctor'),
            'recommendation' => __('Make sure page 2+ of archives is crawlable and self-canonicalising.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_pagination'],
        ]);

        // ── Post-scoped ──

        call_user_func($add, [
            'id'             => 'http_status',
            'group'          => 'technical',
            'title'          => __('HTTP Status Check', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('The URL does not return a healthy 200 response.', 'wp-seo-doctor'),
            'recommendation' => __('Fix the server error, or redirect the URL to a working page.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_http_status'],
        ]);

        call_user_func($add, [
            'id'             => 'not_found',
            'group'          => 'technical',
            'title'          => __('404 Detection', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('A published URL returns 404.', 'wp-seo-doctor'),
            'recommendation' => __('Restore the content or add a 301 redirect to the closest equivalent page.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_404'],
        ]);

        call_user_func($add, [
            'id'             => 'redirect_chain',
            'group'          => 'technical',
            'title'          => __('Redirect Chain Detection', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The URL redirects through more than one hop.', 'wp-seo-doctor'),
            'recommendation' => __('Point the first redirect straight at the final destination.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_redirect_chain'],
        ]);

        call_user_func($add, [
            'id'             => 'redirect_loop',
            'group'          => 'technical',
            'title'          => __('Redirect Loop Detection', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('The URL redirects back to itself.', 'wp-seo-doctor'),
            'recommendation' => __('Remove the circular redirect rule so the page can be reached.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_redirect_loop'],
        ]);

        call_user_func($add, [
            'id'             => 'indexability',
            'group'          => 'technical',
            'title'          => __('Indexability Check', 'wp-seo-doctor'),
            'severity'       => 'critical',
            'description'    => __('The page is blocked from indexing by a robots directive.', 'wp-seo-doctor'),
            'recommendation' => __('Remove the noindex/X-Robots-Tag directive if this page should rank.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_indexability'],
        ]);

        call_user_func($add, [
            'id'             => 'canonical_url',
            'group'          => 'technical',
            'title'          => __('Canonical URL Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The rendered canonical tag is missing, duplicated, or points at a broken URL.', 'wp-seo-doctor'),
            'recommendation' => __('Emit exactly one canonical tag pointing at a URL that returns 200.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_canonical_url'],
        ]);

        call_user_func($add, [
            'id'             => 'crawl_depth',
            'group'          => 'technical',
            'title'          => __('Crawl Depth Analysis', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('The page sits too many clicks from the homepage.', 'wp-seo-doctor'),
            'recommendation' => __('Link to this page from a hub page or the main navigation to lift it closer to the homepage.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_crawl_depth'],
        ]);

        call_user_func($add, [
            'id'             => 'schema',
            'group'          => 'technical',
            'title'          => __('Schema Detection', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('No structured data was found on the page.', 'wp-seo-doctor'),
            'recommendation' => __('Add JSON-LD structured data matching the page type (Article, Product, FAQ…).', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_schema'],
        ]);

        call_user_func($add, [
            'id'             => 'open_graph',
            'group'          => 'technical',
            'title'          => __('Open Graph Check', 'wp-seo-doctor'),
            'severity'       => 'low',
            'description'    => __('Open Graph tags are missing or incomplete.', 'wp-seo-doctor'),
            'recommendation' => __('Add og:title, og:description, og:image and og:url so shared links render properly.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_open_graph'],
        ]);
    }

    // ──────────────────────────────────────────────────── site checks ──

    public static function check_sitemap(): array {
        $candidates = array_unique(array_filter([
            get_option('wpsd_sitemap_url', ''),
            home_url('/sitemap_index.xml'),
            home_url('/sitemap.xml'),
            home_url('/wp-sitemap.xml'),
            home_url('/sitemap-index.xml'),
        ]));

        foreach ($candidates as $url) {
            $response = WPSD_Helpers::request($url, ['method' => 'GET']);
            if ($response['status'] === 200 && stripos($response['body'], '<urlset') !== false) {
                update_option('wpsd_sitemap_url', $url, false);
                return [];
            }
            if ($response['status'] === 200 && stripos($response['body'], '<sitemapindex') !== false) {
                update_option('wpsd_sitemap_url', $url, false);
                return [];
            }
        }

        return [[
            'severity' => 'high',
            'message'  => __('No XML sitemap responded at any of the standard locations (/sitemap.xml, /sitemap_index.xml, /wp-sitemap.xml).', 'wp-seo-doctor'),
            'url'      => home_url('/sitemap.xml'),
            'data'     => ['tried' => array_values($candidates)],
        ]];
    }

    public static function check_robots(): array {
        $url      = home_url('/robots.txt');
        $response = WPSD_Helpers::request($url, ['method' => 'GET']);

        if ($response['status'] !== 200) {
            return [[
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: %d: HTTP status code */
                    __('robots.txt returned HTTP %d.', 'wp-seo-doctor'),
                    $response['status']
                ),
                'url' => $url,
            ]];
        }

        $body   = $response['body'];
        $issues = [];

        if (self::robots_blocks_everything($body)) {
            $issues[] = [
                'severity' => 'critical',
                'message'  => __('robots.txt contains "Disallow: /" for all user agents — the entire site is blocked from crawling.', 'wp-seo-doctor'),
                'url'      => $url,
            ];
        }

        if (stripos($body, 'sitemap:') === false) {
            $issues[] = [
                'severity' => 'low',
                'message'  => __('robots.txt does not declare a Sitemap: line.', 'wp-seo-doctor'),
                'url'      => $url,
            ];
        }

        return $issues;
    }

    public static function check_https(): array {
        $home = home_url('/');
        if (strpos($home, 'https://') !== 0) {
            return [[
                'severity' => 'critical',
                'message'  => __('The site URL is not HTTPS. HTTPS is a confirmed ranking signal and required for modern browser features.', 'wp-seo-doctor'),
                'url'      => $home,
            ]];
        }

        // HTTPS is on — confirm the HTTP version redirects rather than serving
        // a duplicate copy of the site.
        $http     = set_url_scheme($home, 'http');
        $response = WPSD_Helpers::request($http);
        $status   = $response['status'];

        if ($status === 200) {
            return [[
                'severity' => 'high',
                'message'  => __('The HTTP version of the site returns 200 instead of redirecting to HTTPS, creating duplicate content.', 'wp-seo-doctor'),
                'url'      => $http,
            ]];
        }

        if ($status >= 300 && $status < 400 && $status !== 301) {
            return [[
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: %d: HTTP status code */
                    __('HTTP redirects to HTTPS with a %d instead of a permanent 301.', 'wp-seo-doctor'),
                    $status
                ),
                'url' => $http,
            ]];
        }

        return [];
    }

    public static function check_site_indexability(): array {
        if ((string) get_option('blog_public') === '0') {
            return [[
                'severity' => 'critical',
                'message'  => __('Settings → Reading has "Discourage search engines" enabled. Nothing on this site can be indexed.', 'wp-seo-doctor'),
                'url'      => admin_url('options-reading.php'),
            ]];
        }
        return [];
    }

    public static function check_pagination(): array {
        $per_page = (int) get_option('posts_per_page', 10);
        $total    = (int) wp_count_posts('post')->publish;
        if ($per_page <= 0 || $total <= $per_page) {
            return [];
        }

        $page_two = home_url('/page/2/');
        $response = WPSD_Helpers::request($page_two, ['method' => 'GET']);

        if ($response['status'] === 404) {
            return [[
                'severity' => 'high',
                'message'  => __('Page 2 of the blog archive returns 404, so older posts are unreachable by crawlers.', 'wp-seo-doctor'),
                'url'      => $page_two,
            ]];
        }
        if ($response['status'] !== 200) {
            return [[
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: %d: HTTP status code */
                    __('Page 2 of the blog archive returned HTTP %d.', 'wp-seo-doctor'),
                    $response['status']
                ),
                'url' => $page_two,
            ]];
        }

        // Page 2 canonicalising back to page 1 hides the paginated results.
        if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']#i', $response['body'], $m)) {
            $canonical = WPSD_Helpers::normalize_url($m[1]);
            if ($canonical === WPSD_Helpers::normalize_url(home_url('/'))) {
                return [[
                    'severity' => 'medium',
                    'message'  => __('Page 2 of the archive canonicalises to page 1, which hides its posts from search engines.', 'wp-seo-doctor'),
                    'url'      => $page_two,
                ]];
            }
        }

        return [];
    }

    // ──────────────────────────────────────────────────── post checks ──

    public static function check_http_status(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $remote = $c->remote();
        if (!$remote) {
            return [];
        }

        if ($remote['status'] === 0) {
            return [[
                'severity' => 'high',
                'message'  => sprintf(
                    /* translators: %s: error message */
                    __('The URL could not be fetched: %s', 'wp-seo-doctor'),
                    $remote['error']
                ),
            ]];
        }

        // 404s and redirects are reported by their own dedicated checks.
        if ($remote['status'] === 200 || $remote['status'] === 404 || ($remote['status'] >= 300 && $remote['status'] < 400)) {
            return [];
        }

        return [[
            'severity' => $remote['status'] >= 500 ? 'critical' : 'high',
            'message'  => sprintf(
                /* translators: %d: HTTP status code */
                __('The URL returns HTTP %d.', 'wp-seo-doctor'),
                $remote['status']
            ),
            'data' => ['status' => $remote['status']],
        ]];
    }

    public static function check_404(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $remote = $c->remote();
        if (!$remote || $remote['status'] !== 404) {
            return [];
        }

        return [[
            'severity' => 'critical',
            'message'  => __('This published URL returns 404 Not Found.', 'wp-seo-doctor'),
        ]];
    }

    public static function check_redirect_chain(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $remote = $c->remote();
        if (!$remote || $remote['status'] < 300 || $remote['status'] >= 400) {
            return [];
        }

        $trace = WPSD_Helpers::trace_redirects($c->url);
        $hops  = max(0, count($trace['chain']) - 1);

        if ($trace['loop'] || $hops < 2) {
            // A loop is reported separately; a single hop is normal.
            return [];
        }

        return [[
            'severity' => 'high',
            'message'  => sprintf(
                /* translators: 1: number of hops, 2: final URL */
                __('This URL redirects through %1$d hops before reaching %2$s.', 'wp-seo-doctor'),
                $hops,
                WPSD_Helpers::truncate($trace['final_url'], 70)
            ),
            'data' => ['chain' => $trace['chain'], 'final' => $trace['final_url']],
        ]];
    }

    public static function check_redirect_loop(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $remote = $c->remote();
        if (!$remote || $remote['status'] < 300 || $remote['status'] >= 400) {
            return [];
        }

        $trace = WPSD_Helpers::trace_redirects($c->url);
        if (!$trace['loop']) {
            return [];
        }

        return [[
            'severity' => 'critical',
            'message'  => __('This URL is caught in a redirect loop and can never be reached.', 'wp-seo-doctor'),
            'data'     => ['chain' => $trace['chain']],
        ]];
    }

    public static function check_indexability(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $remote = $c->remote();
        if (!$remote || $remote['status'] !== 200) {
            return [];
        }

        $issues = [];

        // X-Robots-Tag beats the meta tag and is easy to set accidentally at
        // the server level.
        $headers = $remote['headers'];
        $robots  = '';
        if (is_object($headers) && method_exists($headers, 'offsetGet')) {
            $robots = (string) ($headers['x-robots-tag'] ?? '');
        } elseif (is_array($headers)) {
            $robots = (string) ($headers['x-robots-tag'] ?? '');
        }
        if ($robots !== '' && stripos($robots, 'noindex') !== false) {
            $issues[] = [
                'severity' => 'critical',
                'message'  => sprintf(
                    /* translators: %s: header value */
                    __('The server sends X-Robots-Tag: %s, blocking this page from search.', 'wp-seo-doctor'),
                    $robots
                ),
            ];
        }

        $head = $c->head_html();
        if ($head !== '' && preg_match('#<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m)) {
            if (stripos($m[1], 'noindex') !== false && !$c->noindex) {
                $issues[] = [
                    'severity' => 'critical',
                    'message'  => sprintf(
                        /* translators: %s: robots meta content */
                        __('The rendered page emits <meta name="robots" content="%s">, blocking indexing.', 'wp-seo-doctor'),
                        $m[1]
                    ),
                ];
            }
        }

        return $issues;
    }

    public static function check_canonical_url(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $head = $c->head_html();
        if ($head === '') {
            return [];
        }

        preg_match_all('#<link[^>]+rel=["\']canonical["\'][^>]*>#i', $head, $tags);
        $count = count($tags[0]);

        if ($count === 0) {
            return [[
                'severity' => 'medium',
                'message'  => __('The rendered page has no canonical tag.', 'wp-seo-doctor'),
            ]];
        }

        if ($count > 1) {
            return [[
                'severity' => 'high',
                'message'  => sprintf(
                    /* translators: %d: number of canonical tags */
                    __('The page emits %d canonical tags. Search engines will ignore all of them.', 'wp-seo-doctor'),
                    $count
                ),
            ]];
        }

        if (!preg_match('#href=["\']([^"\']+)["\']#i', $tags[0][0], $m)) {
            return [];
        }

        $canonical = WPSD_Helpers::absolutize($m[1], $c->url);
        if (WPSD_Helpers::normalize_url($canonical) === WPSD_Helpers::normalize_url($c->url)) {
            return [];
        }

        // A canonical pointing at a dead or redirecting URL wastes the signal.
        $target = WPSD_Helpers::request($canonical);
        if ($target['status'] >= 400 || $target['status'] === 0) {
            return [[
                'severity' => 'critical',
                'message'  => sprintf(
                    /* translators: 1: canonical URL, 2: HTTP status */
                    __('The canonical points to %1$s which returns HTTP %2$d.', 'wp-seo-doctor'),
                    WPSD_Helpers::truncate($canonical, 70),
                    $target['status']
                ),
                'data' => ['canonical' => $canonical, 'status' => $target['status']],
            ]];
        }
        if ($target['status'] >= 300 && $target['status'] < 400) {
            return [[
                'severity' => 'high',
                'message'  => sprintf(
                    /* translators: %s: canonical URL */
                    __('The canonical points to %s, which itself redirects.', 'wp-seo-doctor'),
                    WPSD_Helpers::truncate($canonical, 70)
                ),
                'data' => ['canonical' => $canonical],
            ]];
        }

        return [];
    }

    public static function check_crawl_depth(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $depths = WPSD_Internal_Links::crawl_depths();
        if (!$depths) {
            return [];
        }

        $max   = (int) WPSD_Settings::get('max_crawl_depth', 4);
        $depth = $depths[$c->id] ?? null;

        if ($depth === null) {
            // Unreachable through internal links entirely — orphan territory.
            return [[
                'severity' => 'high',
                'message'  => __('This page cannot be reached from the homepage by following internal links.', 'wp-seo-doctor'),
                'data'     => ['depth' => null],
            ]];
        }

        if ($depth > $max) {
            return [[
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: 1: click depth, 2: configured maximum */
                    __('This page is %1$d clicks from the homepage (maximum recommended: %2$d).', 'wp-seo-doctor'),
                    $depth,
                    $max
                ),
                'data' => ['depth' => $depth],
            ]];
        }

        return [];
    }

    public static function check_schema(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $remote = $c->remote();
        if (!$remote || $remote['status'] !== 200 || $remote['body'] === '') {
            return [];
        }

        $body  = $remote['body'];
        $types = [];

        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $body, $blocks)) {
            foreach ($blocks[1] as $json) {
                $decoded = json_decode(trim($json), true);
                if (!is_array($decoded)) {
                    $types[] = 'invalid-json';
                    continue;
                }
                foreach (self::collect_schema_types($decoded) as $type) {
                    $types[] = $type;
                }
            }
        }

        // Microdata and RDFa still count as structured data.
        $has_microdata = (bool) preg_match('#itemscope[^>]*itemtype=["\']https?://schema\.org/#i', $body);

        if (in_array('invalid-json', $types, true)) {
            return [[
                'severity' => 'high',
                'message'  => __('The page contains a JSON-LD block that is not valid JSON, so search engines will discard it.', 'wp-seo-doctor'),
            ]];
        }

        $types = array_values(array_unique(array_filter($types)));

        if (!$types && !$has_microdata) {
            return [[
                'severity' => 'medium',
                'message'  => __('No structured data (JSON-LD, microdata or RDFa) was found on this page.', 'wp-seo-doctor'),
            ]];
        }

        // Products without Product schema lose rich results.
        if ($c->is_product() && !array_intersect(['Product', 'ProductGroup'], $types)) {
            return [[
                'severity' => 'high',
                'message'  => sprintf(
                    /* translators: %s: comma-separated schema types */
                    __('This product page has no Product schema (found: %s).', 'wp-seo-doctor'),
                    $types ? implode(', ', $types) : __('none', 'wp-seo-doctor')
                ),
                'data' => ['types' => $types],
            ]];
        }

        return [];
    }

    public static function check_open_graph(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }
        $head = $c->head_html();
        if ($head === '') {
            return [];
        }

        $required = ['og:title', 'og:description', 'og:image', 'og:url'];
        $missing  = [];

        foreach ($required as $property) {
            $pattern = '#<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '#') . '["\'][^>]+content=["\']([^"\']*)["\']#i';
            if (!preg_match($pattern, $head, $m) || trim($m[1]) === '') {
                $missing[] = $property;
            }
        }

        if (!$missing) {
            return [];
        }

        // All four missing usually means no social plugin at all — one signal,
        // not four.
        return [[
            'severity' => count($missing) === count($required) ? 'medium' : 'low',
            'message'  => sprintf(
                /* translators: %s: comma-separated list of missing tags */
                __('Missing Open Graph tags: %s.', 'wp-seo-doctor'),
                implode(', ', $missing)
            ),
            'data' => ['missing' => $missing],
        ]];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Does this robots.txt block the whole site for all crawlers?
     *
     * Parsed line by line rather than with one large pattern: directives are
     * grouped under the preceding User-agent line, comments and blank lines are
     * ignored, and only the wildcard group counts.
     */
    public static function robots_blocks_everything(string $body): bool {
        $in_wildcard_group = false;
        $blocked           = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) < 2) {
                continue;
            }

            $directive = strtolower(trim($parts[0]));
            // Strip trailing comments from the value.
            $value = trim(preg_replace('/\s+#.*$/', '', $parts[1]));

            if ($directive === 'user-agent') {
                $in_wildcard_group = ($value === '*');
                continue;
            }
            if (!$in_wildcard_group) {
                continue;
            }

            if ($directive === 'disallow' && $value === '/') {
                $blocked = true;
            }
            // A broad Allow re-opens the site, so the block is not total.
            if ($directive === 'allow' && ($value === '/' || $value === '')) {
                $blocked = false;
            }
        }

        return $blocked;
    }

    /**
     * Pull every @type out of a decoded JSON-LD document, including @graph.
     *
     * @param array<mixed> $node
     * @return array<int,string>
     */
    private static function collect_schema_types(array $node): array {
        $types = [];

        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value) && ($key === '@graph' || is_int($key))) {
                foreach ($value as $child) {
                    if (is_array($child)) {
                        $types = array_merge($types, self::collect_schema_types($child));
                    }
                }
            }
        }

        return $types;
    }
}
