<?php
/**
 * Integration test: runs WP SEO Doctor's real SQL against a real MariaDB.
 *
 * Every query the plugin issues is recorded; any SQL error, or any
 * prepare() placeholder/argument mismatch, fails the run.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';

global $wpdb;

$pass = 0;
$fail = 0;
$section = '';

function section(string $name): void {
    global $section;
    $section = $name;
    echo "\n── {$name}\n";
}

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

/** Run a callable and fail the test if it throws or triggers a SQL error. */
function runs(string $label, callable $fn) {
    global $wpdb, $pass, $fail;
    $before = count($wpdb->failures);
    try {
        $result = $fn();
    } catch (Throwable $e) {
        $fail++;
        echo "  ✗ {$label} — THREW: " . $e->getMessage() . "\n";
        return null;
    }
    $new = array_slice($wpdb->failures, $before);
    if ($new) {
        $fail++;
        echo "  ✗ {$label} — SQL ERROR: " . $new[0]['error'] . "\n      " . $new[0]['sql'] . "\n";
        return null;
    }
    $pass++;
    echo "  ✓ {$label}\n";
    return $result;
}

// ─────────────────────────────────────────────────── fixtures ──

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

section('Schema installation (dbDelta)');

foreach (array_keys(WPSD_DB::TABLES) as $key) {
    $wpdb->query('DROP TABLE IF EXISTS ' . WPSD_DB::table($key));
}
runs('WPSD_DB::install() creates all tables', static fn() => WPSD_DB::install());

foreach (array_keys(WPSD_DB::TABLES) as $key) {
    $table = WPSD_DB::table($key);
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
    ok("table {$key} exists", $exists === $table, 'missing');
}

// Seed posts: a hub, two linked pages, an orphan, and a near-duplicate.
$posts = [
    ['Complete Guide to WordPress Caching', 'caching-guide',
     '<p>WordPress caching improves performance. See our <a href="https://example.test/object-cache/">object cache guide</a> and the <a href="https://example.test/cdn-setup/">CDN setup</a> walkthrough. Also <a href="https://external.test/broken">an external reference</a> and <a href="https://amzn.to/xyz">a recommended plugin</a>.</p><h2>Why caching matters</h2><p>' . str_repeat('Caching reduces server load and speeds up page delivery for visitors. ', 40) . '</p>'],
    ['Object Cache Guide', 'object-cache',
     '<p>Redis and Memcached for WordPress. Back to the <a href="https://example.test/caching-guide/">caching guide</a>.</p><p>' . str_repeat('Object caching stores database query results in memory. ', 30) . '</p>'],
    ['CDN Setup', 'cdn-setup',
     '<p>Configure a CDN. <a href="https://example.test/caching-guide/">Caching guide</a>. <a href="/cdn-setup/">This page</a>. <a href="https://example.test/missing-page/">Click here</a>.</p><p>' . str_repeat('A content delivery network caches assets at the edge. ', 30) . '</p>'],
    ['Forgotten Draft Topic', 'orphan-page',
     '<p>Nobody links to this page.</p><p>' . str_repeat('This page covers an isolated subject nobody references. ', 25) . '</p>'],
    ['Object Cache Guide Copy', 'object-cache-copy',
     '<p>Redis and Memcached for WordPress. Back to the <a href="https://example.test/caching-guide/">caching guide</a>.</p><p>' . str_repeat('Object caching stores database query results in memory. ', 30) . '</p>'],
    ['Tiny Page', 'tiny-page', '<p>Short.</p>'],
];

foreach ($posts as $index => [$title, $slug, $content]) {
    $wpdb->insert('wp_posts', [
        'post_title'        => $title,
        'post_name'         => $slug,
        'post_content'      => $content,
        'post_excerpt'      => 'Excerpt for ' . $title,
        'post_type'         => 'post',
        'post_status'       => 'publish',
        'post_date'         => gmdate('Y-m-d H:i:s', time() - ($index + 1) * 86400 * 30),
        'post_modified'     => gmdate('Y-m-d H:i:s', time() - ($index + 1) * 86400 * 100),
        'post_modified_gmt' => gmdate('Y-m-d H:i:s', time() - ($index + 1) * 86400 * 100),
    ]);
}
$post_ids = array_map('intval', $wpdb->get_col('SELECT ID FROM wp_posts ORDER BY ID'));
ok('seeded ' . count($post_ids) . ' posts', count($post_ids) === 6);

