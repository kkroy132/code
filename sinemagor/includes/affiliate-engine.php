<?php
defined('ABSPATH') || exit;

class Sinemagor_Affiliate_Engine {

    public static function init(): void {
        add_filter('the_content', [self::class, 'inject'], 25);
    }

    /**
     * Inject affiliate parameters into known provider links in post content.
     */
    public static function inject(string $content): string {
        if (!is_single()) return $content;
        if (!get_post_meta(get_the_ID(), '_sinemagor_tmdb_id', true)) return $content;

        $amazon_tag = Sinemagor_Settings::get('affiliate_amazon',    '');
        $justwatch  = Sinemagor_Settings::get('affiliate_justwatch', '');
        $itunes_at  = Sinemagor_Settings::get('affiliate_itunes',    '');

        // Amazon affiliate tag
        if ($amazon_tag) {
            $content = preg_replace_callback(
                '/href="(https?:\/\/(?:www\.)?amazon\.[a-z.]+[^"]*)"/',
                function ($m) use ($amazon_tag) {
                    $url = remove_query_arg('tag', $m[1]);
                    $url = add_query_arg('tag', $amazon_tag, $url);
                    return 'href="' . esc_url($url) . '"';
                },
                $content
            );
        }

        // JustWatch ref
        if ($justwatch) {
            $content = preg_replace_callback(
                '/href="(https?:\/\/(?:www\.)?justwatch\.com[^"]*)"/',
                function ($m) use ($justwatch) {
                    $url = add_query_arg('ref', $justwatch, $m[1]);
                    return 'href="' . esc_url($url) . '"';
                },
                $content
            );
        }

        // Apple iTunes affiliate
        if ($itunes_at) {
            $content = preg_replace_callback(
                '/href="(https?:\/\/(?:tv\.apple|itunes)\.apple\.com[^"]*)"/',
                function ($m) use ($itunes_at) {
                    $url = add_query_arg('at', $itunes_at, $m[1]);
                    return 'href="' . esc_url($url) . '"';
                },
                $content
            );
        }

        return $content;
    }
}
