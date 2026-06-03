<?php
defined('ABSPATH') || exit;

class Sinemagor_Star_Rating {

    const TABLE = 'sinemagor_ratings';

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id    BIGINT UNSIGNED NOT NULL,
            user_ip    VARCHAR(45)     NOT NULL,
            rating     TINYINT         NOT NULL CHECK (rating BETWEEN 1 AND 10),
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_vote (post_id, user_ip),
            KEY idx_post (post_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function init(): void {
        add_filter('the_content',              [self::class, 'append_widget'], 12);
        add_action('wp_ajax_sg_submit_rating', [self::class, 'ajax_submit']);
        add_action('wp_ajax_nopriv_sg_submit_rating', [self::class, 'ajax_submit']);
        add_action('wp_enqueue_scripts',       [self::class, 'enqueue']);
    }

    public static function enqueue(): void {
        if (!is_single()) return;
        if (!get_post_meta(get_the_ID(), '_sinemagor_tmdb_id', true)) return;
        wp_enqueue_script('sinemagor-rating', SINEMAGOR_URL . 'public/js/star-rating.js', [], SINEMAGOR_VERSION, true);
        wp_localize_script('sinemagor-rating', 'sg_rating', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sg_rating_nonce'),
        ]);
    }

    public static function get_stats(int $post_id): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total, AVG(rating) AS avg FROM {$table} WHERE post_id = %d", $post_id
        ));
        return [
            'total' => (int)   ($row->total ?? 0),
            'avg'   => round((float) ($row->avg ?? 0), 1),
        ];
    }

    public static function get_user_rating(int $post_id): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $ip    = self::get_ip();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT rating FROM {$table} WHERE post_id=%d AND user_ip=%s", $post_id, $ip
        ));
    }

    public static function ajax_submit(): void {
        check_ajax_referer('sg_rating_nonce', 'nonce');
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $rating  = (int) ($_POST['rating']  ?? 0);

        if (!$post_id || $rating < 1 || $rating > 10) wp_send_json_error('Invalid data.');
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true)) wp_send_json_error('Not a movie post.');

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $ip    = self::get_ip();

        // Upsert
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (post_id, user_ip, rating) VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE rating = %d, created_at = NOW()",
            $post_id, $ip, $rating, $rating
        ));

        $stats = self::get_stats($post_id);

        // Update aggregate rating in post meta (for Schema)
        update_post_meta($post_id, '_sinemagor_user_rating_avg',   $stats['avg']);
        update_post_meta($post_id, '_sinemagor_user_rating_count', $stats['total']);

        wp_send_json_success($stats);
    }

    public static function append_widget(string $content): string {
        if (!is_single()) return $content;
        $pid = get_the_ID();
        if (!get_post_meta($pid, '_sinemagor_tmdb_id', true)) return $content;

        $stats      = self::get_stats($pid);
        $user_vote  = self::get_user_rating($pid);
        $editor_r   = get_post_meta($pid, '_sinemagor_editor_rating', true);

        $html  = '<div class="sg-rating-widget" id="sg-rating-widget" data-post="'.$pid.'">';
        $html .= '<h2>Rate This Movie</h2>';
        $html .= '<div class="sg-stars" role="group" aria-label="Rate 1 to 10">';
        for ($i = 1; $i <= 10; $i++) {
            $active = $user_vote >= $i ? ' sg-star--on' : '';
            $html  .= '<button class="sg-star'.$active.'" data-val="'.$i.'" aria-label="Rate '.$i.' out of 10" title="'.$i.'/10">★</button>';
        }
        $html .= '</div>';
        $html .= '<div class="sg-rating-summary">';
        if ($stats['total'] > 0) {
            $html .= '<span class="sg-rating-avg">⭐ '.$stats['avg'].' / 10</span>';
            $html .= '<span class="sg-rating-count">'.number_format($stats['total']).' reader rating'.($stats['total'] > 1 ? 's' : '').'</span>';
        }
        // No empty-state placeholder text — stars are self-explanatory
        if ($editor_r) $html .= '<span class="sg-rating-editor">Our rating: '.$editor_r.'/10</span>';
        $html .= '</div>';
        if ($user_vote) $html .= '<p class="sg-your-vote">Your rating: '.$user_vote.'/10</p>';
        $html .= '</div>';

        return $content . $html;
    }

    private static function get_ip(): string {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0';
        return sanitize_text_field(explode(',', $ip)[0]);
    }
}