// ─────────────────────────────────────────────────── issues ──

section('Issue store');

$batch = [];
$severities = ['critical', 'high', 'medium', 'low'];
foreach ($post_ids as $i => $id) {
    $batch[] = [
        'scan_id'        => 1,
        'check_id'       => ['seo_title', 'meta_description', 'thin_content', 'h1'][$i % 4],
        'check_group'    => ['onpage', 'technical', 'content', 'links'][$i % 4],
        'object_type'    => 'post',
        'object_id'      => $id,
        'url'            => get_permalink($id),
        'severity'       => $severities[$i % 4],
        'title'          => 'Test issue ' . $i,
        'message'        => 'Message with an apostrophe: it\'s here, and a "quote" — plus ünïcode',
        'recommendation' => 'Fix it',
        'data'           => ['sample' => [1, 2, 3], 'note' => "line\nbreak"],
    ];
}
$inserted = runs('add_many() bulk insert', static fn() => WPSD_Issues::add_many($batch));
ok('all rows inserted', $inserted === count($batch), "got " . var_export($inserted, true));

$counts = runs('severity_counts()', static fn() => WPSD_Issues::severity_counts());
ok('counts total matches', ($counts['total'] ?? 0) === count($batch), 'got ' . ($counts['total'] ?? 'null'));

runs('query() unfiltered', static fn() => WPSD_Issues::query());
runs('query() by severity', static fn() => WPSD_Issues::query(['severity' => 'critical']));
runs('query() by group + search', static fn() => WPSD_Issues::query(['check_group' => 'onpage', 'search' => "it's"]));
runs('query() by object', static fn() => WPSD_Issues::query(['object_id' => $post_ids[0], 'status' => 'all']));
$sorted = runs('query() ordered by severity', static fn() => WPSD_Issues::query(['orderby' => 'severity']));
ok('critical sorts first', ($sorted['rows'][0]->severity ?? '') === 'critical', 'got ' . ($sorted['rows'][0]->severity ?? 'none'));

$grouped = runs('grouped_by_check()', static fn() => WPSD_Issues::grouped_by_check());
ok('grouping returns rows', !empty($grouped));

$fix_first = runs('fix_first()', static fn() => WPSD_Issues::fix_first(5));
ok('fix_first returns rows', !empty($fix_first));

runs('set_status() bulk update', static fn() => WPSD_Issues::set_status([1, 2], 'ignored'));
$after = WPSD_Issues::severity_counts();
ok('ignored rows leave the open count', $after['total'] === $counts['total'] - 2, "got {$after['total']}");

$issue_row = $wpdb->get_row('SELECT * FROM ' . WPSD_DB::table('issues') . ' LIMIT 1');
$decoded = WPSD_Issues::data($issue_row);
ok('JSON payload round-trips', ($decoded['sample'] ?? null) === [1, 2, 3]);
ok('unicode survives the round trip', strpos((string) $issue_row->message, 'ünïcode') !== false);

// ────────────────────────────────────────────── link graph ──

section('Internal link graph');

foreach ($post_ids as $id) {
    $post = get_post($id);
    runs("index_post({$id})", static fn() => WPSD_Internal_Links::index_post($post));
}

$link_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('links'));
ok('links recorded', $link_count > 0, "got {$link_count}");

$affiliate_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('links') . ' WHERE is_affiliate = 1');
ok('affiliate link detected (amzn.to)', $affiliate_count === 1, "got {$affiliate_count}");

$external_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WPSD_DB::table('links') . " WHERE link_type = 'external'");
ok('external links classified', $external_count === 2, "got {$external_count}");

$incoming = runs('incoming_counts()', static fn() => WPSD_Internal_Links::incoming_counts());
ok('hub page has incoming links', ($incoming[$post_ids[0]] ?? 0) >= 2, 'got ' . ($incoming[$post_ids[0]] ?? 0));

runs('outgoing_counts()', static fn() => WPSD_Internal_Links::outgoing_counts());

$orphans = runs('orphan_pages()', static fn() => WPSD_Internal_Links::orphan_pages(50));
$orphan_slugs = array_map(static fn($o) => get_post($o->ID)->post_name, $orphans);
ok('orphan page found', in_array('orphan-page', $orphan_slugs, true), 'got ' . implode(',', $orphan_slugs));
ok('linked page not called an orphan', !in_array('caching-guide', $orphan_slugs, true));

