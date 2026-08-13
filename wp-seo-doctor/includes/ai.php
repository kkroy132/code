<?php
/**
 * AI SEO assistant.
 *
 * Wraps a chat-completions provider and exposes task-shaped helpers that each
 * return structured suggestions rather than free-form prose, so the admin UI
 * can render them as pickable options.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_AI {

    const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION  = '2023-06-01';
    const OPENAI_ENDPOINT    = 'https://api.openai.com/v1/chat/completions';

    public static function init(): void {
        // Stateless: everything here is called on demand from AJAX handlers.
    }

    public static function is_enabled(): bool {
        return (bool) WPSD_Settings::get('ai_enabled', false)
            && trim((string) WPSD_Settings::get('ai_api_key', '')) !== '';
    }

    /**
     * @return string|WP_Error Human-readable reason when AI is unavailable.
     */
    public static function availability_error() {
        if (!WPSD_Settings::get('ai_enabled', false)) {
            return new WP_Error('wpsd_ai_disabled', __('AI features are turned off. Enable them in WP SEO Doctor → Settings → AI.', 'wp-seo-doctor'));
        }
        if (trim((string) WPSD_Settings::get('ai_api_key', '')) === '') {
            return new WP_Error('wpsd_ai_no_key', __('No AI API key is configured. Add one in WP SEO Doctor → Settings → AI.', 'wp-seo-doctor'));
        }
        return '';
    }

    // ──────────────────────────────────────────────────────── transport ──

    /**
     * Send a prompt and return the model's text response.
     *
     * @return string|WP_Error
     */
    public static function complete(string $system, string $user, int $max_tokens = 0) {
        $error = self::availability_error();
        if (is_wp_error($error)) {
            return $error;
        }

        $max_tokens = $max_tokens > 0 ? $max_tokens : (int) WPSD_Settings::get('ai_max_tokens', 1200);
        $provider   = (string) WPSD_Settings::get('ai_provider', 'anthropic');

        return $provider === 'openai'
            ? self::complete_openai($system, $user, $max_tokens)
            : self::complete_anthropic($system, $user, $max_tokens);
    }

    /**
     * @return string|WP_Error
     */
    private static function complete_anthropic(string $system, string $user, int $max_tokens) {
        $response = wp_remote_post(self::ANTHROPIC_ENDPOINT, [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => (string) WPSD_Settings::get('ai_api_key', ''),
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => (string) WPSD_Settings::get('ai_model', 'claude-sonnet-5'),
                'max_tokens'  => $max_tokens,
                'temperature' => (float) WPSD_Settings::get('ai_temperature', 0.4),
                'system'      => $system,
                'messages'    => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $message = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : sprintf(
                    /* translators: %d: HTTP status code */
                    __('The AI provider returned HTTP %d.', 'wp-seo-doctor'),
                    $code
                );
            return new WP_Error('wpsd_ai_http', $message);
        }

        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            }
        }

        if (trim($text) === '') {
            return new WP_Error('wpsd_ai_empty', __('The AI provider returned an empty response.', 'wp-seo-doctor'));
        }

        return $text;
    }

    /**
     * @return string|WP_Error
     */
    private static function complete_openai(string $system, string $user, int $max_tokens) {
        $response = wp_remote_post(self::OPENAI_ENDPOINT, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) WPSD_Settings::get('ai_api_key', ''),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => (string) WPSD_Settings::get('ai_model', 'gpt-4o-mini'),
                'max_tokens'  => $max_tokens,
                'temperature' => (float) WPSD_Settings::get('ai_temperature', 0.4),
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $message = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : sprintf(
                    /* translators: %d: HTTP status code */
                    __('The AI provider returned HTTP %d.', 'wp-seo-doctor'),
                    $code
                );
            return new WP_Error('wpsd_ai_http', $message);
        }

        $text = (string) ($body['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            return new WP_Error('wpsd_ai_empty', __('The AI provider returned an empty response.', 'wp-seo-doctor'));
        }

        return $text;
    }

    /**
     * Ask for JSON and parse it, tolerating fenced code blocks.
     *
     * @return array<mixed>|WP_Error
     */
    private static function complete_json(string $system, string $user, int $max_tokens = 0) {
        $raw = self::complete($system . "\n\nRespond with valid JSON only. No prose, no markdown fences.", $user, $max_tokens);
        if (is_wp_error($raw)) {
            return $raw;
        }

        $text = trim($raw);
        // Models occasionally wrap JSON in ```json fences despite instructions.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }
        // Or prepend a sentence — grab the outermost JSON value.
        if (!in_array(substr($text, 0, 1), ['{', '['], true)) {
            $start = strcspn($text, '{[');
            if ($start < strlen($text)) {
                $text = substr($text, $start);
            }
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return new WP_Error('wpsd_ai_json', __('The AI response could not be parsed as JSON.', 'wp-seo-doctor'));
        }

        return $decoded;
    }

    // ────────────────────────────────────────────────────────── prompts ──

    private static function system_prompt(): string {
        return sprintf(
            'You are an experienced technical SEO consultant advising the owner of the website "%1$s" (%2$s). '
            . 'Give specific, actionable advice grounded in the data you are shown. '
            . 'Never invent metrics, rankings or facts that are not in the input. '
            . 'Prefer plain language over jargon, and keep every suggestion something the site owner could act on today.',
            get_bloginfo('name'),
            home_url('/')
        );
    }

    /**
     * Plain-English explanation of a stored issue.
     *
     * @return array{explanation:string,impact:string,steps:array<int,string>}|WP_Error
     */
    public static function explain_issue(int $issue_id) {
        global $wpdb;
        $table = WPSD_DB::table('issues');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $issue = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $issue_id));
        if (!$issue) {
            return new WP_Error('wpsd_issue_missing', __('Issue not found.', 'wp-seo-doctor'));
        }

        $prompt = sprintf(
            "An SEO audit flagged this issue.\n\nCheck: %s\nSeverity: %s\nPage: %s\nFinding: %s\nStandard recommendation: %s\n\n"
            . 'Return JSON: {"explanation": "why this matters, 2-3 sentences", "impact": "one sentence on the likely search impact", "steps": ["concrete step", "..."]}',
            $issue->title,
            $issue->severity,
            $issue->url,
            $issue->message,
            $issue->recommendation
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 800);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'explanation' => (string) ($result['explanation'] ?? ''),
            'impact'      => (string) ($result['impact'] ?? ''),
            'steps'       => array_map('strval', (array) ($result['steps'] ?? [])),
        ];
    }

    /**
     * @return array<int,string>|WP_Error
     */
    public static function suggest_titles(int $post_id, int $count = 5) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        $keyword = WPSD_Helpers::get_focus_keyword($post_id);
        $max     = (int) WPSD_Settings::get('title_max', 60);
        $excerpt = WPSD_Helpers::truncate(WPSD_Helpers::plain_text($post->post_content), 1200);

        $prompt = sprintf(
            "Write %d alternative SEO titles for this page.\n\nCurrent title: %s\nFocus keyword: %s\nContent excerpt: %s\n\n"
            . "Rules: each title at most %d characters, front-load the primary topic, no clickbait, no quotation marks, each distinctly different in angle.\n"
            . 'Return JSON: {"titles": ["...", "..."]}',
            $count,
            WPSD_Helpers::get_seo_title($post_id)['value'],
            $keyword !== '' ? $keyword : __('(none set)', 'wp-seo-doctor'),
            $excerpt,
            $max
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 600);
        if (is_wp_error($result)) {
            return $result;
        }

        return array_values(array_filter(array_map('strval', (array) ($result['titles'] ?? []))));
    }

    /**
     * @return array<int,string>|WP_Error
     */
    public static function suggest_descriptions(int $post_id, int $count = 3) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        $min = (int) WPSD_Settings::get('desc_min', 70);
        $max = (int) WPSD_Settings::get('desc_max', 160);

        $prompt = sprintf(
            "Write %d meta descriptions for this page.\n\nTitle: %s\nFocus keyword: %s\nContent excerpt: %s\n\n"
            . "Rules: each between %d and %d characters, include the focus keyword naturally, end with a reason to click, no quotation marks.\n"
            . 'Return JSON: {"descriptions": ["...", "..."]}',
            $count,
            WPSD_Helpers::get_seo_title($post_id)['value'],
            WPSD_Helpers::get_focus_keyword($post_id) ?: __('(none set)', 'wp-seo-doctor'),
            WPSD_Helpers::truncate(WPSD_Helpers::plain_text($post->post_content), 1500),
            $min,
            $max
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 700);
        if (is_wp_error($result)) {
            return $result;
        }

        return array_values(array_filter(array_map('strval', (array) ($result['descriptions'] ?? []))));
    }

    /**
     * ALT text for the images on a page that are missing it.
     *
     * @return array<int,array{src:string,alt:string}>|WP_Error
     */
    public static function suggest_alt_text(int $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        $context = new WPSD_Context($post);
        $missing = array_values(array_filter($context->images, static fn($img) => !$img['has_alt'] && $img['src'] !== ''));

        if (!$missing) {
            return [];
        }

        // The model cannot see the images, so it works from filenames plus the
        // surrounding content — which is what a human would do too.
        $list = [];
        foreach (array_slice($missing, 0, 12) as $index => $image) {
            $list[] = sprintf('%d. filename: %s', $index + 1, basename((string) wp_parse_url($image['src'], PHP_URL_PATH)));
        }

        $prompt = sprintf(
            "Write ALT text for the images on this page.\n\nPage title: %s\nPage topic: %s\nImages:\n%s\n\n"
            . "Rules: describe what the image most likely shows based on its filename and the page topic, under 125 characters, "
            . "no \"image of\" or \"picture of\" prefixes, no keyword stuffing.\n"
            . 'Return JSON: {"alts": [{"index": 1, "alt": "..."}]}',
            $post->post_title,
            WPSD_Helpers::truncate($context->text, 600),
            implode("\n", $list)
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 900);
        if (is_wp_error($result)) {
            return $result;
        }

        $out = [];
        foreach ((array) ($result['alts'] ?? []) as $row) {
            $index = (int) ($row['index'] ?? 0) - 1;
            if (isset($missing[$index])) {
                $out[] = [
                    'src' => $missing[$index]['src'],
                    'alt' => (string) ($row['alt'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * Rank and phrase internal link suggestions for a post.
     *
     * @return array<int,array{target_id:int,title:string,url:string,anchor:string,reason:string}>|WP_Error
     */
    public static function suggest_internal_links(int $post_id, int $limit = 6) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        // Candidates come from the link graph; the model only picks and phrases.
        $candidates = WPSD_Internal_Links::suggest_targets($post_id, $limit * 3);
        if (!$candidates) {
            return [];
        }

        $list = [];
        foreach ($candidates as $index => $candidate) {
            $list[] = sprintf('%d. %s', $index + 1, $candidate['title']);
        }

        $prompt = sprintf(
            "Choose the best internal links to add to this article, and write natural anchor text for each.\n\n"
            . "Article title: %s\nArticle content: %s\n\nCandidate pages to link to:\n%s\n\n"
            . "Rules: pick at most %d that are genuinely relevant, the anchor text must be a phrase that already appears (or could naturally appear) in the article, never \"click here\".\n"
            . 'Return JSON: {"links": [{"index": 1, "anchor": "...", "reason": "one short sentence"}]}',
            $post->post_title,
            WPSD_Helpers::truncate(WPSD_Helpers::plain_text($post->post_content), 2500),
            implode("\n", $list),
            $limit
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 1000);
        if (is_wp_error($result)) {
            return $result;
        }

        $out = [];
        foreach ((array) ($result['links'] ?? []) as $row) {
            $index = (int) ($row['index'] ?? 0) - 1;
            if (!isset($candidates[$index])) {
                continue;
            }
            $out[] = [
                'target_id' => $candidates[$index]['id'],
                'title'     => $candidates[$index]['title'],
                'url'       => $candidates[$index]['url'],
                'anchor'    => (string) ($row['anchor'] ?? $candidates[$index]['anchor']),
                'reason'    => (string) ($row['reason'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<int,string>|WP_Error
     */
    public static function suggest_anchor_text(int $target_id, int $count = 5) {
        $post = get_post($target_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        $existing = [];
        foreach (WPSD_Internal_Links::incoming_links($target_id, 20) as $link) {
            if (trim((string) $link->anchor) !== '') {
                $existing[] = (string) $link->anchor;
            }
        }

        $prompt = sprintf(
            "Suggest %d anchor text variations for links pointing at this page.\n\nPage title: %s\nPage topic: %s\nAnchors already in use: %s\n\n"
            . "Rules: vary the phrasing, stay descriptive, avoid exact-match repetition of the same phrase, 2-6 words each.\n"
            . 'Return JSON: {"anchors": ["...", "..."]}',
            $count,
            $post->post_title,
            WPSD_Helpers::truncate(WPSD_Helpers::plain_text($post->post_content), 800),
            $existing ? implode('; ', array_slice($existing, 0, 10)) : __('(none)', 'wp-seo-doctor')
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 500);
        if (is_wp_error($result)) {
            return $result;
        }

        return array_values(array_filter(array_map('strval', (array) ($result['anchors'] ?? []))));
    }

    /**
     * @return array<int,array{area:string,suggestion:string,priority:string}>|WP_Error
     */
    public static function suggest_content_optimization(int $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        $context  = new WPSD_Context($post);
        $headings = [];
        foreach ($context->headings as $heading) {
            $headings[] = str_repeat('  ', max(0, $heading['level'] - 1)) . 'H' . $heading['level'] . ': ' . $heading['text'];
        }

        $prompt = sprintf(
            "Review this page and suggest content improvements for search performance.\n\n"
            . "Title: %s\nFocus keyword: %s\nWord count: %d\nInternal links: %d\nImages: %d\n\nHeading outline:\n%s\n\nContent:\n%s\n\n"
            . "Rules: at most 6 suggestions, each tied to something concrete in the page, priority is one of high/medium/low.\n"
            . 'Return JSON: {"suggestions": [{"area": "short label", "suggestion": "what to do", "priority": "high"}]}',
            $post->post_title,
            $context->focus_keyword ?: __('(none set)', 'wp-seo-doctor'),
            $context->word_count,
            count($context->internal_links),
            count($context->images),
            $headings ? implode("\n", array_slice($headings, 0, 40)) : __('(no headings)', 'wp-seo-doctor'),
            WPSD_Helpers::truncate($context->text, 3000)
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 1200);
        if (is_wp_error($result)) {
            return $result;
        }

        $out = [];
        foreach ((array) ($result['suggestions'] ?? []) as $row) {
            $priority = strtolower((string) ($row['priority'] ?? 'medium'));
            $out[]    = [
                'area'       => (string) ($row['area'] ?? ''),
                'suggestion' => (string) ($row['suggestion'] ?? ''),
                'priority'   => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium',
            ];
        }

        return $out;
    }

    /**
     * A prioritised plan built from the site's current open issues.
     *
     * @return array{summary:string,actions:array<int,array{title:string,why:string,how:string,effort:string,impact:string}>}|WP_Error
     */
    public static function action_plan(int $limit = 10) {
        $groups = WPSD_Issues::fix_first($limit);
        if (!$groups) {
            return new WP_Error('wpsd_no_issues', __('There are no open issues to plan around. Run a scan first.', 'wp-seo-doctor'));
        }

        $score = WPSD_Score::calculate();
        $lines = [];
        foreach ($groups as $group) {
            $lines[] = sprintf(
                '- [%s] %s — %d pages affected. %s',
                strtoupper($group->severity),
                $group->title,
                (int) $group->affected,
                $group->recommendation
            );
        }

        $prompt = sprintf(
            "Here is the current state of this site's SEO audit.\n\nHealth score: %d/100 (%s)\nOpen issues: %d critical, %d high, %d medium, %d low\n\nTop findings:\n%s\n\n"
            . "Produce an ordered action plan. Sequence the work so that fixes which unblock others come first. Be concrete about what to change.\n"
            . 'Return JSON: {"summary": "2-3 sentences on the overall picture", "actions": [{"title": "...", "why": "...", "how": "...", "effort": "low|medium|high", "impact": "low|medium|high"}]}',
            $score['score'],
            $score['label'],
            $score['counts']['critical'],
            $score['counts']['high'],
            $score['counts']['medium'],
            $score['counts']['low'],
            implode("\n", $lines)
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 2000);
        if (is_wp_error($result)) {
            return $result;
        }

        $actions = [];
        foreach ((array) ($result['actions'] ?? []) as $row) {
            $actions[] = [
                'title'  => (string) ($row['title'] ?? ''),
                'why'    => (string) ($row['why'] ?? ''),
                'how'    => (string) ($row['how'] ?? ''),
                'effort' => strtolower((string) ($row['effort'] ?? 'medium')),
                'impact' => strtolower((string) ($row['impact'] ?? 'medium')),
            ];
        }

        return [
            'summary' => (string) ($result['summary'] ?? ''),
            'actions' => $actions,
        ];
    }

    /**
     * How well a page answers the query it targets — the "will an AI search
     * engine cite this?" view.
     *
     * @return array{score:int,verdict:string,strengths:array<int,string>,gaps:array<int,string>,questions:array<int,string>}|WP_Error
     */
    public static function search_readiness(int $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('wpsd_post_missing', __('Post not found.', 'wp-seo-doctor'));
        }

        $context = new WPSD_Context($post);
        $stats   = WPSD_GSC::has_data() ? WPSD_GSC::page_stats($context->url) : null;

        $prompt = sprintf(
            "Assess how well this page would serve a searcher, and how likely an AI search experience is to cite it.\n\n"
            . "Title: %s\nMeta description: %s\nFocus keyword: %s\nWord count: %d\n%s\n\nContent:\n%s\n\n"
            . "Judge: does it answer the likely query directly and early, is it specific and verifiable, does it cover the obvious follow-up questions.\n"
            . 'Return JSON: {"score": 0-100, "verdict": "one sentence", "strengths": ["..."], "gaps": ["..."], "questions": ["unanswered question a reader would still have"]}',
            $context->seo_title,
            $context->seo_description ?: __('(none)', 'wp-seo-doctor'),
            $context->focus_keyword ?: __('(none set)', 'wp-seo-doctor'),
            $context->word_count,
            $stats
                ? sprintf('Search Console (28 days): %d clicks, %d impressions, average position %s', $stats['clicks'], $stats['impressions'], $stats['position'])
                : __('Search Console data: not available', 'wp-seo-doctor'),
            WPSD_Helpers::truncate($context->text, 4000)
        );

        $result = self::complete_json(self::system_prompt(), $prompt, 1500);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'score'     => max(0, min(100, (int) ($result['score'] ?? 0))),
            'verdict'   => (string) ($result['verdict'] ?? ''),
            'strengths' => array_map('strval', (array) ($result['strengths'] ?? [])),
            'gaps'      => array_map('strval', (array) ($result['gaps'] ?? [])),
            'questions' => array_map('strval', (array) ($result['questions'] ?? [])),
        ];
    }

    /**
     * Free-form assistant, grounded in the site's current audit state.
     *
     * @return string|WP_Error
     */
    public static function ask(string $question) {
        $question = trim($question);
        if ($question === '') {
            return new WP_Error('wpsd_ai_empty_question', __('Please enter a question.', 'wp-seo-doctor'));
        }

        $score  = WPSD_Score::calculate();
        $groups = WPSD_Issues::fix_first(8);

        $findings = [];
        foreach ($groups as $group) {
            $findings[] = sprintf('- %s (%s, %d pages)', $group->title, $group->severity, (int) $group->affected);
        }

        $links     = WPSD_Internal_Links::stats();
        $broken    = WPSD_Broken_Links::stats();
        $notfound  = WPSD_Monitor_404::stats();

        $context = sprintf(
            "Current site state:\n- Health score: %d/100\n- Open issues: %d critical, %d high, %d medium, %d low\n"
            . "- Internal links: %d across %d pages; %d orphan pages\n- Broken links: %d\n- Unresolved 404s: %d\n\nTop findings:\n%s",
            $score['score'],
            $score['counts']['critical'],
            $score['counts']['high'],
            $score['counts']['medium'],
            $score['counts']['low'],
            $links['internal_links'],
            $links['linked_pages'],
            $links['orphans'],
            $broken['broken'],
            $notfound['unresolved'],
            $findings ? implode("\n", $findings) : __('(none — no scan has run yet)', 'wp-seo-doctor')
        );

        return self::complete(
            self::system_prompt(),
            $context . "\n\nQuestion from the site owner:\n" . $question
        );
    }

    /**
     * Persist an AI suggestion the user accepted.
     */
    public static function apply_suggestion(int $post_id, string $field, string $value): bool {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $value = trim(wp_strip_all_tags($value));
        if ($value === '') {
            return false;
        }

        switch ($field) {
            case 'title':
                // Write to whichever SEO plugin owns the field, else our own key.
                update_post_meta($post_id, self::title_meta_key(), $value);
                return true;

            case 'description':
                update_post_meta($post_id, self::description_meta_key(), $value);
                return true;

            case 'focus_keyword':
                update_post_meta($post_id, '_wpsd_focus_keyword', $value);
                return true;

            default:
                return false;
        }
    }

    /** ALT text is stored on the attachment, not the post. */
    public static function apply_alt_text(string $image_url, string $alt): bool {
        $attachment_id = attachment_url_to_postid($image_url);
        if (!$attachment_id) {
            return false;
        }
        return (bool) update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
    }

    private static function title_meta_key(): string {
        if (defined('WPSEO_VERSION')) {
            return '_yoast_wpseo_title';
        }
        if (class_exists('RankMath')) {
            return 'rank_math_title';
        }
        return '_wpsd_title';
    }

    private static function description_meta_key(): string {
        if (defined('WPSEO_VERSION')) {
            return '_yoast_wpseo_metadesc';
        }
        if (class_exists('RankMath')) {
            return 'rank_math_description';
        }
        return '_wpsd_description';
    }
}
