<?php
defined('ABSPATH') || exit;

class Sinemagor_Ajax {

    public static function init(): void {
        $actions = [
            'sg_tmdb_search',
            'sg_tmdb_discover',
            'sg_bulk_add',
            'sg_single_generate',
            'sg_bulk_queue',
            'sg_queue_status',
            'sg_queue_clear',
            'sg_delete_movies',
            'sg_get_library',
            'sg_rebuild_links',
        ];
        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, [self::class, $action]);
        }
    }

    // ── Verify nonce helper ────────────────────────────────────────────────────

    private static function verify(): void {
        if (!check_ajax_referer('sinemagor_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce.', 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied.', 403);
        }
    }

    // ── TMDB: Live search (autocomplete in Library tab) ───────────────────────

    public static function sg_tmdb_search(): void {
        self::verify();
        $query = sanitize_text_field($_POST['query'] ?? '');
        if (strlen($query) < 2) wp_send_json_success([]);

        $tmdb    = new Sinemagor_TMDB();
        $results = $tmdb->search($query);
        wp_send_json_success($results);
    }

    // ── TMDB: Discover with filters ───────────────────────────────────────────

    public static function sg_tmdb_discover(): void {
        self::verify();
        $tmdb = new Sinemagor_TMDB();

        // sanitize_key() breaks "vote_average.desc" (removes the dot)
        // Use sanitize_text_field + explicit whitelist instead
        $allowed_sorts = [
            'popularity.desc', 'popularity.asc',
            'vote_average.desc', 'vote_average.asc',
            'release_date.desc', 'primary_release_date.desc',
            'revenue.desc', 'top_rated',
        ];
        $sort_raw = sanitize_text_field($_POST['sort_by'] ?? 'popularity.desc');
        $sort_by  = in_array($sort_raw, $allowed_sorts, true) ? $sort_raw : 'popularity.desc';

        $data = $tmdb->discover([
            'genre_id'   => sanitize_text_field($_POST['genre_id']   ?? ''),
            'year'       => sanitize_text_field($_POST['year']       ?? ''),
            'language'   => sanitize_text_field($_POST['language']   ?? ''),
            'sort_by'    => $sort_by,
            'min_rating' => sanitize_text_field($_POST['min_rating'] ?? ''),
            'page'       => max(1, (int) ($_POST['page'] ?? 1)),
            'count'      => max(20, min(100, (int) ($_POST['count']  ?? 20))),
        ]);
        wp_send_json_success($data);
    }

    // ── Bulk add movies to library ────────────────────────────────────────────

    public static function sg_bulk_add(): void {
        self::verify();

        $tmdb_ids = array_map('intval', (array) ($_POST['tmdb_ids'] ?? []));
        if (empty($tmdb_ids)) wp_send_json_error('No movies selected.');

        $tmdb    = new Sinemagor_TMDB();
        $added   = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($tmdb_ids as $tmdb_id) {
            // Rate limit: 250ms between TMDB requests
            usleep(250000);

            // Check duplicate first (fast)
            if (Sinemagor_DB::get_movie_by_tmdb($tmdb_id)) {
                $skipped++;
                continue;
            }

            $full = $tmdb->get_full($tmdb_id);
            if (!$full) {
                $errors[] = "TMDB #{$tmdb_id}: fetch failed";
                continue;
            }

            $id = Sinemagor_DB::insert_movie($full);
            if ($id) $added++;
            else     $errors[] = "TMDB #{$tmdb_id}: insert failed";
        }

        wp_send_json_success([
            'added'   => $added,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    // ── Single review generate + publish ─────────────────────────────────────

    public static function sg_single_generate(): void {
        self::verify();

        $movie_id = (int) ($_POST['movie_id'] ?? 0);
        if (!$movie_id) wp_send_json_error('Invalid movie ID.');

        $post_id = Sinemagor_Post_Publisher::publish($movie_id);

        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id->get_error_message());
        }

        wp_send_json_success([
            'post_id'   => $post_id,
            'post_url'  => get_permalink($post_id),
            'edit_url'  => get_edit_post_link($post_id, 'raw'),
            'status'    => get_post_status($post_id),
        ]);
    }

    // ── Bulk queue movies for background generation ───────────────────────────

    public static function sg_bulk_queue(): void {
        self::verify();

        $movie_ids = array_map('intval', (array) ($_POST['movie_ids'] ?? []));
        if (empty($movie_ids)) wp_send_json_error('No movies selected.');

        Sinemagor_Queue::enqueue($movie_ids);

        wp_send_json_success([
            'queued'  => count($movie_ids),
            'message' => count($movie_ids) . ' movies added to queue. Processing in background.',
        ]);
    }

    // ── Poll queue progress ───────────────────────────────────────────────────

    public static function sg_queue_status(): void {
        self::verify();
        wp_send_json_success(Sinemagor_Queue::get_progress());
    }

    // ── Clear/reset queue ─────────────────────────────────────────────────────

    public static function sg_queue_clear(): void {
        self::verify();
        Sinemagor_Queue::clear();
        wp_send_json_success('Queue cleared.');
    }

    // ── Delete movies from library ────────────────────────────────────────────

    public static function sg_delete_movies(): void {
        self::verify();

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (empty($ids)) wp_send_json_error('No IDs provided.');

        Sinemagor_DB::delete_movies($ids);
        wp_send_json_success(['deleted' => count($ids)]);
    }

    // ── Rebuild internal links for a post ────────────────────────────────────

    public static function sg_rebuild_links(): void {
        self::verify();
        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid post ID.');
        Sinemagor_Internal_Linker::rebuild($post_id);
        wp_send_json_success('Internal links rebuilt for post #' . $post_id);
    }

    // ── Get library (for Review tab) ──────────────────────────────────────────

    public static function sg_get_library(): void {
        self::verify();

        $data = Sinemagor_DB::get_movies([
            'status'   => sanitize_text_field($_POST['status']   ?? ''),
            'genre'    => sanitize_text_field($_POST['genre']    ?? ''),
            'year'     => sanitize_text_field($_POST['year']     ?? ''),
            'search'   => sanitize_text_field($_POST['search']   ?? ''),
            'per_page' => max(1, min(50, (int) ($_POST['per_page'] ?? 20))),
            'page'     => max(1, (int) ($_POST['page'] ?? 1)),
        ]);

        // Append poster thumb URLs
        foreach ($data['rows'] as &$row) {
            $row->poster_thumb = $row->poster_path
                ? Sinemagor_TMDB::image_url($row->poster_path, 'w92')
                : '';
        }

        wp_send_json_success($data);
    }
}
