<?php
/**
 * Harness fidelity audit.
 *
 * The suites run against a model of WordPress, not WordPress. That model is
 * only useful while its gaps are known — a stub that silently returns its
 * input is worse than no stub at all, because every test passes and nothing
 * is exercised. Exactly that happened: `apply_filters()` was `return $value;`,
 * so no test could ever run a filter, and a bug that made the plugin miss
 * every filter-injected link on a real site went unnoticed through 288
 * passing assertions.
 *
 * This audit reports two things and fails on either:
 *
 *   1. WordPress functions the plugin calls that the harness does not define.
 *      Those code paths cannot be under test — they would fatal if reached.
 *   2. Stubs that are inert (ignore their arguments and return a constant)
 *      but are not on the acknowledged list below.
 *
 * Adding to ACKNOWLEDGED_INERT is a deliberate act with a stated reason. That
 * is the whole point: the gap becomes a decision instead of an accident.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';

/**
 * Stubs that may safely do nothing, each with the reason it cannot hide a bug.
 */
const ACKNOWLEDGED_INERT = [
    // Output escaping: the plugin's correctness does not depend on what these
    // return in tests, and using them unescaped would be caught by review.
    'esc_url' => 'escaping is verified by inspection, not behaviour',
    'esc_url_raw' => 'escaping is verified by inspection, not behaviour',
    'esc_html__' => 'escaping is verified by inspection, not behaviour',
    'wp_kses_post' => 'escaping is verified by inspection, not behaviour',

    // Translation: identity is the correct behaviour with no locale loaded.
    '__' => 'identity is correct without a loaded text domain',

    // Admin/UI surface the suites deliberately do not cover. Flagged here so
    // the gap stays visible: these paths are unverified.
    'wp_enqueue_style' => 'admin UI is not covered by these suites',
    'wp_enqueue_script' => 'admin UI is not covered by these suites',
    'wp_localize_script' => 'admin UI is not covered by these suites',
    'wp_create_nonce' => 'nonce verification is not covered by these suites',
    'check_ajax_referer' => 'nonce verification is not covered by these suites',
    'wp_verify_nonce' => 'nonce verification is not covered by these suites',
    'current_user_can' => 'capability checks are not covered by these suites',
    'wp_send_json_success' => 'AJAX envelope is not covered by these suites',
    'wp_send_json_error' => 'AJAX envelope is not covered by these suites',
    'wp_die' => 'terminates a request; not meaningful in the harness',
    'status_header' => 'response headers are not observable in the harness',
    'nocache_headers' => 'response headers are not observable in the harness',
    'wp_redirect' => 'redirect emission is asserted through match(), not output',

    // Scheduling: the suites drive cron entry points directly.
    'wp_next_scheduled' => 'cron handlers are invoked directly by the suites',
    'wp_schedule_event' => 'cron handlers are invoked directly by the suites',
    'wp_schedule_single_event' => 'cron handlers are invoked directly by the suites',
    'wp_clear_scheduled_hook' => 'cron handlers are invoked directly by the suites',
    'wp_get_scheduled_event' => 'cron handlers are invoked directly by the suites',

    // Object cache: the post and meta caches that affect measurements are
    // modelled properly in wpdb-stub.php; this is the generic fallback.
    'wp_cache_get' => 'post and meta caches are modelled explicitly instead',
    'wp_cache_set' => 'post and meta caches are modelled explicitly instead',
    'wp_cache_delete' => 'post and meta caches are modelled explicitly instead',

    // Misc.
    'wp_mail' => 'delivery is not observable in the harness',
    'human_time_diff' => 'formatting only',
    'wp_is_post_revision' => 'no revisions exist in the harness',
    'wp_is_post_autosave' => 'no autosaves exist in the harness',
    'maybe_unserialize' => 'harness stores plain values',
    'get_post_thumbnail_id' => 'no attachments in the harness',
    'wp_get_post_terms' => 'no taxonomies in the harness',
    'attachment_url_to_postid' => 'no attachments in the harness',
    'register_activation_hook' => 'activation is driven directly via WPSD_DB::install()',
    'register_deactivation_hook' => 'deactivation is not covered by these suites',
    'is_admin' => 'the harness is never an admin request',
    'wp_doing_ajax' => 'the harness is never an AJAX request',
    'wp_doing_cron' => 'the harness is never a cron request',
    'mysql2date' => 'formatting only',
    'get_current_screen' => 'admin UI is not covered by these suites',
    'check_admin_referer' => 'nonce verification is not covered by these suites',
    'wp_nonce_field' => 'nonce verification is not covered by these suites',
    'wp_nonce_url' => 'nonce verification is not covered by these suites',
    'wp_safe_redirect' => 'redirect emission is not observable in the harness',
    'paginate_links' => 'admin UI is not covered by these suites',
    'checked' => 'admin UI is not covered by these suites',
    'selected' => 'admin UI is not covered by these suites',
    'add_settings_error' => 'admin UI is not covered by these suites',
    'get_settings_errors' => 'admin UI is not covered by these suites',
    'settings_errors' => 'admin UI is not covered by these suites',
    'esc_html_e' => 'escaping is verified by inspection, not behaviour',
    'esc_attr_e' => 'escaping is verified by inspection, not behaviour',
    'add_menu_page' => 'admin UI is not covered by these suites',
    'add_submenu_page' => 'admin UI is not covered by these suites',

    // Content transforms the harness does not perform. Named here because
    // these are the ones most likely to hide something: a site built out of
    // shortcodes would have content the suites never see expanded.
    //
    // In production the plugin gets these through the `the_content` filter
    // chain, which WordPress runs itself; the plugin's own do_shortcode() and
    // wpautop() calls are only the fallback used when apply_content_filters is
    // switched off. So the exposure is limited to that fallback path — but it
    // is exposure, and it is untested.
    'do_shortcode' => 'shortcode expansion is unverified; production path runs through the_content',
    'wpautop' => 'paragraph wrapping is unverified; production path runs through the_content',
    'remove_accents' => 'transliteration is unverified; affects slug comparison only',
];

