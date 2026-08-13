<?php
/**
 * Google Search Console.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_configured = WPSD_GSC::is_configured();
$wpsd_connected  = WPSD_GSC::is_connected();
$wpsd_has_data   = WPSD_GSC::has_data();
$wpsd_days       = (int) WPSD_Settings::get('gsc_lookback_days', 28);
?>

<?php if (isset($_GET['wpsd_connected'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
    <div class="wpsd-notice wpsd-notice--success">
        <?php esc_html_e('Search Console connected. Choose a property below, then run a sync.', 'wp-seo-doctor'); ?>
    </div>
<?php endif; ?>

<div class="wpsd-card">
    <h2><?php esc_html_e('Connection', 'wp-seo-doctor'); ?></h2>

    <?php if (!$wpsd_configured) : ?>
        <p><?php esc_html_e('To connect Search Console you need OAuth credentials from a Google Cloud project with the Search Console API enabled.', 'wp-seo-doctor'); ?></p>
        <ol class="wpsd-steps">
            <li><?php esc_html_e('Create an OAuth 2.0 Client ID of type "Web application" in Google Cloud Console.', 'wp-seo-doctor'); ?></li>
            <li>
                <?php esc_html_e('Add this exact redirect URI:', 'wp-seo-doctor'); ?>
                <code><?php echo esc_html(WPSD_GSC::redirect_uri()); ?></code>
            </li>
            <li><?php esc_html_e('Paste the client ID and secret into Settings → Search Console.', 'wp-seo-doctor'); ?></li>
        </ol>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-settings', ['tab' => 'gsc'])); ?>">
                <?php esc_html_e('Add credentials', 'wp-seo-doctor'); ?>
            </a>
        </p>

    <?php elseif (!$wpsd_connected) : ?>
        <p><?php esc_html_e('Credentials saved. Authorise access to read your Search Console data.', 'wp-seo-doctor'); ?></p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(WPSD_GSC::auth_url()); ?>">
                <?php esc_html_e('Connect to Google Search Console', 'wp-seo-doctor'); ?>
            </a>
        </p>

    <?php else : ?>
        <?php $wpsd_property = (string) WPSD_Settings::get('gsc_property', ''); ?>
        <p>
            <span class="wpsd-badge wpsd-badge--pass"><?php esc_html_e('Connected', 'wp-seo-doctor'); ?></span>
            <?php if ($wpsd_property !== '') : ?>
                <code><?php echo esc_html($wpsd_property); ?></code>
            <?php else : ?>
                <em><?php esc_html_e('No property selected yet.', 'wp-seo-doctor'); ?></em>
            <?php endif; ?>
            <?php if (WPSD_GSC::last_sync() !== '') : ?>
                <span class="wpsd-muted">
                    <?php
                    printf(
                        /* translators: %s: relative time */
                        esc_html__('Last synced %s ago', 'wp-seo-doctor'),
                        esc_html(human_time_diff(strtotime(WPSD_GSC::last_sync())))
                    );
                    ?>
                </span>
            <?php endif; ?>
        </p>

        <div class="wpsd-actions">
            <button type="button" class="button" id="wpsd-gsc-properties"><?php esc_html_e('List properties', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button button-primary" id="wpsd-gsc-sync"><?php esc_html_e('Sync now', 'wp-seo-doctor'); ?></button>
            <form method="post" style="display:inline">
                <?php wp_nonce_field('wpsd_gsc_disconnect'); ?>
                <input type="hidden" name="wpsd_action" value="gsc_disconnect">
                <button type="submit" class="button"><?php esc_html_e('Disconnect', 'wp-seo-doctor'); ?></button>
            </form>
            <span class="wpsd-inline-result" id="wpsd-gsc-result"></span>
        </div>
        <div id="wpsd-gsc-properties-list"></div>
    <?php endif; ?>
</div>

<?php if ($wpsd_has_data) : ?>
    <?php
    $wpsd_totals = WPSD_GSC::totals($wpsd_days);
    $wpsd_daily  = WPSD_GSC::daily_trend(90);
    ?>

    <div class="wpsd-card">
        <h2>
            <?php
            printf(
                /* translators: %d: number of days */
                esc_html__('Performance — last %d days', 'wp-seo-doctor'),
                $wpsd_days
            );
            ?>
        </h2>

        <?php
        WPSD_Admin_Menu::stat_cards([
            ['label' => __('Clicks', 'wp-seo-doctor'), 'value' => number_format_i18n($wpsd_totals['clicks'])],
            ['label' => __('Impressions', 'wp-seo-doctor'), 'value' => number_format_i18n($wpsd_totals['impressions'])],
            ['label' => __('CTR', 'wp-seo-doctor'), 'value' => $wpsd_totals['ctr'] . '%'],
            ['label' => __('Average position', 'wp-seo-doctor'), 'value' => $wpsd_totals['position']],
        ]);
        ?>

        <?php if (count($wpsd_daily) > 1) : ?>
            <?php $wpsd_peak = max(1, max(array_column($wpsd_daily, 'clicks'))); ?>
            <div class="wpsd-chart" role="img" aria-label="<?php esc_attr_e('Daily clicks over the last 90 days', 'wp-seo-doctor'); ?>">
                <?php foreach ($wpsd_daily as $wpsd_point) : ?>
                    <div class="wpsd-chart__col"
                         title="<?php echo esc_attr(sprintf('%s — %d clicks, %d impressions', $wpsd_point['date'], $wpsd_point['clicks'], $wpsd_point['impressions'])); ?>">
                        <span class="wpsd-chart__bar wpsd-chart__bar--clicks"
                              style="height:<?php echo esc_attr((string) max(2, round($wpsd_point['clicks'] / $wpsd_peak * 100))); ?>%"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="wpsd-grid wpsd-grid--halves">
        <div class="wpsd-card">
            <h2><?php esc_html_e('Top queries', 'wp-seo-doctor'); ?></h2>
            <table class="wpsd-table wpsd-table--compact">
                <thead>
                <tr>
                    <th><?php esc_html_e('Query', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Clicks', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Impr.', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('CTR', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Pos.', 'wp-seo-doctor'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (WPSD_GSC::top_queries(20, $wpsd_days) as $wpsd_row) : ?>
                    <tr>
                        <td><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['query'], 40)); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['clicks']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['impressions']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['ctr']); ?>%</td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['position']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="wpsd-card">
            <h2><?php esc_html_e('Top pages', 'wp-seo-doctor'); ?></h2>
            <table class="wpsd-table wpsd-table--compact">
                <thead>
                <tr>
                    <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Clicks', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Impr.', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('CTR', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Pos.', 'wp-seo-doctor'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (WPSD_GSC::top_pages(20, $wpsd_days) as $wpsd_row) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url((string) $wpsd_row['page']); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html(WPSD_Helpers::truncate((string) wp_parse_url((string) $wpsd_row['page'], PHP_URL_PATH), 40)); ?>
                            </a>
                        </td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['clicks']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['impressions']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['ctr']); ?>%</td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['position']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Ranking opportunities', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted">
            <?php
            printf(
                /* translators: 1: lower position bound, 2: upper position bound */
                esc_html__('Queries ranking between position %1$s and %2$s — small gains here move them onto page one.', 'wp-seo-doctor'),
                esc_html((string) WPSD_Settings::get('gsc_position_low', 11)),
                esc_html((string) WPSD_Settings::get('gsc_position_high', 20))
            );
            ?>
        </p>

        <?php $wpsd_striking = WPSD_GSC::striking_distance(30, $wpsd_days); ?>
        <?php if (!$wpsd_striking) : ?>
            <?php WPSD_Admin_Menu::empty_state(__('No queries in the striking-distance band.', 'wp-seo-doctor')); ?>
        <?php else : ?>
            <table class="wpsd-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('Query', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Position', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Impressions', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Clicks', 'wp-seo-doctor'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wpsd_striking as $wpsd_row) : ?>
                    <tr>
                        <td><strong><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['query'], 45)); ?></strong></td>
                        <td>
                            <a href="<?php echo esc_url((string) $wpsd_row['page']); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html(WPSD_Helpers::truncate((string) wp_parse_url((string) $wpsd_row['page'], PHP_URL_PATH), 35)); ?>
                            </a>
                        </td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['position']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['impressions']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['clicks']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="wpsd-grid wpsd-grid--halves">
        <div class="wpsd-card">
            <h2><?php esc_html_e('CTR opportunities', 'wp-seo-doctor'); ?></h2>
            <p class="wpsd-muted"><?php esc_html_e('Ranking well but not being clicked — usually a title or description problem.', 'wp-seo-doctor'); ?></p>
            <?php $wpsd_ctr = WPSD_GSC::ctr_opportunities(20, $wpsd_days); ?>
            <?php if (!$wpsd_ctr) : ?>
                <?php WPSD_Admin_Menu::empty_state(__('No low-CTR pages found.', 'wp-seo-doctor')); ?>
            <?php else : ?>
                <table class="wpsd-table wpsd-table--compact">
                    <tbody>
                    <?php foreach ($wpsd_ctr as $wpsd_row) : ?>
                        <?php $wpsd_post_id = WPSD_Internal_Links::resolve_post_id((string) $wpsd_row['page']); ?>
                        <tr>
                            <td><?php echo esc_html(WPSD_Helpers::truncate((string) wp_parse_url((string) $wpsd_row['page'], PHP_URL_PATH), 40)); ?></td>
                            <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['ctr']); ?>%</td>
                            <td class="wpsd-num wpsd-muted"><?php echo esc_html((string) $wpsd_row['impressions']); ?></td>
                            <td>
                                <?php if ($wpsd_post_id && WPSD_AI::is_enabled()) : ?>
                                    <button type="button" class="button button-small wpsd-ai-task" data-task="titles" data-post-id="<?php echo esc_attr((string) $wpsd_post_id); ?>">
                                        <?php esc_html_e('New titles', 'wp-seo-doctor'); ?>
                                    </button>
                                <?php endif; ?>
                                <div class="wpsd-ai-output"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="wpsd-card">
            <h2><?php esc_html_e('Declining pages', 'wp-seo-doctor'); ?></h2>
            <?php $wpsd_declining = WPSD_GSC::declining_pages(20, $wpsd_days); ?>
            <?php if (!$wpsd_declining) : ?>
                <?php WPSD_Admin_Menu::empty_state(__('No significant declines.', 'wp-seo-doctor')); ?>
            <?php else : ?>
                <table class="wpsd-table wpsd-table--compact">
                    <tbody>
                    <?php foreach ($wpsd_declining as $wpsd_row) : ?>
                        <tr>
                            <td><?php echo esc_html(WPSD_Helpers::truncate((string) wp_parse_url((string) $wpsd_row['page'], PHP_URL_PATH), 40)); ?></td>
                            <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['previous_clicks']); ?> → <?php echo esc_html((string) $wpsd_row['recent_clicks']); ?></td>
                            <td class="wpsd-num"><span class="wpsd-delta wpsd-delta--down"><?php echo esc_html((string) $wpsd_row['change_percent']); ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($wpsd_connected) : ?>
    <div class="wpsd-card">
        <?php WPSD_Admin_Menu::empty_state(
            __('No Search Console data cached yet.', 'wp-seo-doctor'),
            __('Select a property and run a sync to pull performance data.', 'wp-seo-doctor')
        ); ?>
    </div>
<?php endif; ?>
