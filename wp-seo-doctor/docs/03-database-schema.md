# WP SEO Doctor — Step 3: Database Schema

## Tables

The brief specifies seven tables (`scans`, `issues`, `links`, `404`,
`redirects`, `suggestions`, `reports`). One more is added because it's
required by the Step 1 scanner architecture and shouldn't be bolted on
later:

- **`wp_seodoc_queue`** — the scanner's unit-of-work table (§4 of Step 1:
  "no full-site crawl in one request"). A scan is decomposed into queue
  rows *before* any checking happens; `wp_seodoc_scans` tracks the scan as
  a whole (progress, status, score), `wp_seodoc_queue` tracks each item
  still to be processed. Rows are deleted shortly after their scan
  completes (§ Retention below) — this table is working memory, not
  history.

## Cross-cutting decisions

**URL hashing for indexes.** Every table that stores a URL also stores a
`CHAR(32)` MD5 hash of it (`*_hash` columns). Reason: `VARCHAR(767)` in
`utf8mb4` is ~3068 bytes, which sits right at (and on some MariaDB/InnoDB
configs, over) the 3072-byte index-prefix limit — indexing raw URL columns
directly is fragile across hosts. Every lookup, join, and uniqueness
constraint goes through the fixed-width hash column instead; the raw URL
column stays unindexed and is only ever read, never filtered on directly.

**Severity/status as `VARCHAR`, not `ENUM`.** `ENUM` values are painful to
extend via `dbDelta` (some MySQL versions require a full table rebuild to
add a value) and Pro needs to add severities/statuses (e.g. GSC-informed
re-ranking) without a migration. Cost is a few bytes per row; worth it for
extensibility. Application-layer constants (in the Check/Issue classes)
enforce the actual allowed values.

**Multisite.** No table name or query hardcodes a site's prefix — everything
goes through `$wpdb->prefix` read at call time, so `switch_to_blog()`
produces correct results and Pro's network-activation (Phase 4 agency
dashboard) can install per-site tables via `wp_initialize_site` without
touching this schema.

**Retention.**
- `wp_seodoc_queue`: rows deleted when their parent scan completes;
  reset to `pending` (from `processing`) after a stale-claim sweep if
  their `claimed_at` is older than the batch timeout, so a killed request
  doesn't lose the row (Step 1 §4).
- `wp_seodoc_scans`: history is capped by count (Free) / date range (Pro)
  via a scheduled prune, configurable in Settings — not enforced by the
  schema itself.
- `wp_seodoc_404`: stores **aggregates per URL** (`hit_count`,
  `first_seen`, `last_seen`), not one row per hit — this is what "efficient
  logging" (Step 1 §9/§30) means in practice: a 404'd URL hit 10,000 times
  produces one row that increments, not 10,000 rows. Per-hit referrer/UA
  history beyond "most recent" is a Pro-only raw log table, intentionally
  not in Free's default schema.
- `wp_seodoc_issues` / `wp_seodoc_links`: upserted by `(check_id, url_hash)`
  / `(source_hash, target_hash, anchor_hash)` respectively, so re-scans
  update existing rows instead of accumulating duplicates.

## Schema code

`wp-seo-doctor/includes/db/class-schema.php` (production code, not just a
spec — Step 4's activator calls `Schema::install()`, and `Schema::maybe_upgrade()`
runs on `plugins_loaded` per Step 1 §6):

See the file itself for the full `dbDelta`-formatted `CREATE TABLE`
statements. Table-by-table summary:

| Table | Purpose | Key indexes |
|---|---|---|
| `seodoc_scans` | One row per scan run (manual/scheduled/activation) | `status` |
| `seodoc_queue` | Scanner work units for the in-progress scan | `(scan_id, status)` |
| `seodoc_issues` | Detected problems, upserted across scans | unique `(check_id, url_hash)`, `(status, severity)` |
| `seodoc_links` | Internal/external link graph edges (doubles as broken-link table via `is_broken`) | unique `(source_hash, target_hash, anchor_hash)`, `target_hash`, `is_broken` |
| `seodoc_404` | Aggregated 404 hits per URL | unique `url_hash`, `(status, hit_count)` |
| `seodoc_redirects` | Redirect rules | `source_hash`, `status` |
| `seodoc_suggestions` | Internal-link suggestions incl. Free's monthly quota bucket | `(status, month_bucket)` |
| `seodoc_reports` | Generated CSV/email/agency report records | `(report_type, created_at)` |

`wp_seodoc_links` intentionally serves both "Internal Linking" (in/out
counts, orphan detection — `is_broken = 0`) and "Broken Link Intelligence"
(`is_broken = 1`, `http_status`, `redirect_target`) from Step 1's module map
— one edge table instead of two, since an internal link and a broken link
are the same underlying fact (a source page links to a target URL) with
different derived state.
