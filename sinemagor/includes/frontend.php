<?php
defined('ABSPATH') || exit;

class Sinemagor_Frontend {
    public static function init(): void {
        add_filter('the_content',          [self::class, 'wrap_content']);
        add_action('wp_enqueue_scripts',   [self::class, 'enqueue']);
        add_filter('single_template',      [self::class, 'single_template']);
        add_filter('archive_template',     [self::class, 'archive_template']);
        add_filter('category_template',    [self::class, 'category_template']);
        add_action('widgets_init',         [self::class, 'register_sidebar']);
        // Register internal linker content filter here (after linker class loaded)
        Sinemagor_Internal_Linker::register_content_filter();
    }

    public static function is_sg_post(): bool {
        return is_single() && (bool) get_post_meta(get_the_ID(), '_sinemagor_tmdb_id', true);
    }

    public static function wrap_content(string $content): string {
        if (!self::is_sg_post()) return $content;
        return '<div class="sg-post-content">' . $content . '</div>';
    }

    public static function enqueue(): void {
        // Always load CSS on SG posts and archives
        if (!self::is_sg_post() && !is_category()) return;
        wp_enqueue_style('sinemagor-public', SINEMAGOR_URL . 'public/css/public.css', [], SINEMAGOR_VERSION);
        // Theme toggle JS — loaded in <head> with inline pre-paint snippet
        wp_enqueue_script('sinemagor-theme', SINEMAGOR_URL . 'public/js/theme-toggle.js', [], SINEMAGOR_VERSION, false);
    }

    public static function single_template(string $template): string {
        if (!self::is_sg_post()) return $template;
        $custom = SINEMAGOR_DIR . 'templates/single-movie.php';
        return file_exists($custom) ? $custom : $template;
    }

    public static function category_template(string $template): string {
        $term = get_queried_object();
        if (!$term) return $template;

        // Only override for sinemagor categories (sg- prefix)
        if (strpos($term->slug, 'sg-') !== 0) return $template;

        $custom = SINEMAGOR_DIR . 'templates/category-sg.php';
        return file_exists($custom) ? $custom : $template;
    }

    public static function archive_template(string $template): string {
        // Only override if this is a category used for movie reviews
        // You can refine this check further if needed
        $custom = SINEMAGOR_DIR . 'templates/archive-movies.php';
        return file_exists($custom) ? $custom : $template;
    }

    public static function register_sidebar(): void {
        register_sidebar([
            'name'          => 'Sinemagor Sidebar',
            'id'            => 'sinemagor-sidebar',
            'description'   => 'Widgets for Sinemagor movie review pages.',
            'before_widget' => '<div class="sg-sidebar-widget">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="sg-sidebar-title">',
            'after_title'   => '</h3>',
        ]);
    }
}

