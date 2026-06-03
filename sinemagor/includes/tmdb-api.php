<?php
defined('ABSPATH') || exit;

class Sinemagor_TMDB {

    const BASE = 'https://api.themoviedb.org/3';

    // All TMDB genre IDs → names
    const GENRES = [
        28 => 'Action', 12 => 'Adventure', 16 => 'Animation',
        35 => 'Comedy', 80 => 'Crime', 99 => 'Documentary',
        18 => 'Drama', 10751 => 'Family', 14 => 'Fantasy',
        36 => 'History', 27 => 'Horror', 10402 => 'Music',
        9648 => 'Mystery', 10749 => 'Romance', 878 => 'Science Fiction',
        10770 => 'TV Movie', 53 => 'Thriller', 10752 => 'War', 37 => 'Western',
    ];

    private string $api_key;

    public function __construct() {
        $this->api_key = Sinemagor_Settings::get('tmdb_api_key');
    }

    /**
     * Search movies by query string (used in autocomplete)
     */
    public function search(string $query, int $page = 1): array {
        $resp = $this->request('/search/movie', [
            'query'         => $query,
            'page'          => $page,
            'include_adult' => 'false',
        ]);
        if (is_wp_error($resp)) return [];
        return $this->format_list($resp['results'] ?? []);
    }

    /**
     * Discover movies with filters (for Movie Library tab)
     * Special sort_by value: 'top_rated' → uses /movie/top_rated endpoint
     */
    public function discover(array $filters = []): array {
        $sort_by = $filters['sort_by'] ?? 'popularity.desc';

        $valid_sorts = [
            'popularity.desc', 'popularity.asc',
            'vote_average.desc', 'vote_average.asc',
            'release_date.desc', 'primary_release_date.desc',
            'revenue.desc', 'top_rated',
        ];
        if (!in_array($sort_by, $valid_sorts, true)) {
            $sort_by = 'popularity.desc';
        }

        // ── TMDB Top Rated uses a separate endpoint ──────────────────────────
        if ($sort_by === 'top_rated') {
            return $this->fetch_top_rated($filters);
        }

        // ── Discover endpoint (1 TMDB page = 20 results) ────────────────────
        $params = [
            'page'          => max(1, (int) ($filters['page'] ?? 1)),
            'sort_by'       => $sort_by,
            'include_adult' => 'false',
            'include_video' => 'false',
            'language'      => 'en-US',
        ];

        if (in_array($sort_by, ['vote_average.desc', 'vote_average.asc'], true)) {
            $params['vote_count.gte'] = 300;
        }
        if (!empty($filters['genre_id']))   $params['with_genres']            = $filters['genre_id'];
        if (!empty($filters['year']))       $params['primary_release_year']   = (int) $filters['year'];
        if (!empty($filters['language']))   $params['with_original_language'] = $filters['language'];
        if (!empty($filters['min_rating'])) $params['vote_average.gte']       = (float) $filters['min_rating'];

        $resp = $this->request('/discover/movie', $params);
        if (is_wp_error($resp)) return ['total' => 0, 'pages' => 0, 'results' => []];

        return [
            'total'   => $resp['total_results'] ?? 0,
            'pages'   => $resp['total_pages']   ?? 0,
            'results' => $this->format_list($resp['results'] ?? []),
        ];
    }

    /**
     * TMDB official Top Rated list — Bayesian weighted average.
     * Supports genre + language filter via post-processing.
     */
    private function fetch_top_rated(array $filters = []): array {
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $genre_id = (string) ($filters['genre_id'] ?? '');
        $language = (string) ($filters['language'] ?? '');

        // If genre/language filter needed, fetch multiple pages and filter
        if ($genre_id || $language) {
            return $this->fetch_top_rated_filtered($page, $genre_id, $language);
        }

        $resp = $this->request('/movie/top_rated', [
            'page'     => $page,
            'language' => 'en-US',
        ]);

        if (is_wp_error($resp)) return ['total' => 0, 'pages' => 0, 'results' => []];

        return [
            'total'   => $resp['total_results'] ?? 0,
            'pages'   => $resp['total_pages']   ?? 0,
            'results' => $this->format_list($resp['results'] ?? []),
        ];
    }

    /**
     * Top Rated + genre/language filter.
     * Full filtered list is cached in a transient (1 hour) so deep pagination works
     * without re-fetching hundreds of TMDB pages on every request.
     */
    private function fetch_top_rated_filtered(int $page, string $genre_id, string $language): array {
        $cache_key = 'sg_top_rated_' . md5($genre_id . '|' . $language);
        $all       = get_transient($cache_key);

        if ($all === false) {
            $all            = [];
            $max_raw_pages  = 150; // 3000 raw results; ~1500 English, ~300 per genre
            $tmdb_max_pages = 1;

            for ($p = 1; $p <= $max_raw_pages; $p++) {
                $resp = $this->request('/movie/top_rated', ['page' => $p, 'language' => 'en-US']);
                if (is_wp_error($resp) || empty($resp['results'])) break;

                $tmdb_max_pages = $resp['total_pages'] ?? 1;

                foreach ($resp['results'] as $m) {
                    if ($language && ($m['original_language'] ?? '') !== $language) continue;
                    if ($genre_id) {
                        $movie_genres = array_map('strval', $m['genre_ids'] ?? []);
                        if (!in_array($genre_id, $movie_genres, true)) continue;
                    }
                    $all[] = $m;
                }

                if ($p >= $tmdb_max_pages) break;
            }

            set_transient($cache_key, $all, HOUR_IN_SECONDS);
        }

        $per_page = 20;
        $total    = count($all);
        $offset   = ($page - 1) * $per_page;
        $slice    = array_slice($all, $offset, $per_page);

        return [
            'total'   => $total,
            'pages'   => (int) ceil($total / $per_page),
            'results' => $this->format_list($slice),
        ];
    }

