<?php
defined('ABSPATH') || exit;

class Sinemagor_Post_Publisher {

    /**
     * Generate AI content + publish WordPress post for a single movie.
     * Returns wp_post_id on success or WP_Error.
     */
    public static function publish(int $movie_id) {
        $movie = Sinemagor_DB::get_movie($movie_id);
        if (!$movie) return new WP_Error('not_found', 'Movie not found in library.');

        if ($movie->status === 'published' && $movie->wp_post_id) {
            return new WP_Error('already_published', 'This movie is already published.');
        }

        // Generate AI content
        $ai = Sinemagor_AI_Generator::generate($movie);
        if (is_wp_error($ai)) return $ai;

        // Build post content
        $content = self::build_content($movie, $ai);

        // Post status
        $auto_publish = Sinemagor_Settings::get('auto_publish', 0);
        $post_status  = $auto_publish ? 'publish' : 'draft';

        // Category
        $cat = (int) Sinemagor_Settings::get('post_category', 0);

        // Insert WP post
        $post_id = wp_insert_post([
            'post_title'   => wp_strip_all_tags($ai['seo_title'] ?? $movie->title . ' Review'),
            'post_content' => $content,
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'post_category'=> $cat ? [$cat] : [],
            'meta_input'   => [
                '_sinemagor_movie_id'      => $movie->id,
                '_sinemagor_tmdb_id'       => $movie->tmdb_id,
                '_sinemagor_poster'        => $movie->poster_path,
                '_sinemagor_backdrop'      => $movie->backdrop_path,
                '_sinemagor_trailer_key'   => $movie->trailer_key,
                '_sinemagor_cast'          => $movie->cast_json,
                '_sinemagor_director'      => $movie->director,
                '_sinemagor_tmdb_rating'   => $movie->tmdb_rating,
                '_sinemagor_imdb_id'       => $movie->imdb_id,
                '_sinemagor_genre'         => $movie->genre,
                '_sinemagor_year'          => $movie->year,
                '_sinemagor_runtime'       => $movie->runtime,
                '_sinemagor_language'      => self::resolve_language($movie->language),
                '_sinemagor_editor_rating' => $ai['editor_rating'] ?? '',
                '_sinemagor_verdict'       => $ai['verdict']       ?? '',
                '_sinemagor_keywords'      => wp_json_encode($ai['keywords'] ?? []),
                // Yoast SEO
                '_yoast_wpseo_title'       => $ai['seo_title']        ?? '',
                '_yoast_wpseo_metadesc'    => $ai['meta_description'] ?? '',
                // RankMath SEO
                'rank_math_title'          => $ai['seo_title']        ?? '',
                'rank_math_description'    => $ai['meta_description'] ?? '',
                'rank_math_focus_keyword'  => implode(',', array_slice($ai['keywords'] ?? [], 0, 2)),
            ],
        ], true);

        if (is_wp_error($post_id)) return $post_id;

        // Add tags from keywords
        if (!empty($ai['keywords'])) {
            wp_set_post_tags($post_id, $ai['keywords'], false);
        }

        // Update library status
        Sinemagor_DB::update_status($movie->id, $post_status === 'publish' ? 'published' : 'draft', $post_id);

        return $post_id;
    }

    /**
     * Build content string only — used by auto-regenerate (no new post created).
     */
    public static function build_content_only(object $movie, array $ai): string {
        return self::build_content($movie, $ai);
    }

    // ─── Language resolver ─────────────────────────────────────────────────────
    // TMDB returns ISO 639-1 codes (e.g. "en", "fr", "ko").
    // If null or empty, default to "en". Never store "N/A".

    private static function resolve_language(?string $raw): string {
        $code = trim((string) $raw);
        return ($code !== '' && strtolower($code) !== 'n/a') ? strtolower($code) : 'en';
    }

