<?php
/**
 * Schema installation and low-level table access.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_DB {

    /** Logical table name => suffix appended to $wpdb->prefix. */
    const TABLES = [
        'scans'        => 'wpsd_scans',
        'issues'       => 'wpsd_issues',
        'links'        => 'wpsd_links',
        'notfound'     => 'wpsd_notfound',
        'redirects'    => 'wpsd_redirects',
        'redirect_log' => 'wpsd_redirect_log',
        'gsc'          => 'wpsd_gsc',
        'trends'       => 'wpsd_trends',
    ];

    public static function table(string $key): string {
        global $wpdb;
        if (!isset(self::TABLES[$key])) {
            // A typo here would silently produce a broken query, so fail loudly.
            _doing_it_wrong(__METHOD__, esc_html("Unknown table key: {$key}"), '1.0.0');
            return '';
        }
        return $wpdb->prefix . self::TABLES[$key];
    }

    /**
     * Create/upgrade all tables. Safe to call repeatedly — dbDelta diffs them.
     */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        foreach (self::schemas($charset) as $sql) {
            dbDelta($sql);
        }

        WPSD_Settings::install_defaults();
        WPSD_Cron::schedule_from_settings();

        update_option('wpsd_db_version', WPSD_VERSION);
    }

    /**
     * @return array<int,string>
     */
    private static function schemas(string $charset): array {
        $scans        = self::table('scans');
        $issues       = self::table('issues');
        $links        = self::table('links');
        $notfound     = self::table('notfound');
        $redirects    = self::table('redirects');
        $redirect_log = self::table('redirect_log');
        $gsc          = self::table('gsc');
        $trends       = self::table('trends');

        return [
            // Scan history. `queue` holds the remaining object IDs for batching.
            "CREATE TABLE {$scans} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type VARCHAR(20) NOT NULL DEFAULT 'full',
                trigger_source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'running',
                score TINYINT UNSIGNED DEFAULT NULL,
                total_objects INT UNSIGNED NOT NULL DEFAULT 0,
                processed INT UNSIGNED NOT NULL DEFAULT 0,
                total_issues INT UNSIGNED NOT NULL DEFAULT 0,
                critical INT UNSIGNED NOT NULL DEFAULT 0,
                high INT UNSIGNED NOT NULL DEFAULT 0,
                medium INT UNSIGNED NOT NULL DEFAULT 0,
                low INT UNSIGNED NOT NULL DEFAULT 0,
                passed INT UNSIGNED NOT NULL DEFAULT 0,
                queue LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_status (status),
                KEY idx_started (started_at)
            ) {$charset};",

            // One row per detected problem.
            "CREATE TABLE {$issues} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                scan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                check_id VARCHAR(64) NOT NULL,
                check_group VARCHAR(32) NOT NULL DEFAULT 'onpage',
                object_type VARCHAR(20) NOT NULL DEFAULT 'post',
                object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                url TEXT DEFAULT NULL,
                severity VARCHAR(10) NOT NULL DEFAULT 'medium',
                title VARCHAR(255) NOT NULL,
                message TEXT DEFAULT NULL,
                recommendation TEXT DEFAULT NULL,
                data LONGTEXT DEFAULT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_scan (scan_id),
                KEY idx_check (check_id),
                KEY idx_severity (severity),
                KEY idx_status (status),
                KEY idx_object (object_type, object_id),
                KEY idx_group (check_group)
            ) {$charset};",

            // Link graph — powers internal linking, broken links and affiliate SEO.
            "CREATE TABLE {$links} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_url TEXT DEFAULT NULL,
                target_url TEXT NOT NULL,
                target_hash CHAR(32) NOT NULL,
                target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                anchor VARCHAR(255) DEFAULT NULL,
                rel VARCHAR(120) DEFAULT NULL,
                link_type VARCHAR(12) NOT NULL DEFAULT 'internal',
                is_affiliate TINYINT(1) NOT NULL DEFAULT 0,
                http_status SMALLINT NOT NULL DEFAULT 0,
                redirect_target TEXT DEFAULT NULL,
                redirect_hops TINYINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(12) NOT NULL DEFAULT 'unchecked',
                last_checked DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_source (source_id),
                KEY idx_target_hash (target_hash),
                KEY idx_target_id (target_id),
                KEY idx_status (status),
                KEY idx_type (link_type),
                KEY idx_affiliate (is_affiliate)
            ) {$charset};",

            // 404 monitor.
            "CREATE TABLE {$notfound} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                url TEXT NOT NULL,
                url_hash CHAR(32) NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 1,
                referrer TEXT DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                ip VARCHAR(64) DEFAULT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'new',
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_url (url_hash),
                KEY idx_status (status),
                KEY idx_hits (hits),
                KEY idx_last_seen (last_seen)
            ) {$charset};",

            // Redirect rules.
            "CREATE TABLE {$redirects} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source TEXT NOT NULL,
                source_hash CHAR(32) NOT NULL,
                target TEXT DEFAULT NULL,
                code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                match_type VARCHAR(10) NOT NULL DEFAULT 'exact',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                last_hit DATETIME DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_source_hash (source_hash),
                KEY idx_enabled (enabled),
                KEY idx_match_type (match_type)
            ) {$charset};",

            // Redirect history — who was redirected where, and when.
            "CREATE TABLE {$redirect_log} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                redirect_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                request_url TEXT DEFAULT NULL,
                target_url TEXT DEFAULT NULL,
                code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                referrer TEXT DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                ip VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_redirect (redirect_id),
                KEY idx_created (created_at)
            ) {$charset};",

            // Search Console cache.
            "CREATE TABLE {$gsc} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                data_date DATE NOT NULL,
                page TEXT DEFAULT NULL,
                page_hash CHAR(32) NOT NULL DEFAULT '',
                query_text VARCHAR(255) DEFAULT NULL,
                query_hash CHAR(32) NOT NULL DEFAULT '',
                clicks INT UNSIGNED NOT NULL DEFAULT 0,
                impressions INT UNSIGNED NOT NULL DEFAULT 0,
                ctr DECIMAL(7,4) NOT NULL DEFAULT 0,
                position DECIMAL(7,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_row (data_date, page_hash, query_hash),
                KEY idx_date (data_date),
                KEY idx_page (page_hash),
                KEY idx_clicks (clicks)
            ) {$charset};",

            // Daily snapshot for trend reporting.
            "CREATE TABLE {$trends} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                snapshot_date DATE NOT NULL,
                score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                total_issues INT UNSIGNED NOT NULL DEFAULT 0,
                critical INT UNSIGNED NOT NULL DEFAULT 0,
                high INT UNSIGNED NOT NULL DEFAULT 0,
                medium INT UNSIGNED NOT NULL DEFAULT 0,
                low INT UNSIGNED NOT NULL DEFAULT 0,
                passed INT UNSIGNED NOT NULL DEFAULT 0,
                broken_links INT UNSIGNED NOT NULL DEFAULT 0,
                notfound_urls INT UNSIGNED NOT NULL DEFAULT 0,
                orphan_pages INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_date (snapshot_date)
            ) {$charset};",
        ];
    }

    /**
     * Drop every table. Only called from uninstall.php.
     */
    public static function drop_all(): void {
        global $wpdb;
        foreach (array_keys(self::TABLES) as $key) {
            $table = self::table($key);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from a hardcoded whitelist.
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Build a "col IN (…)" fragment with the right number of placeholders.
     *
     * @param array<int,mixed> $values
     */
    public static function in_placeholders(array $values, string $type = '%d'): string {
        return implode(',', array_fill(0, max(1, count($values)), $type));
    }

    /** Total rows in a table, optionally with a prepared WHERE clause. */
    public static function count(string $table_key, string $where = '', array $params = []): int {
        global $wpdb;
        $table = self::table($table_key);
        $sql   = "SELECT COUNT(*) FROM {$table}" . ($where !== '' ? " WHERE {$where}" : '');
        if ($params) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, $params);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var($sql);
    }
}
