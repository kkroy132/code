<?php
/**
 * Plugin Name: WP Native Push
 * Plugin URI:  https://github.com/your-handle/wp-native-push
 * Description: Self-hosted Web Push Notifications via VAPID. Zero third-party dependencies.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0+
 * Text Domain: wp-native-push
 * Requires PHP: 7.3
 */

defined( 'ABSPATH' ) || exit;

define( 'WNP_VERSION',     '1.0.0' );
define( 'WNP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WNP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WNP_PLUGIN_FILE', __FILE__ );

/**
 * Autoloader: WNP_Foo_Bar → includes/class-foo-bar.php
 */
spl_autoload_register( static function ( string $class ): void {
    if ( strncmp( 'WNP_', $class, 4 ) !== 0 ) {
        return;
    }
    $file = WNP_PLUGIN_DIR . 'includes/class-'
          . strtolower( str_replace( '_', '-', substr( $class, 4 ) ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Lifecycle hooks.
register_activation_hook( WNP_PLUGIN_FILE,   [ 'WNP_Activator', 'activate' ] );
register_deactivation_hook( WNP_PLUGIN_FILE, [ 'WNP_Activator', 'deactivate' ] );

/**
 * Fix: Add CORS and SameSite headers for the REST API so that browsers running
 * with third-party cookie restrictions can still POST to /wp-json/wp-native-push/.
 *
 * This runs before WordPress sends any headers.
 */
add_filter( 'rest_pre_serve_request', static function ( bool $served ): bool {

    $request_uri = isset( $_SERVER['REQUEST_URI'] )
        ? wp_unslash( $_SERVER['REQUEST_URI'] )
        : '';

    // Only modify headers for our own REST namespace.
    if ( strpos( $request_uri, 'wp-native-push/v1' ) === false ) {
        return $served;
    }

    // Allow cross-origin requests from the same site (needed when
    // WordPress is on a subdomain or behind a reverse proxy).
    $origin = isset( $_SERVER['HTTP_ORIGIN'] )
        ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) )
        : '';

    $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

    if ( $origin ) {
        $origin_host = wp_parse_url( $origin, PHP_URL_HOST );
        // Allow same domain or same root domain (subdomain).
        if ( $origin_host && (
            $origin_host === $site_host ||
            str_ends_with( $origin_host, '.' . $site_host )
        ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-WNP-Nonce, X-WP-Nonce' );
        }
    }

    return $served;
} );

/**
 * Handle OPTIONS preflight requests for our REST endpoints.
 */
add_action( 'init', static function (): void {
    $request_uri = isset( $_SERVER['REQUEST_URI'] )
        ? wp_unslash( $_SERVER['REQUEST_URI'] )
        : '';

    if (
        isset( $_SERVER['REQUEST_METHOD'] ) &&
        $_SERVER['REQUEST_METHOD'] === 'OPTIONS' &&
        strpos( $request_uri, 'wp-native-push/v1' ) !== false
    ) {
        header( 'Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, X-WNP-Nonce, X-WP-Nonce' );
        header( 'Access-Control-Max-Age: 86400' );
        status_header( 204 );
        exit;
    }
} );

/**
 * Boot the plugin.
 */
function wnp_boot(): void {
    new WNP_Rest_Api();
    new WNP_Public();
    if ( is_admin() ) {
        new WNP_Admin();
    }
}
add_action( 'plugins_loaded', 'wnp_boot' );
