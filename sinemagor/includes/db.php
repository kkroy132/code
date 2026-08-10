<?php
defined('ABSPATH') || exit;

class Sinemagor_DB {

    /**
     * Create custom table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . SINEMAGOR_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tmdb_id       BIGINT UNSIGNED NOT NULL UNIQUE,
            title         VARCHAR(255)    NOT NULL,
            year          SMALLINT        DEFAULT NULL,
            genre         VARCHAR(255)    DEFAULT NULL,
            language      VARCHAR(100)    DEFAULT NULL,
            runtime       SMALLINT        DEFAULT NULL,
            overview      TEXT            DEFAULT NULL,
            poster_path   VARCHAR(255)    DEFAULT NULL,
            backdrop_path VARCHAR(255)    DEFAULT NULL,
            trailer_key   VARCHAR(100)    DEFAULT NULL,
            cast_json     LONGTEXT        DEFAULT NULL,
            director      VARCHAR(255)    DEFAULT NULL,
            tmdb_rating   DECIMAL(3,1)    DEFAULT NULL,
            imdb_id       VARCHAR(20)     DEFAULT NULL,
            status        ENUM('pending','draft','published') NOT NULL DEFAULT 'pending',
            wp_post_id    BIGINT UNSIGNED DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status   (status),
            KEY idx_genre    (genre(50)),
            KEY idx_year     (year),
            KEY idx_tmdb_id  (tmdb_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('sinemagor_db_version', SINEMAGOR_VERSION);
    }

    /**
     * Insert or ignore a movie (duplicate-safe via tmdb_id UNIQUE)
     */
    public static function insert_movie(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;

        // Check duplicate
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is built from $wpdb->prefix + a hardcoded internal constant, never user input; plugin's own dedicated table.
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE tmdb_id = %d", $data['tmdb_id']));
        if ($exists) return (int) $exists;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own dedicated table, not a WP core table; caching not warranted here.
        $wpdb->insert($table, [
            'tmdb_id'       => $data['tmdb_id'],
            'title'         => $data['title'],
            'year'          => $data['year']          ?? null,
            'genre'         => $data['genre']         ?? null,
            'language'      => $data['language']      ?? null,
            'runtime'       => $data['runtime']       ?? null,
            'overview'      => $data['overview']      ?? null,
            'poster_path'   => $data['poster_path']   ?? null,
            'backdrop_path' => $data['backdrop_path'] ?? null,
            'trailer_key'   => $data['trailer_key']   ?? null,
            'cast_json'     => $data['cast_json']     ?? null,
            'director'      => $data['director']      ?? null,
            'tmdb_rating'   => $data['tmdb_rating']   ?? null,
            'imdb_id'       => $data['imdb_id']       ?? null,
            'status'        => 'pending',
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Get movies with filters + pagination
     */
    public static function get_movies(array $args = []): array {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;

        $defaults = [
            'status'   => '',
            'genre'    => '',
            'year'     => '',
            'search'   => '',
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        $where  = ['%d=1'];
        $params = [1];

        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['genre'])) {
            $where[]  = 'genre LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['genre']) . '%';
        }
        if (!empty($args['year'])) {
            $where[]  = 'year = %d';
            $params[] = (int) $args['year'];
        }
        if (!empty($args['search'])) {
            $where[]  = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);
        $order_sql = sanitize_sql_orderby("{$args['orderby']} {$args['order']}") ?: 'created_at DESC';
        $offset    = ((int) $args['page'] - 1) * (int) $args['per_page'];

        $params_count = $params;
        $params_data  = array_merge($params, [(int) $args['per_page'], $offset]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a hardcoded internal constant; $where_sql is built only from %s/%d placeholder fragments above and bound via $params_count; $order_sql is passed through sanitize_sql_orderby().
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params_count));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see justification above; placeholder count is correct at runtime ($where_sql's %s/%d tokens plus the two literal %d tokens here, all bound via $params_data).
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $params_data));

        return [
            'total' => $total,
            'pages' => (int) ceil($total / $args['per_page']),
            'rows'  => $rows ?: [],
        ];
    }

    /**
     * Get a single movie by internal id
     */
    public static function get_movie(int $id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a hardcoded internal constant; plugin's own dedicated table.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }

    /**
     * Get a single movie by TMDB id
     */
    public static function get_movie_by_tmdb(int $tmdb_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see justification above.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE tmdb_id = %d", $tmdb_id)) ?: null;
    }

    /**
     * Update movie status / wp_post_id after publishing
     */
    public static function update_status(int $id, string $status, int $wp_post_id = 0): void {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;
        $data  = ['status' => $status];
        if ($wp_post_id) $data['wp_post_id'] = $wp_post_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own dedicated table.
        $wpdb->update($table, $data, ['id' => $id]);
    }

    /**
     * Delete movies from library (does NOT delete WP posts)
     */
    public static function delete_movies(array $ids): void {
        global $wpdb;
        $table        = $wpdb->prefix . SINEMAGOR_TABLE;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a hardcoded internal constant; $placeholders is a generated string of literal %d tokens (one per $ids entry), all bound via $wpdb->prepare() below.
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
    }

    /**
     * Count movies grouped by status
     */
    public static function get_status_counts(): array {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a hardcoded internal constant; no user input in this query.
        $rows  = $wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status");
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Get distinct genres for filter dropdown
     */
    public static function get_genres(): array {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a hardcoded internal constant; no user input in this query.
        $rows  = $wpdb->get_col("SELECT DISTINCT genre FROM {$table} WHERE genre != '' ORDER BY genre");
        $genres = [];
        foreach ($rows as $row) {
            foreach (explode(',', $row) as $g) {
                $g = trim($g);
                if ($g) $genres[$g] = $g;
            }
        }
        ksort($genres);
        return $genres;
    }

    /**
     * Get distinct years for filter dropdown
     */
    public static function get_years(): array {
        global $wpdb;
        $table = $wpdb->prefix . SINEMAGOR_TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a hardcoded internal constant; no user input in this query.
        return $wpdb->get_col("SELECT DISTINCT year FROM {$table} WHERE year IS NOT NULL ORDER BY year DESC") ?: [];
    }
}