runs('weakly_linked()', static fn() => WPSD_Internal_Links::weakly_linked(50));
runs('incoming_links()', static fn() => WPSD_Internal_Links::incoming_links($post_ids[0]));
runs('outgoing_links()', static fn() => WPSD_Internal_Links::outgoing_links($post_ids[0], 'internal'));
$anchors = runs('anchor_text_report()', static fn() => WPSD_Internal_Links::anchor_text_report(20));
ok('anchor text captured', !empty($anchors));

runs('crawl_depths()', static fn() => WPSD_Internal_Links::crawl_depths(true));
$map = runs('link_map()', static fn() => WPSD_Internal_Links::link_map(50));
ok('link map has nodes', !empty($map['nodes']));

$stats = runs('stats()', static fn() => WPSD_Internal_Links::stats());
ok('stats counts internal links', ($stats['internal_links'] ?? 0) > 0);

$suggestions = runs('suggest_targets()', static fn() => WPSD_Internal_Links::suggest_targets($post_ids[3], 5));
runs('suggest_sources()', static fn() => WPSD_Internal_Links::suggest_sources($post_ids[3], 5));
runs('opportunities()', static fn() => WPSD_Internal_Links::opportunities(10));
runs('related_content()', static fn() => WPSD_Internal_Links::related_content($post_ids[0], 3));

// Link insertion actually rewrites post content.
$before_content = get_post($post_ids[1])->post_content;
$inserted_link = runs('insert_link() edits the source post', static fn() => WPSD_Internal_Links::insert_link($post_ids[1], $post_ids[3], 'Redis'));
$after_content = get_post($post_ids[1])->post_content;
ok('insert_link reported success', $inserted_link === true, var_export($inserted_link, true));
ok('anchor became a link', $before_content !== $after_content && strpos($after_content, '>Redis</a>') !== false);
ok('only the first occurrence was linked', substr_count($after_content, '<a href="https://example.test/orphan-page/">') === 1);

// ────────────────────────────────────────────── broken links ──

section('Broken links');

runs('query() broken', static fn() => WPSD_Broken_Links::query(['status' => 'broken']));
runs('query() all + search', static fn() => WPSD_Broken_Links::query(['status' => 'all', 'search' => 'example']));
runs('query() external only', static fn() => WPSD_Broken_Links::query(['status' => 'all', 'type' => 'external']));
$bl_stats = runs('stats()', static fn() => WPSD_Broken_Links::stats());
ok('stats totals links', ($bl_stats['total'] ?? 0) === $link_count, 'got ' . ($bl_stats['total'] ?? 'null'));
runs('count_broken()', static fn() => WPSD_Broken_Links::count_broken());
runs('broken_for_source()', static fn() => WPSD_Broken_Links::broken_for_source($post_ids[0]));
runs('chained_for_source()', static fn() => WPSD_Broken_Links::chained_for_source($post_ids[0], true));

// Mark one link broken so the workflow paths have something to act on.
$target_link = $wpdb->get_row("SELECT * FROM " . WPSD_DB::table('links') . " WHERE link_type = 'external' LIMIT 1");
$wpdb->update(WPSD_DB::table('links'), ['status' => 'broken', 'http_status' => 404], ['id' => (int) $target_link->id]);

runs('ignore()', static fn() => WPSD_Broken_Links::ignore((int) $target_link->id));
ok('ignore persisted', $wpdb->get_var('SELECT status FROM ' . WPSD_DB::table('links') . ' WHERE id = ' . (int) $target_link->id) === 'ignored');
runs('unignore()', static fn() => WPSD_Broken_Links::unignore((int) $target_link->id));

$replaced = runs('replace() rewrites post content', static fn() => WPSD_Broken_Links::replace((int) $target_link->id, 'https://external.test/fixed'));
ok('replacement touched a post', is_array($replaced) && $replaced['updated'] > 0, json_encode($replaced));
ok('new URL is in the content', strpos(get_post($post_ids[0])->post_content, 'https://external.test/fixed') !== false);

