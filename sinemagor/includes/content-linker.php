<?php
defined('ABSPATH') || exit;

class Sinemagor_Content_Linker {

    // Per-request cache of names → category URLs
    private static array $link_map   = [];
    private static bool  $map_loaded = false;

    public static function init(): void {
        // Priority 8 — after CWV (3,4) but before TOC (5)... actually after TOC
        // Run at priority 30 so content is fully built first
        add_filter('the_content', [self::class, 'auto_link'], 30);
    }

    /**
     * Main: scan content and hyperlink actor/director/genre names.
     */
    public static function auto_link(string $content): string {
        if (!is_single()) return $content;
        if (!Sinemagor_Settings::get('autocat_link_content', 1)) return $content;

        $post_id = get_the_ID();
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true)) return $content;

        // Build name → URL map for this post
        $names = self::get_names_for_post($post_id);
        if (empty($names)) return $content;

        // Split content into HTML-safe segments
        return self::link_names_in_html($content, $names);
    }

    /**
     * Collect all linkable names for a post: cast + director + genres.
     * Returns array of ['name' => string, 'url' => string, 'type' => string]
     */
    private static function get_names_for_post(int $post_id): array {
        $names = [];

        $cast_json = get_post_meta($post_id, '_sinemagor_cast',     true);
        $director  = get_post_meta($post_id, '_sinemagor_director', true);
        $genre     = get_post_meta($post_id, '_sinemagor_genre',    true);

        // Director
        if ($director) {
            $url = self::cat_url(Sinemagor_Auto_Category::make_slug($director));
            if ($url) $names[] = ['name' => $director, 'url' => $url, 'type' => 'director'];
        }

        // Cast
        if ($cast_json) {
            $cast  = json_decode($cast_json, true) ?: [];
            $limit = (int) Sinemagor_Settings::get('autocat_cast_limit', 3);
            foreach (array_slice($cast, 0, $limit) as $member) {
                $name = trim($member['name'] ?? '');
                if (!$name) continue;
                $url = self::cat_url(Sinemagor_Auto_Category::make_slug($name));
                if ($url) $names[] = ['name' => $name, 'url' => $url, 'type' => 'cast'];
            }
        }

        // Genres (link genre names too if enabled)
        if (Sinemagor_Settings::get('autocat_link_genres', 1) && $genre) {
            foreach (explode(',', $genre) as $g) {
                $g = trim($g);
                if (!$g) continue;
                $url = self::cat_url(Sinemagor_Auto_Category::make_slug($g));
                if ($url) $names[] = ['name' => $g, 'url' => $url, 'type' => 'genre'];
            }
        }

        // Sort by name length descending — link longer names first
        // (prevents "Tom" linking before "Tom Hanks")
        usort($names, fn($a, $b) => strlen($b['name']) - strlen($a['name']));

        return $names;
    }

    /**
     * Get category URL by slug. Returns '' if not found.
     */
    private static function cat_url(string $slug): string {
        if (isset(self::$link_map[$slug])) return self::$link_map[$slug];

        $term = get_category_by_slug($slug);
        $url  = $term ? get_category_link($term->term_id) : '';
        self::$link_map[$slug] = $url;
        return $url;
    }

    /**
     * Link names in HTML content without breaking tags or existing links.
     *
     * Strategy:
     * 1. Split content into: TEXT nodes vs HTML tags (via regex)
     * 2. Only process TEXT nodes
     * 3. Track already-linked names (link each name once per post)
     * 4. Skip content inside <h1><h2><h3><a><script><style> tags
     */
    private static function link_names_in_html(string $content, array $names): string {
        $linked    = []; // names already linked in this post
        $skip_tags = ['h1', 'h2', 'h3', 'h4', 'a', 'script', 'style', 'code', 'pre'];
        $in_skip   = 0;
        $skip_tag  = '';

        // Split into tokens: tags vs text
        $tokens = preg_split('/(<[^>]+>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$tokens) return $content;

        $result = '';

        foreach ($tokens as $token) {
            if (substr($token, 0, 1) === '<') {
                // It's a tag
                $tag_name = strtolower(preg_replace('/[^a-zA-Z].*/', '', ltrim($token, '</ ')));

                if (in_array($tag_name, $skip_tags, true)) {
                    if (substr($token, 0, 2) !== '</') {
                        // Opening tag
                        $in_skip++;
                        $skip_tag = $tag_name;
                    } else {
                        // Closing tag
                        $in_skip = max(0, $in_skip - 1);
                    }
                }
                $result .= $token;
            } else {
                // It's text — only process if not inside skip tags
                if ($in_skip > 0) {
                    $result .= $token;
                } else {
                    $result .= self::link_in_text($token, $names, $linked);
                }
            }
        }

        return $result;
    }

    /**
     * Replace name occurrences in a plain text segment with linked versions.
     * Each name linked only once per post (first occurrence only).
     */
    private static function link_in_text(string $text, array $names, array &$linked): string {
        foreach ($names as $entry) {
            $name = $entry['name'];
            $url  = $entry['url'];

            // Skip if already linked in this post
            if (isset($linked[$name])) continue;

            // Case-sensitive word boundary match
            $pattern = '/\b(' . preg_quote($name, '/') . ')\b/u';
            $count   = 0;

            $text = preg_replace_callback($pattern, function ($m) use ($url, &$count, &$linked, $name) {
                if ($count > 0) return $m[0]; // Only first occurrence
                $count++;
                $linked[$name] = true;
                return '<a href="' . esc_url($url) . '" class="sg-auto-link" '
                     . 'title="More movies by ' . esc_attr($name) . '">'
                     . esc_html($m[1]) . '</a>';
            }, $text);
        }

        return $text;
    }
}
