<?php
defined('ABSPATH') || exit;

class Sinemagor_Bulk_Status {

    public static function init(): void {
        // Ajax endpoints
        add_action('wp_ajax_sg_bulk_status_change', [self::class, 'ajax_change']);
        add_action('wp_ajax_sg_regen_single',        [self::class, 'ajax_regen']);
        add_action('wp_ajax_sg_scan_dupes',           [self::class, 'ajax_scan_dupes']);
    }

    /** Bulk transition: pending→draft, draft→publish, publish→draft, any→trash */
    public static function ajax_change(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $db_ids    = array_map('intval', (array) ($_POST['db_ids']    ?? []));
        $new_status= sanitize_key($_POST['new_status'] ?? '');

        if (empty($db_ids) || !in_array($new_status, ['pending', 'draft', 'publish', 'trash'], true)) {
            wp_send_json_error('Invalid request.');
        }

        $done = 0;
        foreach ($db_ids as $db_id) {
            $movie = Sinemagor_DB::get_movie($db_id);
            if (!$movie) continue;

            if ($new_status === 'pending') {
                // Reset — remove WP post link, set library status back to pending
                Sinemagor_DB::update_status($db_id, 'pending', 0);
                $done++;
                continue;
            }

            if ($movie->wp_post_id) {
                // Update existing WP post status
                $wp_status = $new_status === 'publish' ? 'publish' : ($new_status === 'trash' ? 'trash' : 'draft');
                wp_update_post(['ID' => $movie->wp_post_id, 'post_status' => $wp_status]);
                Sinemagor_DB::update_status($db_id, $new_status === 'publish' ? 'published' : ($new_status === 'trash' ? 'pending' : 'draft'));
                $done++;
            } else {
                // No WP post yet — generate then set status
                if (in_array($new_status, ['draft', 'publish'], true)) {
                    $post_id = Sinemagor_Post_Publisher::publish($db_id);
                    if (!is_wp_error($post_id)) {
                        if ($new_status === 'draft') {
                            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
                        }
                        $done++;
                    }
                }
            }
        }

        wp_send_json_success(['done' => $done, 'message' => "{$done} posts updated to '{$new_status}'."]);
    }

    /** Regenerate a single post's AI content */
    public static function ajax_regen(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid post ID.');

        $result = Sinemagor_Auto_Regenerate::trigger_single($post_id);
        $result
            ? wp_send_json_success('Post regenerated successfully.')
            : wp_send_json_error('Regeneration failed. Check API keys.');
    }

    /** Scan for duplicate posts */
    public static function ajax_scan_dupes(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
        wp_send_json_success(Sinemagor_Duplicate_Detector::scan_all());
    }
}
