<?php
/**
 * Check registry and the per-object analysis context.
 *
 * A "check" is a small callable that inspects one object (a post, a product,
 * or the site itself) and returns zero or more issues. Everything the audit
 * reports flows through here.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

/**
 * Everything a check needs about one post, parsed exactly once.
 */
class WPSD_Context {

    /** @var WP_Post */
    public $post;

    public int $id = 0;
    public string $url = '';
    public string $content = '';
    public string $text = '';
    public int $word_count = 0;

    public string $seo_title = '';
    public string $seo_title_source = '';
    public string $seo_description = '';
    public string $seo_description_source = '';
    public string $focus_keyword = '';
    public string $canonical = '';
    public bool $noindex = false;

    /** @var array<int,array{level:int,text:string}> */
    public array $headings = [];
    /** @var array<int,array{src:string,alt:string,has_alt:bool,title:string}> */
    public array $images = [];
    /** @var array<int,array{url:string,raw:string,anchor:string,rel:string,title:string,nofollow:bool}> */
    public array $links = [];
    /** @var array<int,array<string,mixed>> */
    public array $internal_links = [];
    /** @var array<int,array<string,mixed>> */
    public array $external_links = [];

    /** Scratch space shared between checks within one scan (e.g. duplicate maps). */
    public array $shared = [];

    public function __construct(WP_Post $post, array $shared = []) {
        $this->post   = $post;
        $this->id     = (int) $post->ID;
        $this->url    = (string) get_permalink($post);
        $this->shared = $shared;

        $this->content    = WPSD_Helpers::rendered_content($post);
        $this->text       = WPSD_Helpers::plain_text($this->content);
        $this->word_count = WPSD_Helpers::word_count($this->content);

        $title                        = WPSD_Helpers::get_seo_title($this->id);
        $this->seo_title              = $title['value'];
        $this->seo_title_source       = $title['source'];
        $description                  = WPSD_Helpers::get_seo_description($this->id);
        $this->seo_description        = $description['value'];
        $this->seo_description_source = $description['source'];

        $this->focus_keyword = WPSD_Helpers::get_focus_keyword($this->id);
        $this->canonical     = WPSD_Helpers::get_canonical($this->id);
        $this->noindex       = WPSD_Helpers::is_noindex($this->id);

        $this->headings = WPSD_Helpers::extract_headings($this->content);
        $this->images   = WPSD_Helpers::extract_images($this->content, $this->url);
        $this->links    = WPSD_Helpers::extract_links($this->content, $this->url);

        foreach ($this->links as $link) {
            if (WPSD_Helpers::is_internal_url($link['url'])) {
                $this->internal_links[] = $link;
            } else {
                $this->external_links[] = $link;
            }
        }
    }

    /** @var array{status:int,body:string,location:string,error:string}|null */
    private ?array $remote = null;

    /**
     * Fetch the live page once per context and share the response between the
     * technical checks that need real HTML (status, schema, Open Graph…).
     *
     * @return array{status:int,body:string,location:string,error:string}|null
     *         Null when live fetching is disabled in settings.
     */
    public function remote(): ?array {
        if (!WPSD_Settings::get('check_http_status', true)) {
            return null;
        }
        if ($this->remote === null) {
            $this->remote = WPSD_Helpers::request($this->url, [
                'method'      => 'GET',
                'redirection' => 0,
            ]);
        }
        return $this->remote;
    }

    /** The contents of the live page's <head>, or '' when unavailable. */
    public function head_html(): string {
        $remote = $this->remote();
        if (!$remote || $remote['body'] === '') {
            return '';
        }
        if (preg_match('#<head\b[^>]*>(.*?)</head>#is', $remote['body'], $m)) {
            return $m[1];
        }
        return $remote['body'];
    }

    /** @return array<int,array{level:int,text:string}> */
    public function headings_of_level(int $level): array {
        return array_values(array_filter($this->headings, static fn($h) => $h['level'] === $level));
    }

    public function is_product(): bool {
        return $this->post->post_type === 'product';
    }
}

class WPSD_Checks {

    /** @var array<string,array<string,mixed>> */
    private static array $checks = [];

    private static bool $booted = false;

    public static function init(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        WPSD_Checks_OnPage::register();
        WPSD_Checks_Technical::register();
        WPSD_Checks_Content::register();
        WPSD_Checks_Links::register();
        WPSD_Checks_Affiliate::register();
        WPSD_Checks_WooCommerce::register();

        /**
         * Register additional checks.
         *
         * @param string $class WPSD_Checks class name, for calling ::add().
         */
        do_action('wpsd_register_checks');
    }

