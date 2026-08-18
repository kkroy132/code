<?php
/**
 * Scaling behaviour of duplicate detection.
 *
 * Both duplicate indexes used to be built by loading the whole corpus into
 * PHP: one issued a query per post and the other serialised every post's
 * fingerprint into a single transient. Measured on 2,000 posts that was
 * 16,001 queries and a 1.7 MB payload — which extrapolates past the default
 * 16 MB max_allowed_packet at around 20,000 posts, at which point the
 * transient write fails and duplicate detection stops working with no error
 * anywhere.
 *
 * These assertions pin the properties that keep it working on a large site:
 * duplicate lookups must not scale with the corpus, and nothing may be stashed
 * in an oversized option row.
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

$CORPUS = (int) (getenv('WPSD_TEST_CORPUS') ?: 400);

/**
 * Deterministic filler with prose-like variety.
 *
 * A tiny vocabulary is not a fair test: with 18 words every 4-gram recurs
 * across the corpus, so the boilerplate filter cannot tell template text from
 * a genuine copy. Real writing produces mostly-unique 4-grams, which is what
 * this reproduces — while staying reproducible run to run.
 */
function filler(int $seed, int $words = 300): string {
    $vocab = ['caching','plugin','database','server','theme','image','query','network','render',
              'index','latency','payload','schema','cookie','session','router','template','widget'];
    $out = [];
    for ($w = 0; $w < $words; $w++) {
        $out[] = $vocab[($seed * 7 + $w * 13) % count($vocab)];
        if ($w % 3 === 0) {
            $out[] = 'topic' . $seed . 'x' . ($w % 17);
        }
    }
    return '<p>' . implode(' ', $out) . '</p>';
}

