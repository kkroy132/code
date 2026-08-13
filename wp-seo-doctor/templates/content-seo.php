<?php
/**
 * Content SEO: thin, duplicate, decaying and outdated content plus opportunities.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_view  = WPSD_Admin_Menu::query_arg('view', 'opportunities');
$wpsd_stats = WPSD_Content_SEO::stats();

$wpsd_views = [
    'opportunities' => __('Opportunities', 'wp-seo-doctor'),
    'thin'          => __('Thin content', 'wp-seo-doctor'),
    'duplicate'     => __('Duplicate signals', 'wp-seo-doctor'),
    'decay'         => __('Content decay', 'wp-seo-doctor'),
    'outdated'      => __('Outdated content', 'wp-seo-doctor'),
];
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Thin pages', 'wp-seo-doctor'), 'value' => $wpsd_stats['thin'], 'tone' => $wpsd_stats['thin'] > 0 ? 'high' : 'pass'],
        ['label' => __('Duplicate clusters', 'wp-seo-doctor'), 'value' => $wpsd_stats['duplicates'], 'tone' => $wpsd_stats['duplicates'] > 0 ? 'high' : 'pass'],
        ['label' => __('Outdated pages', 'wp-seo-doctor'), 'value' => $wpsd_stats['outdated'], 'tone' => $wpsd_stats['outdated'] > 0 ? 'medium' : 'pass'],
        ['label' => __('Decaying pages', 'wp-seo-doctor'), 'value' => $wpsd_stats['decaying'], 'tone' => $wpsd_stats['decaying'] > 0 ? 'high' : 'pass'],
    ]);
    ?>
    <p>
        <a class="button" href="<?php echo esc_url(WPSD_Export::url('content', 'csv')); ?>"><?php esc_html_e('Export CSV', 'wp-seo-doctor'); ?></a>
    </p>
</div>

<nav class="wpsd-subnav">
    <?php foreach ($wpsd_views as $wpsd_key => $wpsd_label) : ?>
        <a class="wpsd-subnav__item<?php echo $wpsd_view === $wpsd_key ? ' is-active' : ''; ?>"
           href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-content', ['view' => $wpsd_key])); ?>">
            <?php echo esc_html($wpsd_label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="wpsd-card">
<?php if ($wpsd_view === 'thin') : ?>

    <h2><?php esc_html_e('Thin content', 'wp-seo-doctor'); ?></h2>
    <?php $wpsd_rows = WPSD_Content_SEO::thin_pages(200); ?>

    <?php if (!$wpsd_rows) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No thin pages found.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Type', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Words', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Modified', 'wp-seo-doctor'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_rows as $wpsd_row) : ?>
                <tr>
                    <td><a href="<?php echo esc_url((string) $wpsd_row['edit_url']); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['title'], 55)); ?></a></td>
                    <td><?php echo esc_html((string) $wpsd_row['type']); ?></td>
                    <td class="wpsd-num"><strong><?php echo esc_html((string) $wpsd_row['words']); ?></strong></td>
                    <td class="wpsd-muted"><?php echo esc_html(mysql2date(get_option('date_format'), (string) $wpsd_row['modified'])); ?></td>
                    <td>
                        <?php if (WPSD_AI::is_enabled()) : ?>
                            <button type="button" class="button button-small wpsd-ai-task" data-task="content" data-post-id="<?php echo esc_attr((string) $wpsd_row['id']); ?>">
                                <?php esc_html_e('AI suggestions', 'wp-seo-doctor'); ?>
                            </button>
                        <?php endif; ?>
                        <div class="wpsd-ai-output"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'duplicate') : ?>

    <h2><?php esc_html_e('Duplicate content signals', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted">
        <?php
        printf(
            /* translators: %s: similarity threshold as a percentage */
            esc_html__('Pages sharing more than %s%% of their wording. Adjust the threshold in Settings → Content.', 'wp-seo-doctor'),
            esc_html((string) round((float) WPSD_Settings::get('duplicate_threshold', 0.75) * 100))
        );
        ?>
    </p>

    <?php $wpsd_clusters = WPSD_Content_SEO::duplicate_clusters(50); ?>
    <?php if (!$wpsd_clusters) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No duplicate clusters found.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <?php foreach ($wpsd_clusters as $wpsd_cluster) : ?>
            <div class="wpsd-opportunity">
                <div class="wpsd-opportunity__head">
                    <span class="wpsd-badge wpsd-badge--high">
                        <?php echo esc_html((string) $wpsd_cluster['similarity']); ?>%
                    </span>
                    <strong><?php esc_html_e('Overlapping pages', 'wp-seo-doctor'); ?></strong>
                </div>
                <table class="wpsd-table wpsd-table--compact">
                    <tbody>
                    <?php foreach ((array) $wpsd_cluster['pages'] as $wpsd_page) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) $wpsd_page['edit_url']); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_page['title'], 55)); ?></a></td>
                            <td class="wpsd-num"><?php echo esc_html((string) $wpsd_page['words']); ?> <?php esc_html_e('words', 'wp-seo-doctor'); ?></td>
                            <td class="wpsd-num"><?php echo esc_html((string) $wpsd_page['similarity']); ?>%</td>
                            <td><a class="button button-small" href="<?php echo esc_url((string) $wpsd_page['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'wp-seo-doctor'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'decay') : ?>

    <h2><?php esc_html_e('Content decay', 'wp-seo-doctor'); ?></h2>

    <?php if (!WPSD_GSC::has_data()) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('Search Console data is required to detect decay.', 'wp-seo-doctor'),
            __('Connect Search Console to compare recent traffic against the previous period.', 'wp-seo-doctor')
        ); ?>
        <p><a class="button button-primary" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-search-console')); ?>"><?php esc_html_e('Connect Search Console', 'wp-seo-doctor'); ?></a></p>
    <?php else : ?>
        <?php $wpsd_rows = WPSD_Content_SEO::decaying_pages(100); ?>
        <?php if (!$wpsd_rows) : ?>
            <?php WPSD_Admin_Menu::empty_state(__('No pages are losing meaningful traffic.', 'wp-seo-doctor')); ?>
        <?php else : ?>
            <table class="wpsd-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Previous clicks', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Recent clicks', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Change', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Last updated', 'wp-seo-doctor'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wpsd_rows as $wpsd_row) : ?>
                    <tr>
                        <td>
                            <?php if ($wpsd_row['edit_url']) : ?>
                                <a href="<?php echo esc_url((string) $wpsd_row['edit_url']); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['title'], 55)); ?></a>
                            <?php else : ?>
                                <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['url'], 55)); ?>
                            <?php endif; ?>
                        </td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['previous_clicks']); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['recent_clicks']); ?></td>
                        <td class="wpsd-num">
                            <span class="wpsd-delta wpsd-delta--down"><?php echo esc_html((string) $wpsd_row['change_percent']); ?>%</span>
                        </td>
                        <td class="wpsd-muted">
                            <?php echo $wpsd_row['modified'] ? esc_html(mysql2date(get_option('date_format'), (string) $wpsd_row['modified'])) : '—'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'outdated') : ?>

    <h2><?php esc_html_e('Outdated content', 'wp-seo-doctor'); ?></h2>
    <?php $wpsd_rows = WPSD_Content_SEO::outdated_pages(200); ?>

    <?php if (!$wpsd_rows) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('Nothing is past the staleness threshold.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Age', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Last modified', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Clicks (28d)', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_rows as $wpsd_row) : ?>
                <tr>
                    <td><a href="<?php echo esc_url((string) $wpsd_row['edit_url']); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['title'], 55)); ?></a></td>
                    <td class="wpsd-num">
                        <?php
                        printf(
                            /* translators: %d: age in days */
                            esc_html__('%d days', 'wp-seo-doctor'),
                            (int) $wpsd_row['age_days']
                        );
                        ?>
                    </td>
                    <td class="wpsd-muted"><?php echo esc_html(mysql2date(get_option('date_format'), (string) $wpsd_row['modified'])); ?></td>
                    <td class="wpsd-num"><?php echo $wpsd_row['clicks'] === null ? '—' : esc_html((string) $wpsd_row['clicks']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php else : ?>

    <h2><?php esc_html_e('SEO content opportunities', 'wp-seo-doctor'); ?></h2>
    <?php $wpsd_rows = WPSD_Content_SEO::opportunities(50); ?>

    <?php if (!$wpsd_rows) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('No opportunities identified.', 'wp-seo-doctor'),
            __('Connect Search Console for query-level opportunities, or run a content scan.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Priority', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Type', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Signal', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Action', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_rows as $wpsd_row) : ?>
                <tr>
                    <td><?php echo wp_kses_post(WPSD_Admin_Menu::severity_badge((string) $wpsd_row['priority'])); ?></td>
                    <td><?php echo esc_html((string) $wpsd_row['label']); ?></td>
                    <td>
                        <?php if ($wpsd_row['edit_url']) : ?>
                            <a href="<?php echo esc_url((string) $wpsd_row['edit_url']); ?>"><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['title'], 45)); ?></a>
                        <?php else : ?>
                            <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['url'], 45)); ?>
                        <?php endif; ?>
                    </td>
                    <td class="wpsd-muted"><?php echo esc_html((string) $wpsd_row['detail']); ?></td>
                    <td><?php echo esc_html((string) $wpsd_row['action']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php endif; ?>
</div>
