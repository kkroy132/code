<?php
defined('ABSPATH') || exit;

class Sinemagor_List_Generator {

    // Available templates
    const TEMPLATES = [
        'best_of_year'      => 'Best Movies of [Year]',
        'best_of_genre'     => 'Best [Genre] Movies Ever',
        'best_of_director'  => 'All [Director] Movies Ranked',
        'best_of_streaming' => 'Best Movies on [Platform] Right Now',
        'similar_movies'    => 'Movies Like [Movie] You\'ll Love',
        'hidden_gems'       => 'Underrated [Genre] Movies You Missed',
        'best_of_country'   => 'Best [Country] Movies of All Time',
        'custom'            => 'Custom Title',
    ];

    public static function init(): void {
        add_action('wp_ajax_sg_list_preview',  [self::class, 'ajax_preview']);
        add_action('wp_ajax_sg_list_generate', [self::class, 'ajax_generate']);
        add_action('wp_ajax_sg_list_movies',   [self::class, 'ajax_get_movies']);
    }

    /**
     * Get movies from library for list building.
     * Filters by genre, year, min_rating — returns top N by editor/TMDB rating.
     */
    public static function get_movies_for_list(array $filters): array {
        $genre      = sanitize_text_field($filters['genre']      ?? '');
        $year       = sanitize_text_field($filters['year']       ?? '');
        $count      = max(3, min(20, (int) ($filters['count']    ?? 10)));
        $min_rating = (float) ($filters['min_rating']            ?? 0);
        $platform   = sanitize_text_field($filters['platform']   ?? '');
        $director   = sanitize_text_field($filters['director']   ?? '');

        $data = Sinemagor_DB::get_movies([
            'status'   => 'published',
            'genre'    => $genre,
            'year'     => $year,
            'per_page' => 100,
            'page'     => 1,
            'orderby'  => 'tmdb_rating',
            'order'    => 'DESC',
        ]);

        $rows = $data['rows'] ?? [];

        // Director filter
        if ($director) {
            $rows = array_filter($rows, fn($r) =>
                stripos($r->director ?? '', $director) !== false
            );
        }

        // Min rating filter
        if ($min_rating > 0) {
            $rows = array_filter($rows, fn($r) =>
                (float) ($r->tmdb_rating ?? 0) >= $min_rating
            );
        }

        // Sort by TMDB rating desc
        usort($rows, fn($a, $b) =>
            (float)($b->tmdb_rating ?? 0) <=> (float)($a->tmdb_rating ?? 0)
        );

        return array_slice(array_values($rows), 0, $count);
    }

    /**
     * Generate full list post via AI.
     * Returns array: [title, meta_description, intro, items[], conclusion, keywords]
     */
    public static function generate(array $params) {
        $api_key  = Sinemagor_Settings::get('openrouter_api_key');
        $model    = Sinemagor_Settings::get('ai_model', 'deepseek/deepseek-chat');
        if (!$api_key) return new WP_Error('no_key', 'OpenRouter API key not set.');

        $movies   = $params['movies']   ?? [];
        $template = $params['template'] ?? 'custom';
        $title    = $params['title']    ?? '';
        $genre    = $params['genre']    ?? '';
        $year     = $params['year']     ?? '';

        if (empty($movies)) return new WP_Error('no_movies', 'No movies selected.');

        // Build movie list for prompt
        $movie_list = '';
        foreach ($movies as $i => $m) {
            $num  = $i + 1;
            $cast = [];
            if (!empty($m->cast_json)) {
                $c    = json_decode($m->cast_json, true) ?: [];
                $cast = array_column(array_slice($c, 0, 3), 'name');
            }
            $movie_list .= "{$num}. {$m->title} ({$m->year}) — Genre: {$m->genre}, "
                         . "Director: {$m->director}, TMDB: {$m->tmdb_rating}/10"
                         . (!empty($cast) ? ', Cast: ' . implode(', ', $cast) : '')
                         . "\n";
        }

        $count  = count($movies);
        $prompt = "Write a compelling, SEO-optimized list article titled: \"{$title}\"

Movies to include (in this order):
{$movie_list}

RULES:
- Write in simple English. Flesch Reading Ease 60+.
- Sentences: 15-18 words max. Paragraphs: 3-4 sentences.
- Active voice. Use transition words.
- Each movie entry: 2-3 short paragraphs covering story, performances, why it's on this list.
- No spoilers.
- Intro: 2-3 sentences explaining why this list matters.
- Conclusion: 2-3 sentences recommending where to start.

Return ONLY valid JSON (no markdown):
{
  \"seo_title\": \"SEO title max 60 chars\",
  \"meta_description\": \"150-160 char meta description\",
  \"intro\": \"2-3 sentence intro paragraph\",
  \"items\": [
    {
      \"rank\": 1,
      \"title\": \"Movie Title\",
      \"year\": \"2024\",
      \"tagline\": \"One punchy sentence why it's on this list\",
      \"body\": \"2-3 paragraphs about this movie\",
      \"rating\": \"9.2\"
    }
  ],
  \"conclusion\": \"2-3 sentence conclusion\",
  \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\", \"keyword4\", \"keyword5\"]
}";