    /**
     * Register a check.
     *
     * @param array{
     *   id:string, group:string, title:string, scope?:string,
     *   severity?:string, description?:string, recommendation?:string,
     *   callback:callable, enabled?:bool
     * } $check
     */
    public static function add(array $check): void {
        if (empty($check['id']) || empty($check['callback']) || !is_callable($check['callback'])) {
            return;
        }

        self::$checks[$check['id']] = wp_parse_args($check, [
            'group'          => 'onpage',
            'scope'          => 'post',   // post | site
            'severity'       => 'medium',
            'title'          => $check['id'],
            'description'    => '',
            'recommendation' => '',
            'enabled'        => true,
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array {
        self::init();
        return array_filter(self::$checks, static fn($c) => !empty($c['enabled']));
    }

    /** @return array<string,array<string,mixed>> */
    public static function in_group(string $group): array {
        return array_filter(self::all(), static fn($c) => $c['group'] === $group);
    }

    /** @return array<string,array<string,mixed>> */
    public static function in_scope(string $scope): array {
        return array_filter(self::all(), static fn($c) => $c['scope'] === $scope);
    }

    public static function get(string $id): ?array {
        $all = self::all();
        return $all[$id] ?? null;
    }

    /** @return array<string,string> */
    public static function groups(): array {
        return [
            'onpage'    => __('On-Page SEO', 'wp-seo-doctor'),
            'technical' => __('Technical SEO', 'wp-seo-doctor'),
            'content'   => __('Content SEO', 'wp-seo-doctor'),
            'links'     => __('Internal Linking', 'wp-seo-doctor'),
            'woo'       => __('WooCommerce SEO', 'wp-seo-doctor'),
            'affiliate' => __('Affiliate SEO', 'wp-seo-doctor'),
        ];
    }

    /**
     * Run every post-scoped check against one context.
     *
     * @param array<string,mixed> $shared Scan-wide scratch data.
     * @return array{issues:array<int,array<string,mixed>>, passed:int}
     */
    public static function run_post_checks(WPSD_Context $context, int $scan_id = 0): array {
        $issues = [];
        $passed = 0;

        foreach (self::in_scope('post') as $id => $check) {
            // WooCommerce checks only apply to products.
            if ($check['group'] === 'woo' && !$context->is_product()) {
                continue;
            }

            $result = self::invoke($check, $context);
            if (!$result) {
                $passed++;
                continue;
            }

            foreach ($result as $issue) {
                $issues[] = self::normalize_issue($issue, $check, $scan_id, $context);
            }
        }

        return ['issues' => $issues, 'passed' => $passed];
    }

    /**
     * Run a single check against a context. Used by partial scans that only
     * cover some check groups.
     *
     * @param array<string,mixed> $check
     * @return array<int,array<string,mixed>> Empty when the check passes.
     */
    public static function run_post_checks_for(WPSD_Context $context, array $check, int $scan_id = 0): array {
        $issues = [];
        foreach (self::invoke($check, $context) as $issue) {
            $issues[] = self::normalize_issue($issue, $check, $scan_id, $context);
        }
        return $issues;
    }

    /**
     * Run site-scoped checks (sitemap, robots.txt, HTTPS, …).
     *
     * @return array{issues:array<int,array<string,mixed>>, passed:int}
     */
    public static function run_site_checks(int $scan_id = 0): array {
        $issues = [];
        $passed = 0;

        foreach (self::in_scope('site') as $id => $check) {
            $result = self::invoke($check, null);
            if (!$result) {
                $passed++;
                continue;
            }
            foreach ($result as $issue) {
                $issues[] = self::normalize_issue($issue, $check, $scan_id, null);
            }
        }

        return ['issues' => $issues, 'passed' => $passed];
    }

    /**
     * Call a check and coerce whatever it returns into a list of issue arrays.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function invoke(array $check, ?WPSD_Context $context): array {
        try {
            $result = call_user_func($check['callback'], $context);
        } catch (Throwable $e) {
            // One broken check must not take down the whole scan.
            return [[
                'severity' => 'low',
                'message'  => sprintf(
                    /* translators: 1: check id, 2: error message */
                    __('Check "%1$s" could not run: %2$s', 'wp-seo-doctor'),
                    $check['id'],
                    $e->getMessage()
                ),
            ]];
        }

        if ($result === null || $result === false || $result === true || $result === []) {
            return [];
        }
        // A single issue may be returned as a flat array.
        if (isset($result['message']) || isset($result['severity']) || isset($result['title'])) {
            return [$result];
        }
        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * Fill in the fields a check did not bother to set.
     *
     * @param array<string,mixed> $issue
     * @param array<string,mixed> $check
     * @return array<string,mixed>
     */
    private static function normalize_issue(array $issue, array $check, int $scan_id, ?WPSD_Context $context): array {
        return [
            'scan_id'        => $scan_id,
            'check_id'       => $check['id'],
            'check_group'    => $check['group'],
            'object_type'    => $context ? $context->post->post_type : 'site',
            'object_id'      => $context ? $context->id : 0,
            'url'            => $issue['url'] ?? ($context ? $context->url : home_url('/')),
            'severity'       => $issue['severity'] ?? $check['severity'],
            'title'          => $issue['title'] ?? $check['title'],
            'message'        => $issue['message'] ?? $check['description'],
            'recommendation' => $issue['recommendation'] ?? $check['recommendation'],
            'data'           => $issue['data'] ?? [],
        ];
    }
}
