<?php
/**
 * WooCommerce SEO reporting.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_WooCommerce_SEO {

    public static function init(): void {
        if (!self::is_active()) {
            return;
        }
        // Products are auditable out of the box once WooCommerce is present.
        add_filter('wpsd_default_post_types', [self::class, 'add_product_type']);
    }

    public static function is_active(): bool {
        return WPSD_Checks_WooCommerce::is_active();
    }

    /**
     * @param array<int,string> $types
     * @return array<int,string>
     */
    public static function add_product_type(array $types): array {
        if (!in_array('product', $types, true)) {
            $types[] = 'product';
        }
        return $types;
    }

    /**
     * Per-product audit rows for the WooCommerce screen.
     *
     * @param array<string,mixed> $args
     * @return array{rows:array<int,array<string,mixed>>, total:int, pages:int}
     */
    public static function audit(array $args = []): array {
        global $wpdb;

        if (!self::is_active()) {
            return ['rows' => [], 'total' => 0, 'pages' => 0];
        }

        $args = wp_parse_args($args, [
            'per_page' => 25,
            'page'     => 1,
            'filter'   => '',   // thin|no_meta|no_alt|orphan
            'search'   => '',
        ]);

        $per_page  = max(1, (int) $args['per_page']);
        $offset    = (max(1, (int) $args['page']) - 1) * $per_page;
        $filtering = $args['filter'] !== '';

        $where  = ["post_type = 'product'", "post_status = 'publish'"];
        $params = [];

        if ($args['search'] !== '') {
            $where[]  = 'post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where_sql}";
        $data_sql  = "SELECT ID, post_title, post_excerpt, post_content, post_modified
                      FROM {$wpdb->posts}
                      WHERE {$where_sql}
                      ORDER BY post_modified DESC
                      LIMIT %d OFFSET %d";

        // The filters below are derived (word counts, ALT text, link counts),
        // so they cannot be expressed in SQL. When one is active we pull a
        // bounded window and paginate in PHP; otherwise SQL paginates directly.
        $sql_limit  = $filtering ? 2000 : $per_page;
        $sql_offset = $filtering ? 0 : $offset;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);
        $products = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, [$sql_limit, $sql_offset])));
        // phpcs:enable

        $thin_threshold = max(50, (int) round((int) WPSD_Settings::get('thin_content_words', 200) * 0.5));

        $rows = [];
        foreach ((array) $products as $product) {
            $id    = (int) $product->ID;
            $words = WPSD_Helpers::word_count((string) $product->post_content);

            $images  = self::product_image_ids($id);
            $no_alt  = 0;
            foreach ($images as $attachment_id) {
                $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                if (!is_string($alt) || trim($alt) === '') {
                    $no_alt++;
                }
            }

            $row = [
                'id'          => $id,
                'title'       => $product->post_title,
                'url'         => (string) get_permalink($id),
                'edit_url'    => (string) get_edit_post_link($id, 'raw'),
                'words'       => $words,
                'thin'        => $words < $thin_threshold,
                'description' => WPSD_Helpers::get_seo_description($id)['value'],
                'has_meta'    => trim(WPSD_Helpers::get_seo_description($id)['value']) !== '',
                'has_short'   => trim((string) $product->post_excerpt) !== '',
                'images'      => count($images),
                'images_no_alt' => $no_alt,
                'incoming'    => WPSD_Internal_Links::incoming_count($id),
                'canonical'   => WPSD_Helpers::get_canonical($id),
                'issues'      => WPSD_DB::count('issues', 'object_id = %d AND status = %s', [$id, 'open']),
            ];

            // Apply the requested filter after computing, since the criteria
            // are derived rather than stored columns.
            if ($args['filter'] === 'thin' && !$row['thin']) {
                continue;
            }
            if ($args['filter'] === 'no_meta' && $row['has_meta']) {
                continue;
            }
            if ($args['filter'] === 'no_alt' && $row['images_no_alt'] === 0) {
                continue;
            }
            if ($args['filter'] === 'orphan' && $row['incoming'] > 0) {
                continue;
            }

            $rows[] = $row;
        }

        if ($filtering) {
            // The filtered set is what the pager must describe.
            $total = count($rows);
            $rows  = array_slice($rows, $offset, $per_page);
        }

        return [
            'rows'  => $rows,
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        global $wpdb;

        if (!self::is_active()) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");

        $issues = [];
        foreach (['thin_product', 'product_meta', 'product_image_alt', 'product_internal_links', 'product_schema', 'product_canonical'] as $check) {
            $issues[$check] = WPSD_DB::count('issues', 'check_id = %s AND status = %s', [$check, 'open']);
        }

        return array_merge(['total_products' => $total], $issues);
    }

    /**
     * Featured image plus gallery attachment IDs.
     *
     * @return array<int,int>
     */
    public static function product_image_ids(int $product_id): array {
        $ids = [];

        $thumbnail = get_post_thumbnail_id($product_id);
        if ($thumbnail) {
            $ids[] = (int) $thumbnail;
        }

        $gallery = (string) get_post_meta($product_id, '_product_image_gallery', true);
        foreach (array_filter(explode(',', $gallery)) as $id) {
            $id = (int) trim($id);
            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
