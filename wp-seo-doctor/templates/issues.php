<?php
/**
 * Issue browser with filters and bulk actions.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_severity = WPSD_Admin_Menu::query_arg('severity');
$wpsd_group    = WPSD_Admin_Menu::query_arg('check_group');
$wpsd_check    = WPSD_Admin_Menu::query_arg('check_id');
$wpsd_status   = WPSD_Admin_Menu::query_arg('status', 'open');
$wpsd_search   = WPSD_Admin_Menu::query_arg('s');
$wpsd_scan_id  = (int) WPSD_Admin_Menu::query_arg('scan_id', '0');
$wpsd_object   = (int) WPSD_Admin_Menu::query_arg('object_id', '0');
$wpsd_paged    = WPSD_Admin_Menu::current_page();

$wpsd_result = WPSD_Issues::query([
    'severity'    => $wpsd_severity,
    'check_group' => $wpsd_group,
    'check_id'    => $wpsd_check,
    'status'      => $wpsd_status,
    'search'      => $wpsd_search,
    'scan_id'     => $wpsd_scan_id,
    'object_id'   => $wpsd_object,
    'page'        => $wpsd_paged,
    'per_page'    => 30,
]);

$wpsd_counts = WPSD_Issues::severity_counts();
?>

<div class="wpsd-card">
    <form method="get" class="wpsd-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(WPSD_SLUG . '-issues'); ?>">
        <?php if ($wpsd_scan_id > 0) : ?>
            <input type="hidden" name="scan_id" value="<?php echo esc_attr((string) $wpsd_scan_id); ?>">
        <?php endif; ?>
        <?php if ($wpsd_object > 0) : ?>
            <input type="hidden" name="object_id" value="<?php echo esc_attr((string) $wpsd_object); ?>">
        <?php endif; ?>

        <select name="severity">
            <option value=""><?php esc_html_e('All priorities', 'wp-seo-doctor'); ?></option>
            <?php foreach (WPSD_Helpers::SEVERITIES as $wpsd_level) : ?>
                <option value="<?php echo esc_attr($wpsd_level); ?>" <?php selected($wpsd_severity, $wpsd_level); ?>>
                    <?php echo esc_html(WPSD_Helpers::severity_label($wpsd_level)); ?>
                    (<?php echo esc_html((string) $wpsd_counts[$wpsd_level]); ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <select name="check_group">
            <option value=""><?php esc_html_e('All areas', 'wp-seo-doctor'); ?></option>
            <?php foreach (WPSD_Checks::groups() as $wpsd_key => $wpsd_label) : ?>
                <option value="<?php echo esc_attr($wpsd_key); ?>" <?php selected($wpsd_group, $wpsd_key); ?>>
                    <?php echo esc_html($wpsd_label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="check_id">
            <option value=""><?php esc_html_e('All checks', 'wp-seo-doctor'); ?></option>
            <?php foreach (WPSD_Checks::all() as $wpsd_id => $wpsd_definition) : ?>
                <option value="<?php echo esc_attr($wpsd_id); ?>" <?php selected($wpsd_check, $wpsd_id); ?>>
                    <?php echo esc_html((string) $wpsd_definition['title']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="open" <?php selected($wpsd_status, 'open'); ?>><?php esc_html_e('Open', 'wp-seo-doctor'); ?></option>
            <option value="ignored" <?php selected($wpsd_status, 'ignored'); ?>><?php esc_html_e('Ignored', 'wp-seo-doctor'); ?></option>
            <option value="fixed" <?php selected($wpsd_status, 'fixed'); ?>><?php esc_html_e('Marked fixed', 'wp-seo-doctor'); ?></option>
            <option value="all" <?php selected($wpsd_status, 'all'); ?>><?php esc_html_e('All', 'wp-seo-doctor'); ?></option>
        </select>

        <input type="search" name="s" value="<?php echo esc_attr($wpsd_search); ?>"
               placeholder="<?php esc_attr_e('Search issues or URLs', 'wp-seo-doctor'); ?>">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'wp-seo-doctor'); ?></button>
        <a class="button" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues')); ?>"><?php esc_html_e('Reset', 'wp-seo-doctor'); ?></a>
        <a class="button" href="<?php echo esc_url(WPSD_Export::url('audit', 'csv')); ?>"><?php esc_html_e('Export CSV', 'wp-seo-doctor'); ?></a>
    </form>
</div>

<div class="wpsd-card">
    <p class="wpsd-muted">
        <?php
        printf(
            /* translators: %d: total matching issues */
            esc_html__('%d matching issues.', 'wp-seo-doctor'),
            (int) $wpsd_result['total']
        );
        ?>
    </p>

    <?php if (!$wpsd_result['rows']) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('No issues match these filters.', 'wp-seo-doctor'),
            __('Try widening the filters, or run a new scan.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <div class="wpsd-bulkbar">
            <label>
                <input type="checkbox" id="wpsd-select-all-issues">
                <?php esc_html_e('Select all', 'wp-seo-doctor'); ?>
            </label>
            <button type="button" class="button wpsd-issue-bulk" data-status="ignored"><?php esc_html_e('Ignore', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-issue-bulk" data-status="fixed"><?php esc_html_e('Mark fixed', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-issue-bulk" data-status="open"><?php esc_html_e('Reopen', 'wp-seo-doctor'); ?></button>
            <span class="wpsd-inline-result" id="wpsd-issue-bulk-result"></span>
        </div>

        <table class="wpsd-table wpsd-table--issues">
            <thead>
            <tr>
                <th class="wpsd-check-col"></th>
                <th><?php esc_html_e('Priority', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Issue', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_result['rows'] as $wpsd_issue) : ?>
                <?php
                $wpsd_data  = WPSD_Issues::data($wpsd_issue);
                $wpsd_edit  = WPSD_Issues::edit_link($wpsd_issue);
                $wpsd_title = (int) $wpsd_issue->object_id > 0
                    ? get_the_title((int) $wpsd_issue->object_id)
                    : __('Site-wide', 'wp-seo-doctor');
                ?>
                <tr data-issue-id="<?php echo esc_attr((string) $wpsd_issue->id); ?>">
                    <td><input type="checkbox" class="wpsd-issue-check" value="<?php echo esc_attr((string) $wpsd_issue->id); ?>"></td>
                    <td><?php echo wp_kses_post(WPSD_Admin_Menu::severity_badge((string) $wpsd_issue->severity)); ?></td>
                    <td>
                        <strong><?php echo esc_html((string) $wpsd_issue->title); ?></strong>
                        <div class="wpsd-issue__message"><?php echo esc_html((string) $wpsd_issue->message); ?></div>
                        <?php if ((string) $wpsd_issue->recommendation !== '') : ?>
                            <div class="wpsd-issue__fix">
                                <span class="wpsd-issue__fix-label"><?php esc_html_e('Fix:', 'wp-seo-doctor'); ?></span>
                                <?php echo esc_html((string) $wpsd_issue->recommendation); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($wpsd_data['duplicates'])) : ?>
                            <ul class="wpsd-issue__list">
                                <?php foreach (array_slice((array) $wpsd_data['duplicates'], 0, 5) as $wpsd_duplicate) : ?>
                                    <li>
                                        <a href="<?php echo esc_url((string) ($wpsd_duplicate['url'] ?? '')); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html((string) ($wpsd_duplicate['title'] ?? '')); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($wpsd_data['chain'])) : ?>
                            <div class="wpsd-issue__chain">
                                <?php
                                $wpsd_hops = [];
                                foreach ((array) $wpsd_data['chain'] as $wpsd_hop) {
                                    $wpsd_hops[] = sprintf('%s (%d)', WPSD_Helpers::truncate((string) ($wpsd_hop['url'] ?? ''), 50), (int) ($wpsd_hop['status'] ?? 0));
                                }
                                echo esc_html(implode(' → ', $wpsd_hops));
                                ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($wpsd_edit !== '') : ?>
                            <a href="<?php echo esc_url($wpsd_edit); ?>"><?php echo esc_html(WPSD_Helpers::truncate($wpsd_title, 45)); ?></a>
                        <?php else : ?>
                            <?php echo esc_html(WPSD_Helpers::truncate($wpsd_title, 45)); ?>
                        <?php endif; ?>
                        <div class="wpsd-muted wpsd-url"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_issue->url, 60)); ?></div>
                    </td>
                    <td class="wpsd-rowactions">
                        <?php if ((string) $wpsd_issue->url !== '') : ?>
                            <a class="button button-small" href="<?php echo esc_url((string) $wpsd_issue->url); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('View', 'wp-seo-doctor'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ((int) $wpsd_issue->object_id > 0) : ?>
                            <button type="button" class="button button-small wpsd-rescan-post" data-post-id="<?php echo esc_attr((string) $wpsd_issue->object_id); ?>">
                                <?php esc_html_e('Re-check', 'wp-seo-doctor'); ?>
                            </button>
                        <?php endif; ?>
                        <?php if (WPSD_AI::is_enabled()) : ?>
                            <button type="button" class="button button-small wpsd-explain-issue" data-issue-id="<?php echo esc_attr((string) $wpsd_issue->id); ?>">
                                <?php esc_html_e('Explain', 'wp-seo-doctor'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        WPSD_Admin_Menu::pagination((int) $wpsd_paged, (int) $wpsd_result['pages'], [
            'severity'    => $wpsd_severity,
            'check_group' => $wpsd_group,
            'check_id'    => $wpsd_check,
            'status'      => $wpsd_status,
            's'           => $wpsd_search,
        ]);
        ?>
    <?php endif; ?>
</div>
