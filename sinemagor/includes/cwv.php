<?php
defined('ABSPATH') || exit;

class Sinemagor_CWV {

    public static function init(): void {
        // Preconnect to TMDB CDN + YouTube
        add_action('wp_head',           [self::class, 'preconnect'], 1);
        // Add width/height to TMDB images to prevent CLS
        add_filter('the_content',       [self::class, 'fix_image_dimensions'], 3);
        // Reading time + last updated meta
        add_filter('the_content',       [self::class, 'prepend_post_meta'], 4);
        // Open Graph + Twitter Card
        add_action('wp_head',           [self::class, 'og_tags'], 2);
    }

    // ── Preconnect hints ──────────────────────────────────────────────────────

    public static function preconnect(): void {
        if (!is_single()) return;
        if (!get_post_meta(get_the_ID(), '_sinemagor_tmdb_id', true)) return;
        echo '<link rel="preconnect" href="https://image.tmdb.org" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="https://www.youtube.com" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="https://openrouter.ai">' . "\n";
        // Preload first poster image
        $poster = get_post_meta(get_the_ID(), '_sinemagor_poster', true);
        if ($poster) {
            $url = Sinemagor_TMDB::image_url($poster, 'w342');
            echo '<link rel="preload" as="image" href="' . esc_url($url) . '">' . "\n";
        }
    }

    // ── Fix image dimensions → prevent CLS ───────────────────────────────────

    public static function fix_image_dimensions(string $content): string {
        if (!is_single()) return $content;
        if (!get_post_meta(get_the_ID(), '_sinemagor_tmdb_id', true)) return $content;

        // Add width/height to TMDB images that are missing them
        // w342 images = 342×513, w92 = 92×138, w1280 = 1280×720 (backdrop), w185 = 185×278
        $size_map = [
            'w92'   => [92,   138],
            'w185'  => [185,  278],
            'w342'  => [342,  513],
            'w500'  => [500,  750],
            'w1280' => [1280, 720],
        ];

        foreach ($size_map as $size => [$w, $h]) {
            $content = preg_replace_callback(
                '/<img([^>]*?)src="(https:\/\/image\.tmdb\.org\/t\/p\/' . preg_quote($size, '/') . '[^"]*)"([^>]*)>/i',
                function ($m) use ($w, $h) {
                    $attrs = $m[1] . $m[3];
                    // Only add if width/height not already present
                    if (strpos($attrs, 'width=') !== false) return $m[0];
                    return '<img' . $m[1] . 'src="' . $m[2] . '"' . $m[3]
                         . ' width="' . $w . '" height="' . $h . '">';
                },
                $content
            );
        }

        return $content;
    }

    // ── Reading time + Last updated ───────────────────────────────────────────

    public static function prepend_post_meta(string $content): string {
        if (!is_single()) return $content;
        $post_id = get_the_ID();
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true) &&
            !get_post_meta($post_id, '_sinemagor_list_post', true) &&
            !get_post_meta($post_id, '_sinemagor_comparison', true)) {
            return $content;
        }

        $word_count   = str_word_count(wp_strip_all_tags($content));
        $reading_time = max(1, (int) ceil($word_count / 200)); // 200 wpm average
        $updated      = get_the_modified_date('F j, Y', $post_id);
        $published    = get_the_date('F j, Y', $post_id);

        $meta  = '<div class="sg-post-meta-bar">';
        $meta .= '<span class="sg-pmb-item">⏱ ' . $reading_time . ' min read</span>';
        $meta .= '<span class="sg-pmb-sep">·</span>';
        $meta .= '<span class="sg-pmb-item">📅 Updated ' . esc_html($updated) . '</span>';
        if ($published !== $updated) {
            $meta .= '<span class="sg-pmb-sep">·</span>';
            $meta .= '<span class="sg-pmb-item" style="color:var(--sg-muted)">Published ' . esc_html($published) . '</span>';
        }
        $meta .= '</div>';

        return $meta . $content;
    }

    // ── Open Graph + Twitter Card ─────────────────────────────────────────────

    public static function og_tags(): void {
        if (!is_single()) return;
        $post_id = get_the_ID();
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true) &&
            !get_post_meta($post_id, '_sinemagor_list_post', true)) {
            return;
        }

        $title       = get_the_title($post_id);
        $description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true)
                    ?: get_post_meta($post_id, 'rank_math_description', true)
                    ?: wp_trim_words(get_the_excerpt($post_id), 30);
        $url         = get_permalink($post_id);
        $site_name   = get_bloginfo('name');

        // Poster as OG image
        $poster_path = get_post_meta($post_id, '_sinemagor_poster', true);
        $og_image    = $poster_path
            ? Sinemagor_TMDB::image_url($poster_path, 'w500')
            : get_site_icon_url(512);

        // Skip if Yoast/RankMath already outputting OG
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) return;

        echo '<meta property="og:type"        content="article" />' . "\n";
        echo '<meta property="og:title"       content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
        echo '<meta property="og:url"         content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:site_name"   content="' . esc_attr($site_name) . '" />' . "\n";
        if ($og_image) {
            echo '<meta property="og:image"   content="' . esc_url($og_image) . '" />' . "\n";
            echo '<meta property="og:image:width"  content="500" />' . "\n";
            echo '<meta property="og:image:height" content="750" />' . "\n";
        }
        echo '<meta name="twitter:card"        content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title"       content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";
        if ($og_image) echo '<meta name="twitter:image" content="' . esc_url($og_image) . '" />' . "\n";
    }
}
