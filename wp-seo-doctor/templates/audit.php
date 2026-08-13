<?php
/**
 * SEO Audit: launch scans, review scan history, browse checks by group.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_history = WPSD_Scanner::history(20);
$wpsd_groups  = WPSD_Checks::groups();
$wpsd_checks  = WPSD_Checks::all();
$wpsd_latest  = WPSD_Scanner::latest_completed();
?>

<div class="wpsd-card">
    <h2><?php esc_html_e('Run an audit', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted">
        <?php
        printf(
            /* translators: 1: number of checks, 2: comma-separated post types */
            esc_html__('%1$d checks run against every published %2$s.', 'wp-seo-doctor'),
            count($wpsd_checks),
            esc_html(implode(', ', WPSD_Helpers::auditable_post_types()))
        );
        ?>
    </p>

    <div class="wpsd-actions">
        <button type="button" class="button button-primary wpsd-scan-trigger" data-type="full">
            <?php esc_html_e('Complete website audit', 'wp-seo-doctor'); ?>
        </button>
        <button type="button" class="button wpsd-scan-trigger" data-type="onpage">
            <?php esc_html_e('On-page only', 'wp-seo-doctor'); ?>
        </button>
        <button type="button" class="button wpsd-scan-trigger" data-type="technical">
            <?php esc_html_e('Technical only', 'wp-seo-doctor'); ?>
        </button>
        <button type="button" class="button wpsd-scan-trigger" data-type="content">
            <?php esc_html_e('Content only', 'wp-seo-doctor'); ?>
        </button>
        <button type="button" class="button wpsd-scan-trigger" data-type="links">
            <?php esc_html_e('Internal linking only', 'wp-seo-doctor'); ?>
        </button>
        <?php if (WPSD_WooCommerce_SEO::is_active()) : ?>
            <button type="button" class="button wpsd-scan-trigger" data-type="woo">
                <?php esc_html_e('Products only', 'wp-seo-doctor'); ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if ((string) WPSD_Settings::get('scan_schedule', 'disabled') !== 'disabled') : ?>
        <?php $wpsd_next = WPSD_Cron::next_runs()['scan_schedule'] ?? 0; ?>
        <p class="wpsd-muted">
            <?php
            printf(
                /* translators: 1: schedule name, 2: next run date */
                esc_html__('Scheduled scan: %1$s. Next run %2$s.', 'wp-seo-doctor'),
                esc_html((string) WPSD_Settings::get('scan_schedule')),
                $wpsd_next
                    ? esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), gmdate('Y-m-d H:i:s', $wpsd_next)))
                    : esc_html__('not scheduled', 'wp-seo-doctor')
            );
            ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($wpsd_latest) : ?>
    <?php $wpsd_score = WPSD_Score::calculate((int) $wpsd_latest->id); ?>
    <div class="wpsd-card">
        <h2><?php esc_html_e('Latest results', 'wp-seo-doctor'); ?></h2>
        <?php
        WPSD_Admin_Menu::stat_cards([
            ['label' => __('Score', 'wp-seo-doctor'), 'value' => $wpsd_score['score'] . '/100'],
            ['label' => __('Pages', 'wp-seo-doctor'), 'value' => (int) $wpsd_latest->processed],
            ['label' => __('Critical', 'wp-seo-doctor'), 'value' => (int) $wpsd_latest->critical, 'tone' => 'critical'],
            ['label' => __('High', 'wp-seo-doctor'), 'value' => (int) $wpsd_latest->high, 'tone' => 'high'],
            ['label' => __('Medium', 'wp-seo-doctor'), 'value' => (int) $wpsd_latest->medium, 'tone' => 'medium'],
            ['label' => __('Low', 'wp-seo-doctor'), 'value' => (int) $wpsd_latest->low, 'tone' => 'low'],
            ['label' => __('Passed', 'wp-seo-doctor'), 'value' => (int) $wpsd_latest->passed, 'tone' => 'pass'],
        ]);
        ?>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues')); ?>">
                <?php esc_html_e('Review all issues', 'wp-seo-doctor'); ?>
            </a>
            <a class="button" href="<?php echo esc_url(WPSD_Export::url('audit', 'csv')); ?>">
                <?php esc_html_e('Export CSV', 'wp-seo-doctor'); ?>
            </a>
            <a class="button" href="<?php echo esc_url(WPSD_Export::url('audit', 'pdf')); ?>">
                <?php esc_html_e('Export PDF', 'wp-seo-doctor'); ?>
            </a>
        </p>
    </div>
