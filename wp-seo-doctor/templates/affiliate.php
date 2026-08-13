<?php
/**
 * Affiliate SEO.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_view  = WPSD_Admin_Menu::query_arg('view', 'links');
$wpsd_paged = WPSD_Admin_Menu::current_page();
$wpsd_stats = WPSD_Affiliate::stats();

$wpsd_views = [
    'links'      => __('All affiliate links', 'wp-seo-doctor'),
    'dead'       => __('Dead product URLs', 'wp-seo-doctor'),
    'redirects'  => __('Redirects & chains', 'wp-seo-doctor'),
    'attributes' => __('Missing rel attributes', 'wp-seo-doctor'),
    'outbound'   => __('Outbound domains', 'wp-seo-doctor'),
];
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Affiliate URLs', 'wp-seo-doctor'), 'value' => $wpsd_stats['total_links']],
        ['label' => __('Pages using them', 'wp-seo-doctor'), 'value' => $wpsd_stats['pages_with_links']],
        ['label' => __('Broken', 'wp-seo-doctor'), 'value' => $wpsd_stats['broken'], 'tone' => $wpsd_stats['broken'] > 0 ? 'critical' : 'pass'],
        ['label' => __('Redirecting', 'wp-seo-doctor'), 'value' => $wpsd_stats['redirects'], 'tone' => 'medium'],
        ['label' => __('Redirect chains', 'wp-seo-doctor'), 'value' => $wpsd_stats['chains'], 'tone' => $wpsd_stats['chains'] > 0 ? 'medium' : 'pass'],
        ['label' => __('Missing rel', 'wp-seo-doctor'), 'value' => $wpsd_stats['missing_rel'], 'tone' => $wpsd_stats['missing_rel'] > 0 ? 'high' : 'pass'],
    ]);
    ?>

    <div class="wpsd-actions">
        <button type="button" class="button" id="wpsd-retag-affiliate"><?php esc_html_e('Re-detect affiliate links', 'wp-seo-doctor'); ?></button>
        <button type="button" class="button" id="wpsd-check-links"><?php esc_html_e('Check links', 'wp-seo-doctor'); ?></button>
        <a class="button" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-settings', ['tab' => 'affiliate'])); ?>">
            <?php esc_html_e('Detection settings', 'wp-seo-doctor'); ?>
        </a>
        <span class="wpsd-inline-result" id="wpsd-quickfix-result"></span>
    </div>
</div>

<nav class="wpsd-subnav">
    <?php foreach ($wpsd_views as $wpsd_key => $wpsd_label) : ?>
        <a class="wpsd-subnav__item<?php echo $wpsd_view === $wpsd_key ? ' is-active' : ''; ?>"
           href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-affiliate', ['view' => $wpsd_key])); ?>">
            <?php echo esc_html($wpsd_label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="wpsd-card">
<?php if ($wpsd_view === 'dead') : ?>

    <h2><?php esc_html_e('Dead product URLs', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted"><?php esc_html_e('Affiliate destinations returning an error. Every click on these earns nothing and frustrates readers.', 'wp-seo-doctor'); ?></p>

    <?php $wpsd_rows = WPSD_Affiliate::dead_products(100); ?>
    <?php if (!$wpsd_rows) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No dead affiliate links.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Status', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('URL', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Used on', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_rows as $wpsd_row) : ?>
                <tr data-link-id="<?php echo esc_attr((string) $wpsd_row->id); ?>">
                    <td><span class="wpsd-status wpsd-status--broken"><?php echo esc_html((string) ($wpsd_row->http_status ?: '—')); ?></span></td>
                    <td>
                        <code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->target_url, 55)); ?></code>
                        <div class="wpsd-muted"><?php echo esc_html((string) $wpsd_row->domain); ?></div>
                    </td>
                    <td>
                        <?php foreach ((array) $wpsd_row->sources as $wpsd_source) : ?>
                            <div><a href="<?php echo esc_url((string) $wpsd_source['edit_url']); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_source['title'], 40)); ?></a></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="wpsd-rowactions">
                        <button type="button" class="button button-small wpsd-link-action" data-action="recheck"><?php esc_html_e('Recheck', 'wp-seo-doctor'); ?></button>
                        <button type="button" class="button button-small wpsd-link-action" data-action="replace"><?php esc_html_e('Replace', 'wp-seo-doctor'); ?></button>
                        <button type="button" class="button button-small wpsd-link-action" data-action="remove"><?php esc_html_e('Unlink', 'wp-seo-doctor'); ?></button>
                        <div class="wpsd-inline-result"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'redirects') : ?>

    <?php $wpsd_redirects = WPSD_Affiliate::redirects(100); ?>

    <h2><?php esc_html_e('Affiliate redirect chains', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted"><?php esc_html_e('Multiple hops between the click and the merchant lose conversions and can drop tracking parameters.', 'wp-seo-doctor'); ?></p>

    <?php if (!$wpsd_redirects['chains']) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No multi-hop affiliate redirects.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Hops', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('URL', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Final destination', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Used on', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_redirects['chains'] as $wpsd_row) : ?>
                <tr>
                    <td class="wpsd-num"><span class="wpsd-badge wpsd-badge--medium"><?php echo esc_html((string) $wpsd_row->redirect_hops); ?></span></td>
                    <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->target_url, 45)); ?></code></td>
                    <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->redirect_target, 45)); ?></code></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row->used_on); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php esc_html_e('Single-hop redirects', 'wp-seo-doctor'); ?></h2>
    <?php if (!$wpsd_redirects['single']) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('None.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table wpsd-table--compact">
            <tbody>
            <?php foreach ($wpsd_redirects['single'] as $wpsd_row) : ?>
                <tr>
                    <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->target_url, 50)); ?></code></td>
                    <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->redirect_target, 50)); ?></code></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row->used_on); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'attributes') : ?>

    <h2><?php esc_html_e('Affiliate links missing rel attributes', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted">
        <?php
        printf(
            /* translators: %s: comma-separated rel values */
            esc_html__('Monetised links should carry rel="%s". Google treats undisclosed paid links as a link-scheme violation.', 'wp-seo-doctor'),
            esc_html(implode('" or rel="', (array) WPSD_Settings::get('affiliate_required_rel', ['sponsored', 'nofollow'])))
        );
        ?>
    </p>

    <?php $wpsd_rows = WPSD_Affiliate::missing_attributes(200); ?>
    <?php if (!$wpsd_rows) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('Every affiliate link is correctly marked.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('URL', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Anchor', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Current rel', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('On page', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_rows as $wpsd_row) : ?>
                <tr>
                    <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->target_url, 45)); ?></code></td>
                    <td><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->anchor, 30)); ?></td>
                    <td><?php echo esc_html((string) ($wpsd_row->rel ?: '—')); ?></td>
                    <td>
                        <?php if ($wpsd_row->edit_url) : ?>
                            <a href="<?php echo esc_url((string) $wpsd_row->edit_url); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->source_title, 40)); ?></a>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'outbound') : ?>

    <h2><?php esc_html_e('Outbound link analysis', 'wp-seo-doctor'); ?></h2>
    <?php $wpsd_domains = WPSD_Affiliate::outbound_domains(60); ?>

    <?php if (!$wpsd_domains) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No external links recorded.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Domain', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Links', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Pages', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Affiliate', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Broken', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_domains as $wpsd_domain) : ?>
                <tr>
                    <td><?php echo esc_html((string) $wpsd_domain->domain); ?></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_domain->links); ?></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_domain->pages); ?></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_domain->affiliate); ?></td>
                    <td class="wpsd-num">
                        <?php if ((int) $wpsd_domain->broken > 0) : ?>
                            <span class="wpsd-badge wpsd-badge--critical"><?php echo esc_html((string) $wpsd_domain->broken); ?></span>
                        <?php else : ?>
                            0
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php else : ?>

    <h2><?php esc_html_e('All affiliate links', 'wp-seo-doctor'); ?></h2>
    <?php
    $wpsd_result = WPSD_Affiliate::query([
        'status'   => WPSD_Admin_Menu::query_arg('status'),
        'search'   => WPSD_Admin_Menu::query_arg('s'),
        'page'     => $wpsd_paged,
        'per_page' => 30,
    ]);
    ?>

    <?php if (!$wpsd_result['rows']) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('No affiliate links detected.', 'wp-seo-doctor'),
            __('Add your networks and cloaking prefixes in Settings → Affiliate, then re-detect.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Status', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('URL', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Domain', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Used on', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Checked', 'wp-seo-doctor'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_result['rows'] as $wpsd_row) : ?>
                <tr data-link-id="<?php echo esc_attr((string) $wpsd_row->id); ?>">
                    <td><span class="wpsd-status wpsd-status--<?php echo esc_attr((string) $wpsd_row->status); ?>"><?php echo esc_html((string) ($wpsd_row->http_status ?: $wpsd_row->status)); ?></span></td>
                    <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->target_url, 50)); ?></code></td>
                    <td><?php echo esc_html((string) $wpsd_row->domain); ?></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row->used_on); ?></td>
                    <td class="wpsd-muted">
                        <?php
                        echo $wpsd_row->last_checked
                            ? esc_html(human_time_diff(strtotime((string) $wpsd_row->last_checked)) . ' ' . __('ago', 'wp-seo-doctor'))
                            : esc_html__('never', 'wp-seo-doctor');
                        ?>
                    </td>
                    <td class="wpsd-rowactions">
                        <button type="button" class="button button-small wpsd-link-action" data-action="recheck"><?php esc_html_e('Recheck', 'wp-seo-doctor'); ?></button>
                        <div class="wpsd-inline-result"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php WPSD_Admin_Menu::pagination((int) $wpsd_paged, (int) $wpsd_result['pages'], ['view' => 'links']); ?>
    <?php endif; ?>

<?php endif; ?>
</div>
