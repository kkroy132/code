<?php
/**
 * Smoke test for WP SEO Doctor's pure logic.
 */

error_reporting(E_ALL);
require __DIR__ . '/wp-stubs.php';

// Logic-only suite: no database, so the modules it exercises are loaded
// directly rather than through bootstrap.php. That means supplying the
// constants the plugin's main file would otherwise define.
define('WPSD_VERSION', '1.0.1');
define('WPSD_FILE', dirname(__DIR__) . '/wp-seo-doctor.php');
define('WPSD_DIR', dirname(__DIR__) . '/');
define('WPSD_URL', 'https://example.test/wp-content/plugins/wp-seo-doctor/');
define('WPSD_SLUG', 'wp-seo-doctor');

$plugin = dirname(__DIR__) . '/includes/';
foreach (['helpers', 'settings', 'score', 'redirects', 'export'] as $module) {
    require_once $plugin . $module . '.php';
}

$pass = 0;
$fail = 0;

function check(string $label, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

function truthy(string $label, $actual): void {
    global $pass, $fail;
    if ($actual) { $pass++; return; }
    $fail++;
    echo "FAIL {$label}: got " . var_export($actual, true) . "\n";
}

// ── URL normalisation ──
check('normalize strips www + trailing slash',
    WPSD_Helpers::normalize_url('https://WWW.Example.test/Foo/'),
    'https://example.test/Foo');
check('normalize drops utm params',
    WPSD_Helpers::normalize_url('https://example.test/a?utm_source=x&b=2'),
    'https://example.test/a?b=2');
check('normalize keeps root slash',
    WPSD_Helpers::normalize_url('https://example.test/'),
    'https://example.test/');

check('absolutize root-relative',
    WPSD_Helpers::absolutize('/about', 'https://example.test/blog/post/'),
    'https://example.test/about');
check('absolutize document-relative',
    WPSD_Helpers::absolutize('next', 'https://example.test/blog/post'),
    'https://example.test/blog/next');
check('absolutize protocol-relative',
    WPSD_Helpers::absolutize('//cdn.test/x.png'),
    'https://cdn.test/x.png');
check('absolutize leaves mailto alone',
    WPSD_Helpers::absolutize('mailto:a@b.test'),
    'mailto:a@b.test');

truthy('internal URL detected', WPSD_Helpers::is_internal_url('https://www.example.test/x'));
truthy('external URL detected', !WPSD_Helpers::is_internal_url('https://other.test/x'));
truthy('relative URL is internal', WPSD_Helpers::is_internal_url('/x'));
truthy('mailto is non-http', WPSD_Helpers::is_non_http('mailto:a@b.test'));
truthy('fragment is non-http', WPSD_Helpers::is_non_http('#section'));
truthy('https is http-ish', !WPSD_Helpers::is_non_http('https://a.test'));

// ── HTML parsing ──
$html = '<h1>Title</h1><p>Some <a href="/inner" rel="nofollow">inner link</a> and '
      . '<a href="https://out.test/x">outbound</a>.</p><h3>Skipped</h3>'
      . '<img src="/a.png" alt="described"><img src="/b.png">';

$links = WPSD_Helpers::extract_links($html, 'https://example.test/post/');
check('two links extracted', count($links), 2);
check('link absolutized', $links[0]['url'], 'https://example.test/inner');
truthy('nofollow flagged', $links[0]['nofollow']);
check('anchor text captured', $links[0]['anchor'], 'inner link');

$headings = WPSD_Helpers::extract_headings($html);
check('two headings', count($headings), 2);
check('h1 level', $headings[0]['level'], 1);
check('h3 level', $headings[1]['level'], 3);

$images = WPSD_Helpers::extract_images($html, 'https://example.test/post/');
check('two images', count($images), 2);
truthy('first image has alt', $images[0]['has_alt']);
truthy('second image missing alt', !$images[1]['has_alt']);

// ── Text analysis ──
check('word count', WPSD_Helpers::word_count('<p>one two three four</p>'), 4);
check('shortcodes excluded from count', WPSD_Helpers::word_count('[gallery ids="1,2"] one two'), 2);
check('keyword density', WPSD_Helpers::keyword_density('alpha beta alpha gamma', 'alpha'), 50.0);

$a = 'the quick brown fox jumps over the lazy dog again and again for testing purposes';
truthy('identical text is fully similar', WPSD_Helpers::similarity($a, $a) === 1.0);
truthy('unrelated text is dissimilar', WPSD_Helpers::similarity($a, 'completely different words here with nothing shared at all ok') < 0.1);

$keywords = WPSD_Helpers::keywords_from_text('WordPress performance tuning for WordPress sites with caching');
truthy('stopwords excluded', !isset($keywords['with']));
truthy('repeated term ranked first', array_key_first($keywords) === 'wordpress');

truthy('pixel width grows with length',
    WPSD_Helpers::pixel_width('mmmmmmmmmm') > WPSD_Helpers::pixel_width('iiiiiiiiii'));
check('truncate adds ellipsis', WPSD_Helpers::truncate('abcdefghij', 5), 'abcd…');
check('truncate leaves short strings', WPSD_Helpers::truncate('abc', 5), 'abc');

// ── Severity ──
check('critical outweighs low',
    WPSD_Helpers::severity_weight('critical') > WPSD_Helpers::severity_weight('low'), true);

// ── Score ──
check('grade A', WPSD_Score::grade(95), 'A');
check('grade F', WPSD_Score::grade(10), 'F');
truthy('score colour is a hex value', preg_match('/^#[0-9a-f]{6}$/i', WPSD_Score::color(85)) === 1);

// ── Settings ──
$clean = WPSD_Settings::sanitize([
    'scan_batch_size'   => '9999',
    'request_timeout'   => '1',
    'title_min'         => '25',
    'scan_schedule'     => 'nonsense',
    'report_email'      => 'not-an-email',
    'post_types'        => ['post', 'page'],
    'monitor_404'       => '1',
    'duplicate_threshold' => '5',
]);
check('batch size clamped', $clean['scan_batch_size'], 200);
check('timeout floored', $clean['request_timeout'], 3);
check('int setting kept', $clean['title_min'], 25);
check('bad schedule rejected', $clean['scan_schedule'], 'disabled');
check('bad email cleared', $clean['report_email'], '');
check('array setting kept', $clean['post_types'], ['post', 'page']);
check('checkbox on', $clean['monitor_404'], true);
check('absent checkbox off', $clean['email_reports'], false);
check('float clamped', $clean['duplicate_threshold'], 1.0);

// ── Redirect sources ──
check('source normalised from full URL',
    WPSD_Redirects::normalize_source('https://example.test/old-page/'), '/old-page');
check('source normalised from bare path',
    WPSD_Redirects::normalize_source('old-page'), '/old-page');
check('query string preserved',
    WPSD_Redirects::normalize_source('/x/?a=1'), '/x?a=1');
check('root stays root', WPSD_Redirects::normalize_source('/'), '/');

truthy('valid regex compiles', WPSD_Redirects::compile_regex('^/blog/(.+)$') !== '');
truthy('delimited regex compiles', WPSD_Redirects::compile_regex('/^\/blog\/(.+)$/i') !== '');
check('invalid regex rejected', WPSD_Redirects::compile_regex('/^[unclosed/'), '');

$test = WPSD_Redirects::test('/old', '/new', 'exact', '/old/');
truthy('exact rule matches', $test['matches']);
check('exact target absolutized', $test['target'], 'https://example.test/new');

$test = WPSD_Redirects::test('^/blog/(.+)$', '/articles/$1', 'regex', '/blog/hello-world');
truthy('regex rule matches', $test['matches']);
check('capture group substituted', $test['target'], 'https://example.test/articles/hello-world');

$test = WPSD_Redirects::test('^/blog/(.+)$', '/articles/$1', 'regex', '/shop/thing');
truthy('regex rule does not over-match', !$test['matches']);

$test = WPSD_Redirects::test('/^[bad/', '/x', 'regex', '/y');
truthy('invalid regex reported', $test['error'] !== '');

// ── PDF writer ──
$pdf = WPSD_Export::render_pdf([
    'title'     => 'SEO Audit Report',
    'site'      => 'Example Site',
    'url'       => 'https://example.test/',
    'generated' => '2026-08-13 10:00:00',
    'meta'      => ['Health score' => '72/100', 'Open issues' => 41],
    'columns'   => ['Severity', 'Issue', 'URL'],
    'rows'      => array_map(
        static fn($i) => ['High', 'Missing meta description — café ünïcode', 'https://example.test/page-' . $i],
        range(1, 120)
    ),
]);
truthy('PDF has header', strpos($pdf, '%PDF-1.4') === 0);
truthy('PDF has trailer', substr($pdf, -5) === '%%EOF');
truthy('PDF paginated past one page', substr_count($pdf, '/Type /Page') > 2);
truthy('PDF declares an xref', strpos($pdf, "\nxref\n") !== false);
truthy('PDF is non-trivial in size', strlen($pdf) > 5000);

// The xref offsets must actually point at their objects, or readers reject it.
preg_match('/startxref\s+(\d+)/', $pdf, $m);
truthy('startxref points inside the file', isset($m[1]) && (int) $m[1] < strlen($pdf));
truthy('startxref lands on the xref table', substr($pdf, (int) $m[1], 4) === 'xref');

preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);
$offsets_ok = true;
foreach ($offsets[1] as $offset) {
    if (!preg_match('/^\d+ 0 obj/', substr($pdf, (int) $offset, 20))) {
        $offsets_ok = false;
    }
}
truthy('every xref offset points at an object header', $offsets_ok);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
