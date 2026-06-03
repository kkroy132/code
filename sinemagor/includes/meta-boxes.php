<?php
defined('ABSPATH') || exit;

// Meta boxes shown on individual posts (read-only display)
class Sinemagor_Meta_Boxes {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'register']);
    }
    public static function register(): void {
        add_meta_box('sinemagor_movie_info', '🎬 Movie Info (Sinemagor)', [self::class, 'render'], 'post', 'side', 'default');
    }
    public static function render(WP_Post $post): void {
        $tmdb_id = get_post_meta($post->ID, '_sinemagor_tmdb_id', true);
        if (!$tmdb_id) { echo '<p style="color:#999">Not a Sinemagor post.</p>'; return; }
        $poster  = get_post_meta($post->ID, '_sinemagor_poster', true);
        $rating  = get_post_meta($post->ID, '_sinemagor_editor_rating', true);
        $genre   = get_post_meta($post->ID, '_sinemagor_genre', true);
        $year    = get_post_meta($post->ID, '_sinemagor_year', true);
        $tmdb_r  = get_post_meta($post->ID, '_sinemagor_tmdb_rating', true);
        if ($poster) echo '<img src="' . esc_url(Sinemagor_TMDB::image_url($poster, 'w185')) . '" style="width:100%;border-radius:6px;margin-bottom:10px" />';
        echo '<table style="width:100%;font-size:.85rem">';
        foreach (['Genre' => $genre, 'Year' => $year, 'TMDB Rating' => $tmdb_r, 'Editor Rating' => $rating, 'TMDB ID' => $tmdb_id] as $k => $v) {
            echo "<tr><td><strong>{$k}</strong></td><td>" . esc_html($v ?: '—') . "</td></tr>";
        }
        echo '</table>';
        echo '<a href="https://www.themoviedb.org/movie/' . esc_attr($tmdb_id) . '" target="_blank" style="font-size:.8rem;color:#e8b84b;display:block;margin-top:8px">View on TMDB →</a>';
    }
}
