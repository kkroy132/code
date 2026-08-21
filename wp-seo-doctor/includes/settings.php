<?php
/**
 * Plugin options: defaults, accessors and sanitisation.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Settings {

    const OPTION = 'wpsd_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array {
        return [
            // ── Audit ──
            'post_types'            => ['post', 'page'],
            'scan_batch_size'       => 20,
            'request_timeout'       => 10,
            'scan_schedule'         => 'weekly',   // disabled|daily|weekly|monthly
            'scan_history_limit'    => 30,
            // Live-fetch each URL during a scan. Needed for HTTP status,
            // redirect, schema and Open Graph checks; costs one request per page.
            'check_http_status'     => true,
            // Run the `the_content` filter chain when analysing a page. Required
            // to see links injected by themes and plugins (related posts,
            // automatic internal linking). Turn off only if a third-party filter
            // misbehaves during scans.
            'apply_content_filters' => true,

            // ── On-page thresholds ──
            'title_min'             => 30,
            'title_max'             => 60,
            'desc_min'              => 70,
            'desc_max'              => 160,
            'content_min_words'     => 300,
            'thin_content_words'    => 200,
            'keyword_density_min'   => 0.5,
            'keyword_density_max'   => 3.0,
            'max_crawl_depth'       => 4,

            // ── Internal linking ──
            'min_internal_links'    => 3,
            'min_incoming_links'    => 2,
            'link_suggestion_limit' => 8,
            // Pages rendered when working out which links are site chrome
            // (menu, footer). Higher is more accurate and slower.
            'boilerplate_sample_size' => 6,

            // ── Broken links ──
            'link_check_schedule'   => 'weekly',
            'link_check_batch'      => 25,
            'link_recheck_days'     => 14,
            'check_external_links'  => true,

            // ── 404 monitor ──
            'monitor_404'           => true,
            'log_404_referrer'      => true,
            // Off by default: an IP address is personal data under the GDPR,
            // and nothing in the plugin needs one to do its job. When enabled
            // it is still truncated to a /24 (or /64) before storage.
            'log_404_ip'            => false,
            'notfound_retention'    => 90,          // days; 0 keeps forever
            'ignore_404_patterns'   => "/wp-content/\n/wp-includes/\n.env\n.php\nfavicon.ico\nrobots.txt\napple-touch-icon",

            // ── Redirects ──
            'redirects_enabled'     => true,
            'log_redirects'         => true,
            'redirect_log_limit'    => 5000,
            // Days to keep individual redirect hits. Aggregate hit counts on
            // the rule itself are never pruned, so analytics survive.
            'redirect_log_retention' => 30,
            'log_redirect_ip'       => false,

            // ── Content SEO ──
            'decay_window_days'     => 180,
            'outdated_after_days'   => 365,
            'duplicate_threshold'   => 0.75,

            // ── Search Console ──
            'gsc_client_id'         => '',
            'gsc_client_secret'     => '',
            'gsc_property'          => '',
            'gsc_sync_schedule'     => 'daily',
            'gsc_lookback_days'     => 28,
            'gsc_position_low'      => 11.0,        // "striking distance" band
            'gsc_position_high'     => 20.0,
            'gsc_ctr_floor'         => 2.0,

            // ── AI ──
            'ai_enabled'            => false,
            'ai_provider'           => 'anthropic',
            'ai_api_key'            => '',
            'ai_model'              => 'claude-sonnet-5',
            'ai_max_tokens'         => 1200,
            'ai_temperature'        => 0.4,

            // ── Affiliate ──
            'affiliate_domains'     => "amzn.to\namazon.\nrstyle.me\nshareasale.com\nclickbank.net\nawin1.com\nimpact.com\ncj.com\nlinksynergy.com\nebay.to",
            'affiliate_prefixes'    => "/go/\n/recommends/\n/out/\n/ref/\n/link/",
            'affiliate_required_rel' => ['sponsored', 'nofollow'],

            // ── Reports ──
            'report_email'          => '',
            'email_reports'         => false,
            'email_schedule'        => 'weekly',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), $stored);
    }

    /**
     * @param mixed $fallback
     * @return mixed
     */
    public static function get(string $key, $fallback = null) {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            return $fallback;
        }
        $value = $all[$key];
        return ($value === '' && $fallback !== null && !is_string($fallback)) ? $fallback : $value;
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value): void {
        $all       = self::all();
        $all[$key] = $value;
        update_option(self::OPTION, $all);
    }

    public static function install_defaults(): void {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)) {
            add_option(self::OPTION, self::defaults());
            return;
        }
        // Backfill keys added by a plugin upgrade without clobbering user values.
        update_option(self::OPTION, array_merge(self::defaults(), $stored));
    }

    /**
     * Options holding a credential. These are never rendered back into the
     * settings page, so an empty submission means "unchanged" rather than
     * "cleared" — otherwise every save would wipe the key.
     */
    const SECRET_KEYS = ['gsc_client_secret', 'ai_api_key'];

    /**
     * Sanitise a raw $_POST payload from the settings screen.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array {
        $defaults = self::defaults();
        $current  = self::all();
        $clean    = $current;

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $input)) {
                // Unchecked checkboxes are simply absent from the POST body.
                if (is_bool($default)) {
                    $clean[$key] = false;
                }
                continue;
            }
            $raw = $input[$key];

            if (in_array($key, self::SECRET_KEYS, true)) {
                $submitted = trim((string) $raw);
                // Blank keeps the stored credential; a literal deletion is
                // done with the "Clear" control, which posts a sentinel.
                if ($submitted === '') {
                    continue;
                }
                $clean[$key] = $submitted === '-' ? '' : sanitize_text_field($submitted);
                continue;
            }

            if (is_bool($default)) {
                $clean[$key] = (bool) $raw;
            } elseif (is_int($default)) {
                $clean[$key] = max(0, (int) $raw);
            } elseif (is_float($default)) {
                $clean[$key] = (float) $raw;
            } elseif (is_array($default)) {
                $clean[$key] = array_values(array_filter(array_map('sanitize_text_field', (array) $raw)));
            } elseif (strpos((string) $default, "\n") !== false || in_array($key, ['ignore_404_patterns'], true)) {
                $clean[$key] = sanitize_textarea_field((string) $raw);
            } else {
                $clean[$key] = sanitize_text_field((string) $raw);
            }
        }

        // Bounds that keep scans from hammering the host.
        $clean['scan_batch_size']  = min(200, max(1, (int) $clean['scan_batch_size']));
        $clean['link_check_batch'] = min(100, max(1, (int) $clean['link_check_batch']));
        $clean['request_timeout']  = min(60, max(3, (int) $clean['request_timeout']));
        $clean['gsc_lookback_days'] = min(480, max(1, (int) $clean['gsc_lookback_days']));
        $clean['duplicate_threshold'] = min(1.0, max(0.1, (float) $clean['duplicate_threshold']));

        $valid_schedules = ['disabled', 'daily', 'weekly', 'monthly'];
        foreach (['scan_schedule', 'link_check_schedule', 'gsc_sync_schedule', 'email_schedule'] as $key) {
            if (!in_array($clean[$key], $valid_schedules, true)) {
                $clean[$key] = 'disabled';
            }
        }

        if ($clean['report_email'] !== '' && !is_email($clean['report_email'])) {
            $clean['report_email'] = '';
        }

        return $clean;
    }

    /**
     * Split a textarea setting into a trimmed list of non-empty lines.
     *
     * @return array<int,string>
     */
    public static function lines(string $key): array {
        $raw = (string) self::get($key, '');
        $out = array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []);
        return array_values(array_filter($out, static fn($line) => $line !== ''));
    }
}