$affiliate_link = $wpdb->get_row("SELECT * FROM " . WPSD_DB::table('links') . " WHERE is_affiliate = 1 LIMIT 1");
$removed = runs('remove() unwraps the anchor', static fn() => WPSD_Broken_Links::remove((int) $affiliate_link->id));
ok('anchor text kept as plain text', strpos(get_post($post_ids[0])->post_content, 'a recommended plugin') !== false);
ok('anchor tag gone', strpos(get_post($post_ids[0])->post_content, 'amzn.to') === false);

// ───────────────────────────────────────────────── 404 monitor ──

section('404 monitor');

runs('record() first hit', static fn() => WPSD_Monitor_404::record('/old-caching-guide/', 'https://ref.test/a', 'TestBot/1.0'));
runs('record() duplicate hit (upsert)', static fn() => WPSD_Monitor_404::record('/old-caching-guide/', '', 'TestBot/1.0'));
runs('record() second URL', static fn() => WPSD_Monitor_404::record('/gone/', '', 'TestBot/1.0'));

$hits = (int) $wpdb->get_var("SELECT hits FROM " . WPSD_DB::table('notfound') . " WHERE url = '/old-caching-guide/'");
ok('duplicate incremented rather than duplicated', $hits === 2, "got {$hits}");
$distinct = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('notfound'));
ok('two distinct 404 URLs', $distinct === 2, "got {$distinct}");
$kept_referrer = $wpdb->get_var("SELECT referrer FROM " . WPSD_DB::table('notfound') . " WHERE url = '/old-caching-guide/'");
ok('non-empty referrer preserved on upsert', $kept_referrer === 'https://ref.test/a', var_export($kept_referrer, true));

runs('query() new', static fn() => WPSD_Monitor_404::query(['status' => 'new']));
runs('query() all + search', static fn() => WPSD_Monitor_404::query(['status' => 'all', 'search' => 'caching']));
$stats404 = runs('stats()', static fn() => WPSD_Monitor_404::stats());
ok('stats sums hits', ($stats404['hits'] ?? 0) === 3, 'got ' . ($stats404['hits'] ?? 'null'));

$suggested = runs('suggest_redirects()', static fn() => WPSD_Monitor_404::suggest_redirects('/caching-guide/'));
ok('exact slug match suggested first', ($suggested[0]['reason'] ?? '') === 'Exact slug match', json_encode($suggested[0] ?? null));

$row404 = $wpdb->get_row("SELECT * FROM " . WPSD_DB::table('notfound') . " WHERE url = '/old-caching-guide/'");
$redirect_id = runs('create_redirect() from a 404', static fn() => WPSD_Monitor_404::create_redirect((int) $row404->id, 'https://example.test/caching-guide/'));
ok('redirect created', is_int($redirect_id) && $redirect_id > 0, json_encode($redirect_id));
ok('404 marked redirected', $wpdb->get_var('SELECT status FROM ' . WPSD_DB::table('notfound') . ' WHERE id = ' . (int) $row404->id) === 'redirected');

runs('set_status()', static fn() => WPSD_Monitor_404::set_status([(int) $row404->id], 'ignored'));
runs('prune()', static fn() => WPSD_Monitor_404::prune());

// ─────────────────────────────────────────────────── redirects ──

section('Redirect manager');

runs('create() exact', static fn() => WPSD_Redirects::create(['source' => '/a', 'target' => '/b', 'code' => 301]));
runs('create() regex', static fn() => WPSD_Redirects::create(['source' => '^/blog/(.+)$', 'target' => '/articles/$1', 'match_type' => 'regex']));
runs('create() 410', static fn() => WPSD_Redirects::create(['source' => '/dead', 'code' => 410]));

$duplicate = WPSD_Redirects::create(['source' => '/a', 'target' => '/c']);
ok('duplicate source rejected', is_wp_error($duplicate), 'expected WP_Error');
$self = WPSD_Redirects::create(['source' => '/loop', 'target' => '/loop']);
ok('self-redirect rejected', is_wp_error($self));
$bad_regex = WPSD_Redirects::create(['source' => '/^[bad/', 'target' => '/x', 'match_type' => 'regex']);
ok('invalid regex rejected', is_wp_error($bad_regex));
$no_target = WPSD_Redirects::create(['source' => '/no-target', 'code' => 301]);
ok('missing target rejected', is_wp_error($no_target));

