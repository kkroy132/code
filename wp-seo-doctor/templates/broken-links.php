<?php
/**
 * Broken link manager.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_status = WPSD_Admin_Menu::query_arg('status', 'broken');
$wpsd_type   = WPSD_Admin_Menu::query_arg('type');
$wpsd_search = WPSD_Admin_Menu::query_arg('s');
$wpsd_paged  = WPSD_Admin_Menu::current_page();

$wpsd_stats  = WPSD_Broken_Links::stats();
$wpsd_result = WPSD_Broken_Links::query([
    'status'   => $wpsd_status,
    'type'     => $wpsd_type,
    'search'   => $wpsd_search,
    'page'     => $wpsd_paged,
    'per_page' => 30,
]);
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Broken', 'wp-seo-doctor'), 'value' => $wpsd_stats['broken'], 'tone' => $wpsd_stats['broken'] > 0 ? 'critical' : 'pass'],
        ['label' => __('Broken internal', 'wp-seo-doctor'), 'value' => $wpsd_stats['broken_internal'], 'tone' => 'high'],
        ['label' => __('Broken external', 'wp-seo-doctor'), 'value' => $wpsd_stats['broken_external'], 'tone' => 'medium'],
        ['label' => __('Redirecting', 'wp-seo-doctor'), 'value' => $wpsd_stats['redirect'], 'tone' => 'medium'],
        ['label' => __('Healthy', 'wp-seo-doctor'), 'value' => $wpsd_stats['ok'], 'tone' => 'pass'],
        ['label' => __('Not yet checked', 'wp-seo-doctor'), 'value' => $wpsd_stats['unchecked']],
    ]);
    ?>

    <div class="wpsd-actions">
        <button type="button" class="button button-primary" id="wpsd-check-links"><?php esc_html_e('Check next batch', 'wp-seo-doctor'); ?></button>
        <button type="button" class="button" id="wpsd-check-links-all"><?php esc_html_e('Check everything now', 'wp-seo-doctor'); ?></button>
        <a class="button" href="<?php echo esc_url(WPSD_Export::url('broken', 'csv')); ?>"><?php esc_html_e('Export CSV', 'wp-seo-doctor'); ?></a>
        <span class="wpsd-inline-result" id="wpsd-quickfix-result"></span>
    </div>
</div>

<div class="wpsd-card">
    <form method="get" class="wpsd-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(WPSD_SLUG . '-broken-links'); ?>">

        <select name="status">
            <option value="broken" <?php selected($wpsd_status, 'broken'); ?>><?php esc_html_e('Broken', 'wp-seo-doctor'); ?></option>
            <option value="redirect" <?php selected($wpsd_status, 'redirect'); ?>><?php esc_html_e('Redirecting', 'wp-seo-doctor'); ?></option>
            <option value="ignored" <?php selected($wpsd_status, 'ignored'); ?>><?php esc_html_e('Ignored', 'wp-seo-doctor'); ?></option>
            <option value="ok" <?php selected($wpsd_status, 'ok'); ?>><?php esc_html_e('Healthy', 'wp-seo-doctor'); ?></option>
            <option value="unchecked" <?php selected($wpsd_status, 'unchecked'); ?>><?php esc_html_e('Unchecked', 'wp-seo-doctor'); ?></option>
            <option value="all" <?php selected($wpsd_status, 'all'); ?>><?php esc_html_e('All', 'wp-seo-doctor'); ?></option>
        </select>

        <select name="type">
            <option value=""><?php esc_html_e('All link types', 'wp-seo-doctor'); ?></option>
            <option value="internal" <?php selected($wpsd_type, 'internal'); ?>><?php esc_html_e('Internal', 'wp-seo-doctor'); ?></option>
            <option value="external" <?php selected($wpsd_type, 'external'); ?>><?php esc_html_e('External', 'wp-seo-doctor'); ?></option>
        </select>

        <input type="search" name="s" value="<?php echo esc_attr($wpsd_search); ?>"
               placeholder="<?php esc_attr_e('Search URL or anchor', 'wp-seo-doctor'); ?>">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'wp-seo-doctor'); ?></button>
    </form>

    <?php if (!$wpsd_result['rows']) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('Nothing here.', 'wp-seo-doctor'),
            $wpsd_stats['unchecked'] > 0
                ? __('Some links have not been checked yet — run a check batch.', 'wp-seo-doctor')
                : __('No links match these filters.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <p class="wpsd-muted">
            <?php
            printf(
                /* translators: %d: number of matching links */
                esc_html__('%d matching links.', 'wp-seo-doctor'),
                (int) $wpsd_result['total']
            );
            ?>
        </p>

        <table class="wpsd-table wpsd-table--links">
            <thead>
            <tr>
                <th><?php esc_html_e('Status', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Target URL', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Anchor', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Found on', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Checked', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_result['rows'] as $wpsd_link) : ?>
                <tr data-link-id="<?php echo esc_attr((string) $wpsd_link->id); ?>">
                    <td>
                        <span class="wpsd-status wpsd-status--<?php echo esc_attr((string) $wpsd_link->status); ?>">
                            <?php echo esc_html((int) $wpsd_link->http_status ?: (string) $wpsd_link->status); ?>
                        </span>
                        <?php if ((int) $wpsd_link->redirect_hops > 1) : ?>
                            <span class="wpsd-badge wpsd-badge--medium">
                                <?php
                                printf(
                                    /* translators: %d: number of redirect hops */
                                    esc_html__('%d hops', 'wp-seo-doctor'),
                                    (int) $wpsd_link->redirect_hops
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url((string) $wpsd_link->target_url); ?>" target="_blank" rel="noopener nofollow">
                            <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_link->target_url, 60)); ?>
                        </a>
                        <?php if ($wpsd_link->redirect_target) : ?>
                            <div class="wpsd-muted">
                                → <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_link->redirect_target, 55)); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ((int) $wpsd_link->is_affiliate === 1) : ?>
                            <span class="wpsd-badge wpsd-badge--info"><?php esc_html_e('Affiliate', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_link->anchor, 35)); ?></td>
                    <td>
                        <?php if ($wpsd_link->edit_url) : ?>
                            <a href="<?php echo esc_url((string) $wpsd_link->edit_url); ?>">
                                <?php echo esc_html(WPSD_Helpers::truncate((string) ($wpsd_link->source_title ?: __('(untitled)', 'wp-seo-doctor')), 40)); ?>
                            </a>
                        <?php else : ?>
                            <span class="wpsd-muted"><?php esc_html_e('—', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="wpsd-muted">
                        <?php
                        echo $wpsd_link->last_checked
                            ? esc_html(human_time_diff(strtotime((string) $wpsd_link->last_checked)) . ' ' . __('ago', 'wp-seo-doctor'))
                            : esc_html__('never', 'wp-seo-doctor');
                        ?>
                    </td>
                    <td class="wpsd-rowactions">
                        <button type="button" class="button button-small wpsd-link-action" data-action="recheck"><?php esc_html_e('Recheck', 'wp-seo-doctor'); ?></button>
                        <button type="button" class="button button-small wpsd-link-action" data-action="replace"><?php esc_html_e('Replace', 'wp-seo-doctor'); ?></button>
                        <button type="button" class="button button-small wpsd-link-action" data-action="remove"><?php esc_html_e('Unlink', 'wp-seo-doctor'); ?></button>
                        <?php if ((string) $wpsd_link->status === 'ignored') : ?>
                            <button type="button" class="button button-small wpsd-link-action" data-action="unignore"><?php esc_html_e('Unignore', 'wp-seo-doctor'); ?></button>
                        <?php else : ?>
                            <button type="button" class="button button-small wpsd-link-action" data-action="ignore"><?php esc_html_e('Ignore', 'wp-seo-doctor'); ?></button>
                        <?php endif; ?>
                        <div class="wpsd-inline-result"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        WPSD_Admin_Menu::pagination((int) $wpsd_paged, (int) $wpsd_result['pages'], [
            'status' => $wpsd_status,
            'type'   => $wpsd_type,
            's'      => $wpsd_search,
        ]);
        ?>
    <?php endif; ?>
</div>
