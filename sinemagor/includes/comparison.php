<?php
defined('ABSPATH') || exit;

class Sinemagor_Comparison {

    public static function init(): void {
        add_action('wp_ajax_sg_comparison_movies', [self::class, 'ajax_get_movies']);
        add_action('wp_ajax_sg_comparison_generate', [self::class, 'ajax_generate']);
    }

    /**
     * Generate a comparison post between two movies via AI.
     */
    public static function generate(int $movie_a_id, int $movie_b_id) {
        $a = Sinemagor_DB::get_movie($movie_a_id);
        $b = Sinemagor_DB::get_movie($movie_b_id);
        if (!$a || !$b) return new WP_Error('not_found', 'One or both movies not found.');

        $api_key = Sinemagor_Settings::get('openrouter_api_key');
        $model   = Sinemagor_Settings::get('ai_model', 'deepseek/deepseek-chat');
        if (!$api_key) return new WP_Error('no_key', 'OpenRouter API key not set.');

        $cast_a = self::cast_names($a);
        $cast_b = self::cast_names($b);

        $prompt = "Write an SEO-optimized comparison article: \"{$a->title} vs {$b->title}\".

Movie A — {$a->title} ({$a->year}):
Genre: {$a->genre} | Director: {$a->director} | TMDB: {$a->tmdb_rating}/10
Cast: {$cast_a} | Runtime: {$a->runtime} min

Movie B — {$b->title} ({$b->year}):
Genre: {$b->genre} | Director: {$b->director} | TMDB: {$b->tmdb_rating}/10
Cast: {$cast_b} | Runtime: {$b->runtime} min

RULES:
- Simple English. Flesch Reading Ease 60+. Sentences max 18 words.
- Paragraphs: 3-4 sentences. Active voice. Transition words.
- No spoilers. Fair and balanced comparison.
- Compare: story/plot, direction, performances, visuals, pacing, rewatchability.
- End with a clear winner recommendation for different audiences.

Return ONLY valid JSON (no markdown):
{
  \"seo_title\": \"max 60 chars with both movie names\",
  \"meta_description\": \"150-160 chars\",
  \"intro\": \"2-3 sentence hook explaining why this comparison matters\",
  \"story_comparison\": \"paragraph comparing plots without spoilers\",
  \"direction_comparison\": \"paragraph comparing direction styles\",
  \"performances_comparison\": \"paragraph comparing acting\",
  \"visuals_comparison\": \"paragraph comparing cinematography and visuals\",
  \"pacing_comparison\": \"paragraph comparing pace and runtime feel\",
  \"rewatchability\": \"paragraph on which to rewatch and why\",
  \"verdict_a\": \"one sentence who should watch Movie A\",
  \"verdict_b\": \"one sentence who should watch Movie B\",
  \"winner\": \"title of overall winner or 'Tie'\",
  \"winner_reason\": \"2-3 sentences explaining the pick\",
  \"keywords\": [\"keyword1\",\"keyword2\",\"keyword3\",\"keyword4\",\"keyword5\"]
}";

        $res = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'max_tokens'  => 2500,
                'temperature' => 0.7,
                'messages'    => [
                    ['role' => 'system', 'content' => 'You are an expert movie critic. Return only valid JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($res)) return $res;

        $body  = json_decode(wp_remote_retrieve_body($res), true);
        $code  = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return new WP_Error('api', $body['error']['message'] ?? 'API error');

        $raw   = $body['choices'][0]['message']['content'] ?? '';
        $clean = trim(preg_replace('/^```json|^```|```$/m', '', $raw));
        $data  = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data))
            return new WP_Error('parse', 'Could not parse AI response.');

        // Cost tracking
        if (class_exists('Sinemagor_Cost_Tracker') && !empty($body['usage'])) {
            Sinemagor_Cost_Tracker::log([
                'model'         => $model,
                'input_tokens'  => $body['usage']['prompt_tokens']     ?? 0,
                'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
                'movie_title'   => "{$a->title} vs {$b->title}",
                'source'        => 'manual',
            ]);
        }

        return ['ai' => $data, 'movie_a' => $a, 'movie_b' => $b];
    }

