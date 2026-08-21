<?php
/**
 * SSRF protection on the outbound HTTP layer.
 *
 * The scanner fetches URLs it discovers in post content, sitemaps and Location
 * headers. Without a gate, the plugin is a request proxy into whatever the web
 * server can reach: cloud metadata endpoints, internal dashboards, databases
 * on the private network.
 *
 * The opposite mistake is just as damaging and much easier to make: blocking
 * private addresses outright breaks every local and staging install, where the
 * site itself lives on 127.0.0.1 or a LAN address. Both directions are pinned
 * here.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';

global $wpdb;

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

function blocked(string $url): bool {
    return is_wp_error(WPSD_Helpers::validate_request_url($url));
}

echo "\n── Address classification\n";

$private = [
    '127.0.0.1', '127.10.20.30', '10.0.0.1', '10.255.255.254',
    '172.16.0.1', '172.31.255.254', '192.168.1.1', '169.254.169.254',
    '0.0.0.0', '100.64.0.1', '100.127.255.254',
    '::1', '::', 'fe80::1', 'fc00::1', 'fd00::1', 'fd00:ec2::254',
    '::ffff:127.0.0.1', '::ffff:10.0.0.1',
];
foreach ($private as $ip) {
    ok("blocked: {$ip}", WPSD_Helpers::is_blocked_ip($ip));
}

$public = ['8.8.8.8', '1.1.1.1', '93.184.216.34', '172.32.0.1', '100.63.255.255', '2606:4700::1111'];
foreach ($public as $ip) {
    ok("allowed: {$ip}", !WPSD_Helpers::is_blocked_ip($ip));
}

ok('a non-IP string is blocked', WPSD_Helpers::is_blocked_ip('not-an-ip'));
ok('an empty string is blocked', WPSD_Helpers::is_blocked_ip(''));

echo "\n── Schemes\n";

foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd',
          'ftp://ftp.example.org/x', 'gopher://example.org/', 'php://filter/read=x',
          'dict://127.0.0.1:11211/'] as $url) {
    ok('rejected: ' . substr($url, 0, 28), blocked($url));
}

echo "\n── Direct requests to internal addresses\n";

foreach ([
    'http://127.0.0.1/wp-admin/',
    'http://localhost/secret',
    'http://[::1]/',
    'http://10.0.0.5/admin',
    'http://192.168.1.1/router',
    'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
    'http://metadata.google.internal/computeMetadata/v1/',
    'http://100.100.100.200/latest/meta-data/',
    'http://box.local/',
] as $url) {
    ok('rejected: ' . substr($url, 0, 46), blocked($url));
}

echo "\n── The site's own host is always reachable\n";

// This is the case that breaks local and staging installs if the guard is
// written as a blanket private-address ban.
ok('own host allowed', WPSD_Helpers::validate_request_url(home_url('/')) === true,
    'the scanner cannot read its own site');
ok('own host with a path allowed', WPSD_Helpers::validate_request_url(home_url('/some-post/')) === true);
ok('www variant of own host allowed',
    WPSD_Helpers::validate_request_url('https://www.example.test/page/') === true);

// A site actually served from localhost must still be scannable.
$GLOBALS['wpsd_stub_options']['home'] = 'http://127.0.0.1:8080';
$GLOBALS['wpsd_stub_options']['siteurl'] = 'http://127.0.0.1:8080';
ok('a site hosted on 127.0.0.1 can still scan itself',
    WPSD_Helpers::is_own_host('127.0.0.1'),
    'local installs would be unable to scan');
unset($GLOBALS['wpsd_stub_options']['home'], $GLOBALS['wpsd_stub_options']['siteurl']);

echo "\n── The allowlist filter\n";

add_filter('wpsd_allowed_request_hosts', static fn($hosts) => array_merge($hosts, ['internal-tool.example']));
ok('an explicitly allowed host passes',
    WPSD_Helpers::validate_request_url('https://internal-tool.example/x') === true);
remove_all_filters('wpsd_allowed_request_hosts');

echo "\n── request() refuses without issuing the call\n";

$attempted = false;
add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$attempted) {
    $attempted = true;
    return ['response' => ['code' => 200], 'body' => 'SHOULD NOT HAPPEN', 'headers' => []];
}, 10, 3);

$response = WPSD_Helpers::request('http://169.254.169.254/latest/meta-data/');
ok('no HTTP call was made', !$attempted, 'the request layer reached the network');
ok('reported as blocked', !empty($response['blocked']));
ok('status is zero', $response['status'] === 0);
ok('a reason is given', $response['error'] !== '');

$attempted = false;
$response = WPSD_Helpers::request(home_url('/allowed/'), ['method' => 'GET']);
ok('a legitimate request still goes through', $attempted && $response['status'] === 200,
    json_encode($response['status']));
remove_all_filters('pre_http_request');

echo "\n── Redirect chains are validated at every hop\n";

// A public URL that redirects to the metadata endpoint must not be followed.
add_filter('pre_http_request', static function ($preempt, $args, $url) {
    if (strpos($url, '169.254.169.254') !== false) {
        return ['response' => ['code' => 200], 'body' => 'CREDENTIALS', 'headers' => []];
    }
    return [
        'response' => ['code' => 302],
        'body'     => '',
        'headers'  => ['location' => 'http://169.254.169.254/latest/meta-data/'],
    ];
}, 10, 3);

$trace = WPSD_Helpers::trace_redirects(home_url('/redirects-inward/'));
$reached = false;
foreach ($trace['chain'] as $hop) {
    if (strpos($hop['url'], '169.254.169.254') !== false && $hop['status'] === 200) {
        $reached = true;
    }
}
ok('a redirect into the metadata endpoint is not followed', !$reached, json_encode($trace['chain']));
remove_all_filters('pre_http_request');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
