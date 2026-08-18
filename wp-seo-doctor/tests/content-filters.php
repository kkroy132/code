<?php
/**
 * Regression test for filter-injected content links.
 *
 * Reproduces a real-world failure: a site whose internal links are added by a
 * `the_content` filter (related posts, automatic internal linking, tables of
 * contents) rather than stored in post_content. The plugin previously skipped
 * the filter chain entirely, so those links were invisible — the link graph
 * reported a handful of internal links and thousands of orphans on a site that
 * was in fact densely interlinked.
 *
 * The injecting filters here mirror the ones that exposed the bug: they bail
 * unless `is_single()` is true and they resolve the post through `get_the_ID()`.
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

// Twelve posts whose stored content carries only external links — exactly the
// shape of the site that surfaced this bug.
$slugs = [];
for ($i = 1; $i <= 12; $i++) {
    $slug    = 'movie-' . $i;
    $slugs[] = $slug;
    $wpdb->insert('wp_posts', [
        'post_title'   => 'Movie Review ' . $i,
        'post_name'    => $slug,
        'post_content' => '<p>Review number ' . $i . '. '
            . str_repeat('This film is discussed at length across several paragraphs. ', 30)
            . '<a href="https://www.themoviedb.org/movie/' . $i . '">TMDB entry</a> and '
            . '<a href="https://external.example/ref' . $i . '">a source</a>.</p>',
        'post_excerpt' => 'Excerpt ' . $i,
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_date'    => gmdate('Y-m-d H:i:s'),
        'post_modified'     => gmdate('Y-m-d H:i:s'),
        'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
    ]);
}
$post_ids = array_map('intval', $wpdb->get_col('SELECT ID FROM wp_posts ORDER BY ID'));

/**
 * "Related movies" block, appended by a filter. Guarded exactly like the
 * plugins found in the wild: singular views only, post resolved via get_the_ID().
 */
$GLOBALS['wpsd_related_filter'] = static function ($content) use ($post_ids) {
    if (!is_single()) {
        return $content;
    }
    $id = get_the_ID();
    if (!$id) {
        return $content;
    }

    // Ring topology, as a real related-posts block would: each post points at
    // its neighbours, so every post receives incoming links.
    $position = array_search($id, $post_ids, true);
    $total    = count($post_ids);
    $related  = [];
    for ($offset = 1; $offset <= 3; $offset++) {
        $related[] = $post_ids[($position + $offset) % $total];
    }

    $html = '<div class="related"><h3>Related</h3><ul>';
    foreach ($related as $target) {
        $html .= '<li><a href="' . get_permalink($target) . '">' . get_the_title($target) . '</a></li>';
    }
    $html .= '</ul></div>';

    return $content . $html;
};
add_filter('the_content', $GLOBALS['wpsd_related_filter'], 15);

/** Automatic keyword linking, also singular-only. */
$GLOBALS['wpsd_autolink_filter'] = static function ($content) use ($post_ids) {
    if (!is_single()) {
        return $content;
    }
    $id = get_the_ID();
    if (!$id || $id === $post_ids[0]) {
        return $content;
    }
    return str_replace(
        'This film',
        '<a href="' . get_permalink($post_ids[0]) . '">This film</a>',
        $content
    );
};
add_filter('the_content', $GLOBALS['wpsd_autolink_filter'], 30);

// ───────────────────────────────────────────────────────────── tests ──

echo "\n── Filter-injected internal links\n";

$context = new WPSD_Context(get_post($post_ids[1]));

ok('stored content contributes external links', count($context->external_links) >= 2, (string) count($context->external_links));
ok('filter-injected internal links are seen', count($context->internal_links) >= 3,
    'found ' . count($context->internal_links) . ' internal link(s) — the filter chain was skipped');

echo "\n── The global state the filters ran in is restored\n";

$GLOBALS['post'] = null;
$sentinel = new WP_Query();
$GLOBALS['wp_query'] = $sentinel;
new WPSD_Context(get_post($post_ids[2]));

ok('$wp_query restored after analysis', $GLOBALS['wp_query'] === $sentinel);
ok('$post restored after analysis', $GLOBALS['post'] === null);
ok('is_single() is false again outside analysis', is_single() === false);

echo "\n── Every global setup_postdata() touches is handed back\n";

// A filter that reads the postdata globals proves they are populated while it
// runs; the assertions below prove they are restored afterwards.
$GLOBALS['authordata'] = 'ORIGINAL-AUTHOR';
$GLOBALS['more'] = 'ORIGINAL-MORE';
unset($GLOBALS['numpages']);

$seen_inside = [];
add_filter('the_content', static function ($content) use (&$seen_inside) {
    $seen_inside['post_set'] = isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post;
    $seen_inside['is_single'] = is_single();
    return $content;
}, 5);

new WPSD_Context(get_post($post_ids[4]));

ok('filters ran with $post populated', !empty($seen_inside['post_set']));
ok('filters ran with is_single() true', !empty($seen_inside['is_single']));
ok('pre-existing $authordata restored', $GLOBALS['authordata'] === 'ORIGINAL-AUTHOR', var_export($GLOBALS['authordata'] ?? null, true));
ok('pre-existing $more restored', $GLOBALS['more'] === 'ORIGINAL-MORE', var_export($GLOBALS['more'] ?? null, true));
ok('a global that did not exist is not invented', !array_key_exists('numpages', $GLOBALS),
    'numpages leaked as ' . var_export($GLOBALS['numpages'] ?? null, true));

remove_all_filters('the_content');
// Re-register the two link-injecting filters for the graph test below.
add_filter('the_content', $GLOBALS['wpsd_related_filter'], 15);
add_filter('the_content', $GLOBALS['wpsd_autolink_filter'], 30);

echo "\n── Link graph across the whole site\n";

foreach ($post_ids as $id) {
    WPSD_Internal_Links::index_post(get_post($id));
}

$internal = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WPSD_DB::table('links') . " WHERE link_type = 'internal'");
$external = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WPSD_DB::table('links') . " WHERE link_type = 'external'");

ok('internal links recorded for every post', $internal >= count($post_ids) * 3, "got {$internal}");
ok('external links still recorded', $external >= count($post_ids) * 2, "got {$external}");
ok('internal links outnumber the handful seen before the fix', $internal > 6, "got {$internal}");

$resolved = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WPSD_DB::table('links') . " WHERE link_type = 'internal' AND target_id > 0");
ok('internal targets resolve to post IDs', $resolved === $internal, "{$resolved} of {$internal} resolved");

$orphans = WPSD_Internal_Links::orphan_pages(100);
ok('the site is not reported as almost entirely orphaned', count($orphans) <= 2, 'orphans: ' . count($orphans));

$stats = WPSD_Internal_Links::stats();
ok('average links per page is no longer zero', $stats['avg_outgoing'] >= 1, json_encode($stats));

echo "\n── Filters that misbehave must not abort the scan\n";

add_filter('the_content', static function ($content) {
    if (is_single()) {
        throw new RuntimeException('third-party filter exploded');
    }
    return $content;
}, 40);

$recovered = null;
try {
    $recovered = new WPSD_Context(get_post($post_ids[3]));
    $threw = false;
} catch (Throwable $e) {
    $threw = true;
}

ok('a throwing filter does not propagate', !$threw, 'exception escaped WPSD_Context');
ok('content falls back to the stored version', $recovered && $recovered->word_count > 0);
ok('global state restored even after a throw', $GLOBALS['wp_query'] === $sentinel);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