$pass = 0;
$fail = 0;

function ok(string $label, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
}

// ─────────────────────────────────── functions the plugin depends on ──

/** @return array<int,string> */
function plugin_php_files(): array {
    $root  = dirname(__DIR__);
    $files = [$root . '/wp-seo-doctor.php', $root . '/uninstall.php'];

    foreach (['includes', 'includes/checks', 'templates'] as $dir) {
        foreach ((array) glob("{$root}/{$dir}/*.php") as $file) {
            $files[] = $file;
        }
    }

    return array_values(array_filter($files, 'is_file'));
}

/**
 * Function names the plugin calls that are neither PHP built-ins, language
 * constructs, nor defined by the plugin itself — i.e. the WordPress surface
 * it relies on.
 *
 * Uses PHP's own tokeniser rather than regex: SQL inside strings ("SUM(",
 * "COALESCE(") and constructor calls ("new WP_Error(") otherwise read as
 * function calls and drown the real findings.
 *
 * @return array<int,string>
 */
function wordpress_functions_used(): array {
    $internal = array_flip(get_defined_functions()['internal']);

    $defined_in_plugin = [];
    $called            = [];

    foreach (plugin_php_files() as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            // What comes before decides whether this is a call at all.
            $previous = null;
            for ($p = $i - 1; $p >= 0; $p--) {
                if (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $previous = $tokens[$p];
                break;
            }

            // Method calls, static calls, declarations and instantiations.
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                if ($previous[0] === T_FUNCTION) {
                    $defined_in_plugin[strtolower($token[1])] = true;
                }
                continue;
            }
            if (is_string($previous) && $previous === '$') {
                continue;
            }

            // Followed by an opening parenthesis, so it is a call.
            $next = null;
            for ($n = $i + 1; $n < $count; $n++) {
                if (is_array($tokens[$n]) && in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $next = $tokens[$n];
                break;
            }
            if ($next !== '(') {
                continue;
            }

            $called[strtolower($token[1])] = true;
        }
    }

    $out = [];
    foreach (array_keys($called) as $name) {
        if (isset($internal[$name]) || isset($defined_in_plugin[$name])) {
            continue;
        }
        $out[] = $name;
    }

    sort($out);

    return $out;
}

echo "\n── Every WordPress function the plugin calls is modelled\n";

$used      = wordpress_functions_used();
$undefined = array_values(array_filter($used, static fn($name) => !function_exists($name)));

printf("  %d WordPress functions referenced by the plugin\n", count($used));

ok(
    'no plugin code path calls an unmodelled function',
    $undefined === [],
    $undefined ? 'missing from the harness: ' . implode(', ', $undefined) : ''
);

// ──────────────────────────────────────────── inert stub detection ──

/**
 * Top-level stub functions whose body ignores every argument and returns a
 * constant, or hands an argument straight back.
 *
 * Class methods are skipped: WP_Query::init() being empty is faithful, not a
 * gap.
 *
 * @return array<string,string> name => body
 */
