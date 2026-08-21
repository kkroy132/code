<?php
/**
 * Google Search Console integration.
 *
 * OAuth 2.0 authorisation-code flow with a refresh token, plus a daily sync
 * that caches Search Analytics rows locally so every report reads from the
 * database rather than the API.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_GSC {

    const TOKEN_OPTION   = 'wpsd_gsc_token';
    const LAST_SYNC      = 'wpsd_gsc_last_sync';
    const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    const API_BASE       = 'https://searchconsole.googleapis.com/webmasters/v3';
    const SCOPE          = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** Google's per-request ceiling for Search Analytics. */
    const MAX_ROWS_PER_REQUEST = 25000;

    /**
     * Pages to request before stopping. 10 x 25,000 is far more than any
     * report here consumes, and bounds a sync on a very large property.
     */
    const MAX_SYNC_PAGES = 10;

    public static function init(): void {
        add_action('admin_init', [self::class, 'maybe_handle_oauth']);
        add_action('wpsd_sync_gsc', [self::class, 'sync']);
    }

    // ──────────────────────────────────────────────────────────── OAuth ──

    public static function is_configured(): bool {
        return WPSD_Settings::get('gsc_client_id', '') !== ''
            && WPSD_Settings::get('gsc_client_secret', '') !== '';
    }

    public static function is_connected(): bool {
        $token = get_option(self::TOKEN_OPTION, []);
        return is_array($token) && !empty($token['refresh_token']);
    }

    public static function redirect_uri(): string {
        return admin_url('admin.php?page=' . WPSD_SLUG . '-search-console');
    }

    public static function auth_url(): string {
        return add_query_arg([
            'client_id'     => rawurlencode((string) WPSD_Settings::get('gsc_client_id', '')),
            'redirect_uri'  => rawurlencode(self::redirect_uri()),
            'response_type' => 'code',
            'scope'         => rawurlencode(self::SCOPE),
            'access_type'   => 'offline',
            // force ensures Google returns a refresh token on re-authorisation.
            'prompt'        => 'consent',
            'state'         => rawurlencode(wp_create_nonce('wpsd_gsc_oauth')),
        ], self::AUTH_ENDPOINT);
    }

    /**
     * Handle the OAuth redirect back from Google.
     */
    public static function maybe_handle_oauth(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- state is verified below.
        if (!isset($_GET['page'], $_GET['code']) || sanitize_key($_GET['page']) !== WPSD_SLUG . '-search-console') {
            return;
        }

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!wp_verify_nonce($state, 'wpsd_gsc_oauth')) {
            add_settings_error('wpsd', 'wpsd_gsc_state', __('Search Console authorisation failed: the security token did not match. Please try again.', 'wp-seo-doctor'));
            return;
        }

        $code = sanitize_text_field(wp_unslash($_GET['code']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result = self::exchange_code($code);

        if (is_wp_error($result)) {
            add_settings_error('wpsd', 'wpsd_gsc_exchange', $result->get_error_message());
            return;
        }

        wp_safe_redirect(add_query_arg('wpsd_connected', '1', self::redirect_uri()));
        exit;
    }

    /**
     * Trade an authorisation code for access and refresh tokens.
     *
     * @return true|WP_Error
     */
    public static function exchange_code(string $code) {
        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 20,
            'body'    => [
                'code'          => $code,
                'client_id'     => (string) WPSD_Settings::get('gsc_client_id', ''),
                'client_secret' => (string) WPSD_Settings::get('gsc_client_secret', ''),
                'redirect_uri'  => self::redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return new WP_Error(
                'wpsd_gsc_token',
                isset($body['error_description'])
                    ? (string) $body['error_description']
                    : __('Google did not return an access token.', 'wp-seo-doctor')
            );
        }

        update_option(self::TOKEN_OPTION, [
            'access_token'  => (string) $body['access_token'],
            'refresh_token' => (string) ($body['refresh_token'] ?? ''),
            'expires_at'    => time() + (int) ($body['expires_in'] ?? 3600) - 60,
        ], false);

        return true;
    }

    /**
     * A valid access token, refreshing it when necessary.
     *
     * @return string|WP_Error
     */
    public static function access_token() {
        $token = get_option(self::TOKEN_OPTION, []);
        if (!is_array($token) || empty($token['access_token'])) {
            return new WP_Error('wpsd_gsc_disconnected', __('Search Console is not connected.', 'wp-seo-doctor'));
        }

        if ((int) ($token['expires_at'] ?? 0) > time()) {
            return (string) $token['access_token'];
        }

        if (empty($token['refresh_token'])) {
            return new WP_Error('wpsd_gsc_no_refresh', __('The Search Console session expired and there is no refresh token. Please reconnect.', 'wp-seo-doctor'));
        }

        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 20,
            'body'    => [
                'refresh_token' => (string) $token['refresh_token'],
                'client_id'     => (string) WPSD_Settings::get('gsc_client_id', ''),
                'client_secret' => (string) WPSD_Settings::get('gsc_client_secret', ''),
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return new WP_Error('wpsd_gsc_refresh', __('Could not refresh the Search Console access token. Please reconnect.', 'wp-seo-doctor'));
        }

        $token['access_token'] = (string) $body['access_token'];
        $token['expires_at']   = time() + (int) ($body['expires_in'] ?? 3600) - 60;
        update_option(self::TOKEN_OPTION, $token, false);

        return $token['access_token'];
    }

    public static function disconnect(): void {
        delete_option(self::TOKEN_OPTION);
        delete_option(self::LAST_SYNC);
    }

    // ──────────────────────────────────────────────────────────── API ──

    /**
     * Verified properties on the connected account.
     *
     * @return array<int,string>|WP_Error
     */
    public static function list_properties() {
        $token = self::access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $response = wp_remote_get(self::API_BASE . '/sites', [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('wpsd_gsc_sites', __('Could not read the property list.', 'wp-seo-doctor'));
        }

        $sites = [];
        foreach ((array) ($body['siteEntry'] ?? []) as $entry) {
            if (!empty($entry['siteUrl'])) {
                $sites[] = (string) $entry['siteUrl'];
            }
        }

        return $sites;
    }

    /**
     * Query the Search Analytics API.
     *
     * @param array<int,string> $dimensions
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public static function query_api(string $start, string $end, array $dimensions, int $limit = 5000, int $start_row = 0) {
        $token = self::access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $property = (string) WPSD_Settings::get('gsc_property', '');
        if ($property === '') {
            return new WP_Error('wpsd_gsc_property', __('No Search Console property is selected.', 'wp-seo-doctor'));
        }

        $endpoint = self::API_BASE . '/sites/' . rawurlencode($property) . '/searchAnalytics/query';

        $response = wp_remote_post($endpoint, [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'startDate'  => $start,
                'endDate'    => $end,
                'dimensions' => $dimensions,
                'rowLimit'   => min(self::MAX_ROWS_PER_REQUEST, max(1, $limit)),
                'startRow'   => max(0, $start_row),
                'type'       => 'web',
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $message = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : sprintf(
                    /* translators: %d: HTTP status code */
                    __('Search Console returned HTTP %d.', 'wp-seo-doctor'),
                    $code
                );
            return new WP_Error('wpsd_gsc_api', $message);
        }

        return is_array($body) && isset($body['rows']) ? (array) $body['rows'] : [];
    }

    /**
     * Pull the configured window into the local cache.
     *
     * @return array{rows:int,from:string,to:string}|WP_Error
     */
    public static function sync() {
        global $wpdb;

        if (!self::is_connected()) {
            return new WP_Error('wpsd_gsc_disconnected', __('Search Console is not connected.', 'wp-seo-doctor'));
        }

        $days = (int) WPSD_Settings::get('gsc_lookback_days', 28);
        // Search Console data lags by ~2 days; asking for today returns nothing.
        $end   = gmdate('Y-m-d', strtotime('-2 days'));
        $start = gmdate('Y-m-d', strtotime("-{$days} days", strtotime($end)));

        // Google caps a single response at 25,000 rows. Taking the first page
        // and reporting success would quietly discard the rest of a busy
        // property's data, so page until the API runs out or the cap is hit.
        $rows      = [];
        $truncated = false;

        for ($page = 0; $page < self::MAX_SYNC_PAGES; $page++) {
            $batch = self::query_api(
                $start,
                $end,
                ['date', 'page', 'query'],
                self::MAX_ROWS_PER_REQUEST,
                $page * self::MAX_ROWS_PER_REQUEST
            );

            if (is_wp_error($batch)) {
                // Keep whatever earlier pages returned; a partial sync beats
                // discarding good data because page four timed out.
                if (!$rows) {
                    return $batch;
                }
                $truncated = true;
                break;
            }

            $rows = array_merge($rows, $batch);

            if (count($batch) < self::MAX_ROWS_PER_REQUEST) {
                break;
            }

            if ($page === self::MAX_SYNC_PAGES - 1) {
                $truncated = true;
            }
        }

        $table = WPSD_DB::table('gsc');

        // Replace the window wholesale so re-syncing never double-counts.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE data_date BETWEEN %s AND %s",
            $start,
            $end
        ));

        $inserted = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $row) {
                $keys = (array) ($row['keys'] ?? []);
                if (count($keys) < 3) {
                    continue;
                }

                $values[] = '(%s,%s,%s,%s,%s,%d,%d,%f,%f)';
                array_push(
                    $params,
                    (string) $keys[0],                        // date
                    (string) $keys[1],                        // page
                    md5(WPSD_Helpers::normalize_url((string) $keys[1])),
                    mb_substr((string) $keys[2], 0, 255),     // query
                    md5((string) $keys[2]),
                    (int) ($row['clicks'] ?? 0),
                    (int) ($row['impressions'] ?? 0),
                    (float) ($row['ctr'] ?? 0) * 100,
                    (float) ($row['position'] ?? 0)
                );
            }

            if (!$values) {
                continue;
            }

            $sql = "INSERT INTO {$table}
                    (data_date, page, page_hash, query_text, query_hash, clicks, impressions, ctr, position)
                    VALUES " . implode(',', $values) . "
                    ON DUPLICATE KEY UPDATE
                        clicks = VALUES(clicks),
                        impressions = VALUES(impressions),
                        ctr = VALUES(ctr),
                        position = VALUES(position)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare($sql, $params));
            $inserted += count($values);
        }

        update_option(self::LAST_SYNC, WPSD_Helpers::now(), false);

        // The caller is told when the window was not fully retrieved, so the
        // UI can say so rather than implying a complete sync.
        return [
            'rows'      => $inserted,
            'from'      => $start,
            'to'        => $end,
            'truncated' => $truncated,
        ];
    }

    public static function last_sync(): string {
        return (string) get_option(self::LAST_SYNC, '');
    }

    /**
     * Discard Search Console rows older than the reporting window needs.
     *
     * Trend and decay comparisons look back at most twice the configured
     * lookback, so anything beyond that plus a margin is dead weight — and on
     * a busy site this table grows fastest of all.
     */
    public static function prune(): void {
        global $wpdb;

        $table  = WPSD_DB::table('gsc');
        $keep   = max(60, (int) WPSD_Settings::get('gsc_lookback_days', 28) * 3);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE data_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $keep
        ));
    }

    public static function has_data(): bool {
        static $has = null;
        if ($has === null) {
            $has = WPSD_DB::count('gsc') > 0;
        }
        return $has;
    }

    // ────────────────────────────────────────────────────────── reports ──

    /**
     * Headline metrics for a window.
     *
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    public static function totals(int $days = 28): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $sql = "SELECT
                    COALESCE(SUM(clicks),0) AS clicks,
                    COALESCE(SUM(impressions),0) AS impressions,
                    COALESCE(AVG(position),0) AS position
                FROM {$table}
                WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare($sql, max(1, $days)));

        $clicks      = (int) ($row->clicks ?? 0);
        $impressions = (int) ($row->impressions ?? 0);

        return [
            'clicks'      => $clicks,
            'impressions' => $impressions,
            // CTR is recomputed from the totals; averaging per-row CTR would
            // weight a 1-impression row the same as a 10,000-impression one.
            'ctr'         => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            'position'    => round((float) ($row->position ?? 0), 1),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function top_queries(int $limit = 25, int $days = 28): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $sql = "SELECT query_text AS query,
                       SUM(clicks) AS clicks,
                       SUM(impressions) AS impressions,
                       AVG(position) AS position
                FROM {$table}
                WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) AND query_text <> ''
                GROUP BY query_text, query_hash
                ORDER BY clicks DESC, impressions DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, $days), max(1, $limit)), ARRAY_A) ?: [];

        return array_map([self::class, 'decorate_row'], $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function top_pages(int $limit = 25, int $days = 28): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $sql = "SELECT page,
                       SUM(clicks) AS clicks,
                       SUM(impressions) AS impressions,
                       AVG(position) AS position
                FROM {$table}
                WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) AND page <> ''
                GROUP BY page_hash, page
                ORDER BY clicks DESC, impressions DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, $days), max(1, $limit)), ARRAY_A) ?: [];

        return array_map([self::class, 'decorate_row'], $rows);
    }

    /**
     * Queries ranking just outside page one — the cheapest wins available.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function striking_distance(int $limit = 25, int $days = 28): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $low  = (float) WPSD_Settings::get('gsc_position_low', 11.0);
        $high = (float) WPSD_Settings::get('gsc_position_high', 20.0);

        $sql = "SELECT page, query_text AS query,
                       SUM(clicks) AS clicks,
                       SUM(impressions) AS impressions,
                       AVG(position) AS position
                FROM {$table}
                WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                  AND query_text <> ''
                GROUP BY page_hash, page, query_hash, query_text
                HAVING position BETWEEN %f AND %f AND impressions >= 10
                ORDER BY impressions DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, $days), $low, $high, max(1, $limit)), ARRAY_A) ?: [];

        return array_map([self::class, 'decorate_row'], $rows);
    }

    /**
     * Pages with plenty of impressions but a CTR below the floor.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function ctr_opportunities(int $limit = 25, int $days = 28): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $floor = (float) WPSD_Settings::get('gsc_ctr_floor', 2.0);

        $sql = "SELECT page,
                       SUM(clicks) AS clicks,
                       SUM(impressions) AS impressions,
                       AVG(position) AS position
                FROM {$table}
                WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) AND page <> ''
                GROUP BY page_hash, page
                HAVING impressions >= 100
                   AND position <= 20
                   AND (SUM(clicks) / SUM(impressions)) * 100 < %f
                ORDER BY impressions DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, $days), $floor, max(1, $limit)), ARRAY_A) ?: [];

        return array_map([self::class, 'decorate_row'], $rows);
    }

    /**
     * Pages whose clicks dropped versus the preceding equal-length window.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function declining_pages(int $limit = 25, int $days = 28): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        // The aggregate columns are filtered and sorted in an outer query:
        // MariaDB rejects an aggregate alias used inside an ORDER BY
        // expression, so the derived table keeps this portable.
        $sql = "SELECT * FROM (
                    SELECT page,
                           SUM(CASE WHEN data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) THEN clicks ELSE 0 END) AS recent_clicks,
                           SUM(CASE WHEN data_date <  DATE_SUB(CURDATE(), INTERVAL %d DAY) THEN clicks ELSE 0 END) AS previous_clicks
                    FROM {$table}
                    WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) AND page <> ''
                    GROUP BY page_hash, page
                ) AS totals
                WHERE totals.previous_clicks >= 10
                  AND totals.recent_clicks < totals.previous_clicks
                ORDER BY (totals.previous_clicks - totals.recent_clicks) DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            $sql,
            max(1, $days),
            max(1, $days),
            max(2, $days * 2),
            max(1, $limit)
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $previous = (int) $row['previous_clicks'];
            $recent   = (int) $row['recent_clicks'];
            $out[]    = [
                'page'            => (string) $row['page'],
                'recent_clicks'   => $recent,
                'previous_clicks' => $previous,
                'change_percent'  => $previous > 0 ? round((($recent - $previous) / $previous) * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Aggregate stats for one URL.
     *
     * @return array{clicks:int,impressions:int,ctr:float,position:float}|null
     */
    public static function page_stats(string $url, int $days = 28): ?array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $sql = "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
                FROM {$table}
                WHERE page_hash = %s AND data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare($sql, md5(WPSD_Helpers::normalize_url($url)), max(1, $days)));

        if (!$row || $row->impressions === null) {
            return null;
        }

        $clicks      = (int) $row->clicks;
        $impressions = (int) $row->impressions;

        return [
            'clicks'      => $clicks,
            'impressions' => $impressions,
            'ctr'         => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            'position'    => round((float) $row->position, 1),
        ];
    }

    /**
     * Recent vs previous clicks for one URL.
     *
     * @return array{recent_clicks:int,previous_clicks:int,change_percent:float}|null
     */
    public static function page_trend(string $url, int $days = 28): ?array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $sql = "SELECT
                    SUM(CASE WHEN data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) THEN clicks ELSE 0 END) AS recent_clicks,
                    SUM(CASE WHEN data_date <  DATE_SUB(CURDATE(), INTERVAL %d DAY) THEN clicks ELSE 0 END) AS previous_clicks
                FROM {$table}
                WHERE page_hash = %s AND data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            $sql,
            max(1, $days),
            max(1, $days),
            md5(WPSD_Helpers::normalize_url($url)),
            max(2, $days * 2)
        ));

        if (!$row) {
            return null;
        }

        $recent   = (int) $row->recent_clicks;
        $previous = (int) $row->previous_clicks;

        return [
            'recent_clicks'   => $recent,
            'previous_clicks' => $previous,
            'change_percent'  => $previous > 0 ? round((($recent - $previous) / $previous) * 100, 1) : 0.0,
        ];
    }

    /**
     * Daily series for the performance chart.
     *
     * @return array<int,array{date:string,clicks:int,impressions:int,ctr:float,position:float}>
     */
    public static function daily_trend(int $days = 90): array {
        global $wpdb;
        $table = WPSD_DB::table('gsc');

        $sql = "SELECT data_date,
                       SUM(clicks) AS clicks,
                       SUM(impressions) AS impressions,
                       AVG(position) AS position
                FROM {$table}
                WHERE data_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                GROUP BY data_date
                ORDER BY data_date ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, $days))) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $clicks      = (int) $row->clicks;
            $impressions = (int) $row->impressions;
            $out[]       = [
                'date'        => (string) $row->data_date,
                'clicks'      => $clicks,
                'impressions' => $impressions,
                'ctr'         => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
                'position'    => round((float) $row->position, 1),
            ];
        }

        return $out;
    }

    /**
     * Add a computed CTR and tidy numeric types on an aggregate row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decorate_row(array $row): array {
        $clicks      = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);

        $row['clicks']      = $clicks;
        $row['impressions'] = $impressions;
        $row['ctr']         = $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0;
        $row['position']    = round((float) ($row['position'] ?? 0), 1);

        return $row;
    }
}
