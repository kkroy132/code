<?php
/**
 * AI SEO assistant.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

$wpsd_enabled = WPSD_AI::is_enabled();
$wpsd_error   = WPSD_AI::availability_error();
?>

<?php if (!$wpsd_enabled) : ?>
    <div class="wpsd-card">
        <h2><?php esc_html_e('AI features are not available yet', 'wp-seo-doctor'); ?></h2>
        <p><?php echo esc_html(is_wp_error($wpsd_error) ? $wpsd_error->get_error_message() : ''); ?></p>
        <p class="wpsd-muted"><?php esc_html_e('Everything on this page runs against your own API key. Nothing is sent anywhere until you press a button.', 'wp-seo-doctor'); ?></p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(WPSD_Admin_Menu::page_url('-settings', ['tab' => 'ai'])); ?>">
                <?php esc_html_e('Configure AI', 'wp-seo-doctor'); ?>
            </a>
        </p>
    </div>
<?php else : ?>

    <div class="wpsd-card">
        <h2><?php esc_html_e('AI SEO action plan', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('Turns the current open issues into an ordered plan, sequenced so blocking fixes come first.', 'wp-seo-doctor'); ?></p>
        <p>
            <button type="button" class="button button-primary wpsd-ai-task" data-task="action_plan">
                <?php esc_html_e('Generate action plan', 'wp-seo-doctor'); ?>
            </button>
        </p>
        <div class="wpsd-ai-output"></div>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Ask the SEO assistant', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('Questions are answered with your current audit state — score, top issues, link and 404 counts — as context.', 'wp-seo-doctor'); ?></p>

        <div class="wpsd-form__row">
            <textarea id="wpsd-ai-question" rows="3" class="large-text"
                      placeholder="<?php esc_attr_e('e.g. Which of my current issues is costing me the most traffic?', 'wp-seo-doctor'); ?>"></textarea>
        </div>
        <p><button type="button" class="button button-primary" id="wpsd-ai-ask"><?php esc_html_e('Ask', 'wp-seo-doctor'); ?></button></p>
        <div class="wpsd-ai-output" id="wpsd-ai-answer"></div>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Per-page assistance', 'wp-seo-doctor'); ?></h2>
        <p class="wpsd-muted"><?php esc_html_e('Pick a page, then run any of the tasks against it. Suggestions can be applied with one click.', 'wp-seo-doctor'); ?></p>

        <div class="wpsd-form__row">
            <label for="wpsd-ai-post"><?php esc_html_e('Page', 'wp-seo-doctor'); ?></label>
            <select id="wpsd-ai-post" class="regular-text">
                <option value=""><?php esc_html_e('— Select a page —', 'wp-seo-doctor'); ?></option>
                <?php
                $wpsd_recent = get_posts([
                    'post_type'      => WPSD_Helpers::auditable_post_types(),
                    'post_status'    => 'publish',
                    'posts_per_page' => 200,
                    'orderby'        => 'modified',
                    'order'          => 'DESC',
                ]);
                foreach ($wpsd_recent as $wpsd_post) :
                    ?>
                    <option value="<?php echo esc_attr((string) $wpsd_post->ID); ?>">
                        <?php echo esc_html(WPSD_Helpers::truncate($wpsd_post->post_title, 70)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wpsd-actions" id="wpsd-ai-tasks">
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="titles"><?php esc_html_e('Title suggestions', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="descriptions"><?php esc_html_e('Meta descriptions', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="alt_text"><?php esc_html_e('ALT text', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="internal_links"><?php esc_html_e('Internal links', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="anchor_text"><?php esc_html_e('Anchor text', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="content"><?php esc_html_e('Content optimisation', 'wp-seo-doctor'); ?></button>
            <button type="button" class="button wpsd-ai-task wpsd-ai-task--selected" data-task="readiness"><?php esc_html_e('Search readiness', 'wp-seo-doctor'); ?></button>
        </div>

        <div class="wpsd-ai-output" id="wpsd-ai-page-output"></div>
    </div>

    <div class="wpsd-card">
        <h2><?php esc_html_e('Model', 'wp-seo-doctor'); ?></h2>
        <table class="wpsd-table wpsd-table--compact">
            <tbody>
            <tr>
                <td><?php esc_html_e('Provider', 'wp-seo-doctor'); ?></td>
                <td><?php echo esc_html((string) WPSD_Settings::get('ai_provider', 'anthropic')); ?></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Model', 'wp-seo-doctor'); ?></td>
                <td><code><?php echo esc_html((string) WPSD_Settings::get('ai_model', '')); ?></code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Max tokens', 'wp-seo-doctor'); ?></td>
                <td><?php echo esc_html((string) WPSD_Settings::get('ai_max_tokens', 1200)); ?></td>
            </tr>
            </tbody>
        </table>
    </div>

<?php endif; ?>