runs('query()', static fn() => WPSD_Redirects::query());
runs('query() filtered', static fn() => WPSD_Redirects::query(['code' => 301, 'match_type' => 'exact', 'enabled' => true, 'search' => 'a']));
runs('find_by_source()', static fn() => WPSD_Redirects::find_by_source('/a'));
runs('active_rules()', static fn() => WPSD_Redirects::active_rules());

// Build a chain: /a → /b and /b → /final
runs('create() chain link', static fn() => WPSD_Redirects::create(['source' => '/b', 'target' => '/final', 'code' => 301]));
$analysis = runs('analyse()', static fn() => WPSD_Redirects::analyse());
ok('chain detected', count($analysis['chains']) >= 1, json_encode($analysis['chains']));

// Build a loop: /x → /y and /y → /x
WPSD_Redirects::create(['source' => '/x', 'target' => '/y']);
WPSD_Redirects::create(['source' => '/y', 'target' => '/x']);
WPSD_Redirects::flush_cache();
$analysis = WPSD_Redirects::analyse();
ok('loop detected', count($analysis['loops']) >= 1, json_encode($analysis['loops']));

$flattened = runs('flatten_chains()', static fn() => WPSD_Redirects::flatten_chains());
ok('a chain was flattened', $flattened >= 1, "got {$flattened}");
ok('/a now points at the final target',
    $wpdb->get_var("SELECT target FROM " . WPSD_DB::table('redirects') . " WHERE source = '/a'") === '/final');

runs('update()', static fn() => WPSD_Redirects::update((int) $wpdb->get_var("SELECT id FROM " . WPSD_DB::table('redirects') . " WHERE source = '/a'"), ['notes' => 'edited', 'enabled' => false]));
runs('history()', static fn() => WPSD_Redirects::history(0, 10));
$rstats = runs('stats()', static fn() => WPSD_Redirects::stats());
ok('stats counts rules', ($rstats['total'] ?? 0) >= 6, json_encode($rstats));

$csv = runs('export_csv()', static fn() => WPSD_Redirects::export_csv());
ok('CSV has a header row', strpos((string) $csv, 'source,target,code') === 0, substr((string) $csv, 0, 40));

$import = runs('import_csv() with header', static fn() => WPSD_Redirects::import_csv("source,target,code,match_type\n/imported-1,/dest-1,301,exact\n/imported-2,/dest-2,302,exact\n"));
ok('two rows imported', ($import['imported'] ?? 0) === 2, json_encode($import));

$import = runs('import_csv() headerless', static fn() => WPSD_Redirects::import_csv("/imported-3,/dest-3,301\n"));
ok('headerless row imported', ($import['imported'] ?? 0) === 1, json_encode($import));

$import = WPSD_Redirects::import_csv("/imported-1,/dest-x,301\n");
ok('duplicate import skipped with a reason', ($import['skipped'] ?? 0) === 1 && !empty($import['errors']), json_encode($import));

// Matching, using the rules now in the database.
WPSD_Redirects::flush_cache();
$match = runs('match() exact', static fn() => WPSD_Redirects::match('/imported-1'));
ok('exact rule matched', is_array($match) && $match[1] === 'https://example.test/dest-1', json_encode($match[1] ?? null));
$match = WPSD_Redirects::match('/blog/hello-world');
ok('regex rule matched with capture', is_array($match) && $match[1] === 'https://example.test/articles/hello-world', json_encode($match[1] ?? null));
ok('unmatched path returns null', WPSD_Redirects::match('/nothing-here') === null);
$match = WPSD_Redirects::match('/dead');
ok('410 rule matched', is_array($match) && (int) $match[0]->code === 410);

// ───────────────────────────────────────────── search console ──

section('Search Console queries');

$gsc = WPSD_DB::table('gsc');
for ($day = 1; $day <= 40; $day++) {
    $date = gmdate('Y-m-d', strtotime("-{$day} days"));
    foreach ([['/caching-guide/', 'wordpress caching', 12.5], ['/object-cache/', 'redis wordpress', 4.2]] as $i => [$page, $query, $position]) {
        // Recent days get fewer clicks so decay detection has something to find.
        $clicks = $day <= 20 ? 3 : 12;
        $wpdb->insert($gsc, [
            'data_date'   => $date,
            'page'        => 'https://example.test' . $page,
            'page_hash'   => md5(WPSD_Helpers::normalize_url('https://example.test' . $page)),
            'query_text'  => $query,
            'query_hash'  => md5($query),
            'clicks'      => $clicks,
            'impressions' => 400,
            'ctr'         => round($clicks / 400 * 100, 4),
            'position'    => $position,
        ]);
    }
}

