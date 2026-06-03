<?php
defined('ABSPATH') || exit;

class Sinemagor_Where_To_Watch {

    const JUSTWATCH_API = 'https://apis.justwatch.com/contentpartner/v2';
    // Common streaming providers with logos
    const PROVIDERS = [
        8   => ['name' => 'Netflix',        'color' => '#E50914', 'icon' => '🎬'],
        9   => ['name' => 'Amazon Prime',   'color' => '#00A8E0', 'icon' => '📦'],
        337 => ['name' => 'Disney+',        'color' => '#113CCF', 'icon' => '✨'],
        384 => ['name' => 'HBO Max',        'color' => '#6C2BEC', 'icon' => '📺'],
        15  => ['name' => 'Hulu',           'color' => '#1CE783', 'icon' => '🟢'],
        531 => ['name' => 'Paramount+',     'color' => '#0064FF', 'icon' => '⛰️'],
        387 => ['name' => 'Peacock',        'color' => '#FFD700', 'icon' => '🦚'],
        257 => ['name' => 'fuboTV',         'color' => '#FF5A00', 'icon' => '⚽'],
        350 => ['name' => 'Apple TV+',      'color' => '#555555', 'icon' => '🍎'],
        283 => ['name' => 'Crunchyroll',    'color' => '#F47521', 'icon' => '🍥'],
    ];

    public static function init(): void {
        add_action('transition_post_status', [self::class, 'on_publish'], 26, 3);
        add_filter('the_content',            [self::class, 'append_section'], 18);
        add_action('wp_ajax_sg_fetch_wtw',   [self::class, 'ajax_fetch']);
    }

    public static function on_publish(string $new, string $old, WP_Post $post): void {
        if ($new !== 'publish') return;
        if (!get_post_meta($post->ID, '_sinemagor_tmdb_id', true)) return;
        if (get_post_meta($post->ID, '_sinemagor_wtw', true)) return;
        self::fetch_and_save($post->ID);
    }

    public static function fetch_and_save(int $post_id): void {
        $tmdb_id = (int) get_post_meta($post_id, '_sinemagor_tmdb_id', true);
        if (!$tmdb_id) return;

        $data = self::fetch_from_justwatch($tmdb_id);
        if ($data) {
            update_post_meta($post_id, '_sinemagor_wtw', wp_json_encode($data));
            update_post_meta($post_id, '_sinemagor_wtw_updated', current_time('mysql'));
        }
    }

