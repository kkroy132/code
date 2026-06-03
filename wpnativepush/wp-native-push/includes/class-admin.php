<?php
defined( 'ABSPATH' ) || exit;

class WNP_Admin {

    private const TABS = [
        'dashboard'   => [ 'label' => 'Dashboard',        'icon' => '📊' ],
        'compose'     => [ 'label' => 'Send Notification', 'icon' => '✉️'  ],
        'subscribers' => [ 'label' => 'Subscribers',       'icon' => '👥' ],
        'settings'    => [ 'label' => 'Settings',          'icon' => '⚙️'  ],
    ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'register_widget' ] );
        add_action( 'admin_notices',         [ $this, 'maybe_show_notice' ] );
        add_action( 'admin_post_wnp_save_settings',   [ $this, 'save_settings' ] );
        add_action( 'admin_post_wnp_regenerate_keys', [ $this, 'regenerate_keys' ] );
        add_action( 'wp_ajax_wnp_load_tab',           [ $this, 'ajax_load_tab' ] );
        add_action( 'wp_ajax_wnp_clear_log',          [ $this, 'ajax_clear_log' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'WP Native Push', 'wp-native-push' ),
            __( 'Push Notify',    'wp-native-push' ),
            'manage_options',
            'wp-native-push',
            [ $this, 'render_page' ],
            'dashicons-megaphone',
            80
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_wp-native-push' ) return;

        if ( $this->current_tab() === 'compose' ) {
            wp_enqueue_media();
        }

        wp_enqueue_style( 'wnp-admin', WNP_PLUGIN_URL . 'admin/css/admin.css', [], WNP_VERSION );
        wp_enqueue_script( 'wnp-admin', WNP_PLUGIN_URL . 'admin/js/admin.js', [ 'jquery' ], WNP_VERSION, true );
        wp_localize_script( 'wnp-admin', 'wnpAdmin', [
            'restUrl'   => rest_url( 'wp-native-push/v1' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( 'wnp_ajax_tab' ),
            'pluginUrl' => WNP_PLUGIN_URL,
        ] );
    }

    public function render_page(): void {
        $tab = $this->current_tab();
        ?>
        <div class="wrap wnp-wrap">

            <h1 class="wnp-page-title">
                <span class="wnp-page-title__icon">🔔</span>
                <?php esc_html_e( 'WP Native Push', 'wp-native-push' ); ?>
                <span class="wnp-page-title__ver">v<?php echo esc_html( WNP_VERSION ); ?></span>
            </h1>

            <nav class="nav-tab-wrapper wnp-nav-tab-wrapper">
                <?php foreach ( self::TABS as $slug => $info ) :
                    $url    = add_query_arg( [ 'page' => 'wp-native-push', 'tab' => $slug ], admin_url( 'admin.php' ) );
                    $active = ( $tab === $slug );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="nav-tab <?php echo $active ? 'nav-tab-active' : ''; ?>">
                    <span class="wnp-tab-icon"><?php echo $info['icon']; // phpcs:ignore ?></span>
                    <?php echo esc_html( $info['label'] ); ?>
                    <?php if ( $slug === 'subscribers' ) : ?>
                        <span class="wnp-tab-count"><?php echo esc_html( WNP_Subscriber::count() ); ?></span>
                    <?php endif; ?>
                    <?php if ( $slug === 'dashboard' ) :
                        $pending = array_filter( WNP_Sender::get_queue(), static fn($j) => $j['status'] === 'pending' );
                        if ( count( $pending ) > 0 ) :
                    ?>
                        <span class="wnp-tab-badge"><?php echo esc_html( count( $pending ) ); ?></span>
                    <?php endif; endif; ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <div class="wnp-tab-body" id="wnp-tab-body">
                <div id="wnp-tab-loader" style="display:none;text-align:center;padding:40px;color:#8c8f94;font-size:13px">
                    ⏳ <?php esc_html_e( 'Loading…', 'wp-native-push' ); ?>
                </div>
                <?php
                switch ( $tab ) {
                    case 'compose':     $this->page_compose();     break;
                    case 'subscribers': $this->page_subscribers(); break;
                    case 'settings':    $this->page_settings();    break;
                    default:            $this->page_dashboard();   break;
                }
                ?>
            </div>

        </div>
        <?php
    }

    private function page_dashboard(): void {
        $total_subscribers = WNP_Subscriber::count();
        $queue             = WNP_Sender::get_queue();
        $log               = array_slice( WNP_Sender::get_log(), 0, 30 );
        $recent_jobs       = array_reverse( $queue );
        include WNP_PLUGIN_DIR . 'views/dashboard.php';
    }

    private function page_compose(): void {
        include WNP_PLUGIN_DIR . 'views/compose.php';
    }

    private function page_subscribers(): void {
        $per_page     = 50;
        $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;
        $total        = WNP_Subscriber::count();
        $subscribers  = WNP_Subscriber::get_all( $per_page, $offset );
        $total_pages  = (int) ceil( $total / $per_page );
        include WNP_PLUGIN_DIR . 'views/subscribers.php';
    }

    private function page_settings(): void {
        $public_key  = get_option( 'wnp_vapid_public_key', '' );
        $subject     = get_option( 'wnp_vapid_subject', 'mailto:admin@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
        $popup_title = get_option( 'wnp_popup_title', __( 'Stay Updated!', 'wp-native-push' ) );
        $popup_body  = get_option( 'wnp_popup_body',  __( 'Get notified when we publish new content.', 'wp-native-push' ) );
        $popup_delay = (int) get_option( 'wnp_popup_delay', 3 );
        include WNP_PLUGIN_DIR . 'views/settings.php';
    }

    public function save_settings(): void {
        check_admin_referer( 'wnp_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        $vapid_subject = sanitize_text_field( wp_unslash( $_POST['vapid_subject'] ?? '' ) );
        if ( strpos( $vapid_subject, 'mailto:' ) === 0 ) {
            $vapid_subject = 'mailto:' . sanitize_email( substr( $vapid_subject, 7 ) );
        } elseif ( strpos( $vapid_subject, 'https://' ) === 0 ) {
            $vapid_subject = esc_url_raw( $vapid_subject );
        } else {
            $vapid_subject = '';
        }
        update_option( 'wnp_vapid_subject', $vapid_subject );
        update_option( 'wnp_popup_title',   sanitize_text_field( $_POST['popup_title'] ?? '' ) );
        update_option( 'wnp_popup_body',    sanitize_textarea_field( $_POST['popup_body'] ?? '' ) );
        update_option( 'wnp_popup_delay',   absint( $_POST['popup_delay'] ?? 3 ) );
        wp_safe_redirect( add_query_arg( [ 'page' => 'wp-native-push', 'tab' => 'settings', 'wnp_msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function regenerate_keys(): void {
        check_admin_referer( 'wnp_regenerate_keys' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        try {
            $keys = WNP_Vapid::generate_keys();
            update_option( 'wnp_vapid_public_key',  $keys['public_key'],  false );
            update_option( 'wnp_vapid_private_key', $keys['private_key'], false );
            $msg = 'keys_ok';
        } catch ( \Throwable $e ) {
            $msg = 'keys_err';
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'wp-native-push', 'tab' => 'settings', 'wnp_msg' => $msg ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function register_widget(): void {
        wp_add_dashboard_widget( 'wnp_widget', __( 'Push Notifications', 'wp-native-push' ), [ $this, 'render_widget' ] );
    }

    public function render_widget(): void {
        $total    = WNP_Subscriber::count();
        $queue    = WNP_Sender::get_queue();
        $done     = count( array_filter( $queue, static fn($j) => $j['status'] === 'completed' ) );
        $pending  = count( array_filter( $queue, static fn($j) => $j['status'] === 'pending' ) );
        $tab_url  = static fn($t) => add_query_arg( [ 'page' => 'wp-native-push', 'tab' => $t ], admin_url( 'admin.php' ) );
        ?>
        <ul style="margin:0;line-height:2.2">
            <li>🔔 <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> <?php esc_html_e( 'active subscribers', 'wp-native-push' ); ?></li>
            <li>✅ <strong><?php echo esc_html( $done ); ?></strong> <?php esc_html_e( 'notifications sent', 'wp-native-push' ); ?></li>
            <li>⏳ <strong><?php echo esc_html( $pending ); ?></strong> <?php esc_html_e( 'in queue', 'wp-native-push' ); ?></li>
        </ul>
        <p style="margin-top:10px;display:flex;gap:6px">
            <a href="<?php echo esc_url( $tab_url( 'compose' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Send Notification', 'wp-native-push' ); ?></a>
            <a href="<?php echo esc_url( $tab_url( 'dashboard' ) ); ?>" class="button"><?php esc_html_e( 'View Dashboard', 'wp-native-push' ); ?></a>
        </p>
        <?php
    }

    public function maybe_show_notice(): void {
        $msg = sanitize_key( $_GET['wnp_msg'] ?? '' );
        if ( ! $msg ) return;
        $map = [
            'saved'    => [ 'success', 'Settings saved.' ],
            'keys_ok'  => [ 'warning', 'VAPID keys regenerated. All existing subscribers must re-subscribe.' ],
            'keys_err' => [ 'error',   'Key generation failed. Ensure OpenSSL EC support is available.' ],
        ];
        if ( ! isset( $map[$msg] ) ) return;
        [ $type, $text ] = $map[$msg];
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
    }

    private function current_tab(): string {
        $tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
        return array_key_exists( $tab, self::TABS ) ? $tab : 'dashboard';
    }

    /**
     * AJAX handler — load a tab's HTML content without full page reload.
     * Called by JS via admin-ajax.php?action=wnp_load_tab
     */
    public function ajax_clear_log(): void {
        check_ajax_referer( 'wnp_ajax_tab', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        delete_option( 'wnp_send_log' );
        wp_send_json_success( [ 'cleared' => true ] );
    }

    public function ajax_load_tab(): void {
        check_ajax_referer( 'wnp_ajax_tab', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $tab = sanitize_key( $_POST['tab'] ?? 'dashboard' );

        // Temporarily populate $_GET with sanitized filter params sent via POST
        // so that included view files (subscribers.php, dashboard.php) can read them.
        $passthrough   = [ 's', 'browser', 'device', 'days', 'country', 'paged' ];
        $original_get  = $_GET;
        foreach ( $passthrough as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $_GET[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
        }

        ob_start();

        switch ( $tab ) {
            case 'compose':
                $this->page_compose();
                break;
            case 'subscribers':
                $this->page_subscribers();
                break;
            case 'settings':
                $this->page_settings();
                break;
            default:
                $this->page_dashboard();
                break;
        }

        $html = ob_get_clean();
        $_GET = $original_get; // Restore superglobal after view rendering.
        wp_send_json_success( [ 'html' => $html, 'tab' => $tab ] );
    }
}

// ── CSV export (called via admin-post.php) ────────────────────────────────────
// Registered outside class to avoid duplicate method issue.
add_action( 'admin_post_wnp_export_csv', function () {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wnp_export_csv' ) ) {
        wp_die( 'Forbidden.' );
    }

    $rows = WNP_Subscriber::get_all_for_export();

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="subscribers-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    $out = fopen( 'php://output', 'w' );
    // BOM for Excel UTF-8 compatibility
    fputs( $out, "\xEF\xBB\xBF" );
    // Header row
    fputcsv( $out, [ 'ID', 'Browser', 'Device', 'Endpoint (truncated)', 'User Agent', 'Subscribed At' ] );

    foreach ( $rows as $row ) {
        $ua = $row['user_agent'] ?? '';
        fputcsv( $out, [
            $row['id'],
            ucfirst( WNP_Subscriber::detect_browser( $ua ) ),
            ucfirst( WNP_Subscriber::detect_device( $ua ) ),
            substr( $row['endpoint'], 0, 60 ) . '…',
            $ua,
            $row['created_at'],
        ] );
    }

    fclose( $out );
    exit;
} );
