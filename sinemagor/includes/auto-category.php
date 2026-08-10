<?php
defined('ABSPATH') || exit;

class Sinemagor_Auto_Category {

    // Parent category slugs
    const PARENT_GENRE    = 'sg-by-genre';
    const PARENT_DIRECTOR = 'sg-by-director';
    const PARENT_CAST     = 'sg-by-cast';
    const PARENT_LANGUAGE = 'sg-by-language';

    public static function init(): void {
        add_action('transition_post_status', [self::class, 'on_publish'], 25, 3);
        add_action('wp_ajax_sg_setup_categories', [self::class, 'ajax_setup']);
        add_action('wp_ajax_sg_rebuild_categories', [self::class, 'ajax_rebuild_all']);
    }

    /**
     * Fire on post publish — create + assign all categories.
     */
    public static function on_publish(string $new, string $old, WP_Post $post): void {
        if ($new !== 'publish') return;
        if (!get_post_meta($post->ID, '_sinemagor_tmdb_id', true)) return;
        self::process($post->ID);
    }

    /**
     * Main: create categories and assign to post.
     */
    public static function process(int $post_id): void {
        $genre    = get_post_meta($post_id, '_sinemagor_genre',    true);
        $director = get_post_meta($post_id, '_sinemagor_director', true);
        $cast_json= get_post_meta($post_id, '_sinemagor_cast',     true);
        $language = get_post_meta($post_id, '_sinemagor_language', true);

        $cat_ids = [];

        // ── Genre ──────────────────────────────────────────────────────────
        if (Sinemagor_Settings::get('autocat_genre', 1) && $genre) {
            $parent = self::ensure_parent('By Genre', self::PARENT_GENRE);
            foreach (explode(',', $genre) as $g) {
                $g = trim($g);
                if ($g) $cat_ids[] = self::ensure_category($g, $parent, 'genre');
            }
        }

        // ── Director ───────────────────────────────────────────────────────
        if (Sinemagor_Settings::get('autocat_director', 1) && $director) {
            $parent = self::ensure_parent('By Director', self::PARENT_DIRECTOR);
            $cat_ids[] = self::ensure_category($director, $parent, 'director');
        }

        // ── Cast (top 3) ───────────────────────────────────────────────────
        if (Sinemagor_Settings::get('autocat_cast', 1) && $cast_json) {
            $cast   = json_decode($cast_json, true) ?: [];
            $top    = (int) Sinemagor_Settings::get('autocat_cast_limit', 3);
            $parent = self::ensure_parent('By Cast', self::PARENT_CAST);
            foreach (array_slice($cast, 0, $top) as $member) {
                $name = $member['name'] ?? '';
                if ($name) $cat_ids[] = self::ensure_category($name, $parent, 'cast');
            }
        }

        // ── Language ───────────────────────────────────────────────────────
        if (Sinemagor_Settings::get('autocat_language', 1) && $language) {
            $lang_name = self::language_name($language);
            $parent    = self::ensure_parent('By Language', self::PARENT_LANGUAGE);
            $cat_ids[] = self::ensure_category($lang_name, $parent, 'language');
        }

        // Assign all categories (append, don't replace existing)
        $cat_ids = array_filter(array_unique($cat_ids));
        if (!empty($cat_ids)) {
            wp_set_post_categories($post_id, $cat_ids, true);
        }

        // Store category assignment meta for reference
        update_post_meta($post_id, '_sinemagor_cats_assigned', current_time('mysql'));
    }

    /**
     * Ensure a parent category exists. Returns term_id.
     */
    private static function ensure_parent(string $name, string $slug): int {
        $existing = get_category_by_slug($slug);
        if ($existing) return (int) $existing->term_id;

        $result = wp_insert_term($name, 'category', [
            'slug'        => $slug,
            'description' => "Movies organized by {$name}",
        ]);

        if (is_wp_error($result)) {
            // May already exist with different slug — try again
            $existing = get_term_by('name', $name, 'category');
            return $existing ? (int) $existing->term_id : 0;
        }

        // Set category meta for styling
        update_term_meta($result['term_id'], 'sg_cat_type', 'parent');
        return (int) $result['term_id'];
    }

    /**
     * Ensure a child category exists under parent. Returns term_id.
     */
    public static function ensure_category(string $name, int $parent_id, string $type = ''): int {
        $name = trim($name);
        if (!$name) return 0;

        $slug     = self::make_slug($name);
        $existing = get_category_by_slug($slug);

        if ($existing) {
            // Update parent if missing
            if ($parent_id && (int) $existing->category_parent !== $parent_id) {
                wp_update_term($existing->term_id, 'category', ['parent' => $parent_id]);
            }
            return (int) $existing->term_id;
        }

        $result = wp_insert_term($name, 'category', [
            'slug'   => $slug,
            'parent' => $parent_id,
        ]);

        if (is_wp_error($result)) {
            $existing = get_term_by('name', $name, 'category');
            return $existing ? (int) $existing->term_id : 0;
        }

        $term_id = (int) $result['term_id'];

        // Store type meta
        if ($type) update_term_meta($term_id, 'sg_cat_type', $type);

        return $term_id;
    }

    /**
     * Convert ISO language code to human-readable name.
     */
    private static function language_name(string $code): string {
        $map = [
            'en' => 'English',   'ko' => 'Korean',    'ja' => 'Japanese',
            'fr' => 'French',    'de' => 'German',     'es' => 'Spanish',
            'it' => 'Italian',   'zh' => 'Chinese',    'hi' => 'Hindi',
            'pt' => 'Portuguese','ru' => 'Russian',    'ar' => 'Arabic',
            'tr' => 'Turkish',   'th' => 'Thai',       'id' => 'Indonesian',
        ];
        return $map[strtolower($code)] ?? strtoupper($code);
    }

    /**
     * Create SEO-friendly slug from name.
     */
    public static function make_slug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', trim($slug));
        return 'sg-' . $slug;
    }

    /**
     * Rebuild categories for ALL published Sinemagor posts.
     * Called via Ajax from Post Health or Settings.
     */
    public static function rebuild_all(): array {
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- selecting only plugin-generated movie posts is this function's core purpose.
        ]);

        $done = 0;
        foreach ($posts as $post_id) {
            self::process((int) $post_id);
            $done++;
        }

        return ['processed' => $done, 'total' => count($posts)];
    }

    /**
     * Get category stats for settings display.
     */
    public static function get_stats(): array {
        $types = ['genre', 'director', 'cast', 'language', 'parent'];
        $stats = [];
        foreach ($types as $type) {
            $terms = get_terms([
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'meta_query' => [['key' => 'sg_cat_type', 'value' => $type]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering terms by the plugin-defined category-type meta is this function's core purpose.
                'fields'     => 'ids',
            ]);
            $stats[$type] = is_array($terms) ? count($terms) : 0;
        }
        return $stats;
    }

    // ── Ajax ──────────────────────────────────────────────────────────────────

    public static function ajax_setup(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_categories')) wp_send_json_error('Permission denied.');

        // Create parent categories
        self::ensure_parent('By Genre',    self::PARENT_GENRE);
        self::ensure_parent('By Director', self::PARENT_DIRECTOR);
        self::ensure_parent('By Cast',     self::PARENT_CAST);
        self::ensure_parent('By Language', self::PARENT_LANGUAGE);

        wp_send_json_success('Parent categories created.');
    }

    public static function ajax_rebuild_all(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_categories')) wp_send_json_error('Permission denied.');
        $result = self::rebuild_all();
        wp_send_json_success($result);
    }
}
