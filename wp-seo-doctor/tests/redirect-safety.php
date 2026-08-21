<?php
/**
 * Redirect target and regex safety.
 *
 * The redirect manager takes two pieces of attacker-shaped input from an
 * administrator: a destination and a regular expression. A destination can
 * carry a script scheme; a regex can hang every front-end request. Both are
 * validated on save, and both are re-checked at request time so a rule stored
 * before these checks existed cannot slip through.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';

global $wpdb;
wpsd_test_reset_schema();

$pass = 0;
$fail = 0;

function ok(string $label, $condition, $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n── Dangerous redirect targets are refused on save\n";

$dangerous = [
    'javascript:alert(document.cookie)',
    'JaVaScRiPt:alert(1)',
    "java\tscript:alert(1)",
    " javascript:alert(1)",
    'data:text/html;base64,PHNjcmlwdD4=',
    'file:///etc/passwd',
    'php://filter/resource=wp-config.php',
    'vbscript:msgbox(1)',
    "https://ok.example/\r\nSet-Cookie: x=1",
    '//evil.example/phish',
    'relative-without-slash',
];

foreach ($dangerous as $index => $target) {
    $result = WPSD_Redirects::create([
        'source' => '/danger-' . $index,
        'target' => $target,
    ]);
    ok('refused: ' . substr(str_replace(["\r", "\n", "\t"], '', $target), 0, 34), is_wp_error($result),
        is_wp_error($result) ? '' : 'ACCEPTED');
}

echo "\n── Legitimate targets still work\n";

$legitimate = [
    '/relative/path/',
    'https://example.test/internal/',
    'https://external.example/partner-page/',
    'http://external.example/legacy',
];

foreach ($legitimate as $index => $target) {
    $result = WPSD_Redirects::create([
        'source' => '/fine-' . $index,
        'target' => $target,
    ]);
    ok('accepted: ' . substr($target, 0, 40), is_int($result), is_wp_error($result) ? $result->get_error_message() : '');
}

$gone = WPSD_Redirects::create(['source' => '/removed', 'code' => 410]);
ok('410 with no target accepted', is_int($gone), is_wp_error($gone) ? $gone->get_error_message() : '');

echo "\n── update() validates too\n";

$id = WPSD_Redirects::create(['source' => '/mutable', 'target' => '/safe']);
$result = WPSD_Redirects::update((int) $id, ['target' => 'javascript:alert(1)']);
ok('a dangerous target cannot be introduced by an edit', is_wp_error($result));
ok('the stored target is unchanged',
    $wpdb->get_var("SELECT target FROM " . WPSD_DB::table('redirects') . " WHERE id = " . (int) $id) === '/safe');

echo "\n── A rule stored before validation existed is not emitted\n";

// Write straight to the table, bypassing create().
$wpdb->insert(WPSD_DB::table('redirects'), [
    'source' => '/legacy-bad', 'source_hash' => md5('/legacy-bad'),
    'target' => 'javascript:alert(1)', 'code' => 301, 'match_type' => 'exact',
    'enabled' => 1, 'created_at' => WPSD_Helpers::now(), 'updated_at' => WPSD_Helpers::now(),
]);
WPSD_Redirects::flush_cache();

$match = WPSD_Redirects::match('/legacy-bad');
ok('the rule is not honoured at request time', $match === null,
    is_array($match) ? 'resolved to ' . $match[1] : '');

// A safe rule sitting behind it must still be reachable.
$wpdb->insert(WPSD_DB::table('redirects'), [
    'source' => '/legacy-good', 'source_hash' => md5('/legacy-good'),
    'target' => '/destination', 'code' => 301, 'match_type' => 'exact',
    'enabled' => 1, 'created_at' => WPSD_Helpers::now(), 'updated_at' => WPSD_Helpers::now(),
]);
WPSD_Redirects::flush_cache();
$match = WPSD_Redirects::match('/legacy-good');
ok('a safe legacy rule is unaffected',
    is_array($match) && $match[1] === 'https://example.test/destination',
    json_encode($match[1] ?? null));

echo "\n── Regex patterns are validated\n";

$bad_regex = [
    '(a+)+$'            => 'nested quantifier',
    '(.*)*x'            => 'nested star',
    '([a-z]+)+@'        => 'nested plus on a class',
    '/^[unclosed/'      => 'will not compile',
    str_repeat('a', 600) => 'absurdly long',
];

foreach ($bad_regex as $pattern => $why) {
    $result = WPSD_Redirects::validate_regex($pattern);
    ok("rejected ({$why})", is_wp_error($result), 'ACCEPTED: ' . substr($pattern, 0, 30));
}

$good_regex = ['^/blog/(.+)$', '^/product/([0-9]+)/?$', '#^/Shop/(.+)#i', '^/tag/([a-z0-9-]+)$'];
foreach ($good_regex as $pattern) {
    ok('accepted: ' . $pattern, WPSD_Redirects::validate_regex($pattern) === true);
}

echo "\n── A regex rule cannot be saved with a dangerous pattern\n";

$result = WPSD_Redirects::create([
    'source' => '(x+)+y', 'target' => '/somewhere', 'match_type' => 'regex',
]);
ok('create() refuses it', is_wp_error($result), is_wp_error($result) ? '' : 'ACCEPTED');

echo "\n── Capture groups still substitute correctly\n";

$id = WPSD_Redirects::create([
    'source' => '^/news/([0-9]{4})/(.+)$', 'target' => '/archive/$1/$2', 'match_type' => 'regex',
]);
ok('regex rule created', is_int($id), is_wp_error($id) ? $id->get_error_message() : '');

WPSD_Redirects::flush_cache();
$match = WPSD_Redirects::match('/news/2024/a-story');
ok('both groups substituted',
    is_array($match) && $match[1] === 'https://example.test/archive/2024/a-story',
    json_encode($match[1] ?? null));

echo "\n── Matching is bounded, not unbounded\n";

// Even if a pathological pattern reaches the matcher, one request must not
// burn the CPU indefinitely.
$wpdb->insert(WPSD_DB::table('redirects'), [
    'source' => '^(a+)+b$', 'source_hash' => md5('^(a+)+b$'),
    'target' => '/x', 'code' => 301, 'match_type' => 'regex',
    'enabled' => 1, 'created_at' => WPSD_Helpers::now(), 'updated_at' => WPSD_Helpers::now(),
]);
WPSD_Redirects::flush_cache();

$started = microtime(true);
$match   = WPSD_Redirects::match('/' . str_repeat('a', 40) . 'c');
$elapsed = microtime(true) - $started;

printf("  matched in %.3fs\n", $elapsed);
ok('a pathological stored pattern returns quickly', $elapsed < 2.0, sprintf('%.2fs', $elapsed));
ok('and does not fatal', $match === null || is_array($match));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