$rows = [];
for ($i = 1; $i <= $CORPUS; $i++) {
    $rows[] = $wpdb->prepare('(%s,%s,%s,%s,%s,%s,%s,%s,%s)',
        'Unique Post ' . $i, 'post-' . $i, filler($i), 'Excerpt ' . $i,
        'post', 'publish', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'));
    if (count($rows) === 200) {
        $wpdb->query('INSERT INTO wp_posts (post_title,post_name,post_content,post_excerpt,post_type,post_status,post_date,post_modified,post_modified_gmt) VALUES ' . implode(',', $rows));
        $rows = [];
    }
}
if ($rows) {
    $wpdb->query('INSERT INTO wp_posts (post_title,post_name,post_content,post_excerpt,post_type,post_status,post_date,post_modified,post_modified_gmt) VALUES ' . implode(',', $rows));
}

// Two posts sharing a title, two sharing a description, two near-identical bodies.
$shared_body = filler(999);
$extras = [
    ['Shared Title', 'dup-title-a', filler(9001), 'Description A'],
    ['Shared Title', 'dup-title-b', filler(9002), 'Description B'],
    ['Distinct One', 'dup-desc-a',  filler(9003), 'Exactly the same description text'],
    ['Distinct Two', 'dup-desc-b',  filler(9004), 'Exactly the same description text'],
    ['Body Copy A',  'dup-body-a',  $shared_body, 'Body description A'],
    ['Body Copy B',  'dup-body-b',  $shared_body . '<p>One extra closing sentence here.</p>', 'Body description B'],
];
foreach ($extras as [$title, $slug, $content, $desc]) {
    $wpdb->insert('wp_posts', [
        'post_title' => $title, 'post_name' => $slug, 'post_content' => $content,
        'post_excerpt' => 'Excerpt ' . $slug, 'post_type' => 'post', 'post_status' => 'publish',
        'post_date' => gmdate('Y-m-d H:i:s'), 'post_modified' => gmdate('Y-m-d H:i:s'),
        'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
    ]);
    $id = (int) $wpdb->insert_id;
    update_post_meta($id, '_yoast_wpseo_title', $title);
    update_post_meta($id, '_yoast_wpseo_metadesc', $desc);
}

$ids    = array_map('intval', $wpdb->get_col('SELECT ID FROM wp_posts ORDER BY ID'));
$bySlug = [];
foreach ($wpdb->get_results('SELECT ID, post_name FROM wp_posts') as $r) {
    $bySlug[$r->post_name] = (int) $r->ID;
}

echo "\n── Building fingerprints for " . count($ids) . " posts\n";

$t0 = microtime(true);
$q0 = $wpdb->query_count;
foreach ($ids as $id) {
    WPSD_Fingerprints::store(get_post($id));
}
$build_queries = $wpdb->query_count - $q0;
printf("  built in %.2fs, %d queries (%.1f per post)\n",
    microtime(true) - $t0, $build_queries, $build_queries / max(1, count($ids)));

// Five is the irreducible cost: read the post, read its meta, then write the
// fingerprint row, clear the old shingles and insert the new ones.
ok('building costs a small constant per post', $build_queries <= count($ids) * 6,
    "{$build_queries} queries for " . count($ids) . ' posts');

// The property that actually matters: cost per post must not grow with the
// size of the corpus. The old index read the whole corpus for every scan.
$q0 = $wpdb->query_count;
WPSD_Fingerprints::store(get_post($ids[0]));
$single = $wpdb->query_count - $q0;
ok('storing one post is independent of corpus size', $single <= 6,
    "{$single} queries against a " . count($ids) . '-post corpus');

echo "\n── Duplicate lookups do not scale with the corpus\n";

$q0 = $wpdb->query_count;
$dup_titles = WPSD_Fingerprints::duplicate_titles();
$title_queries = $wpdb->query_count - $q0;

ok('duplicate titles found', count($dup_titles) === 1, json_encode($dup_titles));
ok('title lookup is a constant number of queries', $title_queries <= 3, "{$title_queries} queries");

$q0 = $wpdb->query_count;
$dup_descs = WPSD_Fingerprints::duplicate_descriptions();
$desc_queries = $wpdb->query_count - $q0;

ok('duplicate descriptions found', count($dup_descs) === 1, json_encode($dup_descs));
ok('description lookup is a constant number of queries', $desc_queries <= 3, "{$desc_queries} queries");

echo "\n── Near-duplicate bodies are matched through SQL\n";

$q0 = $wpdb->query_count;
$similar = WPSD_Fingerprints::similar_to($bySlug['dup-body-a'], 0.5);
$sim_queries = $wpdb->query_count - $q0;

$similar_ids = array_column($similar, 'post_id');
ok('the near-identical body is matched', in_array($bySlug['dup-body-b'], $similar_ids, true),
    json_encode($similar));
ok('unrelated posts are not matched', count($similar) <= 2, count($similar) . ' matches');
ok('similarity lookup is a constant number of queries', $sim_queries <= 3, "{$sim_queries} queries");

echo "\n── Boilerplate shared site-wide is not mistaken for duplication\n";

// A disclaimer repeated on many posts is template text, not duplicate content.
// Excluding such shingles is what keeps the pair-finding join from exploding,
// so it needs to hold without suppressing genuine duplicates.
$disclaimer = ' ' . str_repeat('All prices include tax and are subject to change without notice. ', 6);
foreach (array_slice($ids, 0, 60) as $n => $id) {
    $post = get_post($id);
    $wpdb->update('wp_posts', ['post_content' => $post->post_content . '<p>' . $disclaimer . '</p>'], ['ID' => $id]);
    clean_post_cache($id);
    WPSD_Fingerprints::store(get_post($id));
}

$clusters = WPSD_Content_SEO::duplicate_clusters(50);
$clustered_ids = [];
foreach ($clusters as $cluster) {
    foreach ($cluster['pages'] as $page) {
        $clustered_ids[] = (int) $page['id'];
    }
}

$boilerplate_only = array_intersect(array_slice($ids, 0, 60), $clustered_ids);
ok('posts sharing only boilerplate are not clustered', count($boilerplate_only) === 0,
    count($boilerplate_only) . ' posts clustered on template text alone');

$still_found = array_intersect([$bySlug['dup-body-a'], $bySlug['dup-body-b']], $clustered_ids);
ok('the genuine near-duplicate pair is still clustered', count($still_found) === 2,
    'found ' . count($still_found) . ' of the pair');

echo "\n── Nothing oversized is stashed in an option row\n";

$biggest = 0;
foreach ((array) ($GLOBALS['wpsd_stub_options'] ?? []) as $key => $value) {
    $size = strlen(serialize($value));
    if ($size > $biggest) {
        $biggest = $size;
    }
}
printf("  largest stored option: %.2f KB\n", $biggest / 1024);
ok('no option approaches max_allowed_packet', $biggest < 1048576,
    sprintf('%.2f MB stored in a single row', $biggest / 1048576));

echo "\n── The checks still report duplicates\n";

$context = new WPSD_Context(get_post($bySlug['dup-title-a']));
$issues  = WPSD_Checks::run_post_checks_for($context, WPSD_Checks::get('duplicate_title'));
ok('duplicate title check fires', count($issues) === 1, json_encode($issues));

$context = new WPSD_Context(get_post($bySlug['dup-desc-a']));
$issues  = WPSD_Checks::run_post_checks_for($context, WPSD_Checks::get('duplicate_description'));
ok('duplicate description check fires', count($issues) === 1, json_encode($issues));

$context = new WPSD_Context(get_post($bySlug['dup-body-a']));
$issues  = WPSD_Checks::run_post_checks_for($context, WPSD_Checks::get('duplicate_content'));
ok('duplicate content check fires', count($issues) === 1, json_encode($issues));

$context = new WPSD_Context(get_post($ids[0]));
$issues  = WPSD_Checks::run_post_checks_for($context, WPSD_Checks::get('duplicate_title'));
ok('a unique post raises nothing', $issues === [], json_encode($issues));

echo "\n── Stale fingerprints are replaced, not duplicated\n";

$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('shingles') . ' WHERE post_id = ' . $bySlug['dup-body-a']);
WPSD_Fingerprints::store(get_post($bySlug['dup-body-a']));
$after = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('shingles') . ' WHERE post_id = ' . $bySlug['dup-body-a']);
ok('re-storing does not accumulate rows', $before === $after, "{$before} → {$after}");

WPSD_Fingerprints::forget($bySlug['dup-body-a']);
$gone = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('shingles') . ' WHERE post_id = ' . $bySlug['dup-body-a']);
ok('forgetting a post clears its shingles', $gone === 0, "{$gone} rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
