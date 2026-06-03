<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frontend assets, the service-worker rewrite, and the popup HTML.
 */
class WNP_Public {

    public function __construct() {
        add_action( 'init',               [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars',         [ $this, 'add_query_var' ] );
        add_action( 'parse_request',      [ $this, 'serve_sw' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_footer',          [ $this, 'render_popup' ], 100 );
    }

    public function add_rewrite_rule(): void {
        add_rewrite_rule( '^wnp-service-worker\.js$', 'index.php?wnp_sw=1', 'top' );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = 'wnp_sw';
        return $vars;
    }

    /**
     * Serve the Service Worker JS file from the site root.
     *
     * BUG FIX #8 — Call ob_end_clean() before sending headers + readfile()
     * so that output buffering from caching plugins (W3TC, LiteSpeed, etc.)
     * does not prepend buffered HTML to the JavaScript response.
     */
    public function serve_sw( WP $wp ): void {
        if ( empty( $wp->query_vars['wnp_sw'] ) ) {
            return;
        }

        $file = WNP_PLUGIN_DIR . 'public/service-worker.js';

        if ( ! file_exists( $file ) ) {
            status_header( 404 );
            exit;
        }

        // Flush and disable any active output buffers.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/javascript; charset=UTF-8' );
        header( 'Service-Worker-Allowed: /' );
        header( 'Content-Length: ' . (string) filesize( $file ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile( $file );
        exit;
    }

    public function enqueue(): void {
        if ( ! get_option( 'wnp_vapid_public_key' ) ) {
            return;
        }

        wp_enqueue_style(
            'wnp-popup',
            WNP_PLUGIN_URL . 'public/css/popup.css',
            [],
            WNP_VERSION
        );

        wp_enqueue_script(
            'wnp-sub',
            WNP_PLUGIN_URL . 'public/js/subscription.js',
            [],
            WNP_VERSION,
            true
        );

        wp_localize_script( 'wnp-sub', 'wnpConfig', [
            'swUrl'      => home_url( '/wnp-service-worker.js' ),
            'restUrl'    => rest_url( 'wp-native-push/v1' ),
            'nonce'      => wp_create_nonce( 'wnp_nonce' ),
            'publicKey'  => (string) get_option( 'wnp_vapid_public_key', '' ),
            'popupTitle' => (string) get_option( 'wnp_popup_title', __( 'Stay Updated!', 'wp-native-push' ) ),
            'popupBody'  => (string) get_option( 'wnp_popup_body',  __( 'Allow notifications to get the latest updates.', 'wp-native-push' ) ),
            'delay'      => (int) get_option( 'wnp_popup_delay', 3 ),
        ] );
    }

    public function render_popup(): void {
        if ( ! get_option( 'wnp_vapid_public_key' ) ) {
            return;
        }
        ?>
        <div id="wnp-popup"
             class="wnp-popup"
             role="dialog"
             aria-modal="true"
             aria-labelledby="wnp-popup-title"
             style="display:none;">
            <div class="wnp-popup__inner">
                <div class="wnp-popup__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                </div>
                <div class="wnp-popup__content">
                    <p class="wnp-popup__title" id="wnp-popup-title"></p>
                    <p class="wnp-popup__body"  id="wnp-popup-body"></p>
                </div>
                <div class="wnp-popup__actions">
                    <button id="wnp-allow-btn"   class="wnp-btn wnp-btn--primary">
                        <?php esc_html_e( 'Allow Notifications', 'wp-native-push' ); ?>
                    </button>
                    <button id="wnp-dismiss-btn" class="wnp-btn wnp-btn--ghost">
                        <?php esc_html_e( 'No thanks', 'wp-native-push' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
