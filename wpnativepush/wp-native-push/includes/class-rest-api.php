<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API endpoints under /wp-json/wp-native-push/v1/
 */
class WNP_Rest_Api {

    const NS               = 'wp-native-push/v1';
    const RATE_LIMIT_MAX   = 5;    // max subscribe attempts per IP per window
    const RATE_LIMIT_SECS  = 300;  // 5-minute window

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        // ── Public endpoints ──────────────────────────────────────────────────

        /**
         * Subscribe — NO nonce required.
         *
         * WHY: WordPress nonces depend on the session cookie. Chrome 80+ blocks
         * cookies set without SameSite=None;Secure on many hosting setups, causing
         * every nonce check to fail for anonymous visitors. Since this endpoint
         * only stores a push endpoint URL (not sensitive data), nonce-free is safe.
         * We protect against abuse with IP-based rate limiting instead.
         */
        register_rest_route( self::NS, '/subscribe', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'subscribe' ],
            'permission_callback' => '__return_true',
            'args'                => $this->subscription_args(),
        ] );

        register_rest_route( self::NS, '/unsubscribe', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'unsubscribe' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'endpoint' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ] );

        register_rest_route( self::NS, '/public-key', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_public_key' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Admin endpoints (capability-gated, nonce via WP REST cookie auth) ─

        register_rest_route( self::NS, '/send', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'send' ],
            'permission_callback' => [ $this, 'is_admin' ],
            'args'                => $this->send_args(),
        ] );

        register_rest_route( self::NS, '/subscribers/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_subscriber' ],
            'permission_callback' => [ $this, 'is_admin' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => 'is_numeric',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    // ── Callbacks ─────────────────────────────────────────────────────────────

    public function subscribe( WP_REST_Request $req ): WP_REST_Response {

        // ── Rate limiting (replaces nonce for anonymous public endpoint) ──────
        if ( $this->is_rate_limited() ) {
            return $this->error( 'Too many subscription attempts. Please wait a few minutes.', 429 );
        }

        $endpoint = (string) $req->get_param( 'endpoint' );

        if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            return $this->error( 'Invalid endpoint URL.', 400 );
        }

        // Verify the endpoint is from a known push service domain.
        if ( ! $this->is_valid_push_endpoint( $endpoint ) ) {
            return $this->error( 'Endpoint must be a valid push service URL.', 400 );
        }

        $is_renewal = (bool) $req->get_param( 'renew' );

        // Handle subscription renewal (from pushsubscriptionchange SW event).
        if ( $is_renewal && WNP_Subscriber::endpoint_exists( $endpoint ) ) {
            WNP_Subscriber::update_keys( $endpoint, [
                'public_key' => (string) $req->get_param( 'public_key' ),
                'auth_token' => (string) $req->get_param( 'auth_token' ),
            ] );
            return new WP_REST_Response( [ 'success' => true, 'renewed' => true ], 200 );
        }

        // Already subscribed — idempotent.
        if ( WNP_Subscriber::endpoint_exists( $endpoint ) ) {
            return new WP_REST_Response( [ 'message' => 'Already subscribed.', 'success' => true ], 200 );
        }

        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        // Detect visitor's country from IP.
        $client_ip = $this->get_client_ip();
        $geo       = WNP_Subscriber::detect_country( $client_ip );

        $id = WNP_Subscriber::save( [
            'endpoint'     => $endpoint,
            'public_key'   => (string) $req->get_param( 'public_key' ),
            'auth_token'   => (string) $req->get_param( 'auth_token' ),
            'user_agent'   => $user_agent,
            'country'      => $geo['code'],
            'country_name' => $geo['name'],
        ] );

        if ( ! $id ) {
            return $this->error( 'Could not save subscription.', 500 );
        }

        $this->record_rate_limit();

        return new WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 );
    }

    public function unsubscribe( WP_REST_Request $req ): WP_REST_Response {
        // Unsubscribe needs to work even with cookies blocked.
        // The endpoint URL itself is the secret — it's only known to the subscribing browser.
        $endpoint = (string) $req->get_param( 'endpoint' );
        if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            return $this->error( 'Invalid endpoint URL.', 400 );
        }
        WNP_Subscriber::delete_by_endpoint( $endpoint );
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function get_public_key( WP_REST_Request $req ): WP_REST_Response {
        $key = get_option( 'wnp_vapid_public_key' );
        if ( ! $key ) {
            return $this->error( 'VAPID keys not generated.', 500 );
        }
        return new WP_REST_Response( [ 'publicKey' => $key ], 200 );
    }

    public function send( WP_REST_Request $req ): WP_REST_Response {
        $notification = [
            'title' => sanitize_text_field( (string) $req->get_param( 'title' ) ),
            'body'  => sanitize_textarea_field( (string) $req->get_param( 'body' ) ),
            'icon'  => esc_url_raw( (string) $req->get_param( 'icon' ) ),
            'url'   => esc_url_raw( (string) ( $req->get_param( 'url' ) ?: home_url() ) ),
        ];

        if ( empty( $notification['title'] ) || empty( $notification['body'] ) ) {
            return $this->error( 'Title and body are required.', 400 );
        }

        $total = WNP_Subscriber::count();
        if ( $total === 0 ) {
            return $this->error( 'No subscribers to send to.', 400 );
        }

        WNP_Sender::queue( $notification );

        return new WP_REST_Response( [
            'success' => true,
            'message' => sprintf( 'Queued for %d subscriber(s).', $total ),
        ], 200 );
    }

    public function delete_subscriber( WP_REST_Request $req ): WP_REST_Response {
        $id      = absint( $req->get_param( 'id' ) );
        $deleted = WNP_Subscriber::delete( $id );
        return $deleted
            ? new WP_REST_Response( [ 'success' => true ], 200 )
            : $this->error( 'Subscriber not found.', 404 );
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public function is_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /**
     * Check if the current IP has exceeded the subscribe rate limit.
     * Uses transients so it works without cookies.
     */
    private function is_rate_limited(): bool {
        $key   = 'wnp_rl_' . md5( $this->get_client_ip() );
        $count = (int) get_transient( $key );
        return $count >= self::RATE_LIMIT_MAX;
    }

    private function record_rate_limit(): void {
        $key   = 'wnp_rl_' . md5( $this->get_client_ip() );
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, self::RATE_LIMIT_SECS );
    }

    private function get_client_ip(): string {
        // Check common proxy headers first (works behind Cloudflare / load balancers).
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',    // Reverse proxy / CDN
            'HTTP_X_REAL_IP',          // Nginx
            'REMOTE_ADDR',             // Direct connection
        ];
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', wp_unslash( $_SERVER[ $h ] ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Allow-list of known push service domains.
     * Prevents arbitrary URLs from being stored as endpoints.
     */
    private function is_valid_push_endpoint( string $endpoint ): bool {
        $allowed = [
            'fcm.googleapis.com',           // Chrome / Edge (FCM)
            'updates.push.services.mozilla.com', // Firefox
            'push.services.mozilla.com',
            'notify.windows.com',           // Edge legacy
            'wns2',                         // WNS partial match
            'api.push.apple.com',           // Safari / iOS
        ];

        $host = wp_parse_url( $endpoint, PHP_URL_HOST );
        if ( ! $host ) {
            return false;
        }

        foreach ( $allowed as $allowed_domain ) {
            if ( str_contains( $host, $allowed_domain ) ) {
                return true;
            }
        }

        // Allow custom/self-hosted push servers if the admin has configured them.
        $extra = apply_filters( 'wnp_allowed_push_domains', [] );
        foreach ( $extra as $domain ) {
            if ( str_contains( $host, $domain ) ) {
                return true;
            }
        }

        return false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function error( string $message, int $code ): WP_REST_Response {
        return new WP_REST_Response( [ 'error' => $message ], $code );
    }

    private function subscription_args(): array {
        return [
            'endpoint'   => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'esc_url_raw' ],
            'public_key' => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'auth_token' => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'renew'      => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
        ];
    }

    private function send_args(): array {
        return [
            'title' => [ 'required' => true,  'type' => 'string' ],
            'body'  => [ 'required' => true,  'type' => 'string' ],
            'icon'  => [ 'required' => false, 'type' => 'string', 'default' => '' ],
            'url'   => [ 'required' => false, 'type' => 'string', 'default' => '' ],
        ];
    }
}

