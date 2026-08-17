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

// ── A real hook system, so filter-injected content is actually exercised ──

$GLOBALS['wp_filter_stub'] = [];

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['wp_filter_stub'][$tag][$priority][] = $callback;
    return true;
}

function apply_filters($tag, $value, ...$args) {
    if (empty($GLOBALS['wp_filter_stub'][$tag])) {
        return $value;
    }
    $hooks = $GLOBALS['wp_filter_stub'][$tag];
    ksort($hooks);
    foreach ($hooks as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = call_user_func($callback, $value, ...$args);
        }
    }
    return $value;
}

function add_action($tag, $callback = null, $priority = 10, $accepted_args = 1) {
    return $callback ? add_filter($tag, $callback, $priority, $accepted_args) : true;
}

function do_action($tag, ...$args) {
    if (empty($GLOBALS['wp_filter_stub'][$tag])) {
        return;
    }
    $hooks = $GLOBALS['wp_filter_stub'][$tag];
    ksort($hooks);
    foreach ($hooks as $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func($callback, ...$args);
        }
    }
}

function remove_all_filters($tag) {
    unset($GLOBALS['wp_filter_stub'][$tag]);
}

// ── Query context, so filters guarded by is_single() behave like on a real page ──

class WP_Query {
    public $posts = [];
    public $post = null;
    public $post_count = 0;
    public $found_posts = 0;
    public $in_the_loop = false;
    public $queried_object = null;
    public $queried_object_id = 0;
    public $is_single = false;
    public $is_page = false;
    public $is_singular = false;
    public $is_home = false;
    public $is_404 = false;
    public $is_archive = false;
    public $current_post = -1;
    public $is_admin = false;
    public $query_vars = [];

    public function init() {}
    public function is_single() { return (bool) $this->is_single; }
    public function is_page() { return (bool) $this->is_page; }
    public function is_singular() { return (bool) $this->is_singular; }
    public function is_main_query() { return true; }
    public function get($var, $default = '') { return $this->query_vars[$var] ?? $default; }
}

$GLOBALS['wp_query'] = new WP_Query();
$GLOBALS['post'] = null;

function is_single() { return isset($GLOBALS['wp_query']) && $GLOBALS['wp_query']->is_single(); }
function is_page() { return isset($GLOBALS['wp_query']) && $GLOBALS['wp_query']->is_page(); }
function is_singular($types = '') { return isset($GLOBALS['wp_query']) && $GLOBALS['wp_query']->is_singular(); }
function is_admin() { return false; }
function is_404() { return isset($GLOBALS['wp_query']) && (bool) $GLOBALS['wp_query']->is_404; }

function get_the_ID() {
    return isset($GLOBALS['post']) && $GLOBALS['post'] ? (int) $GLOBALS['post']->ID : 0;
}

function setup_postdata($post) {
    if ($post instanceof WP_Post) { $GLOBALS['post'] = $post; }
    return true;
}

function wp_reset_postdata() {
    if (isset($GLOBALS['wp_query']) && $GLOBALS['wp_query']->post) {
        $GLOBALS['post'] = $GLOBALS['wp_query']->post;
    }
    return true;
}
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

/**
 * Honours `pre_http_request` exactly as core does, so tests can serve
 * responses without any special-casing in the plugin. Anything not served
 * that way fails, since the harness has no network.
 */
function wp_remote_request($url, $args = []) {
    $short_circuit = apply_filters('pre_http_request', false, $args, $url);
    if ($short_circuit !== false) {
        return $short_circuit;
    }
    return new WP_Error('offline', 'No network in the harness.');
}
function wp_remote_get($url, $args = []) { return wp_remote_request($url, $args); }
function wp_remote_post($url, $args = []) { return wp_remote_request($url, $args); }

function wp_remote_retrieve_response_code($r) {
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0;
}
function wp_remote_retrieve_body($r) {
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}
function wp_remote_retrieve_header($r, $h) {
    if (!is_array($r) || empty($r['headers'])) { return ''; }
    $headers = array_change_key_case((array) $r['headers']);
    return (string) ($headers[strtolower($h)] ?? '');
}
function wp_remote_retrieve_headers($r) {
    return is_array($r) ? (array) ($r['headers'] ?? []) : [];
}
