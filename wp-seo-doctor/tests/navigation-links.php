<?php
/**
 * Navigation and boilerplate links.
 *
 * The link graph is built from content-area links only, so a page reached from
 * the theme's main menu or footer — and from nowhere else — was reported as an
 * orphan. Crawl depth meanwhile seeded from the homepage's full HTML, so the
 * two features disagreed about whether navigation counts.
 *
 * Responses are served through `pre_http_request`, the same short-circuit core
 * offers, so the plugin's own HTTP path runs unchanged.
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

// ────────────────────────────────────────────────────────── fixtures ──

foreach (array_keys(WPSD_DB::TABLES) as $key) {
    $wpdb->query('DROP TABLE IF EXISTS ' . WPSD_DB::table($key));
}
$wpdb->query('DROP TABLE IF EXISTS wp_posts, wp_postmeta');
$wpdb->query("CREATE TABLE wp_posts (
    ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_title TEXT, post_content LONGTEXT, post_excerpt TEXT,
    post_name VARCHAR(200) DEFAULT '', post_type VARCHAR(20) DEFAULT 'post',
    post_status VARCHAR(20) DEFAULT 'publish',
    post_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    post_modified DATETIME DEFAULT CURRENT_TIMESTAMP,
    post_modified_gmt DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ID), KEY idx_name (post_name)
) DEFAULT CHARACTER SET utf8mb4");
$wpdb->query("CREATE TABLE wp_postmeta (
    meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    meta_key VARCHAR(255), meta_value LONGTEXT,
    PRIMARY KEY (meta_id), KEY idx_post (post_id), KEY idx_key (meta_key(191))
) DEFAULT CHARACTER SET utf8mb4");
WPSD_DB::install();

/**
 * Six pages. `about` and `contact` are reached ONLY from the theme's menu and
 * footer — never from any post's content. `hidden` is reached from nowhere at
 * all, and is the only genuine orphan.
 */
$fixtures = [
    ['Guide One',   'guide-1', '<p>See <a href="https://example.test/guide-2/">guide two</a>. ' . str_repeat('Body text for the first guide. ', 40) . '</p>'],
    ['Guide Two',   'guide-2', '<p>See <a href="https://example.test/guide-1/">guide one</a>. ' . str_repeat('Body text for the second guide. ', 40) . '</p>'],
    ['Guide Three', 'guide-3', '<p>See <a href="https://example.test/guide-1/">guide one</a>. ' . str_repeat('Body text for the third guide. ', 40) . '</p>'],
    ['About Us',    'about',   '<p>' . str_repeat('Who we are and what we do here. ', 40) . '</p>'],
    ['Contact',     'contact', '<p>' . str_repeat('How to reach the team by email. ', 40) . '</p>'],
    ['Hidden Page', 'hidden',  '<p>' . str_repeat('Nothing anywhere links to this page. ', 40) . '</p>'],
];

foreach ($fixtures as [$title, $slug, $content]) {
    $wpdb->insert('wp_posts', [
        'post_title' => $title, 'post_name' => $slug, 'post_content' => $content,
        'post_excerpt' => 'Excerpt for ' . $title,
        'post_type' => 'post', 'post_status' => 'publish',
        'post_date' => gmdate('Y-m-d H:i:s'), 'post_modified' => gmdate('Y-m-d H:i:s'),
        'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
    ]);
}
$post_ids = array_map('intval', $wpdb->get_col('SELECT ID FROM wp_posts ORDER BY ID'));
$by_slug  = [];
foreach ($wpdb->get_results('SELECT ID, post_name FROM wp_posts') as $row) {
    $by_slug[$row->post_name] = (int) $row->ID;
}

/**
 * Serve every page with the same chrome: a menu and footer that link to
 * about/contact on every single page, plus the page's own content.
 */
$chrome = '<nav class="main-menu">'
    . '<a href="https://example.test/">Home</a>'
    . '<a href="https://example.test/about/">About Us</a>'
    . '<a href="https://example.test/contact/">Contact</a>'
    . '</nav>';
$footer = '<footer><a href="https://example.test/contact/">Contact</a>'
    . '<a href="https://example.test/privacy/">Privacy</a></footer>';

