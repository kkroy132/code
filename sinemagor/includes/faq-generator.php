<?php
defined('ABSPATH') || exit;

class Sinemagor_FAQ_Generator {

    public static function init(): void {
        add_filter('the_content',            [self::class, 'append_faq'],   20);
        add_action('wp_ajax_sg_generate_faq',[self::class, 'ajax_generate']);
        add_action('transition_post_status', [self::class, 'on_publish'],   25, 3);
    }

    public static function on_publish(string $new, string $old, WP_Post $post): void {
        if ($new !== 'publish') return;
        if (!get_post_meta($post->ID, '_sinemagor_tmdb_id', true)) return;
        if (get_post_meta($post->ID, '_sinemagor_faqs', true)) return;
        self::generate($post->ID);
    }

    // =========================================================================
    // GENERATE
    // =========================================================================

    public static function generate(int $post_id) {
        $title    = get_the_title($post_id);
        $genre    = get_post_meta($post_id, '_sinemagor_genre',    true);
        $year     = get_post_meta($post_id, '_sinemagor_year',     true);
        $director = get_post_meta($post_id, '_sinemagor_director', true);
        $runtime  = get_post_meta($post_id, '_sinemagor_runtime',  true);
        $verdict  = get_post_meta($post_id, '_sinemagor_verdict',  true);
        $keywords_raw = get_post_meta($post_id, '_sinemagor_keywords', true);
        $keywords = $keywords_raw ? implode(', ', json_decode($keywords_raw, true) ?? []) : '';

        $api_key = Sinemagor_Settings::get('openrouter_api_key');
        $model   = Sinemagor_Settings::get('ai_model', 'deepseek/deepseek-v3.2');

        if (!$api_key) return new WP_Error('no_key', 'OpenRouter API key missing.');

        $runtime_fmt = $runtime ? floor($runtime / 60) . 'h ' . ($runtime % 60) . 'm' : '';

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'max_tokens'  => 1200,
                'temperature' => 0.75,
                'messages'    => [
                    ['role' => 'system', 'content' => self::system_prompt()],
                    ['role' => 'user',   'content' => self::build_prompt(
                        $title, $year, $genre, $director, $runtime_fmt, $verdict, $keywords
                    )],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return $response;

        $body  = json_decode(wp_remote_retrieve_body($response), true);
        $code  = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? 'Unknown error';
            return new WP_Error('openrouter_error', $msg);
        }

        $raw   = $body['choices'][0]['message']['content'] ?? '';
        $faqs  = self::parse($raw);

        if (is_wp_error($faqs)) return $faqs;

        update_post_meta($post_id, '_sinemagor_faqs', wp_json_encode($faqs));

        if (class_exists('Sinemagor_Cost_Tracker') && !empty($body['usage'])) {
            Sinemagor_Cost_Tracker::log([
                'model'         => $model,
                'input_tokens'  => $body['usage']['prompt_tokens']     ?? 0,
                'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
                'movie_title'   => $title,
                'action_type'   => 'faq',
            ]);
        }

        return $faqs;
    }

    // =========================================================================
    // SYSTEM PROMPT
    // =========================================================================

    private static function system_prompt(): string {
        return <<<'PROMPT'
You write FAQ sections for a film review website. Your job is to answer the exact questions real people type into Google when they search for a movie.

══ QUESTION RULES ══
Each question must match a real search intent. Write questions the way people actually type them — conversational, specific, not textbook-formal.

BANNED question patterns (too generic, template-feel):
  "Is [Film] worth watching?"         → too vague
  "What is [Film] about?"             → too obvious
  "Who directed [Film]?"              → answered in any quick search
  "Is [Film] a good movie?"           → meaningless

USE conversational search-style questions instead:
  "Does [Film] hold up in [year]?"
  "Why does [Film]'s ending divide audiences?"
  "What made [Film] controversial when it came out?"
  "Is [Film] slow-paced or does it move fast?"
  "How violent is [Film] — is it too much?"
  "Is [Film] worth watching if you haven't seen [similar film]?"
  "What is [Film] really about beneath the surface?"
  "Why do people love [Film] so much?"
  "Does [Film] have a good ending?"

Use these 6 intent categories (one question per category):
1. VERDICT INTENT — specific: "Does [Film] hold up?" / "Is [Film] overrated?"
2. PLOT/MEANING INTENT — specific: "What is [Film] really trying to say?"
3. ENDING INTENT — "What does [Film]'s ending mean?" / "Why does [Film] end the way it does?"
4. BACKGROUND INTENT — "[Film] true story?" / "Was [Film] based on a book?"
5. PRACTICAL INTENT — runtime, streaming, age rating, pacing question
6. CAST/CREW INTENT — specific actor or director question

══ ANSWER RULES ══
- 2-4 sentences. Short. Direct. No filler.
- Answer the question first — one useful detail after.
- Plain language. Contractions always: "it's", "don't", "won't"
- No AI openers: "Great question!", "Certainly!", "Absolutely!"
- No spoilers unless the question is specifically about the ending
- For ending questions: be thoughtful, acknowledge ambiguity if it exists

BANNED in answers (duplicate recommendation problem):
  Never say "I highly recommend this film"
  Never say "This film is a must-watch"
  Never say "You should definitely see this"
  Never say "This film comes highly recommended"
  The verdict section already covers recommendation — FAQ answers should inform, not repeat it

══ OUTPUT FORMAT ══
Return ONLY a valid JSON array. No markdown. No explanation.
Exactly 6 objects. Each: {"question":"...","answer":"..."}
PROMPT;
    }