    /**
     * Build comparison post HTML content.
     */
    public static function build_content(array $result): string {
        $ai = $result['ai'];
        $a  = $result['movie_a'];
        $b  = $result['movie_b'];

        $poster_a  = $a->poster_path ? Sinemagor_TMDB::image_url($a->poster_path, 'w342') : '';
        $poster_b  = $b->poster_path ? Sinemagor_TMDB::image_url($b->poster_path, 'w342') : '';
        $url_a     = $a->wp_post_id  ? get_permalink($a->wp_post_id) : '';
        $url_b     = $b->wp_post_id  ? get_permalink($b->wp_post_id) : '';

        $html  = '';

        // ── Hero comparison bar ──
        $html .= '<div class="sg-vs-hero">';
        $html .= '<div class="sg-vs-side sg-vs-side--a">';
        if ($poster_a) {
            $html .= $url_a
                ? '<a href="' . esc_url($url_a) . '"><img src="' . esc_url($poster_a) . '" alt="' . esc_attr($a->title) . '" loading="lazy" /></a>'
                : '<img src="' . esc_url($poster_a) . '" alt="' . esc_attr($a->title) . '" loading="lazy" />';
        }
        $html .= '<div class="sg-vs-movie-name">' . esc_html($a->title) . ' <span>(' . esc_html($a->year) . ')</span></div>';
        $html .= '<div class="sg-vs-rating">⭐ ' . esc_html($a->tmdb_rating) . '/10</div>';
        $html .= '</div>';

        $html .= '<div class="sg-vs-badge">VS</div>';

        $html .= '<div class="sg-vs-side sg-vs-side--b">';
        if ($poster_b) {
            $html .= $url_b
                ? '<a href="' . esc_url($url_b) . '"><img src="' . esc_url($poster_b) . '" alt="' . esc_attr($b->title) . '" loading="lazy" /></a>'
                : '<img src="' . esc_url($poster_b) . '" alt="' . esc_attr($b->title) . '" loading="lazy" />';
        }
        $html .= '<div class="sg-vs-movie-name">' . esc_html($b->title) . ' <span>(' . esc_html($b->year) . ')</span></div>';
        $html .= '<div class="sg-vs-rating">⭐ ' . esc_html($b->tmdb_rating) . '/10</div>';
        $html .= '</div>';
        $html .= '</div>';

        // ── Quick stats table ──
        $html .= '<div class="sg-vs-stats-table">';
        $html .= '<table><thead><tr><th>' . esc_html($a->title) . '</th><th>Category</th><th>' . esc_html($b->title) . '</th></tr></thead><tbody>';
        $rows = [
            [$a->director ?? '—',  'Director',  $b->director ?? '—'],
            [$a->genre    ?? '—',  'Genre',     $b->genre    ?? '—'],
            [($a->runtime ?? '—') . ' min', 'Runtime', ($b->runtime ?? '—') . ' min'],
            [$a->tmdb_rating ?? '—', 'TMDB Rating', $b->tmdb_rating ?? '—'],
        ];
        foreach ($rows as $row) {
            $html .= '<tr><td>' . esc_html($row[0]) . '</td><td class="sg-vs-cat">' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        // ── Intro ──
        $html .= '<p class="sg-vs-intro">' . esc_html($ai['intro'] ?? '') . '</p>';

        // ── Comparison sections ──
        $sections = [
            'story_comparison'       => '📖 Story & Plot',
            'direction_comparison'   => '🎬 Direction',
            'performances_comparison'=> '🎭 Performances',
            'visuals_comparison'     => '🎥 Visuals & Cinematography',
            'pacing_comparison'      => '⏱ Pacing & Runtime',
            'rewatchability'         => '🔁 Rewatchability',
        ];

        foreach ($sections as $key => $label) {
            if (empty($ai[$key])) continue;
            $html .= '<h2>' . esc_html($label) . '</h2>';
            $html .= '<p>' . esc_html($ai[$key]) . '</p>';
        }

        // ── Verdict boxes ──
        $html .= '<div class="sg-vs-verdicts">';
        $html .= '<div class="sg-vs-verdict sg-vs-verdict--a">';
        $html .= '<div class="sg-vs-verdict-title">Watch <strong>' . esc_html($a->title) . '</strong> if...</div>';
        $html .= '<p>' . esc_html($ai['verdict_a'] ?? '') . '</p>';
        if ($url_a) $html .= '<a href="' . esc_url($url_a) . '" class="sg-vs-verdict-link">Read Full Review →</a>';
        $html .= '</div>';

        $html .= '<div class="sg-vs-verdict sg-vs-verdict--b">';
        $html .= '<div class="sg-vs-verdict-title">Watch <strong>' . esc_html($b->title) . '</strong> if...</div>';
        $html .= '<p>' . esc_html($ai['verdict_b'] ?? '') . '</p>';
        if ($url_b) $html .= '<a href="' . esc_url($url_b) . '" class="sg-vs-verdict-link">Read Full Review →</a>';
        $html .= '</div>';
        $html .= '</div>';

        // ── Winner banner ──
        $winner = $ai['winner'] ?? '';
        if ($winner) {
            $html .= '<div class="sg-vs-winner">';
            $html .= '<div class="sg-vs-winner-label">🏆 Overall Winner</div>';
            $html .= '<div class="sg-vs-winner-name">' . esc_html($winner) . '</div>';
            $html .= '<p>' . esc_html($ai['winner_reason'] ?? '') . '</p>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Publish the comparison post to WordPress.
     */
    public static function publish(array $result) {
        $ai      = $result['ai'];
        $a       = $result['movie_a'];
        $b       = $result['movie_b'];
        $content = self::build_content($result);
        $status  = Sinemagor_Settings::get('auto_publish', 0) ? 'publish' : 'draft';
        $cat     = (int) Sinemagor_Settings::get('post_category', 0);

        $post_id = wp_insert_post([
            'post_title'    => wp_strip_all_tags($ai['seo_title'] ?? "{$a->title} vs {$b->title}"),
            'post_content'  => $content,
            'post_status'   => $status,
            'post_type'     => 'post',
            'post_category' => $cat ? [$cat] : [],
            'meta_input'    => [
                '_sinemagor_comparison'    => 1,
                '_sinemagor_compare_a'     => $a->id,
                '_sinemagor_compare_b'     => $b->id,
                '_sinemagor_tmdb_id'       => $a->tmdb_id, // for schema/linker
                '_yoast_wpseo_title'       => $ai['seo_title']        ?? '',
                '_yoast_wpseo_metadesc'    => $ai['meta_description'] ?? '',
                'rank_math_title'          => $ai['seo_title']        ?? '',
                'rank_math_description'    => $ai['meta_description'] ?? '',
                'rank_math_focus_keyword'  => implode(',', array_slice($ai['keywords'] ?? [], 0, 2)),
            ],
        ], true);

        if (is_wp_error($post_id)) return $post_id;

        if (!empty($ai['keywords'])) wp_set_post_tags($post_id, $ai['keywords'], false);

        // Generate FAQ
        if (class_exists('Sinemagor_FAQ_Generator')) {
            Sinemagor_FAQ_Generator::generate($post_id);
        }

        return $post_id;
    }

    // ── Ajax handlers ─────────────────────────────────────────────────────────

    public static function ajax_get_movies(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $data = Sinemagor_DB::get_movies([
            'status'   => 'published',
            'per_page' => 200,
            'page'     => 1,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'search'   => sanitize_text_field($_POST['search'] ?? ''),
        ]);

        $rows = array_map(fn($m) => [
            'id'           => $m->id,
            'title'        => $m->title,
            'year'         => $m->year,
            'genre'        => $m->genre,
            'tmdb_rating'  => $m->tmdb_rating,
            'poster_thumb' => $m->poster_path ? Sinemagor_TMDB::image_url($m->poster_path, 'w92') : '',
        ], $data['rows']);

        wp_send_json_success($rows);
    }

    public static function ajax_generate(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $id_a = (int) ($_POST['movie_a'] ?? 0);
        $id_b = (int) ($_POST['movie_b'] ?? 0);
        if (!$id_a || !$id_b || $id_a === $id_b) wp_send_json_error('Select two different movies.');

        $result = self::generate($id_a, $id_b);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        $post_id = self::publish($result);
        if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());

        wp_send_json_success([
            'post_id'  => $post_id,
            'post_url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'status'   => get_post_status($post_id),
            'title'    => get_the_title($post_id),
        ]);
    }

    private static function cast_names(object $movie): string {
        if (empty($movie->cast_json)) return '';
        $cast = json_decode($movie->cast_json, true) ?: [];
        return implode(', ', array_column(array_slice($cast, 0, 3), 'name'));
    }
}
