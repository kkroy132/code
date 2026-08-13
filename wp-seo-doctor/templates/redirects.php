<?php
/**
 * Redirect manager: rules, chain/loop analysis, import/export, history.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_view   = WPSD_Admin_Menu::query_arg('view', 'rules');
$wpsd_search = WPSD_Admin_Menu::query_arg('s');
$wpsd_paged  = WPSD_Admin_Menu::current_page();
$wpsd_edit   = (int) WPSD_Admin_Menu::query_arg('edit', '0');

$wpsd_stats  = WPSD_Redirects::stats();
$wpsd_rule   = $wpsd_edit > 0 ? WPSD_Redirects::get($wpsd_edit) : null;

$wpsd_views = [
    'rules'    => __('Rules', 'wp-seo-doctor'),
    'analysis' => __('Chains & loops', 'wp-seo-doctor'),
    'history'  => __('History', 'wp-seo-doctor'),
    'transfer' => __('Import / export', 'wp-seo-doctor'),
];
?>

<div class="wpsd-card">
    <?php
    WPSD_Admin_Menu::stat_cards([
        ['label' => __('Rules', 'wp-seo-doctor'), 'value' => $wpsd_stats['total']],
        ['label' => __('Enabled', 'wp-seo-doctor'), 'value' => $wpsd_stats['enabled'], 'tone' => 'pass'],
        ['label' => __('Regex', 'wp-seo-doctor'), 'value' => $wpsd_stats['regex']],
        ['label' => __('410 Gone', 'wp-seo-doctor'), 'value' => $wpsd_stats['gone']],
        ['label' => __('Total hits', 'wp-seo-doctor'), 'value' => $wpsd_stats['hits']],
        ['label' => __('Chains', 'wp-seo-doctor'), 'value' => $wpsd_stats['chains'], 'tone' => $wpsd_stats['chains'] > 0 ? 'medium' : 'pass'],
        ['label' => __('Loops', 'wp-seo-doctor'), 'value' => $wpsd_stats['loops'], 'tone' => $wpsd_stats['loops'] > 0 ? 'critical' : 'pass'],
    ]);
    ?>
</div>

<nav class="wpsd-subnav">
    <?php foreach ($wpsd_views as $wpsd_key => $wpsd_label) : ?>
        <a class="wpsd-subnav__item<?php echo $wpsd_view === $wpsd_key ? ' is-active' : ''; ?>"
           href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-redirects', ['view' => $wpsd_key])); ?>">
            <?php echo esc_html($wpsd_label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($wpsd_view === 'rules') : ?>

    <div class="wpsd-card">
        <h2><?php echo $wpsd_rule ? esc_html__('Edit redirect', 'wp-seo-doctor') : esc_html__('Add a redirect', 'wp-seo-doctor'); ?></h2>

        <form method="post" class="wpsd-form">
            <?php wp_nonce_field('wpsd_save_redirect'); ?>
            <input type="hidden" name="wpsd_action" value="save_redirect">
            <input type="hidden" name="redirect_id" value="<?php echo esc_attr((string) ($wpsd_rule->id ?? 0)); ?>">

            <div class="wpsd-form__row">
                <label for="wpsd-source"><?php esc_html_e('Source', 'wp-seo-doctor'); ?></label>
                <input type="text" id="wpsd-source" name="source" class="regular-text"
                       value="<?php echo esc_attr((string) ($wpsd_rule->source ?? '')); ?>"
                       placeholder="/old-page" required>
                <p class="description"><?php esc_html_e('A site-relative path, or a full URL. Trailing slashes are ignored.', 'wp-seo-doctor'); ?></p>
            </div>

            <div class="wpsd-form__row">
                <label for="wpsd-target"><?php esc_html_e('Target', 'wp-seo-doctor'); ?></label>
                <input type="text" id="wpsd-target" name="target" class="regular-text"
                       value="<?php echo esc_attr((string) ($wpsd_rule->target ?? '')); ?>"
                       placeholder="/new-page">
                <p class="description"><?php esc_html_e('Leave empty when using 410 Gone. In regex rules, $1, $2… insert capture groups.', 'wp-seo-doctor'); ?></p>
            </div>

            <div class="wpsd-form__row wpsd-form__row--inline">
                <label for="wpsd-code"><?php esc_html_e('Type', 'wp-seo-doctor'); ?></label>
                <select id="wpsd-code" name="code">
                    <?php
                    $wpsd_labels = [
                        301 => __('301 — Moved Permanently', 'wp-seo-doctor'),
                        302 => __('302 — Found (temporary)', 'wp-seo-doctor'),
                        307 => __('307 — Temporary Redirect', 'wp-seo-doctor'),
                        308 => __('308 — Permanent Redirect', 'wp-seo-doctor'),
                        410 => __('410 — Gone', 'wp-seo-doctor'),
                    ];
                    foreach ($wpsd_labels as $wpsd_code => $wpsd_label) :
                        ?>
                        <option value="<?php echo esc_attr((string) $wpsd_code); ?>" <?php selected((int) ($wpsd_rule->code ?? 301), $wpsd_code); ?>>
                            <?php echo esc_html($wpsd_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="wpsd-match"><?php esc_html_e('Match', 'wp-seo-doctor'); ?></label>
                <select id="wpsd-match" name="match_type">
                    <option value="exact" <?php selected((string) ($wpsd_rule->match_type ?? 'exact'), 'exact'); ?>><?php esc_html_e('Exact', 'wp-seo-doctor'); ?></option>
                    <option value="regex" <?php selected((string) ($wpsd_rule->match_type ?? 'exact'), 'regex'); ?>><?php esc_html_e('Regex', 'wp-seo-doctor'); ?></option>
                </select>

                <label>
                    <input type="checkbox" name="enabled" value="1" <?php checked((int) ($wpsd_rule->enabled ?? 1), 1); ?>>
                    <?php esc_html_e('Enabled', 'wp-seo-doctor'); ?>
                </label>
            </div>

            <div class="wpsd-form__row">
                <label for="wpsd-notes"><?php esc_html_e('Notes', 'wp-seo-doctor'); ?></label>
                <input type="text" id="wpsd-notes" name="notes" class="regular-text"
                       value="<?php echo esc_attr((string) ($wpsd_rule->notes ?? '')); ?>">
            </div>

            <p>
                <button type="submit" class="button button-primary">
                    <?php echo $wpsd_rule ? esc_html__('Update redirect', 'wp-seo-doctor') : esc_html__('Add redirect', 'wp-seo-doctor'); ?>
                </button>
                <button type="button" class="button" id="wpsd-test-redirect"><?php esc_html_e('Test', 'wp-seo-doctor'); ?></button>
                <input type="text" id="wpsd-test-sample" class="regular-text"
                       placeholder="<?php esc_attr_e('Sample URL to test, e.g. /old-page', 'wp-seo-doctor'); ?>">
                <?php if ($wpsd_rule) : ?>
                    <a class="button" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-redirects')); ?>"><?php esc_html_e('Cancel', 'wp-seo-doctor'); ?></a>
                <?php endif; ?>
            </p>
            <div class="wpsd-inline-result" id="wpsd-test-result"></div>
        </form>
    </div>

    <div class="wpsd-card">
        <form method="get" class="wpsd-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(WPSD_SLUG . '-redirects'); ?>">
            <input type="search" name="s" value="<?php echo esc_attr($wpsd_search); ?>"
                   placeholder="<?php esc_attr_e('Search source, target or notes', 'wp-seo-doctor'); ?>">
            <button type="submit" class="button"><?php esc_html_e('Search', 'wp-seo-doctor'); ?></button>
        </form>

        <?php
        $wpsd_result = WPSD_Redirects::query([
            'search'   => $wpsd_search,
            'page'     => $wpsd_paged,
            'per_page' => 30,
        ]);
        ?>

        <?php if (!$wpsd_result['rows']) : ?>
            <?php WPSD_Admin_Menu::empty_state(__('No redirects yet.', 'wp-seo-doctor')); ?>
        <?php else : ?>
            <div class="wpsd-bulkbar">
                <label><input type="checkbox" id="wpsd-select-all-redirects"> <?php esc_html_e('Select all', 'wp-seo-doctor'); ?></label>
                <button type="button" class="button wpsd-redirect-bulk" data-action="enable"><?php esc_html_e('Enable', 'wp-seo-doctor'); ?></button>
                <button type="button" class="button wpsd-redirect-bulk" data-action="disable"><?php esc_html_e('Disable', 'wp-seo-doctor'); ?></button>
                <button type="button" class="button wpsd-redirect-bulk" data-action="delete"><?php esc_html_e('Delete', 'wp-seo-doctor'); ?></button>
                <span class="wpsd-inline-result" id="wpsd-redirect-bulk-result"></span>
            </div>

            <table class="wpsd-table">
                <thead>
                <tr>
                    <th class="wpsd-check-col"></th>
                    <th><?php esc_html_e('Source', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Target', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Code', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Match', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Hits', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Last hit', 'wp-seo-doctor'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wpsd_result['rows'] as $wpsd_row) : ?>
                    <tr class="<?php echo (int) $wpsd_row->enabled ? '' : 'wpsd-row--disabled'; ?>">
                        <td><input type="checkbox" class="wpsd-redirect-check" value="<?php echo esc_attr((string) $wpsd_row->id); ?>"></td>
                        <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_row->source, 45)); ?></code></td>
                        <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) ($wpsd_row->target ?: '—'), 45)); ?></code></td>
                        <td><?php echo esc_html((string) $wpsd_row->code); ?></td>
                        <td><?php echo esc_html((string) $wpsd_row->match_type); ?></td>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_row->hits); ?></td>
                        <td class="wpsd-muted">
                            <?php
                            echo $wpsd_row->last_hit
                                ? esc_html(human_time_diff(strtotime((string) $wpsd_row->last_hit)) . ' ' . __('ago', 'wp-seo-doctor'))
                                : '—';
                            ?>
                        </td>
                        <td>
                            <a class="button button-small"
                               href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-redirects', ['edit' => (int) $wpsd_row->id])); ?>">
                                <?php esc_html_e('Edit', 'wp-seo-doctor'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php WPSD_Admin_Menu::pagination((int) $wpsd_paged, (int) $wpsd_result['pages'], ['s' => $wpsd_search, 'view' => 'rules']); ?>
        <?php endif; ?>
    </div>

<?php elseif ($wpsd_view === 'analysis') : ?>

    <?php $wpsd_analysis = WPSD_Redirects::analyse(); ?>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Redirect loops', 'wp-seo-doctor'); ?></h2>
        <?php if (!$wpsd_analysis['loops']) : ?>
            <?php WPSD_Admin_Menu::empty_state(__('No redirect loops detected.', 'wp-seo-doctor')); ?>
        <?php else : ?>
            <table class="wpsd-table">
                <tbody>
                <?php foreach ($wpsd_analysis['loops'] as $wpsd_loop) : ?>
                    <tr>
                        <td><span class="wpsd-badge wpsd-badge--critical"><?php esc_html_e('Loop', 'wp-seo-doctor'); ?></span></td>
                        <td><code><?php echo esc_html(implode(' → ', array_map(static fn($u) => WPSD_Helpers::truncate((string) $u, 30), (array) $wpsd_loop['chain']))); ?></code></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-redirects', ['edit' => (int) $wpsd_loop['id']])); ?>">
                                <?php esc_html_e('Fix', 'wp-seo-doctor'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Redirect chains', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('Each extra hop loses a little link equity and adds latency. Flattening rewrites every chained rule to point straight at its final destination.', 'wp-seo-doctor'); ?></p>

        <?php if (!$wpsd_analysis['chains']) : ?>
            <?php WPSD_Admin_Menu::empty_state(__('No redirect chains detected.', 'wp-seo-doctor')); ?>
        <?php else : ?>
            <p>
                <button type="button" class="button button-primary" id="wpsd-flatten-redirects">
                    <?php esc_html_e('Flatten all chains', 'wp-seo-doctor'); ?>
                </button>
                <span class="wpsd-inline-result" id="wpsd-quickfix-result"></span>
            </p>
            <table class="wpsd-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('Hops', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Chain', 'wp-seo-doctor'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wpsd_analysis['chains'] as $wpsd_chain) : ?>
                    <tr>
                        <td class="wpsd-num"><?php echo esc_html((string) $wpsd_chain['hops']); ?></td>
                        <td><code><?php echo esc_html(implode(' → ', array_map(static fn($u) => WPSD_Helpers::truncate((string) $u, 30), (array) $wpsd_chain['chain']))); ?></code></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-redirects', ['edit' => (int) $wpsd_chain['id']])); ?>">
                                <?php esc_html_e('Edit', 'wp-seo-doctor'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php elseif ($wpsd_view === 'history') : ?>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Redirect history', 'wp-seo-doctor'); ?></h2>
        <?php $wpsd_history = WPSD_Redirects::history(0, 200); ?>

        <?php if (!$wpsd_history) : ?>
            <?php WPSD_Admin_Menu::empty_state(
                __('No redirects have fired yet.', 'wp-seo-doctor'),
                WPSD_Settings::get('log_redirects', true)
                    ? ''
                    : __('Redirect logging is currently disabled in Settings.', 'wp-seo-doctor')
            ); ?>
        <?php else : ?>
            <table class="wpsd-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('When', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Requested', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Sent to', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Code', 'wp-seo-doctor'); ?></th>
                    <th><?php esc_html_e('Referrer', 'wp-seo-doctor'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wpsd_history as $wpsd_entry) : ?>
                    <tr>
                        <td class="wpsd-muted"><?php echo esc_html(mysql2date(get_option('date_format') . ' H:i', (string) $wpsd_entry->created_at)); ?></td>
                        <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_entry->request_url, 40)); ?></code></td>
                        <td><code><?php echo esc_html(WPSD_Helpers::truncate((string) $wpsd_entry->target_url, 40)); ?></code></td>
                        <td><?php echo esc_html((string) $wpsd_entry->code); ?></td>
                        <td class="wpsd-muted"><?php echo esc_html(WPSD_Helpers::truncate((string) ($wpsd_entry->referrer ?: '—'), 35)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php else : ?>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Import redirects', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('CSV columns: source, target, code, match_type. A header row is optional, and exports from the Redirection plugin are recognised.', 'wp-seo-doctor'); ?></p>

        <form method="post" enctype="multipart/form-data" class="wpsd-form">
            <?php wp_nonce_field('wpsd_import_redirects'); ?>
            <input type="hidden" name="wpsd_action" value="import_redirects">

            <div class="wpsd-form__row">
                <label for="wpsd-csv-file"><?php esc_html_e('CSV file', 'wp-seo-doctor'); ?></label>
                <input type="file" id="wpsd-csv-file" name="csv_file" accept=".csv,text/csv">
            </div>

            <div class="wpsd-form__row">
                <label for="wpsd-csv"><?php esc_html_e('…or paste CSV', 'wp-seo-doctor'); ?></label>
                <textarea id="wpsd-csv" name="csv" rows="8" class="large-text code" placeholder="/old-page,/new-page,301,exact"></textarea>
            </div>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Import', 'wp-seo-doctor'); ?></button></p>
        </form>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Export redirects', 'wp-seo-doctor'); ?></h2>
        <p>
            <a class="button" href="<?php echo esc_url(WPSD_Export::url('redirects', 'csv')); ?>"><?php esc_html_e('Download CSV', 'wp-seo-doctor'); ?></a>
            <a class="button" href="<?php echo esc_url(WPSD_Export::url('redirects', 'pdf')); ?>"><?php esc_html_e('Download PDF', 'wp-seo-doctor'); ?></a>
        </p>
    </div>

<?php endif; ?>
