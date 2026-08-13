<?php
/**
 * Internal linking: overview, orphans, weak pages, anchors, opportunities, map.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_view  = WPSD_Admin_Menu::query_arg('view', 'overview');
$wpsd_stats = WPSD_Internal_Links::stats();

$wpsd_views = [
    'overview'      => __('Overview', 'wp-seo-doctor'),
    'orphans'       => __('Orphan pages', 'wp-seo-doctor'),
    'weak'          => __('Weakly linked', 'wp-seo-doctor'),
    'opportunities' => __('Link opportunities', 'wp-seo-doctor'),
    'anchors'       => __('Anchor text', 'wp-seo-doctor'),
    'map'           => __('Link map', 'wp-seo-doctor'),
];
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Internal links', 'wp-seo-doctor'), 'value' => $wpsd_stats['internal_links']],
        ['label' => __('External links', 'wp-seo-doctor'), 'value' => $wpsd_stats['external_links']],
        ['label' => __('Pages linking out', 'wp-seo-doctor'), 'value' => $wpsd_stats['linked_pages']],
        ['label' => __('Average links / page', 'wp-seo-doctor'), 'value' => $wpsd_stats['avg_outgoing']],
        ['label' => __('Orphan pages', 'wp-seo-doctor'), 'value' => $wpsd_stats['orphans'], 'tone' => $wpsd_stats['orphans'] > 0 ? 'high' : 'pass'],
        ['label' => __('Weakly linked', 'wp-seo-doctor'), 'value' => $wpsd_stats['weak'], 'tone' => $wpsd_stats['weak'] > 0 ? 'medium' : 'pass'],
    ]);
    ?>

    <div class="wpsd-actions">
        <button type="button" class="button" id="wpsd-rebuild-links"><?php esc_html_e('Rebuild link graph', 'wp-seo-doctor'); ?></button>
        <a class="button" href="<?php echo esc_url(WPSD_Export::url('internal', 'csv')); ?>"><?php esc_html_e('Export CSV', 'wp-seo-doctor'); ?></a>
        <span class="wpsd-inline-result" id="wpsd-quickfix-result"></span>
    </div>
</div>

<nav class="wpsd-subnav">
    <?php foreach ($wpsd_views as $wpsd_key => $wpsd_label) : ?>
        <a class="wpsd-subnav__item<?php echo $wpsd_view === $wpsd_key ? ' is-active' : ''; ?>"
           href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-internal-links', ['view' => $wpsd_key])); ?>">
            <?php echo esc_html($wpsd_label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="wpsd-card">
<?php if ($wpsd_view === 'orphans') : ?>

    <h2><?php esc_html_e('Orphan pages', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted"><?php esc_html_e('Published pages that no other page links to. Search engines find them only through the sitemap, and they receive no internal link equity.', 'wp-seo-doctor'); ?></p>

    <?php $wpsd_orphans = WPSD_Internal_Links::orphan_pages(200); ?>
    <?php if (!$wpsd_orphans) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No orphan pages. Every published page has at least one incoming link.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Type', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Modified', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Suggested link sources', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_orphans as $wpsd_orphan) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url((string) get_edit_post_link((int) $wpsd_orphan->ID, 'raw')); ?>">
                            <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_orphan->post_title, 55)); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html((string) $wpsd_orphan->post_type); ?></td>
                    <td><?php echo esc_html(mysql2date(get_option('date_format'), (string) $wpsd_orphan->post_modified)); ?></td>
                    <td>
                        <button type="button" class="button button-small wpsd-suggest-sources"
                                data-post-id="<?php echo esc_attr((string) $wpsd_orphan->ID); ?>">
                            <?php esc_html_e('Find sources', 'wp-seo-doctor'); ?>
                        </button>
                        <div class="wpsd-suggestions" data-for="<?php echo esc_attr((string) $wpsd_orphan->ID); ?>"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'weak') : ?>

    <h2><?php esc_html_e('Weakly linked pages', 'wp-seo-doctor'); ?></h2>
    <?php
    $wpsd_weak = WPSD_Internal_Links::weakly_linked(200);
    $wpsd_min  = (int) WPSD_Settings::get('min_incoming_links', 2);
    ?>
    <p class="wpsd-muted">
        <?php
        printf(
            /* translators: %d: configured minimum incoming links */
            esc_html__('Pages with at least one but fewer than %d incoming internal links.', 'wp-seo-doctor'),
            $wpsd_min
        );
        ?>
    </p>

    <?php if (!$wpsd_weak) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('Every linked page meets the incoming-link threshold.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Page', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Incoming', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Suggested sources', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_weak as $wpsd_page) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url((string) get_edit_post_link((int) $wpsd_page->ID, 'raw')); ?>">
                            <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_page->post_title, 55)); ?>
                        </a>
                    </td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_page->incoming); ?></td>
                    <td>
                        <button type="button" class="button button-small wpsd-suggest-sources"
                                data-post-id="<?php echo esc_attr((string) $wpsd_page->ID); ?>">
                            <?php esc_html_e('Find sources', 'wp-seo-doctor'); ?>
                        </button>
                        <div class="wpsd-suggestions" data-for="<?php echo esc_attr((string) $wpsd_page->ID); ?>"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'opportunities') : ?>

    <h2><?php esc_html_e('Internal link opportunities', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted"><?php esc_html_e('Each row pairs an under-linked page with the most relevant pages that could link to it. Insert adds the link to the source post automatically.', 'wp-seo-doctor'); ?></p>

    <?php $wpsd_opportunities = WPSD_Internal_Links::opportunities(40); ?>
    <?php if (!$wpsd_opportunities) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('No opportunities found.', 'wp-seo-doctor'),
            __('Rebuild the link graph if you have added content recently.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <?php foreach ($wpsd_opportunities as $wpsd_opportunity) : ?>
            <div class="wpsd-opportunity">
                <div class="wpsd-opportunity__head">
                    <span class="wpsd-badge wpsd-badge--<?php echo esc_attr((string) $wpsd_opportunity['priority']); ?>">
                        <?php echo esc_html($wpsd_opportunity['type'] === 'orphan' ? __('Orphan', 'wp-seo-doctor') : __('Weak', 'wp-seo-doctor')); ?>
                    </span>
                    <strong><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_opportunity['target'], 70)); ?></strong>
                    <span class="wpsd-muted">
                        <?php
                        printf(
                            /* translators: %d: incoming link count */
                            esc_html__('%d incoming links', 'wp-seo-doctor'),
                            (int) $wpsd_opportunity['incoming']
                        );
                        ?>
                    </span>
                </div>
                <table class="wpsd-table wpsd-table--compact">
                    <tbody>
                    <?php foreach ((array) $wpsd_opportunity['suggestions'] as $wpsd_suggestion) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url((string) $wpsd_suggestion['edit_url']); ?>">
                                    <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_suggestion['title'], 55)); ?>
                                </a>
                            </td>
                            <td>
                                <input type="text" class="wpsd-anchor-input regular-text"
                                       value="<?php echo esc_attr((string) $wpsd_suggestion['anchor']); ?>"
                                       aria-label="<?php esc_attr_e('Anchor text', 'wp-seo-doctor'); ?>">
                            </td>
                            <td class="wpsd-num wpsd-muted"><?php echo esc_html((string) $wpsd_suggestion['score']); ?></td>
                            <td>
                                <button type="button" class="button button-small wpsd-insert-link"
                                        data-source="<?php echo esc_attr((string) $wpsd_suggestion['id']); ?>"
                                        data-target="<?php echo esc_attr((string) $wpsd_opportunity['target_id']); ?>">
                                    <?php esc_html_e('Insert link', 'wp-seo-doctor'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'anchors') : ?>

    <h2><?php esc_html_e('Anchor text analysis', 'wp-seo-doctor'); ?></h2>
    <?php $wpsd_anchors = WPSD_Internal_Links::anchor_text_report(150); ?>

    <?php if (!$wpsd_anchors) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No anchor text recorded yet.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Anchor text', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Uses', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Distinct targets', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Assessment', 'wp-seo-doctor'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_anchors as $wpsd_anchor) : ?>
                <?php
                $wpsd_note = '';
                $wpsd_tone = '';
                if ((int) $wpsd_anchor->targets > 1) {
                    $wpsd_note = __('Same anchor points at different pages — confusing for crawlers.', 'wp-seo-doctor');
                    $wpsd_tone = 'medium';
                } elseif (mb_strlen((string) $wpsd_anchor->anchor) < 4) {
                    $wpsd_note = __('Very short anchor.', 'wp-seo-doctor');
                    $wpsd_tone = 'low';
                }
                ?>
                <tr>
                    <td><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_anchor->anchor, 60)); ?></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_anchor->uses); ?></td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_anchor->targets); ?></td>
                    <td>
                        <?php if ($wpsd_note !== '') : ?>
                            <span class="wpsd-badge wpsd-badge--<?php echo esc_attr($wpsd_tone); ?>"><?php echo esc_html($wpsd_note); ?></span>
                        <?php else : ?>
                            <span class="wpsd-muted"><?php esc_html_e('OK', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($wpsd_view === 'map') : ?>

    <h2><?php esc_html_e('Internal link map', 'wp-seo-doctor'); ?></h2>
    <p class="wpsd-muted"><?php esc_html_e('The most connected pages on the site. Node size reflects incoming links; colour reflects click depth from the homepage.', 'wp-seo-doctor'); ?></p>

    <div id="wpsd-linkmap" class="wpsd-linkmap" data-loading="<?php esc_attr_e('Building map…', 'wp-seo-doctor'); ?>"></div>

<?php else : ?>

    <h2><?php esc_html_e('Crawl depth distribution', 'wp-seo-doctor'); ?></h2>
    <?php
    $wpsd_depths = WPSD_Internal_Links::crawl_depths();
    $wpsd_buckets = [];
    foreach ($wpsd_depths as $wpsd_depth) {
        $wpsd_key                = min(6, (int) $wpsd_depth);
        $wpsd_buckets[$wpsd_key] = ($wpsd_buckets[$wpsd_key] ?? 0) + 1;
    }
    ksort($wpsd_buckets);
    ?>

    <?php if (!$wpsd_buckets) : ?>
        <?php WPSD_Admin_Menu::empty_state(
            __('The link graph is empty.', 'wp-seo-doctor'),
            __('Rebuild the link graph to populate this view.', 'wp-seo-doctor')
        ); ?>
    <?php else : ?>
        <?php $wpsd_max_bucket = max($wpsd_buckets); ?>
        <table class="wpsd-table wpsd-table--compact">
            <tbody>
            <?php foreach ($wpsd_buckets as $wpsd_depth => $wpsd_count) : ?>
                <tr>
                    <td style="width:18%">
                        <?php
                        if ($wpsd_depth === 0) {
                            esc_html_e('Homepage', 'wp-seo-doctor');
                        } elseif ($wpsd_depth >= 6) {
                            esc_html_e('6+ clicks', 'wp-seo-doctor');
                        } else {
                            printf(
                                /* translators: %d: number of clicks from the homepage */
                                esc_html__('%d clicks deep', 'wp-seo-doctor'),
                                (int) $wpsd_depth
                            );
                        }
                        ?>
                    </td>
                    <td class="wpsd-meter-cell">
                        <span class="wpsd-meter">
                            <span class="wpsd-meter__fill"
                                  style="width:<?php echo esc_attr((string) round($wpsd_count / $wpsd_max_bucket * 100)); ?>%"></span>
                        </span>
                    </td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_count); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="wpsd-muted">
            <?php
            printf(
                /* translators: %d: configured maximum crawl depth */
                esc_html__('Pages deeper than %d clicks are flagged by the Crawl Depth check.', 'wp-seo-doctor'),
                (int) WPSD_Settings::get('max_crawl_depth', 4)
            );
            ?>
        </p>
    <?php endif; ?>

<?php endif; ?>
</div>