// ── Test notification endpoint (added below class for clean append) ──────────

// Register the test route — hooked separately since class is already instantiated.
add_action( 'rest_api_init', function () {
    register_rest_route( 'wp-native-push/v1', '/test', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => function ( WP_REST_Request $req ): WP_REST_Response {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_REST_Response( [ 'error' => 'Unauthorized.' ], 403 );
            }

            $title = sanitize_text_field( (string) $req->get_param( 'title' ) );
            $body  = sanitize_textarea_field( (string) $req->get_param( 'body' ) );
            $icon  = esc_url_raw( (string) $req->get_param( 'icon' ) );
            $url   = esc_url_raw( (string) ( $req->get_param( 'url' ) ?: home_url() ) );
            $limit = absint( $req->get_param( 'limit' ) ?: 1 );

            if ( empty( $title ) || empty( $body ) ) {
                return new WP_REST_Response( [ 'error' => 'Title and body required.' ], 400 );
            }

            $subscribers = WNP_Subscriber::get_batch( $limit, 0 );
            if ( empty( $subscribers ) ) {
                return new WP_REST_Response( [ 'error' => 'No subscribers found.' ], 400 );
            }

            $payload = (string) wp_json_encode( [ 'title' => $title, 'body' => $body, 'icon' => $icon, 'url' => $url ] );
            $sent    = 0;

            foreach ( $subscribers as $sub ) {
                try {
                    $enc  = WNP_Encryption::encrypt( $payload, $sub );
                    $hdrs = WNP_Vapid::get_headers( $sub['endpoint'] );
                    $resp = wp_remote_post( $sub['endpoint'], [
                        'method'  => 'POST',
                        'headers' => array_merge( $hdrs, [ 'Content-Type' => 'application/octet-stream', 'Content-Encoding' => 'aes128gcm', 'TTL' => '300' ] ),
                        'body'    => $enc['ciphertext'],
                        'timeout' => 15,
                    ] );
                    if ( ! is_wp_error( $resp ) ) {
                        $code = (int) wp_remote_retrieve_response_code( $resp );
                        if ( in_array( $code, [ 200, 201, 202 ], true ) ) $sent++;
                    }
                } catch ( \Throwable $e ) {
                    // skip on error
                }
            }

            return new WP_REST_Response( [ 'success' => $sent > 0, 'sent' => $sent ], 200 );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ] );
} );

// ── Bulk-delete REST endpoint ─────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
    register_rest_route( 'wp-native-push/v1', '/subscribers/bulk-delete', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => function ( WP_REST_Request $req ): WP_REST_Response {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_REST_Response( [ 'error' => 'Unauthorized.' ], 403 );
            }
            $ids     = $req->get_param( 'ids' );
            if ( ! is_array( $ids ) || empty( $ids ) ) {
                return new WP_REST_Response( [ 'error' => 'No IDs provided.' ], 400 );
            }
            $deleted = WNP_Subscriber::bulk_delete( $ids );
            return new WP_REST_Response( [ 'success' => true, 'deleted' => $deleted ], 200 );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'args' => [
            'ids' => [ 'required' => true, 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
        ],
    ] );
} );

// ── Process batch via REST (AJAX-friendly dashboard button) ───────────────────
add_action( 'rest_api_init', function () {
    register_rest_route( 'wp-native-push/v1', '/process-now', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => function ( WP_REST_Request $req ): WP_REST_Response {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_REST_Response( [ 'error' => 'Unauthorized.' ], 403 );
            }
            WNP_Sender::process_batch();
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Batch processed.' ], 200 );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ] );
} );