$served = 0;
add_filter('pre_http_request', static function ($preempt, $args, $url) use ($chrome, $footer, $by_slug, &$served) {
    $served++;
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');

    $body = '';
    if ($path === '') {
        // Homepage: chrome plus links to the three guides.
        $body = '<html><head><title>Home</title></head><body>' . $chrome
            . '<main><a href="https://example.test/guide-1/">Guide One</a>'
            . '<a href="https://example.test/guide-2/">Guide Two</a>'
            . '<a href="https://example.test/guide-3/">Guide Three</a></main>'
            . $footer . '</body></html>';
    } elseif (isset($by_slug[$path])) {
        $post = get_post($by_slug[$path]);
        $body = '<html><head><title>' . $post->post_title . '</title></head><body>'
            . $chrome . '<article>' . $post->post_content . '</article>' . $footer . '</body></html>';
    } else {
        return ['response' => ['code' => 404], 'body' => '', 'headers' => []];
    }

    return ['response' => ['code' => 200], 'body' => $body, 'headers' => ['content-type' => 'text/html']];
}, 10, 3);

foreach ($post_ids as $id) {
    WPSD_Internal_Links::index_post(get_post($id));
}

// ───────────────────────────────────────────────────────────── tests ──

echo "\n── Site chrome is detected by sampling rendered pages\n";

$boilerplate = WPSD_Internal_Links::boilerplate_targets(true);

ok('menu target detected as site chrome', in_array($by_slug['about'], $boilerplate, true),
    'boilerplate ids: ' . implode(',', $boilerplate));
ok('footer target detected as site chrome', in_array($by_slug['contact'], $boilerplate, true),
    'boilerplate ids: ' . implode(',', $boilerplate));
ok('a page linked from one page only is not chrome', !in_array($by_slug['guide-2'], $boilerplate, true),
    'guide-2 wrongly treated as navigation');
ok('the truly unreachable page is not chrome', !in_array($by_slug['hidden'], $boilerplate, true));
ok('sampling stayed cheap', $served <= 12, "issued {$served} requests");

echo "\n── Orphan detection respects navigation\n";

$orphans     = WPSD_Internal_Links::orphan_pages(50);
$orphan_ids  = array_map(static fn($o) => (int) $o->ID, $orphans);

ok('page reached only from the menu is not an orphan', !in_array($by_slug['about'], $orphan_ids, true),
    'about is still reported as an orphan');
ok('page reached only from the footer is not an orphan', !in_array($by_slug['contact'], $orphan_ids, true),
    'contact is still reported as an orphan');
ok('the genuinely unlinked page is still an orphan', in_array($by_slug['hidden'], $orphan_ids, true),
    'hidden should be reported');
ok('page linked only from the homepage is not an orphan', !in_array($by_slug['guide-3'], $orphan_ids, true),
    'guide-3 is linked from the homepage, which is not itself a post');
ok('exactly one orphan remains', count($orphans) === 1, 'orphans: ' . implode(',', $orphan_ids));

echo "\n── The orphan check agrees with the report\n";

$context = new WPSD_Context(get_post($by_slug['about']));
$issues  = WPSD_Checks::run_post_checks_for($context, WPSD_Checks::get('orphan_page'));
ok('no orphan issue raised for a menu-linked page', $issues === [], json_encode($issues));

$context = new WPSD_Context(get_post($by_slug['hidden']));
$issues  = WPSD_Checks::run_post_checks_for($context, WPSD_Checks::get('orphan_page'));
ok('orphan issue still raised for the unlinked page', count($issues) === 1, json_encode($issues));

echo "\n── Crawl depth uses the same definition of navigation\n";

$depths = WPSD_Internal_Links::crawl_depths(true);

ok('menu-linked page has a depth', isset($depths[$by_slug['about']]),
    'about has no crawl depth despite being in the menu');
ok('menu-linked page sits one click from home', ($depths[$by_slug['about']] ?? null) === 1,
    'depth: ' . var_export($depths[$by_slug['about']] ?? null, true));
ok('unreachable page has no depth', !isset($depths[$by_slug['hidden']]));

echo "\n── Chrome does not drown out real content links\n";

$stats = WPSD_Internal_Links::stats();
ok('content link count excludes chrome', $stats['internal_links'] <= 10,
    'internal links inflated to ' . $stats['internal_links'] . ' by navigation');
ok('navigation targets reported separately', ($stats['navigation_targets'] ?? 0) >= 2,
    json_encode($stats));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
