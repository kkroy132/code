<?php
/**
 * AJAX endpoints.
 *
 * Every handler goes through self::guard(), which checks the nonce and the
 * capability before anything else runs.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Ajax {

    /** action suffix => handler method. */
    const ACTIONS = [
        'start_scan'          => 'start_scan',
        'scan_batch'          => 'scan_batch',
        'cancel_scan'         => 'cancel_scan',
        'delete_scan'         => 'delete_scan',
        'rescan_post'         => 'rescan_post',
        'issue_status'        => 'issue_status',
        'check_links'         => 'check_links',
        'link_action'         => 'link_action',
        'rebuild_links'       => 'rebuild_links',
        'link_suggestions'    => 'link_suggestions',
        'insert_link'         => 'insert_link',
        'link_map'            => 'link_map',
        'notfound_action'     => 'notfound_action',
        'notfound_suggest'    => 'notfound_suggest',
        'create_redirect'     => 'create_redirect',
        'redirect_action'     => 'redirect_action',
        'test_redirect'       => 'test_redirect',
        'flatten_redirects'   => 'flatten_redirects',
        'gsc_sync'            => 'gsc_sync',
        'gsc_properties'      => 'gsc_properties',
        'ai_request'          => 'ai_request',
        'ai_apply'            => 'ai_apply',
        'retag_affiliate'     => 'retag_affiliate',
    ];

    public static function init(): void {
        foreach (self::ACTIONS as $action => $method) {
            add_action('wp_ajax_wpsd_' . $action, [self::class, $method]);
        }
    }

    /**
     * Verify the request, or die with a JSON error.
     */
    private static function guard(): void {
        if (!check_ajax_referer('wpsd_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'wp-seo-doctor')], 403);
        }
        if (!current_user_can(WPSD_Admin_Menu::CAPABILITY)) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'wp-seo-doctor')], 403);
        }
    }

    private static function post_string(string $key, string $default = ''): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
    }

    private static function post_int(string $key, int $default = 0): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        return isset($_POST[$key]) ? (int) wp_unslash($_POST[$key]) : $default;
    }

    /**
     * @return array<int,int>
     */
    private static function post_ids(string $key): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        return array_values(array_filter(array_map('absint', (array) $raw)));
    }

    // ──────────────────────────────────────────────────────────── scans ──

    public static function start_scan(): void {
        self::guard();

        $type   = self::post_string('type', 'full');
        $result = WPSD_Scanner::start($type);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    public static function scan_batch(): void {
        self::guard();

        $scan_id = self::post_int('scan_id');
        if ($scan_id <= 0) {
            wp_send_json_error(['message' => __('Missing scan ID.', 'wp-seo-doctor')]);
        }

        $result = WPSD_Scanner::run_batch($scan_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (!empty($result['done'])) {
            $score            = WPSD_Score::calculate($scan_id);
            $result['score']  = $score['score'];
            $result['label']  = $score['label'];
            $result['counts'] = $score['counts'];
        }

        wp_send_json_success($result);
    }

    public static function cancel_scan(): void {
        self::guard();

        $scan_id = self::post_int('scan_id');
        if ($scan_id > 0) {
            WPSD_Scanner::cancel($scan_id);
        }

        wp_send_json_success(['message' => __('Scan cancelled.', 'wp-seo-doctor')]);
    }

    public static function delete_scan(): void {
        self::guard();

        $scan_id = self::post_int('scan_id');
        if ($scan_id > 0) {
            WPSD_Scanner::delete_scan($scan_id);
        }

        wp_send_json_success(['message' => __('Scan deleted.', 'wp-seo-doctor')]);
    }

    public static function rescan_post(): void {
        self::guard();

        $post_id = self::post_int('post_id');
        if ($post_id <= 0) {
            wp_send_json_error(['message' => __('Missing post ID.', 'wp-seo-doctor')]);
        }

        $result = WPSD_Scanner::rescan_post($post_id);

        wp_send_json_success(array_merge($result, [
            'message' => sprintf(
                /* translators: 1: issue count, 2: passed check count */
                __('Re-checked: %1$d issues, %2$d checks passed.', 'wp-seo-doctor'),
                $result['issues'],
                $result['passed']
            ),
        ]));
    }

    public static function issue_status(): void {
        self::guard();

        $ids    = self::post_ids('ids');
        $status = self::post_string('status', 'ignored');

        $updated = WPSD_Issues::set_status($ids, $status);

        wp_send_json_success([
            'updated' => $updated,
            'counts'  => WPSD_Issues::severity_counts(),
        ]);
    }

    // ──────────────────────────────────────────────────────────── links ──

    public static function check_links(): void {
        self::guard();

        $force  = self::post_int('force') === 1;
        $result = WPSD_Broken_Links::check_batch(0, $force);

        wp_send_json_success($result);
    }

    public static function link_action(): void {
        self::guard();

        $link_id = self::post_int('link_id');
        $action  = self::post_string('link_action');

        if ($link_id <= 0) {
            wp_send_json_error(['message' => __('Missing link ID.', 'wp-seo-doctor')]);
        }

        switch ($action) {
            case 'replace':
                $result = WPSD_Broken_Links::replace($link_id, self::post_string('new_url'));
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                }
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: 1: occurrences replaced, 2: posts updated */
                        __('Replaced %1$d occurrence(s) across %2$d post(s).', 'wp-seo-doctor'),
                        $result['updated'],
                        count($result['posts'])
                    ),
                ]);
                break;

            case 'remove':
                $result = WPSD_Broken_Links::remove($link_id);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                }
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: 1: links unwrapped, 2: posts updated */
                        __('Removed %1$d link(s) from %2$d post(s).', 'wp-seo-doctor'),
                        $result['updated'],
                        count($result['posts'])
                    ),
                ]);
                break;

            case 'ignore':
                WPSD_Broken_Links::ignore($link_id);
                wp_send_json_success(['message' => __('Link ignored.', 'wp-seo-doctor')]);
                break;

            case 'unignore':
                WPSD_Broken_Links::unignore($link_id);
                wp_send_json_success(['message' => __('Link restored to the check queue.', 'wp-seo-doctor')]);
                break;

            case 'recheck':
                $result = WPSD_Broken_Links::recheck($link_id);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                }
                wp_send_json_success(array_merge($result, [
                    'message' => sprintf(
                        /* translators: 1: link state, 2: HTTP status code */
                        __('Rechecked: %1$s (HTTP %2$d).', 'wp-seo-doctor'),
                        $result['status'],
                        $result['http_status']
                    ),
                ]));
                break;

            default:
                wp_send_json_error(['message' => __('Unknown link action.', 'wp-seo-doctor')]);
        }
    }

    public static function rebuild_links(): void {
        self::guard();

        $limit  = max(1, self::post_int('limit', 50));
        $offset = max(0, self::post_int('offset'));

        $indexed = WPSD_Internal_Links::rebuild($limit, $offset);
        $total   = (int) wp_count_posts('post')->publish + (int) wp_count_posts('page')->publish;

        wp_send_json_success([
            'indexed' => $indexed,
            'offset'  => $offset + $limit,
            'done'    => $indexed < $limit,
            'total'   => $total,
        ]);
    }

    public static function link_suggestions(): void {
        self::guard();

        $post_id   = self::post_int('post_id');
        $direction = self::post_string('direction', 'targets');

        if ($post_id <= 0) {
            wp_send_json_error(['message' => __('Missing post ID.', 'wp-seo-doctor')]);
        }

        $suggestions = $direction === 'sources'
            ? WPSD_Internal_Links::suggest_sources($post_id, 8)
            : WPSD_Internal_Links::suggest_targets($post_id, 8);

        wp_send_json_success(['suggestions' => $suggestions]);
    }

    public static function insert_link(): void {
        self::guard();

        $source_id = self::post_int('source_id');
        $target_id = self::post_int('target_id');
        $anchor    = self::post_string('anchor');

        if ($source_id <= 0 || $target_id <= 0 || $anchor === '') {
            wp_send_json_error(['message' => __('A source, target and anchor text are all required.', 'wp-seo-doctor')]);
        }

        if (!WPSD_Internal_Links::insert_link($source_id, $target_id, $anchor)) {
            wp_send_json_error([
                'message' => __('Could not place the link — the anchor text was not found as plain text in the source post. Edit the post manually to add it.', 'wp-seo-doctor'),
            ]);
        }

        wp_send_json_success(['message' => __('Link inserted.', 'wp-seo-doctor')]);
    }

    public static function link_map(): void {
        self::guard();

        wp_send_json_success(WPSD_Internal_Links::link_map(150));
    }

    // ────────────────────────────────────────────────────────────── 404 ──

    public static function notfound_action(): void {
        self::guard();

        $ids    = self::post_ids('ids');
        $action = self::post_string('notfound_action');

        if (!$ids) {
            wp_send_json_error(['message' => __('No rows selected.', 'wp-seo-doctor')]);
        }

        switch ($action) {
            case 'ignore':
                $count = WPSD_Monitor_404::set_status($ids, 'ignored');
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: %d: number of rows */
                        __('%d URL(s) ignored.', 'wp-seo-doctor'),
                        $count
                    ),
                ]);
                break;

            case 'delete':
                $count = WPSD_Monitor_404::delete($ids);
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: %d: number of rows */
                        __('%d URL(s) deleted.', 'wp-seo-doctor'),
                        $count
                    ),
                ]);
                break;

            case 'redirect':
                $target = self::post_string('target');
                $code   = self::post_int('code', 301);
                if ($target === '') {
                    wp_send_json_error(['message' => __('A redirect target is required.', 'wp-seo-doctor')]);
                }

                $created = 0;
                foreach ($ids as $id) {
                    $result = WPSD_Monitor_404::create_redirect($id, $target, $code);
                    if (!is_wp_error($result)) {
                        $created++;
                    }
                }

                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: %d: number of redirects created */
                        __('%d redirect(s) created.', 'wp-seo-doctor'),
                        $created
                    ),
                ]);
                break;

            default:
                wp_send_json_error(['message' => __('Unknown action.', 'wp-seo-doctor')]);
        }
    }

    public static function notfound_suggest(): void {
        self::guard();

        $id = self::post_int('id');
        $row = $id > 0 ? WPSD_Monitor_404::get($id) : null;
        if (!$row) {
            wp_send_json_error(['message' => __('404 record not found.', 'wp-seo-doctor')]);
        }

        wp_send_json_success([
            'url'         => $row->url,
            'suggestions' => WPSD_Monitor_404::suggest_redirects((string) $row->url),
        ]);
    }

    // ──────────────────────────────────────────────────────── redirects ──

    public static function create_redirect(): void {
        self::guard();

        $result = WPSD_Redirects::create([
            'source'     => self::post_string('source'),
            'target'     => self::post_string('target'),
            'code'       => self::post_int('code', 301),
            'match_type' => self::post_string('match_type', 'exact'),
            'notes'      => self::post_string('notes'),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'id'      => $result,
            'message' => __('Redirect created.', 'wp-seo-doctor'),
        ]);
    }

    public static function redirect_action(): void {
        self::guard();

        $ids    = self::post_ids('ids');
        $action = self::post_string('redirect_action');

        if (!$ids) {
            wp_send_json_error(['message' => __('No redirects selected.', 'wp-seo-doctor')]);
        }

        switch ($action) {
            case 'delete':
                $count = WPSD_Redirects::delete($ids);
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: %d: number deleted */
                        __('%d redirect(s) deleted.', 'wp-seo-doctor'),
                        $count
                    ),
                ]);
                break;

            case 'enable':
            case 'disable':
                $enabled = $action === 'enable';
                foreach ($ids as $id) {
                    WPSD_Redirects::update($id, ['enabled' => $enabled]);
                }
                wp_send_json_success([
                    'message' => $enabled
                        ? __('Redirects enabled.', 'wp-seo-doctor')
                        : __('Redirects disabled.', 'wp-seo-doctor'),
                ]);
                break;

            default:
                wp_send_json_error(['message' => __('Unknown action.', 'wp-seo-doctor')]);
        }
    }

    public static function test_redirect(): void {
        self::guard();

        wp_send_json_success(WPSD_Redirects::test(
            self::post_string('source'),
            self::post_string('target'),
            self::post_string('match_type', 'exact'),
            self::post_string('sample')
        ));
    }

    public static function flatten_redirects(): void {
        self::guard();

        $fixed = WPSD_Redirects::flatten_chains();

        wp_send_json_success([
            'fixed'   => $fixed,
            'message' => sprintf(
                /* translators: %d: number of chains flattened */
                __('%d redirect chain(s) flattened.', 'wp-seo-doctor'),
                $fixed
            ),
        ]);
    }

    // ────────────────────────────────────────────────────── search console ──

    public static function gsc_sync(): void {
        self::guard();

        $result = WPSD_GSC::sync();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $message = sprintf(
            /* translators: 1: row count, 2: start date, 3: end date */
            __('Synced %1$d rows (%2$s to %3$s).', 'wp-seo-doctor'),
            $result['rows'],
            $result['from'],
            $result['to']
        );

        if (!empty($result['truncated'])) {
            $message .= ' ' . __('Search Console returned more data than one sync can retrieve, so this window is partial. Narrow the lookback period for complete figures.', 'wp-seo-doctor');
        }

        wp_send_json_success(array_merge($result, ['message' => $message]));
    }

    public static function gsc_properties(): void {
        self::guard();

        $properties = WPSD_GSC::list_properties();
        if (is_wp_error($properties)) {
            wp_send_json_error(['message' => $properties->get_error_message()]);
        }

        wp_send_json_success(['properties' => $properties]);
    }

    // ─────────────────────────────────────────────────────────────── AI ──

    public static function ai_request(): void {
        self::guard();

        $task    = self::post_string('task');
        $post_id = self::post_int('post_id');

        switch ($task) {
            case 'titles':
                $result = WPSD_AI::suggest_titles($post_id);
                break;
            case 'descriptions':
                $result = WPSD_AI::suggest_descriptions($post_id);
                break;
            case 'alt_text':
                $result = WPSD_AI::suggest_alt_text($post_id);
                break;
            case 'internal_links':
                $result = WPSD_AI::suggest_internal_links($post_id);
                break;
            case 'anchor_text':
                $result = WPSD_AI::suggest_anchor_text($post_id);
                break;
            case 'content':
                $result = WPSD_AI::suggest_content_optimization($post_id);
                break;
            case 'readiness':
                $result = WPSD_AI::search_readiness($post_id);
                break;
            case 'action_plan':
                $result = WPSD_AI::action_plan();
                break;
            case 'explain':
                $result = WPSD_AI::explain_issue(self::post_int('issue_id'));
                break;
            case 'ask':
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
                $question = isset($_POST['question']) ? sanitize_textarea_field(wp_unslash($_POST['question'])) : '';
                $result   = WPSD_AI::ask($question);
                break;
            default:
                wp_send_json_error(['message' => __('Unknown AI task.', 'wp-seo-doctor')]);
                return;
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['task' => $task, 'result' => $result]);
    }

    public static function ai_apply(): void {
        self::guard();

        $field = self::post_string('field');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $value = isset($_POST['value']) ? sanitize_textarea_field(wp_unslash($_POST['value'])) : '';

        if ($field === 'alt') {
            $applied = WPSD_AI::apply_alt_text(self::post_string('image'), $value);
        } else {
            $applied = WPSD_AI::apply_suggestion(self::post_int('post_id'), $field, $value);
        }

        if (!$applied) {
            wp_send_json_error(['message' => __('Could not apply that suggestion.', 'wp-seo-doctor')]);
        }

        wp_send_json_success(['message' => __('Applied.', 'wp-seo-doctor')]);
    }

    public static function retag_affiliate(): void {
        self::guard();

        $result = WPSD_Affiliate::retag_links(self::post_int('after_id'));

        wp_send_json_success(array_merge($result, [
            'message' => $result['complete']
                ? sprintf(
                    /* translators: 1: links changed, 2: links examined */
                    __('%1$d of %2$d link(s) retagged.', 'wp-seo-doctor'),
                    $result['changed'],
                    $result['scanned']
                )
                : sprintf(
                    /* translators: 1: links changed, 2: links examined */
                    __('%1$d of %2$d link(s) retagged so far — continuing…', 'wp-seo-doctor'),
                    $result['changed'],
                    $result['scanned']
                ),
        ]));
    }
}
