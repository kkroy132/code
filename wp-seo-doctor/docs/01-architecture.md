# WP SEO Doctor — Step 1: Technical Architecture

Status: draft for review. Nothing below is final until you sign off — this is
the foundation every later step (folder structure, DB schema, scanner, etc.)
gets built on, so it's worth getting the calls right here rather than
unwinding them in Step 6.

## 1. Product split: two plugins, not one plugin with a license key

**Decision:** Free (`wp-seo-doctor`) ships on WordPress.org, fully
self-contained. Pro (`wp-seo-doctor-pro`) is a **separate add-on plugin**,
sold off wordpress.org, that requires Free as a dependency and registers
itself into Free via hooks. This is the same pattern as Yoast/Yoast Premium,
ACF/ACF Pro, WP Rocket + extensions.

Why not one codebase with `if ( is_pro() )` gates everywhere:
- Keeps all licensing/payment code entirely out of the .org-hosted codebase
  — nothing for a plugin reviewer to question, no risk of a guideline
  violation in the free listing.
- Pro can iterate and ship on its own schedule without a new .org release.
- Forces a clean extension API (see §3) instead of gates scattered through
  business logic — which is also what makes third-party checks/integrations
  possible later.

Free must fully work with Pro absent — Pro only *adds* rows to registries
Free already defines, never patches Free's core logic.

## 2. Module map

```
Core (Free, always loaded)
├── Bootstrap & autoloader
├── DB schema + migrations
├── Capability & nonce layer
├── Safe HTTP client (SSRF-guarded fetch wrapper)
├── Check Registry           ← Pro/3rd-party checks plug in here
├── Scanner (queue + Action Scheduler batches)
├── Issue Engine (severity, dedup, "Fix First" ranking)
├── Links: internal graph, orphan detection, basic broken-link check
├── 404 Monitor (lightweight logging hook)
├── Redirect Manager (basic: 301/302, single-hop)
├── Admin UI shell + REST API (namespace `seodoc/v1`)
├── CSV export
└── Module Registry / extension points (seodoc_register_*)

Pro (separate plugin, hooks into Core)
├── Scheduled scans, full crawl, CPT/taxonomy coverage
├── Redirect chains/loops, regex redirects, bulk import/export
├── GSC integration (OAuth, opportunity finder, content decay)
├── AI Assistant (title/meta/ALT/action-plan generation)
├── Advanced internal-linking (unlimited suggestions, topic clusters)
├── Affiliate SEO mode
├── White-label / agency reporting
└── License/update client (paid update server)
```

## 3. Extension points Free must expose from day one

Even though nothing consumes these until Pro exists, they have to be in
Free's public API from Step 4 onward, because retrofitting them later means
touching already-shipped code:

- `seodoc_register_check( string $id, string $class )` — add a Check class
  to the registry (§5).
