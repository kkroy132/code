<?php
defined('ABSPATH') || exit;

class Sinemagor_Duplicate_Detector {

    public static function init(): void {
        add_filter('wp_insert_post_data', [self::class, 'maybe_block'], 10, 2);
        add_action('admin_notices',       [self::class, 'show_notice']);
        add_action('save_post',           [self::class, 'check_on_save'], 5, 2);
    }

    /**
     * Before inserting — if same TMDB ID exists, force draft + set warning.
     */
    public static function maybe_block(array $data, array $postarr): array {
        if ($data['post_type'] !== 'post') return $data;
        if (empty($postarr['meta_input']['_sinemagor_tmdb_id'])) return $data;

        $tmdb_id  = (int) $postarr['meta_input']['_sinemagor_tmdb_id'];
        $existing = self::find_existing($tmdb_id, (int) ($postarr['ID'] ?? 0));

        if ($existing) {
            $data['post_status'] = 'draft';
            set_transient('sinemagor_dupe_' . get_current_user_id(), [
                'tmdb_id'     => $tmdb_id,
                'existing_id' => $existing->ID,
                'title'       => $existing->post_title,
            ], 60);
        }
        return $data;
    }

    public static function check_on_save(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id)) return;
        $tmdb_id = get_post_meta($post_id, '_sinemagor_tmdb_id', true);
        if (!$tmdb_id) return;
        $existing = self::find_existing((int) $tmdb_id, $post_id);
        $existing
            ? update_post_meta($post_id, '_sinemagor_duplicate_of', $existing->ID)
            : delete_post_meta($post_id, '_sinemagor_duplicate_of');
    }

    public static function show_notice(): void {
        $uid  = get_current_user_id();
        $data = get_transient('sinemagor_dupe_' . $uid);
        if (!$data) return;
        delete_transient('sinemagor_dupe_' . $uid);
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . '⚠️ <strong>Sinemagor Duplicate:</strong> TMDB #' . esc_html($data['tmdb_id'])
            . ' already exists — <a href="' . esc_url(get_edit_post_link($data['existing_id'])) . '">'
            . esc_html($data['title']) . '</a>. New post saved as Draft.</p></div>';
    }

    public static function find_existing(int $tmdb_id, int $exclude = 0): ?WP_Post {
        $args = [
            'post_type'      => 'post',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 1,
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'value' => $tmdb_id]],
        ];
        if ($exclude) $args['post__not_in'] = [$exclude];
        $q = new WP_Query($args);
        return $q->have_posts() ? $q->posts[0] : null;
    }

    /** Scan all posts for duplicates — used in Admin dashboard. */
    public static function scan_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT meta_value AS tmdb_id, COUNT(*) AS cnt
             FROM {$wpdb->postmeta}
             WHERE meta_key='_sinemagor_tmdb_id'
             GROUP BY meta_value HAVING cnt > 1"
        );
        $out = [];
        foreach ($rows as $row) {
            $posts = get_posts([
                'post_type'   => 'post',
                'post_status' => 'any',
                'meta_key'    => '_sinemagor_tmdb_id',
                'meta_value'  => $row->tmdb_id,
                'numberposts' => -1,
            ]);
            $out[] = [
                'tmdb_id' => $row->tmdb_id,
                'posts'   => array_map(fn($p) => [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'status' => $p->post_status,
                    'url'    => get_edit_post_link($p->ID, 'raw'),
                ], $posts),
            ];
        }
        return $out;
    }
}
