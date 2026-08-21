<?php
/**
 * Privacy: policy content, and the external services the plugin contacts.
 *
 * The plugin's own features work without collecting anything about visitors.
 * The two places where visitor data can be recorded — the 404 monitor and the
 * redirect log — store a truncated IP only when explicitly switched on, and
 * both prune on a schedule.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Privacy {

    public static function init(): void {
        add_action('admin_init', [self::class, 'register_policy_content']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    /**
     * Suggested text for the site's privacy policy, shown in the WordPress
     * privacy tool.
     */
    public static function register_policy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<h2>' . esc_html__('WP SEO Doctor', 'wp-seo-doctor') . '</h2>';

        $content .= '<p>' . esc_html__(
            'This site uses WP SEO Doctor to audit its own SEO. Most of what the plugin stores describes the site\'s own content — page titles, links, and the problems it finds — and contains no visitor data.',
            'wp-seo-doctor'
        ) . '</p>';

        $content .= '<h3>' . esc_html__('What may be recorded about visitors', 'wp-seo-doctor') . '</h3>';
        $content .= '<ul>';
        $content .= '<li>' . esc_html__(
            '404 monitor: the requested address, the referring page, the browser user-agent string, and the time of the first and most recent request. Enabled by default.',
            'wp-seo-doctor'
        ) . '</li>';
        $content .= '<li>' . esc_html__(
            'Redirect log: the requested address, the destination, the referring page and the user-agent, for requests that matched a redirect rule. Enabled by default.',
            'wp-seo-doctor'
        ) . '</li>';
        $content .= '<li>' . esc_html__(
            'IP addresses: not recorded unless the site owner switches IP logging on. When enabled, addresses are truncated before storage — the final octet of an IPv4 address, or everything after the first four groups of an IPv6 address, is discarded — so a stored address identifies a network rather than a device.',
            'wp-seo-doctor'
        ) . '</li>';
        $content .= '</ul>';

        $content .= '<h3>' . esc_html__('How long it is kept', 'wp-seo-doctor') . '</h3>';
        $content .= '<p>' . sprintf(
            /* translators: 1: 404 retention in days, 2: redirect log retention in days */
            esc_html__('404 records are deleted after %1$d days without a further request. Individual redirect hits are deleted after %2$d days. Both periods are configurable, and the totals shown in reports are counters that hold no visitor data.', 'wp-seo-doctor'),
            (int) WPSD_Settings::get('notfound_retention', 90),
            (int) WPSD_Settings::get('redirect_log_retention', 30)
        ) . '</p>';

        $content .= '<h3>' . esc_html__('Services contacted', 'wp-seo-doctor') . '</h3>';
        $content .= '<p>' . esc_html__(
            'The plugin requests pages from this site, and from external sites it links to, in order to check whether those links still work. It sends no visitor data when it does so.',
            'wp-seo-doctor'
        ) . '</p>';
        $content .= '<p>' . esc_html__(
            'Two optional integrations contact third parties, and only after the site owner supplies credentials for them: Google Search Console (retrieves this site\'s own search statistics) and an AI provider of the owner\'s choosing (receives page titles, descriptions and content excerpts in order to suggest improvements). Neither is enabled by default, and neither transmits visitor data.',
            'wp-seo-doctor'
        ) . '</p>';

        wp_add_privacy_policy_content(
            __('WP SEO Doctor', 'wp-seo-doctor'),
            wp_kses_post(wpautop($content))
        );
    }

    /**
     * @param array<string,array<string,mixed>> $exporters
     * @return array<string,array<string,mixed>>
     */
    public static function register_exporter(array $exporters): array {
        $exporters['wp-seo-doctor'] = [
            'exporter_friendly_name' => __('WP SEO Doctor', 'wp-seo-doctor'),
            'callback'               => [self::class, 'export'],
        ];
        return $exporters;
    }

    /**
     * @param array<string,array<string,mixed>> $erasers
     * @return array<string,array<string,mixed>>
     */
    public static function register_eraser(array $erasers): array {
        $erasers['wp-seo-doctor'] = [
            'eraser_friendly_name' => __('WP SEO Doctor', 'wp-seo-doctor'),
            'callback'             => [self::class, 'erase'],
        ];
        return $erasers;
    }

    /**
     * WordPress identifies a data subject by email address. Nothing this
     * plugin records is keyed by email: 404 and redirect entries are keyed by
     * URL, and the optional IP is truncated specifically so it cannot single
     * out a person.
     *
     * The exporter is still registered, and reports honestly that there is
     * nothing to export, because a site owner answering a subject-access
     * request needs that answer rather than silence.
     *
     * @param string $email_address
     * @param int    $page
     * @return array{data:array<int,mixed>, done:bool}
     */
    public static function export($email_address, $page = 1): array {
        return ['data' => [], 'done' => true];
    }

    /**
     * @param string $email_address
     * @param int    $page
     * @return array{items_removed:bool, items_retained:bool, messages:array<int,string>, done:bool}
     */
    public static function erase($email_address, $page = 1): array {
        return [
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => [
                __('WP SEO Doctor stores no data keyed to an email address. Its 404 and redirect logs are keyed by URL, and any recorded IP address is truncated before storage.', 'wp-seo-doctor'),
            ],
            'done'           => true,
        ];
    }
}