ok('has_data() true', WPSD_GSC::has_data());
$totals = runs('totals()', static fn() => WPSD_GSC::totals(28));
ok('totals computes CTR from sums', ($totals['ctr'] ?? -1) > 0, json_encode($totals));
runs('top_queries()', static fn() => WPSD_GSC::top_queries(10, 28));
runs('top_pages()', static fn() => WPSD_GSC::top_pages(10, 28));
$striking = runs('striking_distance()', static fn() => WPSD_GSC::striking_distance(10, 28));
ok('striking distance finds the position-12.5 query', is_array($striking) && count($striking) === 1, json_encode(array_column($striking, 'position')));
$ctr_ops = runs('ctr_opportunities()', static fn() => WPSD_GSC::ctr_opportunities(10, 28));
ok('low-CTR pages found', is_array($ctr_ops) && count($ctr_ops) >= 1, json_encode($ctr_ops));
$declining = runs('declining_pages()', static fn() => WPSD_GSC::declining_pages(10, 20));
ok('decline detected', is_array($declining) && count($declining) >= 1, json_encode($declining));
$page_stats = runs('page_stats()', static fn() => WPSD_GSC::page_stats('https://example.test/caching-guide/'));
ok('page stats resolve by hash', ($page_stats['impressions'] ?? 0) > 0, json_encode($page_stats));
$trend = runs('page_trend()', static fn() => WPSD_GSC::page_trend('https://example.test/caching-guide/', 20));
ok('page trend shows the drop', ($trend['change_percent'] ?? 0) < 0, json_encode($trend));
$daily = runs('daily_trend()', static fn() => WPSD_GSC::daily_trend(90));
ok('daily series returned', is_array($daily) && count($daily) === 40, 'got ' . count($daily));

// ───────────────────────────────────────────────────── score ──

section('Score and trends');

runs('by_group()', static fn() => WPSD_Score::by_group());
runs('snapshot() insert', static fn() => WPSD_Score::snapshot());
runs('snapshot() upsert same day', static fn() => WPSD_Score::snapshot());
$snapshots = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WPSD_DB::table('trends'));
ok('same-day snapshot updates rather than duplicates', $snapshots === 1, "got {$snapshots}");
runs('trend()', static fn() => WPSD_Score::trend(30));
runs('trend_delta()', static fn() => WPSD_Score::trend_delta(30));
$score = runs('calculate()', static fn() => WPSD_Score::calculate());
ok('score is within range', ($score['score'] ?? -1) >= 0 && ($score['score'] ?? 101) <= 100, json_encode($score['score'] ?? null));

// ─────────────────────────────────────────────── content SEO ──

section('Content SEO');

$thin = runs('thin_pages()', static fn() => WPSD_Content_SEO::thin_pages(20));
ok('tiny page flagged as thin', is_array($thin) && in_array('Tiny Page', array_column($thin, 'title'), true), json_encode(array_column($thin, 'title')));
$clusters = runs('duplicate_clusters()', static fn() => WPSD_Content_SEO::duplicate_clusters(10));
ok('near-duplicate pair clustered', is_array($clusters) && count($clusters) >= 1, json_encode($clusters));
runs('outdated_pages()', static fn() => WPSD_Content_SEO::outdated_pages(20));
runs('decaying_pages()', static fn() => WPSD_Content_SEO::decaying_pages(20));
runs('opportunities()', static fn() => WPSD_Content_SEO::opportunities(20));
runs('stats()', static fn() => WPSD_Content_SEO::stats());

// ──────────────────────────────────────────────── affiliate ──

section('Affiliate');

