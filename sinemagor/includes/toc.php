<?php
defined('ABSPATH') || exit;

class Sinemagor_TOC {

    public static function init(): void {
        // Priority 5 — runs before internal linker (15) and FAQ (20)
        add_filter('the_content', [self::class, 'inject'], 5);
    }

    /**
     * Parse H2 headings from content, add IDs, prepend TOC.
     */
    public static function inject(string $content): string {
        if (!is_single()) return $content;

        $post_id = get_the_ID();
        if (!get_post_meta($post_id, '_sinemagor_tmdb_id', true) &&
            !get_post_meta($post_id, '_sinemagor_list_post', true) &&
            !get_post_meta($post_id, '_sinemagor_comparison', true)) {
            return $content;
        }

        // Find all H2 headings
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/i', $content, $matches);
        if (empty($matches[0]) || count($matches[0]) < 3) return $content; // TOC only if 3+ headings

        $items = [];
        foreach ($matches[1] as $i => $heading_text) {
            $clean = wp_strip_all_tags($heading_text);
            $slug  = self::slugify($clean);

            // Add ID to the H2 in content
            $original = $matches[0][$i];
            $with_id  = preg_replace('/<h2([^>]*)>/i', '<h2$1 id="' . esc_attr($slug) . '">', $original, 1);
            $content  = str_replace($original, $with_id, $content);

            $items[] = ['slug' => $slug, 'text' => $clean];
        }

        $toc = self::build_toc($items);
        return $toc . $content;
    }

    private static function build_toc(array $items): string {
        $html  = '<div class="sg-toc" id="sg-toc">';
        $html .= '<div class="sg-toc-header">';
        $html .= '<span class="sg-toc-icon">📋</span>';
        $html .= '<span class="sg-toc-title">Table of Contents</span>';
        $html .= '<button class="sg-toc-toggle" aria-expanded="true" aria-controls="sg-toc-list" aria-label="Toggle table of contents">−</button>';
        $html .= '</div>';
        $html .= '<ol class="sg-toc-list" id="sg-toc-list">';
        foreach ($items as $i => $item) {
            $html .= '<li class="sg-toc-item">';
            $html .= '<a href="#' . esc_attr($item['slug']) . '" class="sg-toc-link">';
            $html .= '<span class="sg-toc-num">' . ($i + 1) . '</span>';
            $html .= esc_html($item['text']);
            $html .= '</a></li>';
        }
        $html .= '</ol></div>';

        // Inline toggle JS
        $html .= '<script>(function(){var btn=document.querySelector(".sg-toc-toggle");var list=document.getElementById("sg-toc-list");if(!btn||!list)return;btn.addEventListener("click",function(){var open=this.getAttribute("aria-expanded")==="true";this.setAttribute("aria-expanded",String(!open));list.style.display=open?"none":"";this.textContent=open?"+":"−";});})();</script>';

        return $html;
    }

    private static function slugify(string $text): string {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', trim($text));
        return 'sg-' . $text;
    }
}
