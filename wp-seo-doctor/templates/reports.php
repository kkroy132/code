<?php
/**
 * Reports: preview any report and export it as CSV or PDF.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_available = WPSD_Reports::available();
$wpsd_selected  = WPSD_Admin_Menu::query_arg('report', 'audit');
if (!isset($wpsd_available[$wpsd_selected])) {
    $wpsd_selected = 'audit';
}

$wpsd_report = WPSD_Reports::build($wpsd_selected, ['limit' => 200]);
?>

<div class="wpsd-card">
    <h2><?php esc_html_e('Reports', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted"><?php esc_html_e('Every report can be previewed here and downloaded as CSV for spreadsheets, or PDF for sharing.', 'wp-seo-doctor'); ?></p>

    <table class="wpsd-table">
        <thead>
        <tr>
            <th><?php esc_html_e('Report', 'wp-seo-doctor'); ?></th>
            <th><?php esc_html_e('Preview', 'wp-seo-doctor'); ?></th>
            <th><?php esc_html_e('Download', 'wp-seo-doctor'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($wpsd_available as $wpsd_key => $wpsd_label) : ?>
            <tr<?php echo $wpsd_key === $wpsd_selected ? ' class="wpsd-row--active"' : ''; ?>>
                <td><strong><?php echo esc_html($wpsd_label); ?></strong></td>
                <td>
                    <a class="button button-small" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-reports', ['report' => $wpsd_key])); ?>">
                        <?php esc_html_e('Preview', 'wp-seo-doctor'); ?>
                    </a>
                </td>
                <td>
                    <a class="button button-small" href="<?php echo esc_url(WPSD_Export::url($wpsd_key, 'csv')); ?>"><?php esc_html_e('CSV', 'wp-seo-doctor'); ?></a>
                    <a class="button button-small" href="<?php echo esc_url(WPSD_Export::url($wpsd_key, 'pdf')); ?>"><?php esc_html_e('PDF', 'wp-seo-doctor'); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="wpsd-card">
    <h2><?php echo esc_html((string) $wpsd_report['title']); ?></h2>
    <p class="wpsd-muted">
        <?php
        printf(
            /* translators: 1: site name, 2: generation timestamp */
            esc_html__('%1$s — generated %2$s', 'wp-seo-doctor'),
            esc_html((string) $wpsd_report['site']),
            esc_html((string) $wpsd_report['generated'])
        );
        ?>
    </p>

    <?php if (!empty($wpsd_report['meta'])) : ?>
        <div class="wpsd-stats">
            <?php foreach ((array) $wpsd_report['meta'] as $wpsd_label => $wpsd_value) : ?>
                <div class="wpsd-stat">
                    <span class="wpsd-stat__value"><?php echo esc_html((string) $wpsd_value); ?></span>
                    <span class="wpsd-stat__label"><?php echo esc_html((string) $wpsd_label); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($wpsd_report['rows'])) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('This report has no rows.', 'wp-seo-doctor'),
            __('Run a scan, or check links, to populate it.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(WPSD_Export::url($wpsd_selected, 'csv', 5000)); ?>">
                <?php esc_html_e('Download full CSV', 'wp-seo-doctor'); ?>
            </a>
            <a class="button" href="<?php echo esc_url(WPSD_Export::url($wpsd_selected, 'pdf', 1000)); ?>">
                <?php esc_html_e('Download PDF', 'wp-seo-doctor'); ?>
            </a>
            <span class="wpsd-muted">
                <?php
                printf(
                    /* translators: %d: number of rows shown in the preview */
                    esc_html__('Showing the first %d rows.', 'wp-seo-doctor'),
                    count((array) $wpsd_report['rows'])
                );
                ?>
            </span>
        </p>

        <div class="wpsd-scroll">
            <table class="wpsd-table wpsd-table--compact">
                <thead>
                <tr>
                    <?php foreach ((array) $wpsd_report['columns'] as $wpsd_column) : ?>
                        <th><?php echo esc_html((string) $wpsd_column); ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ((array) $wpsd_report['rows'] as $wpsd_row) : ?>
                    <tr>
                        <?php foreach ((array) $wpsd_row as $wpsd_cell) : ?>
                            <td><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_cell, 90)); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="wpsd-card">
    <h2><?php esc_html_e('Emailed summaries', 'wp-seo-doctor'); ?></h2>
    <?php if (WPSD_Settings::get('email_reports', false)) : ?>
        <p>
            <?php
            $wpsd_recipient = (string) WPSD_Settings::get('report_email', '');
            printf(
                /* translators: 1: schedule, 2: email address */
                esc_html__('A health summary is emailed %1$s to %2$s.', 'wp-seo-doctor'),
                esc_html((string) WPSD_Settings::get('email_schedule', 'weekly')),
                esc_html($wpsd_recipient !== '' ? $wpsd_recipient : (string) get_option('admin_email'))
            );
            ?>
        </p>
    <?php else : ?>
        <p class="wpsd-muted"><?php esc_html_e('Email summaries are turned off.', 'wp-seo-doctor'); ?></p>
    <?php endif; ?>
    <p>
        <a class="button" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-settings', ['tab' => 'reports'])); ?>">
            <?php esc_html_e('Email settings', 'wp-seo-doctor'); ?>
        </a>
    </p>
</div>