<?php endif; ?>

<div class="wpsd-card">
    <h2><?php esc_html_e('Scan history', 'wp-seo-doctor'); ?></h2>

    <?php if (!$wpsd_history) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No scans recorded yet.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Started', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Type', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Trigger', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Status', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Pages', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Score', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Issues', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Duration', 'wp-seo-doctor'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_history as $wpsd_row) : ?>
                <?php
                $wpsd_started  = strtotime((string) $wpsd_row->started_at);
                $wpsd_finished = $wpsd_row->finished_at ? strtotime((string) $wpsd_row->finished_at) : 0;
                $wpsd_duration = ($wpsd_started && $wpsd_finished) ? human_time_diff($wpsd_started, $wpsd_finished) : '—';
                ?>
                <tr>
                    <td><?php echo esc_html(mysql2date(get_option('date_format') . ' H:i', (string) $wpsd_row->started_at)); ?></td>
                    <td><?php echo esc_html((string) $wpsd_row->type); ?></td>
                    <td><?php echo esc_html((string) $wpsd_row->trigger_source); ?></td>
                    <td><span class="wpsd-status wpsd-status--<?php echo esc_attr((string) $wpsd_row->status); ?>"><?php echo esc_html((string) $wpsd_row->status); ?></span></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row->processed); ?></td>
                    <td class="wpsd-num"><?php echo $wpsd_row->score !== null ? esc_html((string) $wpsd_row->score) : '—'; ?></td>
                    <td class="wpsd-num">
                        <?php echo esc_html((string) $wpsd_row->total_issues); ?>
                        <span class="wpsd-muted">(<?php echo esc_html((string) $wpsd_row->critical); ?> <?php esc_html_e('critical', 'wp-seo-doctor'); ?>)</span>
                    </td>
                    <td><?php echo esc_html($wpsd_duration); ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues', ['scan_id' => (int) $wpsd_row->id])); ?>">
                            <?php esc_html_e('Issues', 'wp-seo-doctor'); ?>
                        </a>
                        <button type="button" class="button button-small wpsd-delete-scan" data-scan-id="<?php echo esc_attr((string) $wpsd_row->id); ?>">
                            <?php esc_html_e('Delete', 'wp-seo-doctor'); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="wpsd-card">
    <h2><?php esc_html_e('Checks performed', 'wp-seo-doctor'); ?></h2>

    <?php foreach ($wpsd_groups as $wpsd_group_key => $wpsd_group_label) : ?>
        <?php
        $wpsd_group_checks = WPSD_Checks::in_group($wpsd_group_key);
        if (!$wpsd_group_checks) {
            continue;
        }
        ?>
        <h3><?php echo esc_html($wpsd_group_label); ?> <span class="wpsd-muted">(<?php echo esc_html((string) count($wpsd_group_checks)); ?>)</span></h3>
        <table class="wpsd-table wpsd-table--compact">
            <tbody>
            <?php foreach ($wpsd_group_checks as $wpsd_check) : ?>
                <?php $wpsd_open = WPSD_DB::count('issues', 'check_id = %s AND status = %s', [$wpsd_check['id'], 'open']); ?>
                <tr>
                    <td style="width:22%"><strong><?php echo esc_html((string) $wpsd_check['title']); ?></strong></td>
                    <td><?php echo esc_html((string) $wpsd_check['description']); ?></td>
                    <td style="width:10%"><?php echo wp_kses_post(WPSD_Admin_Menu::severity_badge((string) $wpsd_check['severity'])); ?></td>
                    <td style="width:12%" class="wpsd-num">
                        <?php if ($wpsd_open > 0) : ?>
                            <a href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues', ['check_id' => (string) $wpsd_check['id']])); ?>">
                                <?php
                                printf(
                                    /* translators: %d: number of failing pages */
                                    esc_html__('%d failing', 'wp-seo-doctor'),
                                    $wpsd_open
                                );
                                ?>
                            </a>
                        <?php else : ?>
                            <span class="wpsd-muted"><?php esc_html_e('Passing', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</div>
