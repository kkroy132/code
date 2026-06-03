<?php
defined('ABSPATH') || exit;

class Sinemagor_Widgets {

    public static function init(): void {
        add_action('widgets_init', [self::class, 'register']);
    }

    public static function register(): void {
        register_widget('Sinemagor_Widget_Trending');
        register_widget('Sinemagor_Widget_Latest');
    }
}

// ── Trending Reviews Widget ────────────────────────────────────────────────────

class Sinemagor_Widget_Trending extends WP_Widget {

    public function __construct() {
        parent::__construct('sinemagor_trending', '🔥 Sinemagor: Trending Reviews', [
            'description' => 'Show top-rated Sinemagor movie reviews.',
        ]);
    }

    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title'] ?? '🔥 Trending Reviews');
        $count = (int) ($instance['count'] ?? 5);

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'meta_key'       => '_sinemagor_editor_rating',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']],
        ]);

        echo $args['before_widget'];
        if ($title) echo $args['before_title'] . esc_html($title) . $args['after_title'];
        echo '<ul class="sg-widget-list">';
        foreach ($posts as $p) {
            $poster  = get_post_meta($p->ID, '_sinemagor_poster', true);
            $rating  = get_post_meta($p->ID, '_sinemagor_editor_rating', true);
            $img     = $poster ? Sinemagor_TMDB::image_url($poster, 'w92') : '';
            echo '<li class="sg-widget-item">';
            if ($img) echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($p->post_title) . '" loading="lazy" />';
            echo '<div class="sg-widget-info">';
            echo '<a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html($p->post_title) . '</a>';
            if ($rating) echo '<span class="sg-widget-rating">⭐ ' . esc_html($rating) . '/10</span>';
            echo '</div></li>';
        }
        echo '</ul>';
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = $instance['title'] ?? '🔥 Trending Reviews';
        $count = $instance['count'] ?? 5;
        ?>
        <p>
            <label>Title:<input class="widefat" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($title); ?>" /></label>
        </p>
        <p>
            <label>Count:<input type="number" class="tiny-text" name="<?php echo $this->get_field_name('count'); ?>" value="<?php echo esc_attr($count); ?>" min="1" max="10" /></label>
        </p>
        <?php
    }

    public function update($new, $old) {
        return [
            'title' => sanitize_text_field($new['title'] ?? ''),
            'count' => max(1, min(10, (int) ($new['count'] ?? 5))),
        ];
    }
}

// ── Latest Reviews Widget ─────────────────────────────────────────────────────

class Sinemagor_Widget_Latest extends WP_Widget {

    public function __construct() {
        parent::__construct('sinemagor_latest', '🆕 Sinemagor: Latest Reviews', [
            'description' => 'Show most recent Sinemagor movie reviews.',
        ]);
    }

    public function widget($args, $instance) {
        $title  = apply_filters('widget_title', $instance['title'] ?? '🆕 Latest Reviews');
        $count  = (int) ($instance['count'] ?? 5);
        $genre  = sanitize_text_field($instance['genre'] ?? '');

        $meta_query = [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']];
        if ($genre) {
            $meta_query[] = ['key' => '_sinemagor_genre', 'value' => $genre, 'compare' => 'LIKE'];
        }

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query,
        ]);

        echo $args['before_widget'];
        if ($title) echo $args['before_title'] . esc_html($title) . $args['after_title'];
        echo '<ul class="sg-widget-list">';
        foreach ($posts as $p) {
            $poster = get_post_meta($p->ID, '_sinemagor_poster', true);
            $year   = get_post_meta($p->ID, '_sinemagor_year',   true);
            $genre_ = get_post_meta($p->ID, '_sinemagor_genre',  true);
            $img    = $poster ? Sinemagor_TMDB::image_url($poster, 'w92') : '';
            echo '<li class="sg-widget-item">';
            if ($img) echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($p->post_title) . '" loading="lazy" />';
            echo '<div class="sg-widget-info">';
            echo '<a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html($p->post_title) . '</a>';
            echo '<span class="sg-widget-meta">' . esc_html(trim(($year ? $year . ' · ' : '') . (explode(',', $genre_)[0] ?? ''))) . '</span>';
            echo '</div></li>';
        }
        echo '</ul>';
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = $instance['title'] ?? '🆕 Latest Reviews';
        $count = $instance['count'] ?? 5;
        $genre = $instance['genre'] ?? '';
        ?>
        <p><label>Title:<input class="widefat" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($title); ?>" /></label></p>
        <p><label>Count:<input type="number" class="tiny-text" name="<?php echo $this->get_field_name('count'); ?>" value="<?php echo esc_attr($count); ?>" min="1" max="10" /></label></p>
        <p><label>Filter by Genre (optional):<input class="widefat" name="<?php echo $this->get_field_name('genre'); ?>" value="<?php echo esc_attr($genre); ?>" placeholder="e.g. Action" /></label></p>
        <?php
    }

    public function update($new, $old) {
        return [
            'title' => sanitize_text_field($new['title'] ?? ''),
            'count' => max(1, min(10, (int) ($new['count'] ?? 5))),
            'genre' => sanitize_text_field($new['genre'] ?? ''),
        ];
    }
}
