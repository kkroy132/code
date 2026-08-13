<?php
/**
 * 404 monitor.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_status = WPSD_Admin_Menu::query_arg('status', 'new');
$wpsd_search = WPSD_Admin_Menu::query_arg('s');
$wpsd_paged  = WPSD_Admin_Menu::current_page();

$wpsd_stats  = WPSD_Monitor_404::stats();
$wpsd_result = WPSD_Monitor_404::query([
    'status'   => $wpsd_status,
    'search'   => $wpsd_search,
    'page'     => $wpsd_paged,
    'per_page' => 30,
]);
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Distinct URLs', 'wp-seo-doctor'), 'value' => $wpsd_stats['total']],
        ['label' => __('Total hits', 'wp-seo-doctor'), 'value' => $wpsd_stats['hits']],
        ['label' => __('Seen this week', 'wp-seo-doctor'), 'value' => $wpsd_stats['this_week'], 'tone' => $wpsd_stats['this_week'] > 0 ? 'high' : 'pass'],
        ['label' => __('Unresolved', 'wp-seo-doctor'), 'value' => $wpsd_stats['unresolved'], 'tone' => $wpsd_stats['unresolved'] > 0 ? 'medium' : 'pass'],
        ['label' => __('Redirected', 'wp-seo-doctor'), 'value' => $wpsd_stats['redirected'], 'tone' => 'pass'],
    ]);
    ?>

    <?php if (!WPSD_Settings::get('monitor_404', true)) : ?>
        <div class="wpsd-notice wpsd-notice--warning">
            <?php esc_html_e('404 monitoring is turned off. Enable it in Settings to start logging.', 'wp-seo-doctor'); ?>
        </div>
    <?php endif; ?>

    <div class="wpsd-actions">
        <a class="button" href="<?php echo esc_url(WPSD_Export::url('notfound', 'csv')); ?>"><?php esc_html_e('Export CSV', 'wp-seo-doctor'); ?></a>
        <a class="button" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-redirects')); ?>"><?php esc_html_e('Manage redirects', 'wp-seo-doctor'); ?></a>
    </div>
</div>

<div class="wpsd-card">
    <form method="get" class="wpsd-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(WPSD_SLUG . '-404-monitor'); ?>">

        <select name="status">
            <option value="new" <?php selected($wpsd_status, 'new'); ?>><?php esc_html_e('Unresolved', 'wp-seo-doctor'); ?></option>
            <option value="redirected" <?php selected($wpsd_status, 'redirected'); ?>><?php esc_html_e('Redirected', 'wp-seo-doctor'); ?></option>
            <option value="ignored" <?php selected($wpsd_status, 'ignored'); ?>><?php esc_html_e('Ignored', 'wp-seo-doctor'); ?></option>
            <option value="all" <?php selected($wpsd_status, 'all'); ?>><?php esc_html_e('All', 'wp-seo-doctor'); ?></option>
        </select>

        <input type="search" name="s" value="<?php echo esc_attr($wpsd_search); ?>"
               placeholder="<?php esc_attr_e('Search URL or referrer', 'wp-seo-doctor'); ?>">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'wp-seo-doctor'); ?></button>
    </form>

    <?php if (!$wpsd_result['rows']) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('No 404s logged.', 'wp-seo-doctor'),
            __('URLs are recorded as visitors and crawlers hit them.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <div class="wpsd-bulkbar">
            <label>
                <input type="checkbox" id="wpsd-select-all-404">
                <?php esc_html_e('Select all', 'wp-seo-doctor'); ?>
            </label>
            <button type="button" class="button wpsd-404-bulk" data-action="ignore"><?php esc_html_e('Ignore', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-404-bulk" data-action="delete"><?php esc_html_e('Delete', 'wp-seo-doctor'); ?></button>
            <span class="wpsd-inline-result" id="wpsd-404-bulk-result"></span>
        </div>

        <table class="wpsd-table wpsd-table--404">
            <thead>
            <tr>
                <th class="wpsd-check-col"></th>
                <th><?php esc_html_e('URL', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Hits', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('First seen', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Last seen', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Referrer', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_result['rows'] as $wpsd_row) : ?>
                <tr data-notfound-id="<?php echo esc_attr((string) $wpsd_row->id); ?>">
                    <td><input type="checkbox" class="wpsd-404-check" value="<?php echo esc_attr((string) $wpsd_row->id); ?>"></td>
                    <td>
                        <code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->url, 60)); ?></code>
                        <?php if ((string) $wpsd_row->status === 'redirected') : ?>
                            <span class="wpsd-badge wpsd-badge--pass"><?php esc_html_e('Redirected', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="wpsd-num"><strong><?php echo esc_html((string) $wpsd_row->hits); ?></strong></td>
                    <td class="wpsd-muted"><?php echo esc_html(mysql2date(get_option('date_format'), (string) $wpsd_row->first_seen)); ?></td>
                    <td class="wpsd-muted"><?php echo esc_html(human_time_diff(strtotime((string) $wpsd_row->last_seen)) . ' ' . __('ago', 'wp-seo-doctor')); ?></td>
                    <td class="wpsd-muted">
                        <?php echo $wpsd_row->referrer ? esc_html(WPSD_Helpers::truncate((string) $wpsd_row->referrer, 40)) : '—'; ?>
                    </td>
                    <td class="wpsd-rowactions">
                        <button type="button" class="button button-small wpsd-404-suggest"><?php esc_html_e('Suggest redirect', 'wp-seo-doctor'); ?></button>
                        <div class="wpsd-suggestions"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        WPSD_Admin_Menu::pagination((int) $wpsd_paged, (int) $wpsd_result['pages'], [
            'status' => $wpsd_status,
            's'      => $wpsd_search,
        ]);
        ?>
    <?php endif; ?>
</div>
