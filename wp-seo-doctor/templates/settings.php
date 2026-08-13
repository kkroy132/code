<?php
/**
 * Settings screen.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_settings = WPSD_Settings::all();
$wpsd_tab      = WPSD_Admin_Menu::query_arg('tab', 'general');
$wpsd_next     = WPSD_Cron::next_runs();

$wpsd_tabs = [
    'general'   => __('General', 'wp-seo-doctor'),
    'onpage'    => __('On-page thresholds', 'wp-seo-doctor'),
    'links'     => __('Links', 'wp-seo-doctor'),
    '404'       => __('404 & redirects', 'wp-seo-doctor'),
    'content'   => __('Content', 'wp-seo-doctor'),
    'gsc'       => __('Search Console', 'wp-seo-doctor'),
    'ai'        => __('AI', 'wp-seo-doctor'),
    'affiliate' => __('Affiliate', 'wp-seo-doctor'),
    'reports'   => __('Reports', 'wp-seo-doctor'),
];

/**
 * Render a schedule dropdown.
 *
 * @param string $key   Setting key.
 * @param string $value Current value.
 */
$wpsd_schedule_field = static function (string $key, string $value, int $next = 0): void {
    $options = [
        'disabled' => __('Disabled', 'wp-seo-doctor'),
        'daily'    => __('Daily', 'wp-seo-doctor'),
        'weekly'   => __('Weekly', 'wp-seo-doctor'),
        'monthly'  => __('Monthly', 'wp-seo-doctor'),
    ];
    echo '<select name="wpsd[' . esc_attr($key) . ']">';
    foreach ($options as $option_value => $label) {
        printf(
            '<option value="%1$s" %2$s>%3$s</option>',
            esc_attr($option_value),
            selected($value, $option_value, false),
            esc_html($label)
        );
    }
    echo '</select>';

    if ($next > 0) {
        echo ' <span class="wpsd-muted">' . esc_html(sprintf(
            /* translators: %s: date and time of the next scheduled run */
            __('Next run: %s', 'wp-seo-doctor'),
            mysql2date(get_option('date_format') . ' H:i', gmdate('Y-m-d H:i:s', $next))
        )) . '</span>';
    }
};
?>