    private static function language_label(?string $raw): string {
        $map = [
            'en' => 'English',   'fr' => 'French',    'de' => 'German',
            'es' => 'Spanish',   'it' => 'Italian',   'ja' => 'Japanese',
            'ko' => 'Korean',    'zh' => 'Chinese',   'hi' => 'Hindi',
            'pt' => 'Portuguese','ru' => 'Russian',   'ar' => 'Arabic',
            'tr' => 'Turkish',   'sv' => 'Swedish',   'da' => 'Danish',
            'nl' => 'Dutch',     'pl' => 'Polish',    'th' => 'Thai',
            'id' => 'Indonesian','vi' => 'Vietnamese','bn' => 'Bengali',
            'fa' => 'Persian',   'uk' => 'Ukrainian', 'cs' => 'Czech',
            'ro' => 'Romanian',  'hu' => 'Hungarian', 'el' => 'Greek',
            'he' => 'Hebrew',    'fi' => 'Finnish',   'no' => 'Norwegian',
        ];
        $code = self::resolve_language($raw);
        $name = $map[$code] ?? strtoupper($code);
        // Show both: "English (EN)"
        return $name . ' (' . strtoupper($code) . ')';
    }

    // ─── Paragraph helper ─────────────────────────────────────────────────────

    private static function paragraphs(string $text): string {
        if (empty(trim($text))) return '';
        $parts = preg_split('/\n{2,}/', trim($text));
        $html  = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') $html .= '<p>' . nl2br(esc_html($part)) . '</p>';
        }
        return $html ?: '<p>' . esc_html($text) . '</p>';
    }

    // ─── Section helper (H2 + content, skips if empty) ───────────────────────

    private static function section(string $heading, string $content, string $css_class = ''): string {
        if (empty(trim($content))) return '';
        $class = $css_class ? ' class="' . esc_attr($css_class) . '"' : '';
        return '<section' . $class . '><h2>' . esc_html($heading) . '</h2>' . $content . '</section>';
    }

    // ─── Build post HTML content ───────────────────────────────────────────────

    private static function build_content(object $movie, array $ai): string {

        // ── URLs & helpers ──
        $tmdb_poster   = Sinemagor_TMDB::image_url($movie->poster_path,   'w342');
        $tmdb_backdrop = Sinemagor_TMDB::image_url($movie->backdrop_path, 'w1280');
        $trailer_embed = Sinemagor_TMDB::trailer_embed($movie->trailer_key);
        $trailer_link  = Sinemagor_TMDB::trailer_link($movie->trailer_key);
        $tmdb_url      = 'https://www.themoviedb.org/movie/' . $movie->tmdb_id;
        $imdb_url      = $movie->imdb_id ? 'https://www.imdb.com/title/' . $movie->imdb_id . '/' : '';
        $wiki_url      = 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $movie->title));

        // Cast grid rendered by single-movie.php — no need to parse here

        $runtime_fmt = $movie->runtime
            ? floor($movie->runtime / 60) . 'h ' . ($movie->runtime % 60) . 'm'
            : 'N/A';

        // ── Language: always resolved, never N/A ──
        $language_display = self::language_label($movie->language);

        // ── Star builder ──
        $stars_html = static function(float $rating, float $max = 10): string {
            $filled = round(($rating / $max) * 5);
            $out = '';
            for ($i = 1; $i <= 5; $i++) {
                $out .= $i <= $filled ? '★' : '☆';
            }
            return $out;
        };

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 1 — Backdrop
        // ══════════════════════════════════════════════════════════════════════
        $backdrop_html = $tmdb_backdrop
            ? '<figure class="sg-backdrop"><img src="' . esc_url($tmdb_backdrop) . '" alt="' . esc_attr($movie->title) . ' backdrop" loading="lazy" /></figure>'
            : '';

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 2 — Header row: poster + info box
        // ══════════════════════════════════════════════════════════════════════
        $poster_html = $tmdb_poster
            ? '<figure class="sg-poster"><img src="' . esc_url($tmdb_poster) . '" alt="' . esc_attr($movie->title) . ' poster" loading="lazy" /></figure>'
            : '';

        $ed_rating   = $ai['editor_rating'] ?? '';
        $tmdb_rating = $movie->tmdb_rating  ?? '';

        $info_box = '<div class="sg-info-box"><ul>'
            . '<li><strong>Genre:</strong> '         . esc_html($movie->genre    ?: 'N/A') . '</li>'
            . '<li><strong>Director:</strong> '      . esc_html($movie->director ?: 'N/A') . '</li>'
            . '<li><strong>Year:</strong> '          . esc_html($movie->year     ?: 'N/A') . '</li>'
            . '<li><strong>Runtime:</strong> '       . esc_html($runtime_fmt)              . '</li>'
            . '<li><strong>Language:</strong> '      . esc_html($language_display)         . '</li>'
            . '<li><strong>TMDB Rating:</strong> ⭐ ' . esc_html($tmdb_rating ?: 'N/A') . '/10</li>'
            . '</ul></div>';

        $header_row = '<div class="sg-header-row">' . $poster_html . $info_box . '</div>';

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 3 — Plot / Movie Overview
        // ══════════════════════════════════════════════════════════════════════
        $plot = self::section('Movie Overview', self::paragraphs($ai['plot'] ?? ''), 'sg-section-plot');

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 4 — Direction & Cinematography
        // ══════════════════════════════════════════════════════════════════════
        $direction = self::section(
            'Direction &amp; Cinematography',
            self::paragraphs($ai['direction'] ?? ''),
            'sg-section-direction'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 5 — Performances
        // Cast grid is rendered by single-movie.php (theme template).
        // Do NOT append $cast_html here — it would duplicate the cast list.
        // ══════════════════════════════════════════════════════════════════════
        $performances = self::section(
            'Cast &amp; Performances',
            self::paragraphs($ai['performances'] ?? ''),
            'sg-section-performances'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 6 — Character Psychology (NEW)
        // ══════════════════════════════════════════════════════════════════════
        $char_psych = self::section(
            'Character Psychology',
            self::paragraphs($ai['character_psychology'] ?? ''),
            'sg-section-psychology'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 7 — Themes
        // ══════════════════════════════════════════════════════════════════════
        $themes = self::section(
            'Themes &amp; Emotional Depth',
            self::paragraphs($ai['themes'] ?? ''),
            'sg-section-themes'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 8 — Memorable Moments (NEW)
        // ══════════════════════════════════════════════════════════════════════
        $memorable = self::section(
            'Memorable Scenes &amp; Dialogue',
            self::paragraphs($ai['memorable_moments'] ?? ''),
            'sg-section-memorable'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 9 — Climax / Ending (NEW)
        // ══════════════════════════════════════════════════════════════════════
        $climax = self::section(
            'The Ending — Does It Deliver?',
            self::paragraphs($ai['climax_analysis'] ?? ''),
            'sg-section-climax'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 10 — What Works / What Doesn't
        // ══════════════════════════════════════════════════════════════════════
        $what_works = self::section(
            'What Works',
            '<p>' . esc_html($ai['what_works'] ?? '') . '</p>',
            'sg-section-works'
        );

        // Renamed: "What Doesn't Work" → "Honest Criticism"
        $what_doesnt = self::section(
            'Honest Criticism',
            '<p>' . esc_html($ai['what_doesnt'] ?? '') . '</p>',
            'sg-section-criticism'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 11 — Comparison with Similar Films (NEW)
        // ══════════════════════════════════════════════════════════════════════
        $comparison = self::section(
            'How It Compares',
            self::paragraphs($ai['comparison'] ?? ''),
            'sg-section-comparison'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 12 — Legacy & Cultural Impact (NEW)
        // ══════════════════════════════════════════════════════════════════════
        $legacy = self::section(
            'Legacy &amp; Cultural Impact',
            self::paragraphs($ai['legacy'] ?? ''),
            'sg-section-legacy'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 13 — Behind the Scenes / Trivia (NEW)
        // ══════════════════════════════════════════════════════════════════════
        $trivia_content = '';
        if (!empty($ai['trivia'])) {
            // If AI returned numbered list text, keep as paragraphs; otherwise wrap in <ul>
            $trivia_raw = trim($ai['trivia']);
            $is_list    = preg_match('/^\d+[\.\)]/m', $trivia_raw);
            if ($is_list) {
                $lines = preg_split('/\n+/', $trivia_raw);
                $items = '';
                foreach ($lines as $line) {
                    $line = trim(preg_replace('/^\d+[\.\)]\s*/', '', $line));
                    if ($line !== '') $items .= '<li>' . esc_html($line) . '</li>';
                }
                $trivia_content = '<ul class="sg-trivia-list">' . $items . '</ul>';
            } else {
                $trivia_content = self::paragraphs($trivia_raw);
            }
        }
        $trivia = self::section('Behind the Scenes', $trivia_content, 'sg-section-trivia');

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 14 — Who Should Watch It?
        // ══════════════════════════════════════════════════════════════════════
        $audience = self::section(
            'Who Should Watch It?',
            '<p>' . esc_html($ai['audience'] ?? '') . '</p>',
            'sg-section-audience'
        );

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 15 — Final Verdict box
        // ══════════════════════════════════════════════════════════════════════
        $verdict_html = '';
        if (!empty($ai['verdict'])) {
            $stars = $ed_rating ? $stars_html((float) $ed_rating) . ' <strong>' . esc_html($ed_rating) . '/10</strong>' : '';
            $verdict_html  = '<div class="sg-verdict-box">';
            $verdict_html .= '<h2>Final Verdict</h2>';
            $verdict_html .= '<p>' . esc_html($ai['verdict']) . '</p>';
            if ($stars) {
                $verdict_html .= '<div class="sg-rating-badge">' . $stars . '</div>';
            }
            $verdict_html .= '</div>';
        }

        // ══════════════════════════════════════════════════════════════════════
        // BLOCK 16 — External links (natural sentence, not a bullet template)
        // ══════════════════════════════════════════════════════════════════════
        $ext_parts = [];
        if ($imdb_url) {
            $ext_parts[] = '<a href="' . esc_url($imdb_url)  . '" target="_blank" rel="nofollow noopener">IMDb</a>';
        }
        $ext_parts[] = '<a href="' . esc_url($tmdb_url)  . '" target="_blank" rel="nofollow noopener">TMDB</a>';
        $ext_parts[] = '<a href="' . esc_url($wiki_url)  . '" target="_blank" rel="nofollow noopener">Wikipedia</a>';

        $ext_sentence = 'More details, ratings, and cast information on ' . implode(', ', $ext_parts) . '.';

        if ($trailer_link) {
            $ext_sentence .= ' <a href="' . esc_url($trailer_link) . '" target="_blank" rel="nofollow noopener">Watch the official trailer on YouTube →</a>';
        }

        $ext_links = '<p class="sg-external-links">' . $ext_sentence . '</p>';

        // ══════════════════════════════════════════════════════════════════════
        // ASSEMBLE — ordered content flow
        // ══════════════════════════════════════════════════════════════════════
        $html  = $backdrop_html;
        $html .= $header_row;

        $html .= $plot;
        $html .= $direction;
        $html .= $performances;
        $html .= $char_psych;
        $html .= $themes;
        $html .= $memorable;
        $html .= $climax;
        $html .= $what_works;
        $html .= $what_doesnt;
        $html .= $comparison;
        $html .= $legacy;
        $html .= $trivia;
        $html .= $audience;
        $html .= $verdict_html;

        // Placeholder divs — internal links injected by Sinemagor_Internal_Linker after save
        $html .= '<div class="sg-related-cast" data-movie-id="'  . esc_attr($movie->id)    . '"></div>';
        $html .= '<div class="sg-related-genre" data-genre="'    . esc_attr($movie->genre)  . '"></div>';

        $html .= $ext_links;

        return $html;
    }
}