        $res = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'max_tokens'  => 3000,
                'temperature' => 0.7,
                'messages'    => [
                    ['role' => 'system', 'content' => 'You are an expert movie critic and SEO content writer. Return only valid JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($res)) return $res;

        $body  = json_decode(wp_remote_retrieve_body($res), true);
        $code  = wp_remote_retrieve_response_code($res);

        if ($code !== 200) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'API error');
        }

        $raw   = $body['choices'][0]['message']['content'] ?? '';
        $clean = trim(preg_replace('/^```json|^```|```$/m', '', $raw));
        $data  = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return new WP_Error('parse_error', 'Could not parse AI response.');
        }

        // Cost tracking
        if (class_exists('Sinemagor_Cost_Tracker') && !empty($body['usage'])) {
            Sinemagor_Cost_Tracker::log([
                'model'         => $model,
                'input_tokens'  => $body['usage']['prompt_tokens']     ?? 0,
                'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
                'movie_title'   => $title,
                'source'        => 'manual',
            ]);
        }

        return $data;
    }

    /**
     * Build the full WordPress post HTML from AI data + movie library data.
     */
    public static function build_post_content(array $ai, array $movies): string {
        $html = '';

        // Intro
        $html .= '<p class="sg-list-intro">' . esc_html($ai['intro'] ?? '') . '</p>';

        // Movie items
        foreach ($ai['items'] ?? [] as $item) {
            $rank  = (int) ($item['rank'] ?? 0);
            $title = $item['title'] ?? '';

            // Find matching movie from library
            $movie = null;
            foreach ($movies as $m) {
                if (strtolower(trim($m->title)) === strtolower(trim($title))) {
                    $movie = $m;
                    break;
                }
            }
            // Fallback: match by rank index
            if (!$movie && isset($movies[$rank - 1])) {
                $movie = $movies[$rank - 1];
            }

            $poster_url   = '';
            $review_url   = '';
            $runtime_fmt  = '';

            if ($movie) {
                $poster_url  = $movie->poster_path ? Sinemagor_TMDB::image_url($movie->poster_path, 'w342') : '';
                $runtime_fmt = $movie->runtime
                    ? floor($movie->runtime / 60) . 'h ' . ($movie->runtime % 60) . 'm'
                    : '';
                // Find existing WP post
                if ($movie->wp_post_id) {
                    $review_url = get_permalink($movie->wp_post_id);
                }
            }

            $html .= '<div class="sg-list-item" id="sg-item-' . $rank . '">';

            // Rank badge + title
            $html .= '<div class="sg-list-item-header">';
            $html .= '<span class="sg-list-rank">#' . $rank . '</span>';
            $html .= '<h2 class="sg-list-movie-title">' . esc_html($title);
            if (!empty($item['year'])) $html .= ' <span class="sg-list-year">(' . esc_html($item['year']) . ')</span>';
            $html .= '</h2>';
            $html .= '</div>';

            // Poster + meta
            $html .= '<div class="sg-list-item-body">';
            if ($poster_url) {
                $html .= '<div class="sg-list-poster">';
                if ($review_url) $html .= '<a href="' . esc_url($review_url) . '">';
                $html .= '<img src="' . esc_url($poster_url) . '" alt="' . esc_attr($title) . ' poster" loading="lazy" />';
                if ($review_url) $html .= '</a>';
                $html .= '</div>';
            }

            $html .= '<div class="sg-list-item-content">';

            // Tagline
            if (!empty($item['tagline'])) {
                $html .= '<p class="sg-list-tagline">💬 ' . esc_html($item['tagline']) . '</p>';
            }

            // Quick info
            if ($movie) {
                $html .= '<div class="sg-list-meta">';
                if ($movie->genre)    $html .= '<span>🎭 ' . esc_html(explode(',', $movie->genre)[0]) . '</span>';
                if ($movie->director) $html .= '<span>🎬 ' . esc_html($movie->director) . '</span>';
                if ($runtime_fmt)     $html .= '<span>⏱ ' . esc_html($runtime_fmt) . '</span>';
                if ($movie->tmdb_rating) $html .= '<span>⭐ TMDB ' . esc_html($movie->tmdb_rating) . '</span>';
                if (!empty($item['rating'])) $html .= '<span class="sg-list-our-rating">🏆 Our Pick: ' . esc_html($item['rating']) . '/10</span>';
                $html .= '</div>';
            }

            // Body text
            $html .= '<div class="sg-list-body-text">';
            $html .= '<p>' . nl2br(esc_html($item['body'] ?? '')) . '</p>';
            $html .= '</div>';

            // Read full review link (internal)
            if ($review_url) {
                $html .= '<a href="' . esc_url($review_url) . '" class="sg-list-review-btn">Read Full Review →</a>';
            }

            $html .= '</div>'; // .sg-list-item-content
            $html .= '</div>'; // .sg-list-item-body
            $html .= '</div>'; // .sg-list-item
        }

        // Conclusion
        if (!empty($ai['conclusion'])) {
            $html .= '<div class="sg-list-conclusion">';
            $html .= '<h2>Final Thoughts</h2>';
            $html .= '<p>' . esc_html($ai['conclusion']) . '</p>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Publish the list post to WordPress.
     */
    public static function publish_post(array $ai, array $movies, array $params) {
        $content = self::build_post_content($ai, $movies);
        $status  = Sinemagor_Settings::get('auto_publish', 0) ? 'publish' : 'draft';
        $cat     = (int) Sinemagor_Settings::get('post_category', 0);

        $post_id = wp_insert_post([
            'post_title'    => wp_strip_all_tags($ai['seo_title'] ?? $params['title']),
            'post_content'  => $content,
            'post_status'   => $status,
            'post_type'     => 'post',
            'post_category' => $cat ? [$cat] : [],
            'meta_input'    => [
                '_sinemagor_list_post'       => 1,
                '_sinemagor_list_template'   => $params['template'] ?? '',
                '_sinemagor_list_genre'      => $params['genre']    ?? '',
                '_sinemagor_list_year'       => $params['year']     ?? '',
                '_sinemagor_list_movie_ids'  => wp_json_encode(array_column($movies, 'id')),
                '_yoast_wpseo_title'         => $ai['seo_title']        ?? '',
                '_yoast_wpseo_metadesc'      => $ai['meta_description'] ?? '',
                'rank_math_title'            => $ai['seo_title']        ?? '',
                'rank_math_description'      => $ai['meta_description'] ?? '',
                'rank_math_focus_keyword'    => implode(',', array_slice($ai['keywords'] ?? [], 0, 2)),
            ],
        ], true);

        if (is_wp_error($post_id)) return $post_id;

        // Tags from keywords
        if (!empty($ai['keywords'])) {
            wp_set_post_tags($post_id, $ai['keywords'], false);
        }

        // Generate FAQ for list post too
        if (class_exists('Sinemagor_FAQ_Generator')) {
            Sinemagor_FAQ_Generator::generate($post_id);
        }

        return $post_id;
    }

    // ── Ajax handlers ─────────────────────────────────────────────────────────

    /** Get movies available for a list (filtered) */
    public static function ajax_get_movies(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $movies = self::get_movies_for_list([
            'genre'      => sanitize_text_field($_POST['genre']      ?? ''),
            'year'       => sanitize_text_field($_POST['year']       ?? ''),
            'director'   => sanitize_text_field($_POST['director']   ?? ''),
            'min_rating' => sanitize_text_field($_POST['min_rating'] ?? ''),
            'count'      => (int) ($_POST['count'] ?? 10),
        ]);

        // Add poster thumb + wp post url
        foreach ($movies as &$m) {
            $m->poster_thumb = $m->poster_path
                ? Sinemagor_TMDB::image_url($m->poster_path, 'w92')
                : '';
            $m->review_url = $m->wp_post_id ? get_permalink($m->wp_post_id) : '';
        }

        wp_send_json_success($movies);
    }

    /** Preview title based on template + params */
    public static function ajax_preview(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        $template = sanitize_text_field($_POST['template'] ?? 'custom');
        $genre    = sanitize_text_field($_POST['genre']    ?? '');
        $year     = sanitize_text_field($_POST['year']     ?? '');
        $director = sanitize_text_field($_POST['director'] ?? '');
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $movie    = sanitize_text_field($_POST['movie']    ?? '');
        $count    = (int) ($_POST['count'] ?? 10);

        $title = self::build_title($template, compact('genre','year','director','platform','movie','count'));
        wp_send_json_success(['title' => $title]);
    }

    /** Full generate + publish */
    public static function ajax_generate(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $template   = sanitize_text_field($_POST['template']   ?? 'custom');
        $genre      = sanitize_text_field($_POST['genre']      ?? '');
        $year       = sanitize_text_field($_POST['year']       ?? '');
        $director   = sanitize_text_field($_POST['director']   ?? '');
        $platform   = sanitize_text_field($_POST['platform']   ?? '');
        $movie_ref  = sanitize_text_field($_POST['movie_ref']  ?? '');
        $custom_title = sanitize_text_field($_POST['custom_title'] ?? '');
        $count      = max(3, min(20, (int) ($_POST['count'] ?? 10)));
        $min_rating = (float) ($_POST['min_rating'] ?? 0);

        // Selected movie IDs (manual override) or auto-pick
        $selected_ids = array_map('intval', (array) ($_POST['movie_ids'] ?? []));

        if (!empty($selected_ids)) {
            $movies = array_filter(array_map(
                fn($id) => Sinemagor_DB::get_movie($id),
                $selected_ids
            ));
            $movies = array_values($movies);
        } else {
            $movies = self::get_movies_for_list(compact('genre','year','director','min_rating','count'));
        }

        if (empty($movies)) {
            wp_send_json_error('No movies found. Add more movies to your library first.');
        }

        $title = $custom_title ?: self::build_title(
            $template,
            compact('genre','year','director','platform','count') + ['movie' => $movie_ref]
        );

        $ai = self::generate([
            'movies'   => $movies,
            'template' => $template,
            'title'    => $title,
            'genre'    => $genre,
            'year'     => $year,
        ]);

        if (is_wp_error($ai)) {
            wp_send_json_error($ai->get_error_message());
        }

        $post_id = self::publish_post($ai, $movies, [
            'template' => $template,
            'title'    => $title,
            'genre'    => $genre,
            'year'     => $year,
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id->get_error_message());
        }

        wp_send_json_success([
            'post_id'   => $post_id,
            'post_url'  => get_permalink($post_id),
            'edit_url'  => get_edit_post_link($post_id, 'raw'),
            'status'    => get_post_status($post_id),
            'title'     => get_the_title($post_id),
        ]);
    }

    // ── Title builder ─────────────────────────────────────────────────────────

    public static function build_title(string $template, array $p): string {
        $count    = (int) ($p['count']    ?? 10);
        $genre    = $p['genre']    ?? '';
        $year     = $p['year']     ?? date('Y');
        $director = $p['director'] ?? '';
        $platform = $p['platform'] ?? 'Netflix';
        $movie    = $p['movie']    ?? '';

        return match($template) {
            'best_of_year'      => "{$count} Best Movies of {$year} You Must Watch",
            'best_of_genre'     => "{$count} Best " . ($genre ?: 'Movies') . " of All Time",
            'best_of_director'  => "All " . ($director ?: 'Director') . " Movies Ranked Worst to Best",
            'best_of_streaming' => "{$count} Best Movies on " . ($platform ?: 'Netflix') . " Right Now",
            'similar_movies'    => "{$count} Movies Like " . ($movie ?: 'This') . " You'll Love",
            'hidden_gems'       => "{$count} Underrated " . ($genre ?: '') . " Movies You Missed",
            'best_of_country'   => "{$count} Best " . ($genre ?: '') . " Movies of All Time",
            default             => $p['custom_title'] ?? "{$count} Must-Watch Movies",
        };
    }
}
