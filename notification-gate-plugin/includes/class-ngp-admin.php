<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NGP_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_menu() {
        add_options_page(
            'Notification Gate Settings',
            'Notification Gate',
            'manage_options',
            'notification-gate',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'ngp_settings_group', 'ngp_settings', array( $this, 'sanitize' ) );
    }

    public function sanitize( $input ) {
        $clean = array();
        $clean['redirect_url']   = esc_url_raw( $input['redirect_url'] );
        $clean['headline']       = sanitize_text_field( $input['headline'] );
        $clean['description']    = sanitize_textarea_field( $input['description'] );
        $clean['button_text']    = sanitize_text_field( $input['button_text'] );
        $clean['denied_message'] = sanitize_textarea_field( $input['denied_message'] );
        $clean['bg_color']       = sanitize_hex_color( $input['bg_color'] );
        $clean['text_color']     = sanitize_hex_color( $input['text_color'] );
        $clean['button_color']   = sanitize_hex_color( $input['button_color'] );
        $clean['auto_prompt']    = isset( $input['auto_prompt'] ) ? '1' : '0';
        return $clean;
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_notification-gate' !== $hook ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_style( 'ngp-admin-style', NGP_URL . 'assets/css/admin.css', array(), NGP_VERSION );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script( 'ngp-admin', NGP_URL . 'assets/js/admin.js', array( 'wp-color-picker' ), NGP_VERSION, true );
    }

    public function settings_page() {
        $settings = get_option( 'ngp_settings' );
        ?>
        <div class="wrap ngp-admin-wrap">
            <h1>🔔 Notification Gate Settings</h1>
            <div class="ngp-admin-layout">
                <div class="ngp-admin-main">
                    <form method="post" action="options.php">
                        <?php settings_fields( 'ngp_settings_group' ); ?>

                        <div class="ngp-card">
                            <h2>Redirect Settings</h2>
                            <table class="form-table">
                                <tr>
                                    <th><label for="redirect_url">Redirect URL</label></th>
                                    <td>
                                        <input type="url" id="redirect_url" name="ngp_settings[redirect_url]"
                                            value="<?php echo esc_attr( $settings['redirect_url'] ); ?>"
                                            class="regular-text" required />
                                        <p class="description">Notification Allow করার পর ব্যবহারকারী এই URL-এ redirect হবে।</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="auto_prompt">Auto Prompt</label></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="auto_prompt" name="ngp_settings[auto_prompt]"
                                                value="1" <?php checked( $settings['auto_prompt'], '1' ); ?> />
                                            পেজ লোড হওয়ার সাথে সাথে স্বয়ংক্রিয়ভাবে notification permission চাও
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="ngp-card">
                            <h2>Page Content</h2>
                            <table class="form-table">
                                <tr>
                                    <th><label for="headline">Headline</label></th>
                                    <td>
                                        <input type="text" id="headline" name="ngp_settings[headline]"
                                            value="<?php echo esc_attr( $settings['headline'] ); ?>"
                                            class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="description">Description</label></th>
                                    <td>
                                        <textarea id="description" name="ngp_settings[description]"
                                            rows="3" class="large-text"><?php echo esc_textarea( $settings['description'] ); ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="button_text">Button Text</label></th>
                                    <td>
                                        <input type="text" id="button_text" name="ngp_settings[button_text]"
                                            value="<?php echo esc_attr( $settings['button_text'] ); ?>"
                                            class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="denied_message">Denied Message</label></th>
                                    <td>
                                        <textarea id="denied_message" name="ngp_settings[denied_message]"
                                            rows="3" class="large-text"><?php echo esc_textarea( $settings['denied_message'] ); ?></textarea>
                                        <p class="description">ব্যবহারকারী notification block করলে এই বার্তা দেখাবে।</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="ngp-card">
                            <h2>Design / Colors</h2>
                            <table class="form-table">
                                <tr>
                                    <th><label for="bg_color">Background Color</label></th>
                                    <td><input type="text" id="bg_color" name="ngp_settings[bg_color]"
                                        value="<?php echo esc_attr( $settings['bg_color'] ); ?>" class="ngp-color-picker" /></td>
                                </tr>
                                <tr>
                                    <th><label for="text_color">Text Color</label></th>
                                    <td><input type="text" id="text_color" name="ngp_settings[text_color]"
                                        value="<?php echo esc_attr( $settings['text_color'] ); ?>" class="ngp-color-picker" /></td>
                                </tr>
                                <tr>
                                    <th><label for="button_color">Button Color</label></th>
                                    <td><input type="text" id="button_color" name="ngp_settings[button_color]"
                                        value="<?php echo esc_attr( $settings['button_color'] ); ?>" class="ngp-color-picker" /></td>
                                </tr>
                            </table>
                        </div>

                        <?php submit_button( 'Settings সেভ করুন' ); ?>
                    </form>
                </div>

                <div class="ngp-admin-sidebar">
                    <div class="ngp-card">
                        <h2>How to Use</h2>
                        <p>যেকোনো Page বা Post-এ এই shortcode ব্যবহার করুন:</p>
                        <code class="ngp-shortcode">[notification_gate]</code>
                        <hr>
                        <p><strong>কী হবে:</strong></p>
                        <ul>
                            <li>✅ Allow → Redirect URL-এ যাবে</li>
                            <li>❌ Deny → Blocked message দেখাবে</li>
                        </ul>
                    </div>
                    <div class="ngp-card">
                        <h2>Tips</h2>
                        <ul>
                            <li>একটি নতুন <strong>WordPress Page</strong> তৈরি করুন।</li>
                            <li>Page-এ <code>[notification_gate]</code> shortcode যোগ করুন।</li>
                            <li>সেই Page-এর URL শেয়ার করুন।</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