    /**
     * Fetch full movie details: info + cast + trailer
     * Uses 3 TMDB endpoints in one append_to_response call (rate-limit friendly)
     */
    public function get_full(int $tmdb_id): ?array {
        $resp = $this->request("/movie/{$tmdb_id}", [
            'append_to_response' => 'credits,videos,external_ids',
        ]);
        if (is_wp_error($resp) || empty($resp['id'])) return null;

        // Extract trailer (YouTube)
        $trailer_key = '';
        foreach ($resp['videos']['results'] ?? [] as $v) {
            if ($v['site'] === 'YouTube' && in_array($v['type'], ['Trailer', 'Teaser'], true)) {
                $trailer_key = $v['key'];
                break;
            }
        }

        // Extract top 5 cast
        $cast = [];
        foreach (array_slice($resp['credits']['cast'] ?? [], 0, 5) as $c) {
            $cast[] = [
                'name'       => $c['name'],
                'character'  => $c['character'],
                'profile'    => $c['profile_path'] ? $c['profile_path'] : null,
            ];
        }

        // Extract director
        $director = '';
        foreach ($resp['credits']['crew'] ?? [] as $crew) {
            if ($crew['job'] === 'Director') { $director = $crew['name']; break; }
        }

        // Genre names
        $genre_names = implode(', ', array_map(fn($g) => $g['name'], $resp['genres'] ?? []));

        return [
            'tmdb_id'       => $resp['id'],
            'title'         => $resp['title']             ?? '',
            'year'          => isset($resp['release_date']) ? (int) substr($resp['release_date'], 0, 4) : null,
            'genre'         => $genre_names,
            'language'      => $resp['original_language'] ?? '',
            'runtime'       => $resp['runtime']           ?? null,
            'overview'      => $resp['overview']          ?? '',
            'poster_path'   => $resp['poster_path']       ?? '',
            'backdrop_path' => $resp['backdrop_path']     ?? '',
            'trailer_key'   => $trailer_key,
            'cast_json'     => wp_json_encode($cast),
            'director'      => $director,
            'tmdb_rating'   => round((float) ($resp['vote_average'] ?? 0), 1),
            'imdb_id'       => $resp['external_ids']['imdb_id'] ?? '',
        ];
    }

    /**
     * Build full poster/backdrop URL
     */
    public static function image_url(string $path, string $size = 'w500'): string {
        if (!$path) return '';
        $base = Sinemagor_Settings::get('tmdb_image_base', 'https://image.tmdb.org/t/p/');
        return $base . $size . $path;
    }

    /**
     * YouTube embed URL
     */
    public static function trailer_embed(string $key): string {
        if (!$key) return '';
        return "https://www.youtube.com/embed/{$key}?rel=0";
    }

    /**
     * YouTube watch URL (external link)
     */
    public static function trailer_link(string $key): string {
        if (!$key) return '';
        return "https://www.youtube.com/watch?v={$key}";
    }

    /**
     * Fetch a pre-defined feed endpoint (trending, popular, etc.)
     * Used by Sinemagor_TMDB_Feeds.
     */
    public function fetch_feed(string $endpoint, int $page = 1): array {
        $resp = $this->request($endpoint, ['page' => $page, 'language' => 'en-US']);
        if (is_wp_error($resp)) return [];
        return $this->format_list($resp['results'] ?? []);
    }

    // ─── private helpers ──────────────────────────────────────────────────────

    private function request(string $endpoint, array $params = []) {
        if (!$this->api_key) return new WP_Error('no_key', 'TMDB API key not set.');

        $url = self::BASE . $endpoint . '?' . http_build_query(
            array_merge(['api_key' => $this->api_key], $params)
        );

        $resp = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($resp))  return $resp;
        if (wp_remote_retrieve_response_code($resp) !== 200)
            return new WP_Error('tmdb_error', wp_remote_retrieve_body($resp));

        return json_decode(wp_remote_retrieve_body($resp), true);
    }

    private function format_list(array $results): array {
        return array_map(function ($m) {
            $genre_ids = $m['genre_ids'] ?? [];
            $genres    = array_map(fn($id) => self::GENRES[$id] ?? '', $genre_ids);
            $genres    = implode(', ', array_filter($genres));

            return [
                'tmdb_id'      => $m['id'],
                'title'        => $m['title']            ?? '',
                'year'         => isset($m['release_date']) ? (int) substr($m['release_date'], 0, 4) : null,
                'genre'        => $genres,
                'language'     => $m['original_language'] ?? '',
                'poster_path'  => $m['poster_path']       ?? '',
                'tmdb_rating'  => round((float) ($m['vote_average'] ?? 0), 1),
                'overview'     => $m['overview']          ?? '',
            ];
        }, $results);
    }
}
