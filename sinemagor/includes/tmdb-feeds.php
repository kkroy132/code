<?php
defined('ABSPATH') || exit;

class Sinemagor_TMDB_Feeds {

    const FEEDS = [
        'trending'    => '/trending/movie/day',
        'popular'     => '/movie/popular',
        'top_rated'   => '/movie/top_rated',
        'upcoming'    => '/movie/upcoming',
        'now_playing' => '/movie/now_playing',
    ];

    /**
     * Fetch movies from a specific feed.
     * Returns array of basic movie data (same format as TMDB::format_list).
     */
    public static function fetch(string $feed, int $page = 1): array {
        $endpoint = self::FEEDS[$feed] ?? self::FEEDS['trending'];
        $tmdb     = new Sinemagor_TMDB();
        return $tmdb->fetch_feed($endpoint, $page);
    }

    /**
     * Fetch + filter + return only new movies not already in library.
     * Applies: language filter, min rating filter, duplicate check.
     */
    public static function fetch_new(string $feed, int $limit, float $min_rating, string $language): array {
        $collected = [];
        $page      = 1;
        $max_pages = 3; // max 3 pages to avoid too many TMDB calls

        while (count($collected) < $limit && $page <= $max_pages) {
            $results = self::fetch($feed, $page);
            if (empty($results)) break;

            foreach ($results as $movie) {
                if (count($collected) >= $limit) break;

                // Language filter
                if ($language && $movie['language'] !== $language) continue;

                // Rating filter
                if ($min_rating > 0 && $movie['tmdb_rating'] < $min_rating) continue;

                // Duplicate check
                if (Sinemagor_DB::get_movie_by_tmdb((int) $movie['tmdb_id'])) continue;

                $collected[] = $movie;
            }
            $page++;
        }

        return $collected;
    }
}