// Re-add an affiliate link (the remove() test above unwrapped the only one).
$wpdb->insert(WPSD_DB::table('links'), [
    'source_id' => $post_ids[0], 'source_url' => get_permalink($post_ids[0]),
    'target_url' => 'https://amzn.to/deal', 'target_hash' => WPSD_Helpers::url_hash('https://amzn.to/deal'),
    'target_id' => 0, 'anchor' => 'buy now', 'rel' => '', 'link_type' => 'external',
    'is_affiliate' => 1, 'http_status' => 404, 'status' => 'broken', 'created_at' => WPSD_Helpers::now(),
]);
$wpdb->insert(WPSD_DB::table('links'), [
    'source_id' => $post_ids[1], 'source_url' => get_permalink($post_ids[1]),
    'target_url' => 'https://shareasale.com/r.cfm?x=1', 'target_hash' => WPSD_Helpers::url_hash('https://shareasale.com/r.cfm?x=1'),
    'target_id' => 0, 'anchor' => 'deal', 'rel' => 'sponsored', 'link_type' => 'external',
    'is_affiliate' => 1, 'http_status' => 200, 'redirect_hops' => 3, 'redirect_target' => 'https://merchant.test/p',
    'status' => 'redirect', 'created_at' => WPSD_Helpers::now(),
]);

$aff = runs('query()', static fn() => WPSD_Affiliate::query());
ok('affiliate rows grouped by destination', is_array($aff) && count($aff['rows']) === 2, json_encode(count($aff['rows'])));
ok('worst status ranked first', ($aff['rows'][0]->status ?? '') === 'broken', json_encode($aff['rows'][0]->status ?? null));
runs('dead_products()', static fn() => WPSD_Affiliate::dead_products(10));
$redirects = runs('redirects()', static fn() => WPSD_Affiliate::redirects(10));
ok('multi-hop chain classified', is_array($redirects) && count($redirects['chains']) === 1, json_encode($redirects));
runs('outbound_domains()', static fn() => WPSD_Affiliate::outbound_domains(10));
$missing_rel = runs('missing_attributes()', static fn() => WPSD_Affiliate::missing_attributes(10));
ok('link without rel flagged', is_array($missing_rel) && count($missing_rel) === 1, json_encode(array_column($missing_rel, 'target_url')));
runs('stats()', static fn() => WPSD_Affiliate::stats());
runs('retag_links()', static fn() => WPSD_Affiliate::retag_links());

// ────────────────────────────────────────────────── scanner ──

section('Scanner');

$started = runs('start() full scan', static fn() => WPSD_Scanner::start('full'));
ok('queue built from published posts', ($started['total'] ?? 0) === count($post_ids), json_encode($started));

$concurrent = WPSD_Scanner::start('full');
ok('second concurrent scan refused', is_wp_error($concurrent), 'expected WP_Error');

$scan_id = (int) $started['scan_id'];
$guard = 0;
do {
    $batch = WPSD_Scanner::run_batch($scan_id, 2);
    $guard++;
    if (is_wp_error($batch)) {
        break;
    }
} while (empty($batch['done']) && $guard < 20);

ok('scan ran to completion', !is_wp_error($batch) && !empty($batch['done']), is_wp_error($batch) ? $batch->get_error_message() : json_encode($batch));
ok('every page processed', ($batch['processed'] ?? 0) === count($post_ids), json_encode($batch['processed'] ?? null));

$scan_row = WPSD_Scanner::get_scan($scan_id);
ok('scan marked completed', $scan_row->status === 'completed', $scan_row->status);
ok('scan recorded a score', $scan_row->score !== null);
ok('scan found issues', (int) $scan_row->total_issues > 0, (string) $scan_row->total_issues);

$found_checks = $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT check_id FROM ' . WPSD_DB::table('issues') . ' WHERE scan_id = %d',
    $scan_id
));
ok('thin content check fired', in_array('thin_content', $found_checks, true), implode(',', $found_checks));
ok('orphan check fired', in_array('orphan_page', $found_checks, true), implode(',', $found_checks));
ok('duplicate content check fired', in_array('duplicate_content', $found_checks, true), implode(',', $found_checks));

runs('history()', static fn() => WPSD_Scanner::history(10));
runs('latest_completed()', static fn() => WPSD_Scanner::latest_completed());
runs('rescan_post()', static fn() => WPSD_Scanner::rescan_post($post_ids[0]));
runs('delete_scan()', static fn() => WPSD_Scanner::delete_scan($scan_id));

// ────────────────────────────────────────────────── reports ──

section('Reports and export');

foreach (array_keys(WPSD_Reports::available()) as $key) {
    $report = runs("build('{$key}')", static fn() => WPSD_Reports::build($key, ['limit' => 50]));
    ok("  {$key} has columns", !empty($report['columns']) || $key === 'health', 'no columns');
}

