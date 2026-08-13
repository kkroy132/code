<?php
/**
 * WooCommerce product SEO checks.
 *
 * All of these are scoped to the `product` post type; the registry skips the
 * whole group for anything else, and registration is skipped entirely when
 * WooCommerce is not active.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Checks_WooCommerce {

    public static function is_active(): bool {
        return class_exists('WooCommerce') || post_type_exists('product');
    }

    public static function register(): void {
        if (!self::is_active()) {
            return;
        }

        $add = ['WPSD_Checks', 'add'];

        call_user_func($add, [
            'id'             => 'product_title',
            'group'          => 'woo',
            'title'          => __('Product Title Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The product title is too short, too long, or duplicated across variants.', 'wp-seo-doctor'),
            'recommendation' => __('Use a descriptive title with brand, model and a key differentiator.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_title'],
        ]);

        call_user_func($add, [
            'id'             => 'product_meta',
            'group'          => 'woo',
            'title'          => __('Product Meta Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The product is missing a meta description or short description.', 'wp-seo-doctor'),
            'recommendation' => __('Write a short description that doubles as a compelling SERP snippet.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_meta'],
        ]);

        call_user_func($add, [
            'id'             => 'product_image_alt',
            'group'          => 'woo',
            'title'          => __('Product Image ALT Check', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('Product gallery images have no ALT text.', 'wp-seo-doctor'),
            'recommendation' => __('Describe each product image; image search is a real traffic source for commerce.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_image_alt'],
        ]);

        call_user_func($add, [
            'id'             => 'thin_product',
            'group'          => 'woo',
            'title'          => __('Thin Product Detection', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The product page has almost no unique description.', 'wp-seo-doctor'),
            'recommendation' => __('Replace manufacturer boilerplate with original copy covering use cases, specs and sizing.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_thin'],
        ]);

        call_user_func($add, [
            'id'             => 'product_internal_links',
            'group'          => 'woo',
            'title'          => __('Product Internal Linking', 'wp-seo-doctor'),
            'severity'       => 'medium',
            'description'    => __('The product is weakly linked from the rest of the store.', 'wp-seo-doctor'),
            'recommendation' => __('Add the product to a category page, related products, or a buying guide.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_internal_links'],
        ]);

        call_user_func($add, [
            'id'             => 'product_canonical',
            'group'          => 'woo',
            'title'          => __('Product Canonical Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('The product canonical is missing or points at a filtered/variant URL.', 'wp-seo-doctor'),
            'recommendation' => __('Canonicalise variants to the parent product URL.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_canonical'],
        ]);

        call_user_func($add, [
            'id'             => 'product_schema',
            'group'          => 'woo',
            'title'          => __('Product Schema Check', 'wp-seo-doctor'),
            'severity'       => 'high',
            'description'    => __('Product structured data is missing required fields.', 'wp-seo-doctor'),
            'recommendation' => __('Ensure Product schema includes name, image, price, availability and (where possible) reviews.', 'wp-seo-doctor'),
            'callback'       => [self::class, 'check_schema'],
        ]);
    }

    // ─────────────────────────────────────────────────────────── checks ──

    public static function check_title(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $title  = trim($c->seo_title);
        $length = mb_strlen($title);

        if ($length > 0 && $length < 20) {
            return [[
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: %d: title length */
                    __('Product title is only %d characters — add brand, model or a key attribute.', 'wp-seo-doctor'),
                    $length
                ),
            ]];
        }

        return [];
    }

    public static function check_meta(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $issues = [];

        if (trim($c->seo_description) === '') {
            $issues[] = [
                'severity' => 'high',
                'message'  => __('This product has no meta description.', 'wp-seo-doctor'),
            ];
        }

        // WooCommerce stores the short description in post_excerpt.
        if (trim((string) $c->post->post_excerpt) === '') {
            $issues[] = [
                'severity' => 'medium',
                'message'  => __('This product has no short description, which many themes show above the add-to-cart button.', 'wp-seo-doctor'),
            ];
        }

        return $issues;
    }

    public static function check_image_alt(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $attachment_ids = [];

        $thumbnail = get_post_thumbnail_id($c->id);
        if ($thumbnail) {
            $attachment_ids[] = (int) $thumbnail;
        }

        $gallery = (string) get_post_meta($c->id, '_product_image_gallery', true);
        if ($gallery !== '') {
            foreach (explode(',', $gallery) as $id) {
                $id = (int) trim($id);
                if ($id) {
                    $attachment_ids[] = $id;
                }
            }
        }

        if (!$attachment_ids) {
            return [[
                'severity' => 'medium',
                'message'  => __('This product has no featured image.', 'wp-seo-doctor'),
            ]];
        }

        $missing = [];
        foreach (array_unique($attachment_ids) as $id) {
            $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
            if (!is_string($alt) || trim($alt) === '') {
                $missing[] = $id;
            }
        }

        if (!$missing) {
            return [];
        }

        return [[
            'severity' => 'medium',
            'message'  => sprintf(
                /* translators: 1: images missing alt, 2: total product images */
                __('%1$d of %2$d product images have no ALT text.', 'wp-seo-doctor'),
                count($missing),
                count(array_unique($attachment_ids))
            ),
            'data' => ['attachment_ids' => array_slice($missing, 0, 20)],
        ]];
    }

    public static function check_thin(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        // Products legitimately run shorter than articles, so use a lower bar.
        $threshold = max(50, (int) round((int) WPSD_Settings::get('thin_content_words', 200) * 0.5));
        if ($c->word_count >= $threshold) {
            return [];
        }

        return [[
            'severity' => 'high',
            'message'  => sprintf(
                /* translators: 1: word count, 2: threshold */
                __('Product description is only %1$d words (threshold %2$d).', 'wp-seo-doctor'),
                $c->word_count,
                $threshold
            ),
            'data' => ['words' => $c->word_count],
        ]];
    }

    public static function check_internal_links(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $incoming = WPSD_Internal_Links::incoming_count($c->id);
        if ($incoming >= 2) {
            return [];
        }

        $terms = wp_get_post_terms($c->id, 'product_cat', ['fields' => 'ids']);
        $has_category = !is_wp_error($terms) && !empty($terms);

        return [[
            'severity' => $incoming === 0 ? 'high' : 'medium',
            'message'  => $incoming === 0
                ? __('No internal links point to this product.', 'wp-seo-doctor')
                : sprintf(
                    /* translators: %d: number of incoming links */
                    __('Only %d internal link points to this product.', 'wp-seo-doctor'),
                    $incoming
                ),
            'data' => [
                'incoming'     => $incoming,
                'has_category' => $has_category,
                'sources'      => WPSD_Internal_Links::suggest_sources($c->id, 5),
            ],
        ]];
    }

    public static function check_canonical(?WPSD_Context $c): array {
        if (!$c) {
            return [];
        }

        $canonical = trim($c->canonical);
        if ($canonical === '') {
            return [];
        }

        // Filtered/variant URLs must never be the canonical target.
        $query = (string) wp_parse_url($canonical, PHP_URL_QUERY);
        if ($query !== '' && preg_match('/(attribute_|filter_|orderby|min_price|max_price|add-to-cart)/i', $query)) {
            return [[
                'severity' => 'high',
                'message'  => sprintf(
                    /* translators: %s: canonical URL */
                    __('The canonical points at a filtered or variant URL (%s).', 'wp-seo-doctor'),
                    WPSD_Helpers::truncate($canonical, 70)
                ),
                'data' => ['canonical' => $canonical],
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

        $product = self::find_product_schema($remote['body']);
        if ($product === null) {
            // The generic schema check already reports "no Product schema".
            return [];
        }

        $required = ['name', 'image', 'offers'];
        $missing  = [];
        foreach ($required as $field) {
            if (empty($product[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($product['offers'])) {
            $offers = isset($product['offers'][0]) ? $product['offers'][0] : $product['offers'];
            if (is_array($offers)) {
                foreach (['price', 'priceCurrency', 'availability'] as $field) {
                    if (empty($offers[$field])) {
                        $missing[] = "offers.{$field}";
                    }
                }
            }
        }

        if (!$missing) {
            return [];
        }

        return [[
            'severity' => 'high',
            'message'  => sprintf(
                /* translators: %s: comma-separated field names */
                __('Product schema is missing: %s.', 'wp-seo-doctor'),
                implode(', ', $missing)
            ),
            'data' => ['missing' => $missing],
        ]];
    }

    // ────────────────────────────────────────────────────────── helpers ──

    /**
     * Locate the Product node inside a page's JSON-LD, including @graph.
     *
     * @return array<string,mixed>|null
     */
    private static function find_product_schema(string $html): ?array {
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            return null;
        }

        foreach ($blocks[1] as $json) {
            $decoded = json_decode(trim($json), true);
            if (!is_array($decoded)) {
                continue;
            }
            $found = self::search_for_type($decoded, 'Product');
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @return array<string,mixed>|null
     */
    private static function search_for_type(array $node, string $type): ?array {
        if (isset($node['@type'])) {
            $types = (array) $node['@type'];
            if (in_array($type, $types, true)) {
                return $node;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = self::search_for_type($value, $type);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
