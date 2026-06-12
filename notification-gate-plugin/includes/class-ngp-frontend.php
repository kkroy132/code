<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NGP_Frontend {

    private $settings;

    public function __construct() {
        $this->settings = get_option( 'ngp_settings' );
        add_shortcode( 'notification_gate', array( $this, 'render_gate' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'notification_gate' ) ) {
            wp_enqueue_style( 'ngp-style', NGP_URL . 'assets/css/gate.css', array(), NGP_VERSION );
            wp_enqueue_script( 'ngp-gate', NGP_URL . 'assets/js/gate.js', array(), NGP_VERSION, true );
            wp_localize_script( 'ngp-gate', 'NGP', array(
                'redirect_url' => esc_url( $this->settings['redirect_url'] ),
                'auto_prompt'  => $this->settings['auto_prompt'],
            ) );
        }
    }

    public function render_gate( $atts ) {
        $s            = $this->settings;
        $redirect_url = esc_url( $s['redirect_url'] );
        $headline     = esc_html( $s['headline'] );
        $description  = esc_html( $s['description'] );
        $button_text  = esc_html( $s['button_text'] );
        $denied_msg   = esc_html( $s['denied_message'] );
        $bg           = esc_attr( $s['bg_color'] );
        $text_c       = esc_attr( $s['text_color'] );
        $btn_c        = esc_attr( $s['button_color'] );

        ob_start();
        ?>
        <style>
            #ngp-gate-wrapper {
                background-color: <?php echo $bg; ?>;
                color: <?php echo $text_c; ?>;
            }
            #ngp-allow-btn {
                background-color: <?php echo $btn_c; ?>;
            }
            #ngp-allow-btn:hover {
                filter: brightness(1.15);
            }
        </style>

        <div id="ngp-gate-wrapper" class="ngp-gate-wrapper">

            <!-- Lock screen (default visible) -->
            <div id="ngp-lock-screen" class="ngp-screen ngp-lock-screen">
                <div class="ngp-icon">🔔</div>
                <h2><?php echo $headline; ?></h2>
                <p><?php echo $description; ?></p>
                <button id="ngp-allow-btn" class="ngp-btn">
                    <?php echo $button_text; ?>
                </button>
            </div>

            <!-- Denied screen (hidden by default) -->
            <div id="ngp-denied-screen" class="ngp-screen ngp-denied-screen" style="display:none;">
                <div class="ngp-icon">🚫</div>
                <h2>অ্যাক্সেস ব্লক করা হয়েছে</h2>
                <p><?php echo $denied_msg; ?></p>
                <button id="ngp-retry-btn" class="ngp-btn">
                    🔄 আবার চেষ্টা করুন
                </button>
            </div>

            <!-- Loading screen (hidden by default) -->
            <div id="ngp-loading-screen" class="ngp-screen ngp-loading-screen" style="display:none;">
                <div class="ngp-spinner"></div>
                <p>Redirecting...</p>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
