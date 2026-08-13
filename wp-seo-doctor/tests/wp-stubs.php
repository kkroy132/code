<?php
/**
 * Minimal WordPress stubs — just enough to exercise WP SEO Doctor's pure logic
 * outside of WordPress. Not a WP emulation; anything DB-backed is out of scope.
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MINUTE_IN_SECONDS', 60);
define('ENT_QUOTES_COMPAT', ENT_QUOTES);

define('WPSD_VERSION', '1.0.0');
define('WPSD_FILE', __DIR__ . '/wp-seo-doctor.php');
define('WPSD_DIR', __DIR__ . '/');
define('WPSD_URL', 'https://example.test/wp-content/plugins/wp-seo-doctor/');
define('WPSD_SLUG', 'wp-seo-doctor');

$GLOBALS['wpsd_stub_options'] = [];
$GLOBALS['wpsd_stub_filters'] = [];

function __($text, $domain = '') { return $text; }
function _n($single, $plural, $number, $domain = '') { return $number === 1 ? $single : $plural; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t) { return (string) $t; }
function esc_url_raw($t) { return (string) $t; }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_textarea_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_key($t) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $t)); }
function sanitize_title($t) { return preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) $t)); }
function sanitize_html_class($t) { return preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $t); }
function absint($v) { return abs((int) $v); }
function wp_unslash($v) {
    if (is_array($v)) { return array_map('wp_unslash', $v); }
    return is_string($v) ? stripslashes($v) : $v;
}
function wp_slash($v) {
    if (is_array($v)) { return array_map('wp_slash', $v); }
    return is_string($v) ? addslashes($v) : $v;
}
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function wp_rand($min = 0, $max = 1) { return random_int($min, $max); }
function remove_accents($t) { return (string) $t; }
function wp_json_encode($v) { return json_encode($v); }
function _doing_it_wrong($f, $m, $v) { throw new RuntimeException("doing_it_wrong: {$f} {$m}"); }

function home_url($path = '/') { return rtrim('https://example.test', '/') . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function get_bloginfo($k) { return $k === 'name' ? 'Example Site' : ''; }
function set_url_scheme($url, $scheme) { return preg_replace('#^https?://#', $scheme . '://', $url); }

function wp_parse_url($url, $component = -1) {
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function wp_parse_args($args, $defaults = []) {
    return array_merge($defaults, (array) $args);
}

function apply_filters($tag, $value) {
    return $value;
}
function add_filter() { return true; }
function add_action() { return true; }
function do_action() { return true; }
function has_blocks($c) { return strpos((string) $c, '<!-- wp:') !== false; }
function do_blocks($c) { return preg_replace('/<!--\s*\/?wp:.*?-->/s', '', (string) $c); }
function do_shortcode($c) { return (string) $c; }
function strip_shortcodes($c) { return preg_replace('/\[[^\]]*\]/', ' ', (string) $c); }
function wp_strip_all_tags($c) { return trim(strip_tags((string) $c)); }
function wpautop($c) { return (string) $c; }
function wp_list_pluck($list, $field) {
    return array_map(static fn($i) => is_array($i) ? ($i[$field] ?? null) : ($i->$field ?? null), (array) $list);
}

function get_option($k, $default = false) { return $GLOBALS['wpsd_stub_options'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['wpsd_stub_options'][$k] = $v; return true; }
function add_option($k, $v, $d = '', $a = null) { $GLOBALS['wpsd_stub_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['wpsd_stub_options'][$k]); return true; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function delete_transient($k) { return true; }
function current_time($type = 'mysql') { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
function get_post_types($args = [], $output = 'names') { return ['post' => 'post', 'page' => 'page']; }
function post_type_exists($t) { return in_array($t, ['post', 'page'], true); }
function class_exists_stub() { return false; }

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

function wp_remote_request($url, $args = []) { return new WP_Error('offline', 'No network in the harness.'); }
function wp_remote_get($url, $args = []) { return wp_remote_request($url, $args); }
function wp_remote_post($url, $args = []) { return wp_remote_request($url, $args); }
function wp_remote_retrieve_response_code($r) { return 0; }
function wp_remote_retrieve_body($r) { return ''; }
function wp_remote_retrieve_header($r, $h) { return ''; }
function wp_remote_retrieve_headers($r) { return []; }
