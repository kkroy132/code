<?php
defined('ABSPATH') || exit;

class Sinemagor_Schema {

    public static function init(): void {
        add_action('wp_head', [self::class, 'output_schema']);
    }

    public static function output_schema(): void {
        if (!is_single()) return;

        $post_id = get_the_ID();
        $tmdb_id = get_post_meta($post_id, '_sinemagor_tmdb_id', true);
        if (!$tmdb_id) return;

        $title          = get_the_title();
        $year           = get_post_meta($post_id, '_sinemagor_year',         true);
        $genre          = get_post_meta($post_id, '_sinemagor_genre',        true);
        $director       = get_post_meta($post_id, '_sinemagor_director',     true);
        $editor_rating  = get_post_meta($post_id, '_sinemagor_editor_rating',true);
        $tmdb_rating    = get_post_meta($post_id, '_sinemagor_tmdb_rating',  true);
        $verdict        = get_post_meta($post_id, '_sinemagor_verdict',      true);
        $runtime        = get_post_meta($post_id, '_sinemagor_runtime',      true);
        $imdb_id        = get_post_meta($post_id, '_sinemagor_imdb_id',      true);
        $poster_path    = get_post_meta($post_id, '_sinemagor_poster',       true);
        $poster_url     = $poster_path ? Sinemagor_TMDB::image_url($poster_path, 'w500') : '';

        $cast_json  = get_post_meta($post_id, '_sinemagor_cast', true);
        $cast       = $cast_json ? json_decode($cast_json, true) : [];
        $actors     = array_map(fn($c) => [
            '@type' => 'Person',
            'name'  => $c['name'],
        ], array_slice($cast ?: [], 0, 5));

        $site_name = get_bloginfo('name');
        $site_url  = home_url();

        // Runtime in ISO 8601 duration (PT2H30M)
        $duration = '';
        if ($runtime) {
            $h = floor($runtime / 60);
            $m = $runtime % 60;
            $duration = 'PT' . ($h ? "{$h}H" : '') . ($m ? "{$m}M" : '');
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                // Movie entity
                [
                    '@type'       => 'Movie',
                    '@id'         => $site_url . '/#movie-' . $tmdb_id,
                    'name'        => $title,
                    'dateCreated' => $year ?: '',
                    'genre'       => $genre ? explode(', ', $genre) : [],
                    'director'    => $director ? [['@type' => 'Person', 'name' => $director]] : [],
                    'actor'       => $actors,
                    'image'       => $poster_url ?: '',
                    'duration'    => $duration,
                    'sameAs'      => array_filter([
                        'https://www.themoviedb.org/movie/' . $tmdb_id,
                        $imdb_id ? 'https://www.imdb.com/title/' . $imdb_id . '/' : '',
                    ]),
                    'aggregateRating' => $tmdb_rating ? [
                        '@type'       => 'AggregateRating',
                        'ratingValue' => (string) $tmdb_rating,
                        'bestRating'  => '10',
                        'worstRating' => '1',
                        'ratingCount' => '1000',
                    ] : null,
                ],
                // Review entity
                [
                    '@type'        => 'Review',
                    'itemReviewed' => ['@id' => $site_url . '/#movie-' . $tmdb_id],
                    'author'       => ['@type' => 'Organization', 'name' => $site_name, 'url' => $site_url],
                    'url'          => get_permalink($post_id),
                    'datePublished'=> get_the_date('c', $post_id),
                    'dateModified' => get_the_modified_date('c', $post_id),
                    'reviewBody'   => $verdict ?: '',
                    'reviewRating' => $editor_rating ? [
                        '@type'       => 'Rating',
                        'ratingValue' => (string) $editor_rating,
                        'bestRating'  => '10',
                        'worstRating' => '1',
                    ] : null,
                ],
                // BreadcrumbList
                [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',         'item' => $site_url],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Movie Reviews','item' => $site_url . '/movie-reviews/'],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $title,         'item' => get_permalink($post_id)],
                    ],
                ],
            ],
        ];

        // Remove null values recursively
        $schema = self::clean($schema);

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";
    }

    private static function clean(array $arr): array {
        foreach ($arr as $k => $v) {
            if (is_null($v) || $v === '' || $v === []) {
                unset($arr[$k]);
            } elseif (is_array($v)) {
                $arr[$k] = self::clean($v);
            }
        }
        return $arr;
    }
}
