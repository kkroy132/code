<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation, and initial setup.
 */
class WNP_Activator {

    public static function activate(): void {
        self::require_php();
        self::create_table();
        self::generate_vapid_keys();
        self::schedule_cron();

        add_rewrite_rule( '^wnp-service-worker\.js$', 'index.php?wnp_sw=1', 'top' );
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'wnp_process_batch' );
        flush_rewrite_rules();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function require_php(): void {
        if ( version_compare( PHP_VERSION, '7.3', '<' ) ) {
            deactivate_plugins( WNP_PLUGIN_FILE );
            wp_die(
                esc_html__( 'WP Native Push requires PHP 7.3 or higher.', 'wp-native-push' ),
                '',
                [ 'back_link' => true ]
            );
        }
        if ( ! extension_loaded( 'openssl' ) ) {
            deactivate_plugins( WNP_PLUGIN_FILE );
            wp_die(
                esc_html__( 'WP Native Push requires the PHP OpenSSL extension with EC key support.', 'wp-native-push' ),
                '',
                [ 'back_link' => true ]
            );
        }
    }

    private static function create_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'push_subscribers';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  endpoint text NOT NULL,
  public_key varchar(255) NOT NULL,
  auth_token varchar(255) NOT NULL,
  user_agent text,
  country varchar(2) NOT NULL DEFAULT '',
  country_name varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint(191))
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Migration: add country columns to existing installations.
        self::maybe_add_country_columns( $table );

        update_option( 'wnp_db_version', WNP_VERSION );
    }

    /**
     * Safely add country columns if they don't exist yet (handles upgrades from older versions).
     */
    private static function maybe_add_country_columns( string $table ): void {
        global $wpdb;
        $columns = $wpdb->get_col( "DESC `{$table}`", 0 ); // phpcs:ignore
        if ( ! in_array( 'country', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `country` varchar(2) NOT NULL DEFAULT '' AFTER `auth_token`" ); // phpcs:ignore
        }
        if ( ! in_array( 'country_name', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `country_name` varchar(100) NOT NULL DEFAULT '' AFTER `country`" ); // phpcs:ignore
        }
    }

    private static function generate_vapid_keys(): void {
        if ( get_option( 'wnp_vapid_public_key' ) ) {
            return;
        }
        $keys = WNP_Vapid::generate_keys();
        update_option( 'wnp_vapid_public_key',  $keys['public_key'],  false );
        update_option( 'wnp_vapid_private_key', $keys['private_key'], false );
    }

    private static function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'wnp_process_batch' ) ) {
            wp_schedule_event( time(), 'wnp_every_minute', 'wnp_process_batch' );
        }
    }
}

// Register the one-minute interval.
add_filter( 'cron_schedules', static function ( array $schedules ): array {
    $schedules['wnp_every_minute'] = [
        'interval' => 60,
        'display'  => __( 'Every Minute (WP Native Push)', 'wp-native-push' ),
    ];
    return $schedules;
} );
