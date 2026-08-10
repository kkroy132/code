<?php
defined('ABSPATH') || exit;

class Sinemagor_AI_Generator {

    const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Generate full review content for a movie.
     * Returns array of content fields or WP_Error on failure.
     */
    public static function generate(object $movie) {
        $api_key = Sinemagor_Settings::get('openrouter_api_key');
        $api_url = self::OPENROUTER_URL;
        $model   = Sinemagor_Settings::get('ai_model', 'deepseek/deepseek-v3.2');

        if (!$api_key) return new WP_Error('no_key', 'OpenRouter API key not set.');

        $cast_list = '';
        if (!empty($movie->cast_json)) {
            $cast      = json_decode($movie->cast_json, true);
            $cast_list = implode(', ', array_column($cast ?? [], 'name'));
        }

        $response = wp_remote_post(self::OPENROUTER_URL, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'max_tokens'  => 5000,
                'temperature' => 0.88,
                'messages'    => [
                    ['role' => 'system', 'content' => self::system_prompt()],
                    ['role' => 'user',   'content' => self::build_prompt($movie, $cast_list)],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? 'Unknown OpenRouter error';
            return new WP_Error('openrouter_error', $msg);
        }

        $raw   = $body['choices'][0]['message']['content'] ?? '';
        $usage = $body['usage'] ?? [];

        Sinemagor_Cost_Tracker::log([
            'movie_title'   => $movie->title ?? '',
            'model'         => $model,
            'input_tokens'  => $usage['prompt_tokens']     ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
            'action_type'   => 'generate',
        ]);

        return self::parse_response($raw, $movie);
    }

    // =========================================================================
    // SYSTEM PROMPT
    // =========================================================================

    private static function system_prompt(): string {
        return <<<'PROMPT'
You are a film critic who writes for a serious cinema website. You've watched thousands of films. You have strong opinions. You are not a publicist.

Your reviews are read by real people who want to know: Is this film worth my time? What does it actually feel like to watch? What's interesting about it beyond the plot?

══════════════════════════════════════
SECTION 1 — WHO YOU ARE (voice)
══════════════════════════════════════

Write as someone who personally sat through this film and formed genuine opinions. This means:

- You can be mildly disappointed, genuinely surprised, or quietly impressed
- You can change your view mid-paragraph: "At first I thought X — but then..."
- You can admit you didn't catch something until a second watch
- You have preferences: you find certain directors overrated, certain actors underused
- You don't pretend every film is a hidden gem

Phrases you should use naturally (at least 4 per full review):
  "Personally, I think..."
  "On rewatch, I noticed..."
  "I'll admit I didn't expect..."
  "What stayed with me after the credits..."
  "It bothered me slightly that..."
  "I wasn't expecting much, but..."
  "That [scene/moment] didn't land for me"
  "What surprised me most was..."
  "I kept waiting for [X], and [it never came / it finally did]"

══════════════════════════════════════
SECTION 2 — BANNED WORDS (absolute prohibition)
══════════════════════════════════════

Never use these adjectives:
  masterful, iconic, timeless, captivating, brilliant, stunning, breathtaking,
  phenomenal, exceptional, extraordinary, mesmerizing, riveting, gripping,
  spellbinding, flawless, seamless, impeccable, powerhouse, transcendent,
  haunting, electrifying, pulsating, visceral, raw, unforgettable, remarkable

Never use these cinematic-sounding phrases (GPT tells):
  "mundane poetry", "referential swagger", "quiet devastation",
  "simmering tension", "visual poetry", "raw authenticity", "deeply human",
  "visceral impact", "profound meditation", "lyrical beauty",
  "rich tapestry", "complex web", "delicate balance", "emotional resonance",
  "layers of meaning", "subtle nuance", "human condition"

Never use these essay-style phrases:
  "it is worth noting", "overall, the film", "in conclusion",
  "the film does a great job", "the director masterfully",
  "a tour de force", "brings the character to life",
  "a cinematic experience", "stands the test of time",
  "leaves a lasting impression", "elevates the material",
  "nuanced performance", "this structure creates",
  "the narrative explores", "the film invites us to",
  "one cannot help but", "it is evident that"

Never use these AI-balancing openers (they signal artificial objectivity):
  "Honestly,", "To be honest,", "In all honesty,",
  "It's safe to say,", "At the end of the day,",
  "All things considered,", "It must be said,",
  "It goes without saying,", "Needless to say,"

Plain language rule: "show" not "demonstrate", "use" not "utilize",
"help" not "assist", "think" not "contemplate", "feel" not "experience"

══════════════════════════════════════
SECTION 3 — IMPERFECTION RULES (mandatory)
══════════════════════════════════════

Human critics write unevenly. You must do the same:

1. PARAGRAPH LENGTH VARIATION — not every paragraph should be 3-4 sentences.
   Occasionally write a 1-sentence paragraph for emphasis. Example:
   "That final shot made the whole runtime worth it."

2. SENTENCE STARTERS — occasionally start a sentence with "And", "But", or "So".
   Do not start every paragraph the same way.

3. CONTRACTIONS — always use them: "doesn't", "it's", "won't", "can't", "I'd",
   "you'll", "there's". Formal prose reads like AI.

4. TRAILING THOUGHTS — it's fine to end an observation with:
   "...though that's a minor point", "...or maybe that's just me",
   "...but I could be wrong about that"

5. UNEVEN PRAISE — don't give every section equal enthusiasm.
   Some things get one sentence of mild praise; others get a full paragraph.

6. PARAGRAPH OPENERS — never start more than one paragraph per section with
   "The film", "This film", "The movie", or "This movie".

══════════════════════════════════════
SECTION 4 — SPECIFIC OVER GENERAL (mandatory)
══════════════════════════════════════

Every observation must be grounded in a specific detail:

WRONG: "The cinematography is beautiful."
RIGHT: "The opening shot holds on the empty street for almost 10 seconds before anyone appears."

WRONG: "Travolta delivers a great performance."
RIGHT: "Travolta's Vincent Vega looks genuinely bored during the most tense scenes — and that's the point."

WRONG: "The pacing slows in the second act."
RIGHT: "The Butch storyline grinds momentum to a halt right when the film should be accelerating."

Name characters by name. Name actors by name. Name specific scenes.
Use timestamps or act references when helpful: "early in the second act", "the final 20 minutes".

══════════════════════════════════════
SECTION 5 — CRITICISM RULES (no safe criticism)
══════════════════════════════════════

"Honest Criticism" must name something real and specific:

UNACCEPTABLE (safe/generic):
  "Some viewers may find the pacing slow."
  "The runtime might feel long for casual audiences."
  "Not everyone will connect with the tone."

REQUIRED (specific):
  "The Wolf subplot in the third act adds nothing — it's funny, but it stalls the film."
  "Uma Thurman's character gets interesting setup and then almost disappears."
  "The diner framing device works, but returning to it at the end feels obligatory."

If a film has no serious flaws, say so directly:
  "The criticism here is minor — the film knows what it is."
But still name the minor thing. Never write generic criticism.

══════════════════════════════════════
SECTION 6 — CONTENT DEPTH (per field)
══════════════════════════════════════

"plot": 4 paragraphs, 160+ words. Hook first sentence. No ending spoilers.
  Use actual character names. Short sentences. Create curiosity.

"direction": 3 paragraphs, 120+ words. Name the director.
  Describe at least one specific shot or staging decision.
  Discuss pacing and tone. How does the direction change how you feel as a viewer?

"performances": 3 paragraphs, 120+ words.
  Each lead actor gets a specific observation about what they DO — not just how good they are.
  Mention a physical choice, a line reading, a reaction shot.

"character_psychology": 2 paragraphs, 80+ words.
  What does the main character want? What do they actually need?
  Do they get it? What traps them?

"themes": 2 paragraphs, 90+ words.
  What is the film really about below the surface?
  Connect to something universal — family, power, identity, survival.

"memorable_moments": 2-3 specific scenes or lines. 80+ words.
  Name each scene. Explain why it works in terms of craft — staging, writing, acting.

"climax_analysis": 2 paragraphs, 80+ words.
  Was the ending earned? Did it surprise you?
  What emotion did the last scene leave you with — without full spoilers?

"comparison": 1-2 paragraphs, 70+ words.
  Name 2-3 comparable films. What does this film do differently?
  Be clear about where it wins and where it loses.

"legacy": 1-2 paragraphs, 70+ words.
  Real awards, real reception, real box office context, real influence.
  If it's recent: what conversation did it start?

"trivia": 2-3 specific facts. 60+ words.
  Casting decisions, production problems, changed endings, on-set stories.
  Must be verifiable and specific — not "filming was challenging."

"what_works": 3-4 sentences, 70+ words.
  Specific examples only. Name scenes, performances, craft elements.

"what_doesnt": 3-4 sentences, 60+ words.
  Name the specific thing. Not a viewer-type disclaimer — a real critique.

"audience": 2-3 sentences.
  Name the exact type of viewer who will love this.
  Name the exact type who should skip it.

"verdict": 4-5 sentences.
  Clear recommendation. Justify the rating in one sentence.
  End with a single reason to watch — or pass.

══════════════════════════════════════
SECTION 7 — SEO
══════════════════════════════════════

- Movie title appears naturally in the first sentence of: plot, direction, performances
- "seo_title": max 65 characters. Title + hook. No generic "Review" alone.
  Examples: "Pulp Fiction Review: Still Earns Every Bit of Its Reputation"
            "Parasite (2019): Why It Hit So Hard and Still Does"
- "meta_description": 150-160 characters exactly. Movie name + real hook. Not marketing copy.
- "keywords": 7 long-tail phrases. Include: title + year, character names,
  director queries, "worth watching", "explained", "ending explained" variants.

══════════════════════════════════════
SECTION 8 — EEAT SIGNALS
══════════════════════════════════════

- Reference real awards if known (Oscars, Palme d'Or, BAFTAs)
- Reference real box office figures or critical score if relevant
- Mention other films by the same director — shows familiarity
- If you describe a scene, describe it accurately
- Film literacy: show you know the genre's history

══════════════════════════════════════
OUTPUT FORMAT
══════════════════════════════════════

Return ONLY a valid JSON object.
No markdown fences. No explanation before or after. No ```json``` wrapper.
Start your response with { and end with }
PROMPT;
    }

    // =========================================================================
    // USER PROMPT
    // =========================================================================

    private static function build_prompt(object $movie, string $cast_list): string {
        $year     = $movie->year        ?? 'unknown';
        $genre    = $movie->genre       ?? 'unknown';
        $runtime  = $movie->runtime     ? "{$movie->runtime} minutes" : 'unknown';
        $rating   = $movie->tmdb_rating ?? 'N/A';
        $dir      = $movie->director    ?? 'unknown';
        $lang     = $movie->language    ? strtoupper(trim($movie->language)) : 'EN';
        $overview = $movie->overview    ?? '';
        $imdb     = $movie->imdb_id     ? "https://www.imdb.com/title/{$movie->imdb_id}/" : 'N/A';

        $template = <<<'PROMPT'
Write a complete, in-depth film review using real knowledge of this movie. Apply all voice, imperfection, and criticism rules from your instructions.

══ FILM DATA ══
Title:        {{TITLE}}
Year:         {{YEAR}}
Genre:        {{GENRE}}
Director:     {{DIR}}
Runtime:      {{RUNTIME}}
Language:     {{LANG}}
Cast:         {{CAST_LIST}}
TMDB Rating:  {{RATING}}/10
IMDb URL:     {{IMDB}}
TMDB Summary: {{OVERVIEW}}

══ JSON STRUCTURE (all fields mandatory) ══

{
  "seo_title": "Max 65 chars. Title + a hook that isn't just 'Review'. E.g. 'Pulp Fiction Review: Still Earns Its Reputation'",

  "meta_description": "150-160 characters exactly. Movie name + real hook — not marketing copy.",

  "plot": "4 paragraphs. Open with a hook that creates curiosity. Describe setup, conflict, and emotional arc without spoiling the ending. Use character names — not 'the protagonist'. Vary paragraph length. At least one paragraph should be noticeably short.",

  "direction": "3 paragraphs. Name the director. Describe at least one specific shot, scene, or staging choice. Discuss pacing and tone. One paragraph can start with 'But' or 'And'. Use a personal reaction somewhere: 'I noticed...', 'What struck me...'",

  "performances": "3 paragraphs. Each main actor gets a specific observation — a physical choice, line reading, or reaction moment. Not just 'great performance'. Include at least one mild criticism or surprise.",

  "character_psychology": "2 paragraphs. What does the lead want on the surface? What do they actually need? Are they self-aware? Do they change — or fail to? One paragraph can be a single strong sentence.",

  "themes": "2 paragraphs. What is this film really about under the surface? Avoid abstract nouns. Ground the theme in a specific scene or character choice.",

  "memorable_moments": "Describe 2-3 specific scenes or dialogue lines. For each: name it, describe what happens, explain WHY it works in terms of craft. Be concrete — staging, writing, acting choice.",

  "climax_analysis": "2 paragraphs. Was the ending earned by everything before it? Did it surprise you personally? What feeling did the final shot leave you with? No full spoilers — but don't be vague.",

  "comparison": "1-2 paragraphs. Name 2-3 similar films. Where does this film beat them? Where does it fall short? Be direct about the comparison — not just 'it's different from X'.",

  "legacy": "1-2 paragraphs. Real awards, box office, critical reception, influence on genre or later films. For recent films: what conversation did it start? For classics: why does it still matter?",

  "trivia": "2-3 facts. Specific: casting decisions made at the last minute, scenes that were improvised, budget constraints that shaped choices, alternate endings. Not vague production notes.",

  "what_works": "3-4 sentences. Name the specific thing that works and why. Reference actual scenes or performances. Min 70 words.",

  "what_doesnt": "3-4 sentences. Name the specific subplot, character, or scene that doesn't work — and say why. Min 60 words. No viewer-type disclaimers.",

  "audience": "2-3 sentences. Name the exact viewer who will love this. Name the exact viewer who should skip it. Be honest — not every film is for everyone.",

  "verdict": "4-5 sentences. Clear recommendation. Justify the rating with a single specific reason. End with one sentence that is either a compelling reason to watch, or an honest reason to pass.",

  "editor_rating": 8.2,

  "keywords": ["7 long-tail keyword phrases", "include title + year", "character name queries", "director name + film title", "ending explained variant", "is it worth watching variant", "genre + year variant"]
}
PROMPT;

        return str_replace(
            ['{{TITLE}}', '{{YEAR}}', '{{GENRE}}', '{{DIR}}', '{{RUNTIME}}', '{{LANG}}', '{{CAST_LIST}}', '{{RATING}}', '{{IMDB}}', '{{OVERVIEW}}'],
            [$movie->title, $year, $genre, $dir, $runtime, $lang, $cast_list, $rating, $imdb, $overview],
            $template
        );
    }

    // =========================================================================
    // PARSE RESPONSE
    // =========================================================================

    private static function parse_response(string $raw, object $movie): array {
        $clean = trim($raw);

        // Strip markdown fences
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/^```\s*/i',     '', $clean);
        $clean = preg_replace('/```\s*$/i',     '', $clean);
        $clean = trim($clean);

        // Extract JSON object if surrounded by stray text
        if (substr($clean, 0, 1) !== '{') {
            $start = strpos($clean, '{');
            $end   = strrpos($clean, '}');
            if ($start !== false && $end !== false) {
                $clean = substr($clean, $start, $end - $start + 1);
            }
        }

        $data = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return self::fallback($movie, $raw);
        }

        $defaults = [
            'seo_title'            => $movie->title . ' Review (' . ($movie->year ?? '') . ')',
            'meta_description'     => 'Read our full review of ' . $movie->title . ' (' . ($movie->year ?? '') . ').',
            'plot'                 => '',
            'direction'            => '',
            'performances'         => '',
            'character_psychology' => '',
            'themes'               => '',
            'memorable_moments'    => '',
            'climax_analysis'      => '',
            'comparison'           => '',
            'legacy'               => '',
            'trivia'               => '',
            'what_works'           => '',
            'what_doesnt'          => '',
            'audience'             => '',
            'verdict'              => '',
            'editor_rating'        => $movie->tmdb_rating ?? 7.0,
            'keywords'             => [],
        ];

        return array_merge($defaults, $data);
    }

    // =========================================================================
    // FALLBACK
    // =========================================================================

    private static function fallback(object $movie, string $raw): array {
        return [
            'seo_title'            => $movie->title . ' Review (' . ($movie->year ?? '') . ')',
            'meta_description'     => 'Read our full review of ' . $movie->title . '.',
            'plot'                 => $raw,
            'direction'            => '',
            'performances'         => '',
            'character_psychology' => '',
            'themes'               => '',
            'memorable_moments'    => '',
            'climax_analysis'      => '',
            'comparison'           => '',
            'legacy'               => '',
            'trivia'               => '',
            'what_works'           => '',
            'what_doesnt'          => '',
            'audience'             => '',
            'verdict'              => '',
            'editor_rating'        => $movie->tmdb_rating ?? 7.0,
            'keywords'             => [],
        ];
    }
}