$report = WPSD_Reports::build('audit', ['limit' => 50]);
$pdf = runs('render_pdf() on live data', static fn() => WPSD_Export::render_pdf($report));
ok('PDF well-formed', strpos((string) $pdf, '%PDF-1.4') === 0 && substr((string) $pdf, -5) === '%%EOF');

runs('send_email_report()', static fn() => WPSD_Reports::send_email_report());

section('Regression: bugs found by this harness');

// A regex source must be stored verbatim, not normalised into a path, and
// capture groups must reach the target.
$rid = WPSD_Redirects::create(['source' => '^/news/(\\d{4})/(.+)$', 'target' => '/archive/$1/$2', 'match_type' => 'regex']);
ok('regex rule created', is_int($rid), json_encode($rid));
$stored = $wpdb->get_var($wpdb->prepare('SELECT source FROM ' . WPSD_DB::table('redirects') . ' WHERE id = %d', (int) $rid));
ok('regex source stored verbatim', $stored === '^/news/(\\d{4})/(.+)$', var_export($stored, true));
WPSD_Redirects::flush_cache();
$m = WPSD_Redirects::match('/news/2024/big-story');
ok('both capture groups substituted',
    is_array($m) && $m[1] === 'https://example.test/archive/2024/big-story',
    json_encode($m[1] ?? null));

// A pre-delimited pattern with flags still works.
$rid2 = WPSD_Redirects::create(['source' => '#^/Shop/(.+)#i', 'target' => '/store/$1', 'match_type' => 'regex']);
WPSD_Redirects::flush_cache();
$m = WPSD_Redirects::match('/SHOP/hats');
ok('delimited pattern honours its flags',
    is_array($m) && $m[1] === 'https://example.test/store/hats',
    json_encode($m[1] ?? null));

// An exact source is still normalised.
WPSD_Redirects::create(['source' => 'https://example.test/legacy/page/', 'target' => '/new']);
$stored = $wpdb->get_var("SELECT source FROM " . WPSD_DB::table('redirects') . " WHERE target = '/new'");
ok('exact source normalised to a path', $stored === '/legacy/page', var_export($stored, true));

// Backslashes in content must survive a link rewrite (the wp_slash fix).
$wpdb->insert('wp_posts', [
    'post_title' => 'Regex Tutorial', 'post_name' => 'regex-tutorial',
    'post_content' => '<p>Use <code>\\d+</code> and the path C:\\Users\\test to match. <a href="https://old.test/ref">reference</a></p>',
    'post_type' => 'post', 'post_status' => 'publish',
    'post_date' => gmdate('Y-m-d H:i:s'), 'post_modified' => gmdate('Y-m-d H:i:s'),
    'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
]);
$regex_post = (int) $wpdb->insert_id;
WPSD_Internal_Links::index_post(get_post($regex_post));
$ref = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . WPSD_DB::table('links') . ' WHERE source_id = %d LIMIT 1',
    $regex_post
));
runs('replace() on content containing backslashes', static fn() => WPSD_Broken_Links::replace((int) $ref->id, 'https://new.test/ref'));
$after = get_post($regex_post)->post_content;
ok('literal \\d+ survived the rewrite', strpos($after, '\\d+') !== false, substr($after, 0, 90));
ok('Windows path backslashes survived', strpos($after, 'C:\\Users\\test') !== false, substr($after, 0, 90));
ok('the URL was still replaced', strpos($after, 'https://new.test/ref') !== false);

// Content-decay SQL (derived table) must return rows, not error.
$declining = runs('declining_pages() after the SQL fix', static fn() => WPSD_GSC::declining_pages(10, 20));
ok('declining pages returned', is_array($declining) && count($declining) >= 1, json_encode($declining));
ok('decline percentage is negative',
    isset($declining[0]['change_percent']) && $declining[0]['change_percent'] < 0,
    json_encode($declining[0] ?? null));

// ───────────────────────────────────────────────── teardown ──

section('Result');

echo "\nQueries executed: {$wpdb->query_count}\n";
if ($wpdb->failures) {
    echo "SQL failures (" . count($wpdb->failures) . "):\n";
    foreach (array_slice($wpdb->failures, 0, 15) as $failure) {
        echo "  - {$failure['error']}\n      {$failure['sql']}\n";
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
