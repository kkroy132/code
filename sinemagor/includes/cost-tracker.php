<?php
defined('ABSPATH') || exit;

class Sinemagor_Cost_Tracker {

    const TABLE = 'sinemagor_cost_log';

    // Model cost per 1M tokens (input / output) in USD — updated Dec 2024
    const MODEL_COSTS = [
        'deepseek/deepseek-chat'            => ['in' => 0.14,  'out' => 0.28],
        'deepseek/deepseek-r1'              => ['in' => 0.55,  'out' => 2.19],
        'meta-llama/llama-3.3-70b-instruct' => ['in' => 0.59,  'out' => 0.79],
        'mistralai/mistral-nemo'            => ['in' => 0.15,  'out' => 0.15],
        'google/gemini-flash-1.5'           => ['in' => 0.075, 'out' => 0.30],
    ];

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_post_id    BIGINT UNSIGNED DEFAULT NULL,
            movie_title   VARCHAR(255)    DEFAULT NULL,
            model         VARCHAR(100)    NOT NULL,
            input_tokens  INT UNSIGNED    NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED    NOT NULL DEFAULT 0,
            cost_usd      DECIMAL(8,6)    NOT NULL DEFAULT 0,
            source        ENUM('manual','bulk','autopilot','regen') NOT NULL DEFAULT 'manual',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date  (created_at),
            KEY idx_post  (wp_post_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Log one AI call.
     */
    public static function log(array $data): void {
        global $wpdb;
        $model  = $data['model'] ?? Sinemagor_Settings::get('ai_model', 'deepseek/deepseek-chat');
        $in     = (int) ($data['input_tokens']  ?? 0);
        $out    = (int) ($data['output_tokens'] ?? 0);
        $costs  = self::MODEL_COSTS[$model] ?? ['in' => 0.14, 'out' => 0.28];
        $cost   = ($in / 1_000_000 * $costs['in']) + ($out / 1_000_000 * $costs['out']);

        $wpdb->insert($wpdb->prefix . self::TABLE, [
            'wp_post_id'   => $data['wp_post_id']   ?? null,
            'movie_title'  => $data['movie_title']  ?? '',
            'model'        => $model,
            'input_tokens' => $in,
            'output_tokens'=> $out,
            'cost_usd'     => round($cost, 6),
            'source'       => $data['source']       ?? 'manual',
        ]);
    }

    /** Stats: total cost, calls, tokens — optionally filtered by date range. */
    public static function get_stats(string $from = '', string $to = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($from && $to) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS calls, SUM(input_tokens) AS total_input, SUM(output_tokens) AS total_output, SUM(cost_usd) AS total_cost
                 FROM {$table} WHERE created_at >= %s AND created_at <= %s",
                $from, $to . ' 23:59:59'
            ));
        } elseif ($from) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS calls, SUM(input_tokens) AS total_input, SUM(output_tokens) AS total_output, SUM(cost_usd) AS total_cost
                 FROM {$table} WHERE created_at >= %s",
                $from
            ));
        } elseif ($to) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS calls, SUM(input_tokens) AS total_input, SUM(output_tokens) AS total_output, SUM(cost_usd) AS total_cost
                 FROM {$table} WHERE created_at <= %s",
                $to . ' 23:59:59'
            ));
        } else {
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS calls, SUM(input_tokens) AS total_input, SUM(output_tokens) AS total_output, SUM(cost_usd) AS total_cost
                 FROM {$table}"
            );
        }

        return [
            'calls'        => (int)   ($row->calls        ?? 0),
            'total_input'  => (int)   ($row->total_input  ?? 0),
            'total_output' => (int)   ($row->total_output ?? 0),
            'total_cost'   => (float) ($row->total_cost   ?? 0),
        ];
    }

    /** Daily cost for the last N days — for chart. */
    public static function get_daily(int $days = 30): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $since = gmdate('Y-m-d', strtotime("-{$days} days"));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day,
                    COUNT(*)         AS calls,
                    SUM(cost_usd)    AS cost
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $since
        ));

        // Fill missing days with 0
        $map = [];
        foreach ($rows as $r) $map[$r->day] = ['calls' => (int)$r->calls, 'cost' => (float)$r->cost];

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-{$i} days"));
            $result[] = ['day' => $d, 'calls' => $map[$d]['calls'] ?? 0, 'cost' => $map[$d]['cost'] ?? 0];
        }
        return $result;
    }

    /** Cost breakdown by model. */
    public static function get_by_model(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            "SELECT model, COUNT(*) AS calls, SUM(cost_usd) AS cost
             FROM {$table} GROUP BY model ORDER BY cost DESC"
        ) ?: [];
    }

    /** Cost breakdown by source. */
    public static function get_by_source(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            "SELECT source, COUNT(*) AS calls, SUM(cost_usd) AS cost
             FROM {$table} GROUP BY source ORDER BY cost DESC"
        ) ?: [];
    }
}
