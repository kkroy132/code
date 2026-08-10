<?php
defined('ABSPATH') || exit;

class Sinemagor_Shortcode {
    public static function init(): void {
        add_shortcode('sinemagor', [self::class, 'render']);
    }

    // Usage: [sinemagor genre="Action" count="8" orderby="date"]
    public static function render(array $atts): string {
        $atts = shortcode_atts([
            'genre'   => '',
            'count'   => 8,
            'orderby' => 'date',
        ], $atts, 'sinemagor');

        $query_args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $atts['count'],
            'orderby'        => sanitize_key($atts['orderby']),
            'order'          => 'DESC',
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering by plugin-defined movie meta is this shortcode's core purpose.
        ];

        if ($atts['genre']) {
            $query_args['meta_query'][] = [
                'key'     => '_sinemagor_genre',
                'value'   => sanitize_text_field($atts['genre']),
                'compare' => 'LIKE',
            ];
        }

        $q   = new WP_Query($query_args);
        $out = '<div class="sg-grid-shortcode">';

        while ($q->have_posts()) {
            $q->the_post();
            $id      = get_the_ID();
            $poster  = get_post_meta($id, '_sinemagor_poster', true);
            $rating  = get_post_meta($id, '_sinemagor_editor_rating', true);
            $genre   = get_post_meta($id, '_sinemagor_genre', true);
            $year    = get_post_meta($id, '_sinemagor_year', true);
            $img     = $poster ? Sinemagor_TMDB::image_url($poster, 'w342') : '';

            $out .= '<div class="sg-grid-item">';
            $out .= '<a href="' . esc_url(get_permalink()) . '" class="sg-grid-link">';
            if ($img) $out .= '<img src="' . esc_url($img) . '" alt="' . esc_attr(get_the_title()) . '" loading="lazy" />';
            $out .= '<div class="sg-grid-overlay">';
            $out .= '<h3>' . esc_html(get_the_title()) . '</h3>';
            $out .= '<div class="sg-grid-meta">' . esc_html($year ?: '') . ' · ' . esc_html(explode(',', $genre)[0] ?? '') . '</div>';
            if ($rating) $out .= '<div class="sg-grid-rating">⭐ ' . esc_html($rating) . '/10</div>';
            $out .= '</div></a></div>';
        }
        wp_reset_postdata();

        $out .= '</div>';

        // Inline minimal grid CSS (only once)
        static $css_printed = false;
        if (!$css_printed) {
            $css_printed = true;
            $out = '<style>.sg-grid-shortcode{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}.sg-grid-item{position:relative;border-radius:10px;overflow:hidden}.sg-grid-link img{width:100%;aspect-ratio:2/3;object-fit:cover;display:block}.sg-grid-overlay{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.85));padding:14px 10px;color:#fff}.sg-grid-overlay h3{font-size:.88rem;margin:0 0 4px;line-height:1.3}.sg-grid-meta{font-size:.75rem;opacity:.75}.sg-grid-rating{font-size:.8rem;margin-top:4px;color:#e8b84b;font-weight:700}</style>' . $out;
        }

        return $out;
    }
}
