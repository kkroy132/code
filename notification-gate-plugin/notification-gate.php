<?php
/**
 * Plugin Name: Notification Gate
 * Plugin URI:  https://github.com/kkroy132/code
 * Description: Users must enable push notifications to view content. After allowing, they are redirected to a configured URL.
 * Version:     1.0.0
 * Author:      kkroy132
 * License:     GPL-2.0+
 * Text Domain: notification-gate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NGP_VERSION', '1.0.0' );
define( 'NGP_DIR', plugin_dir_path( __FILE__ ) );
define( 'NGP_URL', plugin_dir_url( __FILE__ ) );

require_once NGP_DIR . 'includes/class-ngp-admin.php';
require_once NGP_DIR . 'includes/class-ngp-frontend.php';

function ngp_init() {
    new NGP_Admin();
    new NGP_Frontend();
}
add_action( 'plugins_loaded', 'ngp_init' );

register_activation_hook( __FILE__, 'ngp_activate' );
function ngp_activate() {
    $defaults = array(
        'redirect_url'      => home_url(),
        'headline'          => 'একটু অপেক্ষা করুন!',
        'description'       => 'এই পেজটি দেখতে হলে আপনাকে Push Notification চালু করতে হবে। নিচের বাটনে ক্লিক করুন।',
        'button_text'       => '🔔 Notification চালু করুন',
        'denied_message'    => 'আপনি Notification ব্লক করেছেন। পেজটি দেখতে অনুগ্রহ করে ব্রাউজার সেটিংস থেকে Notification Allow করুন।',
        'bg_color'          => '#0f0f0f',
        'text_color'        => '#ffffff',
        'button_color'      => '#e63946',
        'auto_prompt'       => '1',
    );
    add_option( 'ngp_settings', $defaults );
}