function inert_stubs(): array {
    $inert = [];

    foreach ([__DIR__ . '/wp-stubs.php', __DIR__ . '/wpdb-stub.php', __DIR__ . '/bootstrap.php'] as $file) {
        $source = (string) file_get_contents($file);
        $tokens = token_get_all($source);
        $count  = count($tokens);
        $depth  = 0;
        $in_class_until = -1;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') { $depth++; }
                if ($token === '}') {
                    $depth--;
                    if ($depth <= $in_class_until) { $in_class_until = -1; }
                }
                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $in_class_until = $depth;
                continue;
            }

            if ($token[0] !== T_FUNCTION || $in_class_until >= 0) {
                continue;
            }

            // function NAME (args) { body }
            $name = null;
            for ($n = $i + 1; $n < $count; $n++) {
                if (is_array($tokens[$n]) && $tokens[$n][0] === T_WHITESPACE) { continue; }
                if (is_array($tokens[$n]) && $tokens[$n][0] === T_STRING) { $name = $tokens[$n][1]; }
                break;
            }
            if ($name === null) {
                continue;
            }

            $open = strpos($source, '{', (int) strpos($source, $name . '(', $token[2] > 0 ? 0 : 0));
            // Re-locate precisely from the token stream instead of the string.
            $args = '';
            $body = '';
            $paren = 0;
            $brace = 0;
            $stage = 'args';
            for ($n = $i + 1; $n < $count; $n++) {
                $t = $tokens[$n];
                $text = is_array($t) ? $t[1] : $t;

                if ($stage === 'args') {
                    if ($text === '(') { $paren++; if ($paren === 1) { continue; } }
                    if ($text === ')') { $paren--; if ($paren === 0) { $stage = 'pre-body'; continue; } }
                    if ($paren >= 1) { $args .= $text; }
                    continue;
                }
                if ($stage === 'pre-body') {
                    if ($text === '{') { $stage = 'body'; $brace = 1; }
                    if ($text === ';') { break; }
                    continue;
                }
                if ($text === '{') { $brace++; }
                if ($text === '}') {
                    $brace--;
                    if ($brace === 0) { break; }
                }
                $body .= $text;
            }

            $body = trim($body);

            // Class methods reach here when brace tracking loses the class
            // boundary; only a real top-level function can be a stub.
            if (!function_exists($name)) {
                continue;
            }

            if ($body === '') {
                $inert[$name] = '(empty)';
                continue;
            }
            // Any constant: 'test-nonce' is no more useful than true.
            if (preg_match('/^return\s+(true|false|null|\[\]|-?\d+(\.\d+)?|\'[^\']*\'|"[^"]*")\s*;$/', $body)) {
                $inert[$name] = $body;
                continue;
            }
            // An argument handed straight back, with or without a cast.
            if (preg_match('/^return\s+(?:\((?:string|int|float|bool|array)\)\s*)?(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*;$/', $body, $ret)) {
                if (strpos($args, $ret[1]) !== false) {
                    $inert[$name] = $body;
                }
            }
        }
    }

    return $inert;
}

echo "\n── Inert stubs are acknowledged, not accidental\n";

$inert        = inert_stubs();
$unacknowledged = array_diff_key($inert, ACKNOWLEDGED_INERT);

printf("  %d inert stubs, %d acknowledged\n", count($inert), count($inert) - count($unacknowledged));

$detail = '';
foreach ($unacknowledged as $name => $body) {
    $detail .= "\n      {$name}(): {$body}";
}

ok(
    'no unacknowledged inert stub',
    $unacknowledged === [],
    $unacknowledged
        ? 'add these to ACKNOWLEDGED_INERT with a reason, or make them behave:' . $detail
        : ''
);

// Entries that no longer correspond to a stub are stale documentation.
$stale = array_diff(array_keys(ACKNOWLEDGED_INERT), array_keys($inert));
ok(
    'the acknowledged list has no stale entries',
    $stale === [],
    $stale ? 'no longer inert (or no longer defined): ' . implode(', ', $stale) : ''
);

// ────────────────────────────────── behaviour the harness must model ──

echo "\n── Load-bearing behaviour is really modelled\n";

// Each of these was, at some point, an inert stub that hid a real defect or
// invalidated a measurement.
$filter_ran = false;
add_filter('wpsd_fidelity_probe', static function ($value) use (&$filter_ran) {
    $filter_ran = true;
    return $value . '-filtered';
});
$result = apply_filters('wpsd_fidelity_probe', 'input');
ok('apply_filters() actually runs callbacks', $filter_ran && $result === 'input-filtered', var_export($result, true));

$action_ran = false;
add_action('wpsd_fidelity_action', static function () use (&$action_ran) {
    $action_ran = true;
});
do_action('wpsd_fidelity_action');
ok('do_action() actually runs callbacks', $action_ran);

set_transient('wpsd_fidelity', ['a' => 1], 60);
ok('transients round-trip', get_transient('wpsd_fidelity') === ['a' => 1]);
delete_transient('wpsd_fidelity');
ok('transients can be deleted', get_transient('wpsd_fidelity') === false);

update_option('wpsd_fidelity_option', 'stored');
ok('options round-trip', get_option('wpsd_fidelity_option') === 'stored');

add_filter('pre_http_request', static fn($p, $a, $u) => [
    'response' => ['code' => 201],
    'body'     => 'probe-body',
    'headers'  => ['x-probe' => 'yes'],
], 10, 3);
$response = WPSD_Helpers::request('https://example.test/probe', ['method' => 'GET']);
ok('HTTP requests can be served by pre_http_request', $response['status'] === 201 && $response['body'] === 'probe-body',
    json_encode($response));
remove_all_filters('pre_http_request');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