    // =========================================================================
    // USER PROMPT
    // =========================================================================

    private static function build_prompt(
        string $title,
        string $year,
        string $genre,
        string $director,
        string $runtime,
        string $verdict,
        string $keywords
    ): string {
        return <<<PROMPT
Generate 6 FAQ questions and answers for this film. Cover 6 different search intent categories.

══ FILM DATA ══
Title:    {$title}
Year:     {$year}
Genre:    {$genre}
Director: {$director}
Runtime:  {$runtime}
Review verdict: {$verdict}
SEO keywords: {$keywords}

══ REQUIRED COVERAGE (one question per category) ══
1. Verdict intent — specific, not "Is it worth watching?" 
   E.g. "Does [Film] hold up today?" / "Is [Film] overrated?"
2. Meaning/subtext — "What is [Film] really about?" / "What does [Film] say about [theme]?"
3. Ending intent — "Why does [Film] end the way it does?" / "What does [Film]'s ending mean?"
4. Background — true story, book adaptation, filming location, or production fact
5. Practical — runtime, pacing, age/content warning, or streaming question
6. Cast or director — specific actor choice or director's style question

Return exactly 6 JSON objects:
[{"question":"...","answer":"..."}]
PROMPT;
    }

    // =========================================================================
    // PARSE
    // =========================================================================

    private static function parse(string $raw) {
        $clean = trim($raw);
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/^```\s*/i',     '', $clean);
        $clean = preg_replace('/```\s*$/i',     '', $clean);
        $clean = trim($clean);

        // Extract array if surrounded by stray text
        if (substr($clean, 0, 1) !== '[') {
            $start = strpos($clean, '[');
            $end   = strrpos($clean, ']');
            if ($start !== false && $end !== false) {
                $clean = substr($clean, $start, $end - $start + 1);
            }
        }

        $data = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || empty($data)) {
            return new WP_Error('faq_parse_error', 'FAQ JSON parse failed. Raw: ' . substr($raw, 0, 200));
        }

        // Sanitize and enforce structure
        $faqs = [];
        foreach ($data as $item) {
            if (empty($item['question']) || empty($item['answer'])) continue;
            $faqs[] = [
                'question' => sanitize_text_field($item['question']),
                'answer'   => sanitize_textarea_field($item['answer']),
            ];
        }

        return !empty($faqs) ? $faqs : new WP_Error('faq_empty', 'No valid FAQ items parsed.');
    }

    // =========================================================================
    // BUILD HTML + SCHEMA
    // =========================================================================

    public static function build_html(array $faqs, string $title): string {
        if (empty($faqs)) return '';

        // FAQPage schema
        $schema_items = array_map(fn($f) => [
            '@type'          => 'Question',
            'name'           => $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
        ], $faqs);

        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $schema_items,
        ];

        $html  = '<script type="application/ld+json">';
        $html .= wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $html .= '</script>';

        $html .= '<section class="sg-faq-section">';
        $html .= '<h2>Questions People Ask About ' . esc_html($title) . '</h2>';
        $html .= '<div class="sg-faq-list">';

        foreach ($faqs as $i => $faq) {
            $html .= '<div class="sg-faq-item">';
            $html .= '<button class="sg-faq-q" aria-expanded="false" aria-controls="sg-faq-' . $i . '">';
            $html .= esc_html($faq['question']);
            $html .= '<span class="sg-faq-icon" aria-hidden="true">+</span>';
            $html .= '</button>';
            $html .= '<div class="sg-faq-a" id="sg-faq-' . $i . '" hidden>';
            $html .= '<p>' . esc_html($faq['answer']) . '</p>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div></section>';

        // Inline accordion JS — no jQuery dependency
        $html .= '<script>(function(){';
        $html .= 'document.querySelectorAll(".sg-faq-q").forEach(function(btn){';
        $html .= 'btn.addEventListener("click",function(){';
        $html .= 'var panel=document.getElementById(this.getAttribute("aria-controls"));';
        $html .= 'var open=this.getAttribute("aria-expanded")==="true";';
        $html .= 'this.setAttribute("aria-expanded",String(!open));';
        $html .= 'panel.hidden=open;';
        $html .= 'this.querySelector(".sg-faq-icon").textContent=open?"+":"-";';
        $html .= '});});})();</script>';

        return $html;
    }

    // =========================================================================
    // APPEND TO CONTENT
    // =========================================================================

    public static function append_faq(string $content): string {
        if (!is_single()) return $content;

        $pid = get_the_ID();
        if (!get_post_meta($pid, '_sinemagor_tmdb_id', true)) return $content;

        $json = get_post_meta($pid, '_sinemagor_faqs', true);
        if (!$json) return $content;

        $faqs = json_decode($json, true);
        if (!is_array($faqs) || empty($faqs)) return $content;

        return $content . self::build_html($faqs, get_the_title($pid));
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public static function ajax_generate(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

        $pid = (int) ($_POST['post_id'] ?? 0);
        if (!$pid) wp_send_json_error('Invalid post ID.');

        // Allow regeneration: clear old FAQs first
        delete_post_meta($pid, '_sinemagor_faqs');

        $result = self::generate($pid);
        is_wp_error($result)
            ? wp_send_json_error($result->get_error_message())
            : wp_send_json_success(['message' => 'FAQ generated.', 'count' => count($result)]);
    }
}
