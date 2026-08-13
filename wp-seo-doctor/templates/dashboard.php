<?php
/**
 * Dashboard: health score, severity breakdown, Fix First list and trends.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_score   = WPSD_Score::calculate();
$wpsd_counts  = $wpsd_score['counts'];
$wpsd_groups  = WPSD_Score::by_group();
$wpsd_trend   = WPSD_Score::trend(30);
$wpsd_delta   = WPSD_Score::trend_delta(30);
$wpsd_fix     = WPSD_Issues::fix_first(8);
$wpsd_scan    = WPSD_Scanner::latest_completed();
$wpsd_links   = WPSD_Internal_Links::stats();
$wpsd_broken  = WPSD_Broken_Links::stats();
$wpsd_404     = WPSD_Monitor_404::stats();
?>

<?php if (!$wpsd_scan) : ?>
    <div class="wpsd-callout">
        <h2><?php esc_html_e('No scan has run yet', 'wp-seo-doctor'); ?></h2>
        <p><?php esc_html_e('Run a full scan to audit every published page for on-page, technical, content and internal-linking problems.', 'wp-seo-doctor'); ?></p>
        <button type="button" class="button button-primary button-hero wpsd-scan-trigger" data-type="full">
            <?php esc_html_e('Run your first scan', 'wp-seo-doctor'); ?>
        </button>
    </div>
<?php endif; ?>

<div class="wpsd-grid wpsd-grid--dashboard">

    <div class="wpsd-card wpsd-card--score">
        <h2><?php esc_html_e('SEO Health Score', 'wp-seo-doctor'); ?></h2>

        <div class="wpsd-gauge" style="--wpsd-score:<?php echo esc_attr((string) $wpsd_score['score']); ?>;--wpsd-color:<?php echo esc_attr(WPSD_Score::color((int) $wpsd_score['score'])); ?>">
            <div class="wpsd-gauge__value">
                <strong><?php echo esc_html((string) $wpsd_score['score']); ?></strong>
                <span><?php esc_html_e('/ 100', 'wp-seo-doctor'); ?></span>
            </div>
        </div>

        <p class="wpsd-gauge__label">
            <?php echo esc_html($wpsd_score['label']); ?>
            <?php if ($wpsd_delta['delta'] !== 0) : ?>
                <span class="wpsd-delta wpsd-delta--<?php echo esc_attr($wpsd_delta['direction']); ?>">
                    <?php echo esc_html(sprintf('%+d', $wpsd_delta['delta'])); ?>
                    <?php esc_html_e('in 30 days', 'wp-seo-doctor'); ?>
                </span>
            <?php endif; ?>
        </p>

        <?php if ($wpsd_scan) : ?>
            <p class="wpsd-muted">
                <?php
                printf(
                    /* translators: 1: page count, 2: date */
                    esc_html__('%1$d pages scanned on %2$s', 'wp-seo-doctor'),
                    (int) $wpsd_scan->processed,
                    esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $wpsd_scan->finished_at))
                );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Issues by priority', 'wp-seo-doctor'); ?></h2>
        <?php
        WPSD_Admin_Menu::stat_cards([
            [
                'label' => __('Critical', 'wp-seo-doctor'),
                'value' => $wpsd_counts['critical'],
                'tone'  => 'critical',
                'href'  => WPSD_Admin_Menu::page_url('-issues', ['severity' => 'critical']),
            ],
            [
                'label' => __('High', 'wp-seo-doctor'),
                'value' => $wpsd_counts['high'],
                'tone'  => 'high',
                'href'  => WPSD_Admin_Menu::page_url('-issues', ['severity' => 'high']),
            ],
            [
                'label' => __('Medium', 'wp-seo-doctor'),
                'value' => $wpsd_counts['medium'],
                'tone'  => 'medium',
                'href'  => WPSD_Admin_Menu::page_url('-issues', ['severity' => 'medium']),
            ],
            [
                'label' => __('Low', 'wp-seo-doctor'),
                'value' => $wpsd_counts['low'],
                'tone'  => 'low',
                'href'  => WPSD_Admin_Menu::page_url('-issues', ['severity' => 'low']),
            ],
            [
                'label' => __('Passed checks', 'wp-seo-doctor'),
                'value' => $wpsd_score['passed'],
                'tone'  => 'pass',
            ],
        ]);
        ?>

        <h3><?php esc_html_e('Score by area', 'wp-seo-doctor'); ?></h3>
        <table class="wpsd-table wpsd-table--compact">
            <tbody>
            <?php foreach ($wpsd_groups as $wpsd_group) : ?>
                <tr>
                    <td><?php echo esc_html($wpsd_group['label']); ?></td>
                    <td class="wpsd-meter-cell">
                        <span class="wpsd-meter">
                            <span class="wpsd-meter__fill"
                                  style="width:<?php echo esc_attr((string) $wpsd_group['score']); ?>%;background:<?php echo esc_attr(WPSD_Score::color((int) $wpsd_group['score'])); ?>"></span>
                        </span>
                    </td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_group['score']); ?></td>
                    <td class="wpsd-num wpsd-muted">
                        <?php
                        printf(
                            /* translators: %d: issue count */
                            esc_html__('%d issues', 'wp-seo-doctor'),
                            (int) $wpsd_group['issues']
                        );
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="wpsd-card wpsd-card--wide">
        <h2><?php esc_html_e('Fix these first', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('Ranked by severity multiplied by the number of pages affected — the highest-leverage work at the top.', 'wp-seo-doctor'); ?></p>

        <?php if (!$wpsd_fix) : ?>
            <?php WPSD_Admin_Menu::empty_state(
                __('Nothing to fix right now.', 'wp-seo-doctor'),
                $wpsd_scan ? __('Every check passed on the last scan.', 'wp-seo-doctor') : __('Run a scan to populate this list.', 'wp-seo-doctor')
            ); ?>
        <?php else : ?>
            <table class="wpsd-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('Priority', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Issue', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Pages', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('What to do', 'wp-seo-doctor'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wpsd_fix as $wpsd_item) : ?>
                    <tr>
                        <td><?php echo wp_kses_post(WPSD_Admin_Menu::severity_badge((string) $wpsd_item->severity)); ?></td>
                        <td><strong><?php echo esc_html((string) $wpsd_item->title); ?></strong></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_item->affected); ?></td>
                        <td class="wpsd-muted"><?php echo esc_html((string) $wpsd_item->recommendation); ?></td>
                        <td>
                            <a class="button button-small"
                               href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues', ['check_id' => (string) $wpsd_item->check_id])); ?>">
                                <?php esc_html_e('View', 'wp-seo-doctor'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Quick fixes', 'wp-seo-doctor'); ?></h2>
        <div class="wpsd-actions">
            <button type="button" class="button wpsd-scan-trigger" data-type="onpage"><?php esc_html_e('Scan on-page SEO', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-scan-trigger" data-type="technical"><?php esc_html_e('Scan technical SEO', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button" id="wpsd-check-links"><?php esc_html_e('Check links now', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button" id="wpsd-rebuild-links"><?php esc_html_e('Rebuild link graph', 'wp-seo-doctor'); ?></button>
            <?php if (WPSD_Redirects::stats()['chains'] > 0) : ?>
                <button type="button" class="button" id="wpsd-flatten-redirects"><?php esc_html_e('Flatten redirect chains', 'wp-seo-doctor'); ?></button>
            <?php endif; ?>
        </div>
        <div class="wpsd-inline-result" id="wpsd-quickfix-result"></div>

        <h3><?php esc_html_e('Site at a glance', 'wp-seo-doctor'); ?></h3>
        <?php
        WPSD_Admin_Menu::stat_cards([
            [
                'label' => __('Broken links', 'wp-seo-doctor'),
                'value' => $wpsd_broken['broken'],
                'tone'  => $wpsd_broken['broken'] > 0 ? 'critical' : 'pass',
                'href'  => WPSD_Admin_Menu::page_url('-broken-links'),
            ],
            [
                'label' => __('Unresolved 404s', 'wp-seo-doctor'),
                'value' => $wpsd_404['unresolved'],
                'tone'  => $wpsd_404['unresolved'] > 0 ? 'high' : 'pass',
                'href'  => WPSD_Admin_Menu::page_url('-404-monitor'),
            ],
            [
                'label' => __('Orphan pages', 'wp-seo-doctor'),
                'value' => $wpsd_links['orphans'],
                'tone'  => $wpsd_links['orphans'] > 0 ? 'high' : 'pass',
                'href'  => WPSD_Admin_Menu::page_url('-internal-links', ['view' => 'orphans']),
            ],
            [
                'label' => __('Internal links', 'wp-seo-doctor'),
                'value' => $wpsd_links['internal_links'],
                'href'  => WPSD_Admin_Menu::page_url('-internal-links'),
            ],
        ]);
        ?>
    </div>

    <div class="wpsd-card wpsd-card--wide">
        <h2><?php esc_html_e('SEO trends', 'wp-seo-doctor'); ?></h2>
        <?php if (count($wpsd_trend) < 2) : ?>
            <?php WPSD_Admin_Menu::empty_state(
                __('Not enough history to chart yet.', 'wp-seo-doctor'),
                __('A snapshot is recorded after every completed scan, and daily thereafter.', 'wp-seo-doctor')
            ); ?>
        <?php else : ?>
            <?php
            $wpsd_max_issues = max(1, max(array_map(static fn($r) => (int) $r->total_issues, $wpsd_trend)));
            ?>
            <div class="wpsd-chart" role="img"
                 aria-label="<?php esc_attr_e('SEO health score over the last 30 days', 'wp-seo-doctor'); ?>">
                <?php foreach ($wpsd_trend as $wpsd_point) : ?>
                    <div class="wpsd-chart__col"
                         title="<?php echo esc_attr(sprintf('%s — %d/100, %d issues', $wpsd_point->snapshot_date, (int) $wpsd_point->score, (int) $wpsd_point->total_issues)); ?>">
                        <span class="wpsd-chart__bar"
                              style="height:<?php echo esc_attr((string) max(2, (int) $wpsd_point->score)); ?>%;background:<?php echo esc_attr(WPSD_Score::color((int) $wpsd_point->score)); ?>"></span>
                        <span class="wpsd-chart__issues"
                              style="height:<?php echo esc_attr((string) round((int) $wpsd_point->total_issues / $wpsd_max_issues * 40)); ?>%"></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="wpsd-legend">
                <span class="wpsd-legend__key wpsd-legend__key--score"></span><?php esc_html_e('Health score', 'wp-seo-doctor'); ?>
                <span class="wpsd-legend__key wpsd-legend__key--issues"></span><?php esc_html_e('Open issues', 'wp-seo-doctor'); ?>
            </p>
        <?php endif; ?>
    </div>

</div>