    private static function fetch_from_justwatch(int $tmdb_id): ?array {
        // JustWatch uses TMDB IDs via their content partner API
        $url  = self::JUSTWATCH_API . '/titles/movie/' . $tmdb_id . '/locale/en_US';
        $resp = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Content-Type' => 'application/json']]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            // Fallback: use JustWatch search
            return self::search_justwatch($tmdb_id);
        }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        return self::parse_offers($body);
    }

    private static function search_justwatch(int $tmdb_id): ?array {
        // Search by TMDB ID via JustWatch search API
        $resp = wp_remote_post('https://apis.justwatch.com/content/titles/en_US/popular', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'content_types'    => ['movie'],
                'fields'           => ['id','title','offers','scoring'],
                'scoring_filter_types' => ['tmdb:id'],
                'filters'          => ['scoring_filter_types' => ['tmdb:id'], 'scoring_filters' => [['scoring_provider_id' => 'tmdb:id', 'scoring_type' => 'tmdb:id', 'from_value' => $tmdb_id, 'to_value' => $tmdb_id]]],
                'page_size'        => 1,
            ]),
        ]);
        if (is_wp_error($resp)) return null;
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $item = $body['items'][0] ?? null;
        return $item ? self::parse_offers($item) : null;
    }

    private static function parse_offers(?array $data): ?array {
        if (empty($data['offers'])) return null;
        $result = ['stream' => [], 'rent' => [], 'buy' => []];

        foreach ($data['offers'] as $offer) {
            $pid      = $offer['provider_id'] ?? 0;
            $type     = $offer['monetization_type'] ?? '';
            $url      = $offer['urls']['standard_web'] ?? '';
            $price    = $offer['retail_price'] ?? null;
            $provider = self::PROVIDERS[$pid] ?? null;

            if (!$provider || !$url) continue;

            $entry = ['name' => $provider['name'], 'color' => $provider['color'], 'icon' => $provider['icon'], 'url' => $url, 'price' => $price];

            if ($type === 'flatrate' || $type === 'free') {
                $result['stream'][$pid] = $entry;
            } elseif ($type === 'rent') {
                $result['rent'][$pid] = $entry;
            } elseif ($type === 'buy') {
                $result['buy'][$pid] = $entry;
            }
        }

        // Remove duplicates, limit each
        $result['stream'] = array_values(array_slice($result['stream'], 0, 6));
        $result['rent']   = array_values(array_slice($result['rent'],   0, 4));
        $result['buy']    = array_values(array_slice($result['buy'],    0, 4));

        if (empty($result['stream']) && empty($result['rent']) && empty($result['buy'])) return null;
        return $result;
    }

    public static function build_html(array $data, string $title): string {
        $affiliate_tag = Sinemagor_Settings::get('affiliate_amazon_tag', '');

        $html  = '<div class="sg-wtw-section">';
        $html .= '<h2>Where to Watch ' . esc_html($title) . '</h2>';

        if (!empty($data['stream'])) {
            $html .= '<div class="sg-wtw-group"><h3 class="sg-wtw-label">▶ Stream Now</h3><div class="sg-wtw-pills">';
            foreach ($data['stream'] as $p) {
                $url = self::maybe_add_affiliate($p['url'], $p['name'], $affiliate_tag);
                $html .= '<a href="' . esc_url($url) . '" class="sg-wtw-pill" target="_blank" rel="nofollow noopener" style="--pill-color:' . esc_attr($p['color']) . '">';
                $html .= '<span>' . esc_html($p['icon']) . '</span><span>' . esc_html($p['name']) . '</span>';
                $html .= '</a>';
            }
            $html .= '</div></div>';
        }

        if (!empty($data['rent'])) {
            $html .= '<div class="sg-wtw-group"><h3 class="sg-wtw-label">🎞 Rent</h3><div class="sg-wtw-pills">';
            foreach ($data['rent'] as $p) {
                $url = self::maybe_add_affiliate($p['url'], $p['name'], $affiliate_tag);
                $html .= '<a href="' . esc_url($url) . '" class="sg-wtw-pill sg-wtw-pill--rent" target="_blank" rel="nofollow noopener">';
                $html .= esc_html($p['icon'] . ' ' . $p['name']);
                if ($p['price']) $html .= ' <small>$' . esc_html($p['price']) . '</small>';
                $html .= '</a>';
            }
            $html .= '</div></div>';
        }

        if (!empty($data['buy'])) {
            $html .= '<div class="sg-wtw-group"><h3 class="sg-wtw-label">🛒 Buy</h3><div class="sg-wtw-pills">';
            foreach ($data['buy'] as $p) {
                $url = self::maybe_add_affiliate($p['url'], $p['name'], $affiliate_tag);
                $html .= '<a href="' . esc_url($url) . '" class="sg-wtw-pill sg-wtw-pill--buy" target="_blank" rel="nofollow noopener">';
                $html .= esc_html($p['icon'] . ' ' . $p['name']);
                if ($p['price']) $html .= ' <small>$' . esc_html($p['price']) . '</small>';
                $html .= '</a>';
            }
            $html .= '</div></div>';
        }

        $html .= '<p class="sg-wtw-note">Availability may vary by region. Powered by <a href="https://www.justwatch.com" rel="nofollow noopener" target="_blank">JustWatch</a>.</p>';
        $html .= '</div>';
        return $html;
    }

    private static function maybe_add_affiliate(string $url, string $provider, string $tag): string {
        if (!$tag) return $url;
        if (stripos($provider, 'amazon') !== false || stripos($provider, 'prime') !== false) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'tag=' . urlencode($tag);
        }
        return $url;
    }

    public static function append_section(string $content): string {
        if (!is_single()) return $content;
        $pid = get_the_ID();
        if (!get_post_meta($pid, '_sinemagor_tmdb_id', true)) return $content;
        $json = get_post_meta($pid, '_sinemagor_wtw', true);
        if (!$json) return $content;
        $data = json_decode($json, true);
        return $data ? $content . self::build_html($data, get_the_title($pid)) : $content;
    }

    public static function ajax_fetch(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');
        $pid = (int)($_POST['post_id'] ?? 0);
        if (!$pid) wp_send_json_error('Invalid ID.');
        delete_post_meta($pid, '_sinemagor_wtw');
        self::fetch_and_save($pid);
        wp_send_json_success('Where to Watch data refreshed.');
    }
}
