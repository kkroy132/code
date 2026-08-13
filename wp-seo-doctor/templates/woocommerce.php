<?php
/**
 * WooCommerce product SEO audit.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_filter = WPSD_Admin_Menu::query_arg('filter');
$wpsd_search = WPSD_Admin_Menu::query_arg('s');
$wpsd_paged  = WPSD_Admin_Menu::current_page();

$wpsd_stats  = WPSD_WooCommerce_SEO::stats();
$wpsd_result = WPSD_WooCommerce_SEO::audit([
    'filter'   => $wpsd_filter,
    'search'   => $wpsd_search,
    'page'     => $wpsd_paged,
    'per_page' => 30,
]);
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Published products', 'wp-seo-doctor'), 'value' => $wpsd_stats['total_products'] ?? 0],
        ['label' => __('Thin products', 'wp-seo-doctor'), 'value' => $wpsd_stats['thin_product'] ?? 0, 'tone' => 'high'],
        ['label' => __('Missing meta', 'wp-seo-doctor'), 'value' => $wpsd_stats['product_meta'] ?? 0, 'tone' => 'high'],
        ['label' => __('Missing image ALT', 'wp-seo-doctor'), 'value' => $wpsd_stats['product_image_alt'] ?? 0, 'tone' => 'medium'],
        ['label' => __('Weak linking', 'wp-seo-doctor'), 'value' => $wpsd_stats['product_internal_links'] ?? 0, 'tone' => 'medium'],
        ['label' => __('Schema problems', 'wp-seo-doctor'), 'value' => $wpsd_stats['product_schema'] ?? 0, 'tone' => 'high'],
        ['label' => __('Canonical problems', 'wp-seo-doctor'), 'value' => $wpsd_stats['product_canonical'] ?? 0, 'tone' => 'high'],
    ]);
    ?>

    <div class="wpsd-actions">
        <button type="button" class="button button-primary wpsd-scan-trigger" data-type="woo">
            <?php esc_html_e('Audit all products', 'wp-seo-doctor'); ?>
        </button>
        <a class="button" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues', ['check_group' => 'woo'])); ?>">
            <?php esc_html_e('View product issues', 'wp-seo-doctor'); ?>
        </a>
    </div>
</div>

<div class="wpsd-card">
    <form method="get" class="wpsd-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(WPSD_SLUG . '-woocommerce'); ?>">

        <select name="filter">
            <option value=""><?php esc_html_e('All products', 'wp-seo-doctor'); ?></option>
            <option value="thin" <?php selected($wpsd_filter, 'thin'); ?>><?php esc_html_e('Thin description', 'wp-seo-doctor'); ?></option>
            <option value="no_meta" <?php selected($wpsd_filter, 'no_meta'); ?>><?php esc_html_e('No meta description', 'wp-seo-doctor'); ?></option>
            <option value="no_alt" <?php selected($wpsd_filter, 'no_alt'); ?>><?php esc_html_e('Images without ALT', 'wp-seo-doctor'); ?></option>
            <option value="orphan" <?php selected($wpsd_filter, 'orphan'); ?>><?php esc_html_e('No incoming links', 'wp-seo-doctor'); ?></option>
        </select>

        <input type="search" name="s" value="<?php echo esc_attr($wpsd_search); ?>"
               placeholder="<?php esc_attr_e('Search products', 'wp-seo-doctor'); ?>">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'wp-seo-doctor'); ?></button>
    </form>

    <?php if (!$wpsd_result['rows']) : ?>
        <?php WPSD_Admin_Menu::empty_state(__('No products match these filters.', 'wp-seo-doctor')); ?>
    <?php else : ?>
        <table class="wpsd-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Product', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Words', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Meta', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Short desc.', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Images', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Incoming links', 'wp-seo-doctor'); ?></th>
                <th><?php esc_html_e('Open issues', 'wp-seo-doctor'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($wpsd_result['rows'] as $wpsd_row) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url((string) $wpsd_row['edit_url']); ?>">
                            <?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row['title'], 50)); ?>
                        </a>
                    </td>
                    <td class="wpsd-num">
                        <?php if ($wpsd_row['thin']) : ?>
                            <span class="wpsd-badge wpsd-badge--high"><?php echo esc_html((string) $wpsd_row['words']); ?></span>
                        <?php else : ?>
                            <?php echo esc_html((string) $wpsd_row['words']); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($wpsd_row['has_meta']) : ?>
                            <span class="wpsd-badge wpsd-badge--pass"><?php esc_html_e('Yes', 'wp-seo-doctor'); ?></span>
                        <?php else : ?>
                            <span class="wpsd-badge wpsd-badge--high"><?php esc_html_e('Missing', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($wpsd_row['has_short']) : ?>
                            <span class="wpsd-badge wpsd-badge--pass"><?php esc_html_e('Yes', 'wp-seo-doctor'); ?></span>
                        <?php else : ?>
                            <span class="wpsd-badge wpsd-badge--medium"><?php esc_html_e('Missing', 'wp-seo-doctor'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="wpsd-num">
                        <?php echo esc_html((string) $wpsd_row['images']); ?>
                        <?php if ((int) $wpsd_row['images_no_alt'] > 0) : ?>
                            <span class="wpsd-badge wpsd-badge--medium">
                                <?php
                                printf(
                                    /* translators: %d: images without ALT text */
                                    esc_html__('%d no ALT', 'wp-seo-doctor'),
                                    (int) $wpsd_row['images_no_alt']
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="wpsd-num">
                        <?php if ((int) $wpsd_row['incoming'] === 0) : ?>
                            <span class="wpsd-badge wpsd-badge--high">0</span>
                        <?php else : ?>
                            <?php echo esc_html((string) $wpsd_row['incoming']); ?>
                        <?php endif; ?>
                    </td>
                    <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row['issues']); ?></td>
                    <td class="wpsd-rowactions">
                        <button type="button" class="button button-small wpsd-rescan-post" data-post-id="<?php echo esc_attr((string) $wpsd_row['id']); ?>">
                            <?php esc_html_e('Re-check', 'wp-seo-doctor'); ?>
                        </button>
                        <a class="button button-small" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-issues', ['object_id' => (int) $wpsd_row['id']])); ?>">
                            <?php esc_html_e('Issues', 'wp-seo-doctor'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        WPSD_Admin_Menu::pagination((int) $wpsd_paged, (int) $wpsd_result['pages'], [
            'filter' => $wpsd_filter,
            's'      => $wpsd_search,
        ]);
        ?>
    <?php endif; ?>
</div>