<nav class="wpsd-subnav">
    <?php foreach ($wpsd_tabs as $wpsd_key => $wpsd_label) : ?>
        <a class="wpsd-subnav__item<?php echo $wpsd_tab === $wpsd_key ? ' is-active' : ''; ?>"
           href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-settings', ['tab' => $wpsd_key])); ?>">
            <?php echo esc_html($wpsd_label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<form method="post" class="wpsd-form">
    <?php wp_nonce_field('wpsd_save_settings'); ?>
    <input type="hidden" name="wpsd_action" value="save_settings">

    <div class="wpsd-card">
    <?php if ($wpsd_tab === 'onpage') : ?>

        <h2><?php esc_html_e('On-page thresholds', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsd-title-min"><?php esc_html_e('Title length', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-title-min" name="wpsd[title_min]" value="<?php echo esc_attr((string) $wpsd_settings['title_min']); ?>" min="10" max="100" class="small-text">
                    <?php esc_html_e('to', 'wp-seo-doctor'); ?>
                    <input type="number" name="wpsd[title_max]" value="<?php echo esc_attr((string) $wpsd_settings['title_max']); ?>" min="20" max="120" class="small-text">
                    <p class="description"><?php esc_html_e('Characters. Google truncates titles at roughly 580 pixels, which is about 60 characters.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-desc-min"><?php esc_html_e('Meta description length', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-desc-min" name="wpsd[desc_min]" value="<?php echo esc_attr((string) $wpsd_settings['desc_min']); ?>" min="20" max="200" class="small-text">
                    <?php esc_html_e('to', 'wp-seo-doctor'); ?>
                    <input type="number" name="wpsd[desc_max]" value="<?php echo esc_attr((string) $wpsd_settings['desc_max']); ?>" min="60" max="320" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-content-min"><?php esc_html_e('Minimum word count', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-content-min" name="wpsd[content_min_words]" value="<?php echo esc_attr((string) $wpsd_settings['content_min_words']); ?>" min="50" class="small-text">
                    <p class="description"><?php esc_html_e('Pages below this are flagged as short.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-thin"><?php esc_html_e('Thin content threshold', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-thin" name="wpsd[thin_content_words]" value="<?php echo esc_attr((string) $wpsd_settings['thin_content_words']); ?>" min="20" class="small-text">
                    <p class="description"><?php esc_html_e('Pages below this are flagged as thin content — a stronger signal than "short".', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-density-min"><?php esc_html_e('Keyword density band', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" step="0.1" id="wpsd-density-min" name="wpsd[keyword_density_min]" value="<?php echo esc_attr((string) $wpsd_settings['keyword_density_min']); ?>" class="small-text">%
                    <?php esc_html_e('to', 'wp-seo-doctor'); ?>
                    <input type="number" step="0.1" name="wpsd[keyword_density_max]" value="<?php echo esc_attr((string) $wpsd_settings['keyword_density_max']); ?>" class="small-text">%
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-depth"><?php esc_html_e('Maximum crawl depth', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-depth" name="wpsd[max_crawl_depth]" value="<?php echo esc_attr((string) $wpsd_settings['max_crawl_depth']); ?>" min="1" max="20" class="small-text">
                    <p class="description"><?php esc_html_e('Clicks from the homepage before a page is flagged as buried.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === 'links') : ?>

        <h2><?php esc_html_e('Internal linking', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsd-min-internal"><?php esc_html_e('Minimum outgoing internal links', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" id="wpsd-min-internal" name="wpsd[min_internal_links]" value="<?php echo esc_attr((string) $wpsd_settings['min_internal_links']); ?>" min="0" class="small-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-min-incoming"><?php esc_html_e('Minimum incoming internal links', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" id="wpsd-min-incoming" name="wpsd[min_incoming_links]" value="<?php echo esc_attr((string) $wpsd_settings['min_incoming_links']); ?>" min="0" class="small-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-suggestions"><?php esc_html_e('Suggestions per page', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" id="wpsd-suggestions" name="wpsd[link_suggestion_limit]" value="<?php echo esc_attr((string) $wpsd_settings['link_suggestion_limit']); ?>" min="1" max="30" class="small-text"></td>
            </tr>
        </table>

        <h2><?php esc_html_e('Broken link checking', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Schedule', 'wp-seo-doctor'); ?></th>
                <td><?php $wpsd_schedule_field('link_check_schedule', (string) $wpsd_settings['link_check_schedule'], (int) ($wpsd_next['link_check_schedule'] ?? 0)); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-link-batch"><?php esc_html_e('Links per batch', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-link-batch" name="wpsd[link_check_batch]" value="<?php echo esc_attr((string) $wpsd_settings['link_check_batch']); ?>" min="1" max="100" class="small-text">
                    <p class="description"><?php esc_html_e('Lower this if your host rate-limits outbound requests.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-recheck"><?php esc_html_e('Recheck interval', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-recheck" name="wpsd[link_recheck_days]" value="<?php echo esc_attr((string) $wpsd_settings['link_recheck_days']); ?>" min="1" class="small-text">
                    <?php esc_html_e('days', 'wp-seo-doctor'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('External links', 'wp-seo-doctor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpsd[check_external_links]" value="1" <?php checked((bool) $wpsd_settings['check_external_links']); ?>>
                        <?php esc_html_e('Also check links pointing off-site', 'wp-seo-doctor'); ?>
                    </label>
                </td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === '404') : ?>

        <h2><?php esc_html_e('404 monitor', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Logging', 'wp-seo-doctor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpsd[monitor_404]" value="1" <?php checked((bool) $wpsd_settings['monitor_404']); ?>>
                        <?php esc_html_e('Record 404 requests', 'wp-seo-doctor'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="wpsd[log_404_referrer]" value="1" <?php checked((bool) $wpsd_settings['log_404_referrer']); ?>>
                        <?php esc_html_e('Record the referring URL', 'wp-seo-doctor'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-404-retention"><?php esc_html_e('Retention', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-404-retention" name="wpsd[notfound_retention]" value="<?php echo esc_attr((string) $wpsd_settings['notfound_retention']); ?>" min="0" class="small-text">
                    <?php esc_html_e('days (0 keeps everything)', 'wp-seo-doctor'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-404-ignore"><?php esc_html_e('Ignore patterns', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <textarea id="wpsd-404-ignore" name="wpsd[ignore_404_patterns]" rows="7" class="large-text code"><?php echo esc_textarea((string) $wpsd_settings['ignore_404_patterns']); ?></textarea>
                    <p class="description"><?php esc_html_e('One per line. Plain text matches anywhere in the URL; wrap in slashes for a regular expression.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Redirects', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Redirect engine', 'wp-seo-doctor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpsd[redirects_enabled]" value="1" <?php checked((bool) $wpsd_settings['redirects_enabled']); ?>>
                        <?php esc_html_e('Serve redirects from the rules below', 'wp-seo-doctor'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="wpsd[log_redirects]" value="1" <?php checked((bool) $wpsd_settings['log_redirects']); ?>>
                        <?php esc_html_e('Log every redirect that fires', 'wp-seo-doctor'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-redirect-log-limit"><?php esc_html_e('Log entries to keep', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" id="wpsd-redirect-log-limit" name="wpsd[redirect_log_limit]" value="<?php echo esc_attr((string) $wpsd_settings['redirect_log_limit']); ?>" min="0" class="regular-text"></td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === 'content') : ?>

        <h2><?php esc_html_e('Content SEO', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsd-outdated"><?php esc_html_e('Outdated after', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-outdated" name="wpsd[outdated_after_days]" value="<?php echo esc_attr((string) $wpsd_settings['outdated_after_days']); ?>" min="30" class="small-text">
                    <?php esc_html_e('days without an update', 'wp-seo-doctor'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-decay"><?php esc_html_e('Decay comparison window', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-decay" name="wpsd[decay_window_days]" value="<?php echo esc_attr((string) $wpsd_settings['decay_window_days']); ?>" min="14" class="small-text">
                    <?php esc_html_e('days', 'wp-seo-doctor'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-duplicate"><?php esc_html_e('Duplicate threshold', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" step="0.05" min="0.1" max="1" id="wpsd-duplicate" name="wpsd[duplicate_threshold]" value="<?php echo esc_attr((string) $wpsd_settings['duplicate_threshold']); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('Word-overlap ratio, from 0.1 to 1.0. 0.75 catches near-duplicates without flagging pages that merely share a topic.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === 'gsc') : ?>

        <h2><?php esc_html_e('Google Search Console', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsd-gsc-id"><?php esc_html_e('OAuth client ID', 'wp-seo-doctor'); ?></label></th>
                <td><input type="text" id="wpsd-gsc-id" name="wpsd[gsc_client_id]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_client_id']); ?>" class="large-text" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-gsc-secret"><?php esc_html_e('OAuth client secret', 'wp-seo-doctor'); ?></label></th>
                <td><input type="password" id="wpsd-gsc-secret" name="wpsd[gsc_client_secret]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_client_secret']); ?>" class="large-text" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Redirect URI', 'wp-seo-doctor'); ?></th>
                <td>
                    <code><?php echo esc_html(WPSD_GSC::redirect_uri()); ?></code>
                    <p class="description"><?php esc_html_e('Add this exact value to your OAuth client in Google Cloud Console.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-gsc-property"><?php esc_html_e('Property', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="text" id="wpsd-gsc-property" name="wpsd[gsc_property]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_property']); ?>" class="large-text" placeholder="sc-domain:example.com">
                    <p class="description"><?php esc_html_e('Either sc-domain:example.com or the full URL-prefix property.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sync schedule', 'wp-seo-doctor'); ?></th>
                <td><?php $wpsd_schedule_field('gsc_sync_schedule', (string) $wpsd_settings['gsc_sync_schedule'], (int) ($wpsd_next['gsc_sync_schedule'] ?? 0)); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-gsc-lookback"><?php esc_html_e('Lookback window', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-gsc-lookback" name="wpsd[gsc_lookback_days]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_lookback_days']); ?>" min="1" max="480" class="small-text">
                    <?php esc_html_e('days', 'wp-seo-doctor'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-gsc-low"><?php esc_html_e('Striking distance band', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <?php esc_html_e('positions', 'wp-seo-doctor'); ?>
                    <input type="number" step="0.5" id="wpsd-gsc-low" name="wpsd[gsc_position_low]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_position_low']); ?>" class="small-text">
                    <?php esc_html_e('to', 'wp-seo-doctor'); ?>
                    <input type="number" step="0.5" name="wpsd[gsc_position_high]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_position_high']); ?>" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-gsc-ctr"><?php esc_html_e('Low CTR threshold', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" step="0.1" id="wpsd-gsc-ctr" name="wpsd[gsc_ctr_floor]" value="<?php echo esc_attr((string) $wpsd_settings['gsc_ctr_floor']); ?>" class="small-text">%</td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === 'ai') : ?>

        <h2><?php esc_html_e('AI', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('AI requests go directly from your server to the provider using your key. Page content is sent as part of the prompt, so only enable this if that is acceptable for your site.', 'wp-seo-doctor'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable', 'wp-seo-doctor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpsd[ai_enabled]" value="1" <?php checked((bool) $wpsd_settings['ai_enabled']); ?>>
                        <?php esc_html_e('Enable AI features', 'wp-seo-doctor'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-ai-provider"><?php esc_html_e('Provider', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <select id="wpsd-ai-provider" name="wpsd[ai_provider]">
                        <option value="anthropic" <?php selected((string) $wpsd_settings['ai_provider'], 'anthropic'); ?>><?php esc_html_e('Anthropic', 'wp-seo-doctor'); ?></option>
                        <option value="openai" <?php selected((string) $wpsd_settings['ai_provider'], 'openai'); ?>><?php esc_html_e('OpenAI', 'wp-seo-doctor'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-ai-key"><?php esc_html_e('API key', 'wp-seo-doctor'); ?></label></th>
                <td><input type="password" id="wpsd-ai-key" name="wpsd[ai_api_key]" value="<?php echo esc_attr((string) $wpsd_settings['ai_api_key']); ?>" class="large-text" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-ai-model"><?php esc_html_e('Model', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="text" id="wpsd-ai-model" name="wpsd[ai_model]" value="<?php echo esc_attr((string) $wpsd_settings['ai_model']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('For example claude-sonnet-5 (Anthropic) or gpt-4o-mini (OpenAI).', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-ai-tokens"><?php esc_html_e('Max tokens', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" id="wpsd-ai-tokens" name="wpsd[ai_max_tokens]" value="<?php echo esc_attr((string) $wpsd_settings['ai_max_tokens']); ?>" min="200" max="8000" class="small-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-ai-temp"><?php esc_html_e('Temperature', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" step="0.1" min="0" max="1" id="wpsd-ai-temp" name="wpsd[ai_temperature]" value="<?php echo esc_attr((string) $wpsd_settings['ai_temperature']); ?>" class="small-text"></td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === 'affiliate') : ?>

        <h2><?php esc_html_e('Affiliate link detection', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsd-aff-domains"><?php esc_html_e('Network domains', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <textarea id="wpsd-aff-domains" name="wpsd[affiliate_domains]" rows="8" class="large-text code"><?php echo esc_textarea((string) $wpsd_settings['affiliate_domains']); ?></textarea>
                    <p class="description"><?php esc_html_e('One per line. A link is affiliate if its host contains any of these strings.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-aff-prefixes"><?php esc_html_e('Cloaking prefixes', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <textarea id="wpsd-aff-prefixes" name="wpsd[affiliate_prefixes]" rows="6" class="large-text code"><?php echo esc_textarea((string) $wpsd_settings['affiliate_prefixes']); ?></textarea>
                    <p class="description"><?php esc_html_e('Path prefixes on your own domain used for cloaked links, e.g. /go/ or /recommends/.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Required rel values', 'wp-seo-doctor'); ?></th>
                <td>
                    <?php foreach (['sponsored', 'nofollow', 'ugc'] as $wpsd_rel) : ?>
                        <label style="margin-right:14px">
                            <input type="checkbox" name="wpsd[affiliate_required_rel][]" value="<?php echo esc_attr($wpsd_rel); ?>"
                                <?php checked(in_array($wpsd_rel, (array) $wpsd_settings['affiliate_required_rel'], true)); ?>>
                            rel="<?php echo esc_html($wpsd_rel); ?>"
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e('A link passes if it carries at least one of the selected values.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
        </table>

    <?php elseif ($wpsd_tab === 'reports') : ?>

        <h2><?php esc_html_e('Email reports', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Send summaries', 'wp-seo-doctor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpsd[email_reports]" value="1" <?php checked((bool) $wpsd_settings['email_reports']); ?>>
                        <?php esc_html_e('Email an SEO health summary on a schedule', 'wp-seo-doctor'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Schedule', 'wp-seo-doctor'); ?></th>
                <td><?php $wpsd_schedule_field('email_schedule', (string) $wpsd_settings['email_schedule'], (int) ($wpsd_next['email_schedule'] ?? 0)); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-report-email"><?php esc_html_e('Recipient', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="email" id="wpsd-report-email" name="wpsd[report_email]" value="<?php echo esc_attr((string) $wpsd_settings['report_email']); ?>" class="regular-text"
                           placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                    <p class="description"><?php esc_html_e('Leave blank to use the site admin address.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
        </table>

    <?php else : ?>

        <h2><?php esc_html_e('General', 'wp-seo-doctor'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Post types to audit', 'wp-seo-doctor'); ?></th>
                <td>
                    <?php foreach (get_post_types(['public' => true], 'objects') as $wpsd_type) : ?>
                        <?php if ($wpsd_type->name === 'attachment') { continue; } ?>
                        <label style="display:inline-block;margin:0 16px 6px 0">
                            <input type="checkbox" name="wpsd[post_types][]" value="<?php echo esc_attr($wpsd_type->name); ?>"
                                <?php checked(in_array($wpsd_type->name, (array) $wpsd_settings['post_types'], true)); ?>>
                            <?php echo esc_html($wpsd_type->labels->name); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Scheduled audit', 'wp-seo-doctor'); ?></th>
                <td><?php $wpsd_schedule_field('scan_schedule', (string) $wpsd_settings['scan_schedule'], (int) ($wpsd_next['scan_schedule'] ?? 0)); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-batch"><?php esc_html_e('Pages per batch', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-batch" name="wpsd[scan_batch_size]" value="<?php echo esc_attr((string) $wpsd_settings['scan_batch_size']); ?>" min="1" max="200" class="small-text">
                    <p class="description"><?php esc_html_e('Lower this on shared hosting if scans time out.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-timeout"><?php esc_html_e('Request timeout', 'wp-seo-doctor'); ?></label></th>
                <td>
                    <input type="number" id="wpsd-timeout" name="wpsd[request_timeout]" value="<?php echo esc_attr((string) $wpsd_settings['request_timeout']); ?>" min="3" max="60" class="small-text">
                    <?php esc_html_e('seconds', 'wp-seo-doctor'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Live URL checks', 'wp-seo-doctor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpsd[check_http_status]" value="1" <?php checked((bool) $wpsd_settings['check_http_status']); ?>>
                        <?php esc_html_e('Fetch each URL during a scan', 'wp-seo-doctor'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Required for HTTP status, redirect, schema and Open Graph checks. Costs one request per page scanned.', 'wp-seo-doctor'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsd-history"><?php esc_html_e('Scans to keep', 'wp-seo-doctor'); ?></label></th>
                <td><input type="number" id="wpsd-history" name="wpsd[scan_history_limit]" value="<?php echo esc_attr((string) $wpsd_settings['scan_history_limit']); ?>" min="1" max="365" class="small-text"></td>
            </tr>
        </table>

    <?php endif; ?>

        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save settings', 'wp-seo-doctor'); ?></button>
        </p>
    </div>
</form>