- `seodoc_register_scanner_stage( string $id, callable $handler )` — insert
  a stage into the batch pipeline (e.g. Pro's full-crawl stage).
- `seodoc_register_admin_page( array $config )` — add a submenu page/tab
  without editing Core's menu file.
- `seodoc_issue_severity` (filter) — let Pro re-rank severity using GSC
  data (impressions/clicks) once it's available.
- `seodoc_is_pro_active()` (function) — Free checks this only to decide
  whether to show an upsell nudge; it never gates functionality, since Free
  has none of Pro's code loaded to gate.

## 4. Data flow (scan → dashboard)

```
WP-Cron tick
   │
   ▼
Scanner Queue (DB table: rows = "unit of work", e.g. one post/page)
   │  Action Scheduler pulls N rows per batch (default 20, filterable,
   │  auto-tuned down on shared hosting via a timing self-check)
   ▼
Batch Processor
   │  for each row: load content locally via WP_Post (no HTTP fetch for
   │  on-page checks — only Links/Technical checks that need live HTTP
   │  fetch it, and only through the Safe HTTP Client)
   ▼
Check Registry → runs every applicable registered Check against the row
   │  each Check returns 0..n Issue objects: {check_id, severity, message,
   │  url, meta}
   ▼
Issue Engine
   │  - upserts into wp_seodoc_issues (keyed by check_id + url, so re-scans
   │    update rather than duplicate)
   │  - marks previously-open issues resolved if the Check no longer fires
   │  - recomputes SEO Health Score + "Fix First" ranking
   ▼
wp_seodoc_scans row marked complete → transient cache for dashboard
summary invalidated
   │
   ▼
Admin dashboard (REST API reads from DB, not from a live scan)
```

Design constraints this enforces:
- **No full-site crawl in one request.** A scan is decomposed into queue
  rows before any checking happens; the queue itself is built in a batch
  too (paginated `WP_Query`/`get_posts`), never `get_posts(['numberposts' =>
  -1])` on a live site.
- **Idempotent batches.** A batch that times out or a host that kills the
  process mid-run leaves partially-processed rows marked `processing` with
  a claim timestamp; a stale-claim sweep (next Cron tick) resets them to
  `pending` rather than losing them or double-processing.
- **Pause/cancel** is a status flag on `wp_seodoc_scans`; the batch
  processor checks it before pulling the next batch — no in-flight work to
  kill, just stop scheduling more.

## 5. Check plugin model

```php
abstract class SEODoc_Check {
    abstract public function get_id(): string;          // 'missing-meta-desc'
    abstract public function get_category(): string;     // 'on-page' | 'technical' | 'links'
    abstract public function applies_to( SEODoc_Scan_Context $ctx ): bool;
    abstract public function run( SEODoc_Scan_Context $ctx ): array; // SEODoc_Issue[]
}
```

`SEODoc_Scan_Context` wraps the `WP_Post` (or term/archive object in Pro),
plus lazily-fetched extras (rendered HTML, headers) so a Check that doesn't
need an HTTP fetch never triggers one. ~45 Free checks and Pro's additional
checks (thin-content, duplicate-content signals, archive/author-page
checks, etc.) are just more classes registered against the same registry —
this is what keeps Step 6 (SEO checks) additive instead of a rewrite.

## 6. Database

Custom tables (`wp_seodoc_*`), not postmeta/options, because issue/link/404
volumes on a real site (thousands of rows) need real indexes and range
queries the postmeta table isn't built for. Full DDL comes in Step 3; the
architectural commitment here is:
- `dbDelta`-based migrations, schema version stored in an option, checked
  on `plugins_loaded` and on Pro activation (Pro adds its own tables the
  same way, versioned independently).
- Every table gets `site_id`-agnostic design but multisite-safe (no
  hardcoded `$wpdb->prefix` assumptions that break under `switch_to_blog`).
- Retention: 404 logs and scan-history rows are pruned by a scheduled
  cleanup (configurable window), so the plugin doesn't grow unbounded on a
  busy site — this is a Free-tier requirement, not just a Pro nice-to-have.

## 7. Admin UI

- React (via `@wordpress/scripts`, already ships with core — zero extra
  build dependency for site owners) talking to the `seodoc/v1` REST API.
  Assets are enqueued **only** on WP SEO Doctor's own admin screens
  (`admin_enqueue_scripts` gated on `$hook`), never site-wide.
- REST routes use `permission_callback` on every route (capability
  `manage_options` by default, filterable to a custom `seodoc_manage` cap
  for agencies that want to delegate without full admin), plus the standard
  WP REST nonce (`X-WP-Nonce`) — no custom auth scheme.
- No page-builder, no admin-wide CSS/JS injection, no frontend assets
  except the minimal 404-logging hook in §8.

## 8. Frontend footprint

Deliberately almost zero:
- 404 monitor hooks `template_redirect` only when `is_404()` is true, does
  one lightweight insert (rate-limited/deduped by URL+day to avoid a
  hit-count row per request), no JS, no frontend asset enqueue at all.
- Redirect Manager hooks `template_redirect` early, does an indexed lookup
  by requested path, issues the redirect, or falls through — no measurable
  cost on non-redirected requests.
- Everything else (scanning, link graph, GSC, AI) runs in admin/Cron
  context only.

## 9. Security baseline (binding for every later step)

- **Safe HTTP Client**: single wrapper (`SEODoc_Http::get()`) around
  `wp_remote_get` used by *every* feature that fetches a URL (broken-link
  check, redirect-chain follow, sitemap/robots fetch, future AI calls to
  our own license/API server). It enforces: `wp_http_validate_url()`,
  blocks loopback/private/link-local IP ranges (SSRF guard, checked after
  DNS resolution, not just on the hostname string, to stop DNS-rebinding),
  caps redirect count, caps timeout, and is the only code path allowed to
  make outbound requests.
- **Redirect Manager** validates destinations the same way — no open
  redirect via user-supplied target.
- All DB access via `$wpdb->prepare()`; all REST input sanitized on the
  way in and escaped on the way out; nonces on every state-changing
  request; capability check before any nonce check (fail closed).
- No auto-applied bulk changes: every fix action is preview → confirm →
  apply → undo, enforced at the REST-handler level, not just in the UI (so
  a crafted request can't skip the preview step).

## 10. Compatibility layer

On `plugins_loaded`, detect Yoast/Rank Math/AIOSEO by class/constant
presence. When one is active, Checks that would duplicate that plugin's
canonical/title/meta output (e.g. "missing SEO title") read that plugin's
generated value instead of computing our own competing one, and the
admin notice explains we're running in complementary mode. This is a
Free-tier requirement since most real installs already have one of these
active.

## 11. Open questions before Step 2

1. Plugin text-domain/slug: proposing `wp-seo-doctor` — confirm no
   conflict check needed yet since this repo copy won't be submitted to
   .org until later, but worth locking the slug now since it's threaded
   through file/class/table names from Step 2 onward.
2. Minimum WP version: proposing WP 6.0+ / PHP 7.4+ as stated in the brief
   — confirm.
3. Action Scheduler: proposing the bundled library (MIT, same one
   WooCommerce uses) for the batch queue rather than hand-rolling one on
   raw WP-Cron. Confirm OK to bundle it (it's a common, review-safe
   inclusion, ships as plain PHP, no build step).

If this all looks right, Step 2 is the concrete folder structure (file by
file, matching the modules above) followed by Step 3's full table DDL.
