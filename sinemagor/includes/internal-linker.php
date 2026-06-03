<?php
defined('ABSPATH') || exit;

class Sinemagor_Internal_Linker {

    // Per-request lock using static property (works for bulk too)
    private static array $processing = [];

    public static function init(): void {
        // Only hook transition_post_status — avoids double-fire with save_post
        add_action('transition_post_status', [self::class, 'on_status_change'], 20, 3);
        // Also fire when post meta is set (catches bulk publish via wp_update_post)
        add_action('wp_after_insert_post',   [self::class, 'after_insert'], 20, 2);
    }

    public static function on_status_change(string $new, string $old, WP_Post $post): void {
        // Only act when becoming published
        if ($new !== 'publish') return;
        self::maybe_inject($post->ID);
    }

    public static function after_insert(int $post_id, WP_Post $post): void {
        if ($post->post_status !== 'publish') return;
        self::maybe_inject($post_id);
    }

    /**
     * Gate: skip revisions, non-sinemagor posts, already-processing posts.
     * Uses post meta flag to prevent re-injection on subsequent saves.
     */
    private static function maybe_inject(int $post_id): void {
        // Static lock — prevents double-fire in same PHP request
        if (isset(self::$processing[$post_id])) return;
        if (wp_is_post_revision($post_id))       return;
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true)) return;

        self::$processing[$post_id] = true;
        self::inject($post_id);
    }

    /**
     * Build and append related sections to the post.
     * Stores them in post meta — displayed via filter, NOT written into post_content.
     * This avoids the regex-matching-HTML problem entirely.
     */
    public static function inject(int $post_id): void {
        $cast_json = get_post_meta($post_id, '_sinemagor_cast',     true);
        $genre     = get_post_meta($post_id, '_sinemagor_genre',    true);
        $director  = get_post_meta($post_id, '_sinemagor_director', true);

        $cast_names = [];
        if ($cast_json) {
            $cast       = json_decode($cast_json, true) ?: [];
            $cast_names = array_column($cast, 'name');
        }

        // Build HTML for each section
        $cast_html  = self::build_cast_section($cast_names, $post_id, $director);
        $genre_html = self::build_genre_section($genre, $post_id);

        // Save as post meta — rendered via the_content filter below
        update_post_meta($post_id, '_sinemagor_related_cast',  $cast_html);
        update_post_meta($post_id, '_sinemagor_related_genre', $genre_html);
        update_post_meta($post_id, '_sinemagor_links_built',   current_time('mysql'));
    }

    /**
     * Append related sections to post content on display.
     * Called via the_content filter — no regex on saved HTML needed.
     */
    public static function append_to_content(string $content): string {
        if (!is_single()) return $content;

        $post_id = get_the_ID();
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true)) return $content;

        $cast_html  = get_post_meta($post_id, '_sinemagor_related_cast',  true);
        $genre_html = get_post_meta($post_id, '_sinemagor_related_genre', true);

        // If not built yet (freshly published), build now
        if (!$cast_html && !$genre_html) {
            self::inject($post_id);
            $cast_html  = get_post_meta($post_id, '_sinemagor_related_cast',  true);
            $genre_html = get_post_meta($post_id, '_sinemagor_related_genre', true);
        }

        return $content . ($cast_html ?: '') . ($genre_html ?: '');
    }

    // ── Register the_content filter ──────────────────────────────────────────

    public static function register_content_filter(): void {
        add_filter('the_content', [self::class, 'append_to_content'], 15);
    }

    // ── Cast-based section ───────────────────────────────────────────────────

    private static function build_cast_section(array $cast_names, int $exclude_id, string $director): string {
        $search_terms = array_slice($cast_names, 0, 3);
        if ($director) $search_terms[] = $director;
        if (empty($search_terms)) return '';

        $found = [];

        foreach ($search_terms as $name) {
            $name = trim($name);
            if (!$name) continue;

            $q = new WP_Query([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 3,
                'post__not_in'   => [$exclude_id],
                'meta_query'     => [[
                    'key'     => '_sinemagor_cast',
                    'value'   => $name,
                    'compare' => 'LIKE',
                ]],
            ]);

            foreach ($q->posts as $p) {
                if (!isset($found[$p->ID])) $found[$p->ID] = $p;
            }
            wp_reset_postdata();

            if (count($found) >= 4) break;
        }

        // Also search by director via meta
        if ($director && count($found) < 4) {
            $q = new WP_Query([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 3,
                'post__not_in'   => array_merge([$exclude_id], array_keys($found)),
                'meta_query'     => [[
                    'key'     => '_sinemagor_director',
                    'value'   => $director,
                    'compare' => 'LIKE',
                ]],
            ]);
            foreach ($q->posts as $p) {
                if (!isset($found[$p->ID])) $found[$p->ID] = $p;
            }
            wp_reset_postdata();
        }

        if (empty($found)) return '';

        $html  = '<div class="sg-related-section sg-related-cast-section">';
        $html .= '<h2>More from This Cast</h2>';
        $html .= '<ul class="sg-related-list">';
        foreach (array_slice($found, 0, 4) as $p) {
            $poster  = get_post_meta($p->ID, '_sinemagor_poster', true);
            $img_url = $poster ? Sinemagor_TMDB::image_url($poster, 'w92') : '';
            $html   .= '<li>';
            if ($img_url) $html .= '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($p->post_title) . '" loading="lazy" />';
            $html   .= '<a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html($p->post_title) . '</a>';
            $html   .= '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    // ── Genre-based section ──────────────────────────────────────────────────

    private static function build_genre_section(string $genre, int $exclude_id): string {
        if (!$genre) return '';

        $primary = trim(explode(',', $genre)[0]);

        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'post__not_in'   => [$exclude_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [[
                'key'     => '_sinemagor_genre',
                'value'   => $primary,
                'compare' => 'LIKE',
            ]],
        ]);

        if (!$q->have_posts()) {
            wp_reset_postdata();
            return '';
        }

        $html  = '<div class="sg-related-section sg-related-genre-section">';
        $html .= '<h2>You Might Also Like</h2>';
        $html .= '<ul class="sg-related-list">';

        while ($q->have_posts()) {
            $q->the_post();
            $pid     = get_the_ID();
            $poster  = get_post_meta($pid, '_sinemagor_poster', true);
            $img_url = $poster ? Sinemagor_TMDB::image_url($poster, 'w92') : '';
            $html   .= '<li>';
            if ($img_url) $html .= '<img src="' . esc_url($img_url) . '" alt="' . esc_attr(get_the_title()) . '" loading="lazy" />';
            $html   .= '<a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a>';
            $html   .= '</li>';
        }
        wp_reset_postdata();

        $html .= '</ul></div>';
        return $html;
    }

    /**
     * Manual rebuild for a single post — callable from Admin or bulk action.
     */
    public static function rebuild(int $post_id): void {
        delete_post_meta($post_id, '_sinemagor_related_cast');
        delete_post_meta($post_id, '_sinemagor_related_genre');
        delete_post_meta($post_id, '_sinemagor_links_built');
        unset(self::$processing[$post_id]);
        self::inject($post_id);
    }
}
