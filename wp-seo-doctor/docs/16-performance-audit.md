# WP SEO Doctor — Step 16: Performance Audit

Same methodology as Step 15: verify by reading the actual query patterns
and call graphs, not by re-asserting design intent already written down.
This pass found one significant fix, one worthwhile addition, and
confirmed several things already built correctly hold up under scrutiny.

## Fixed

### Missing index on the single hottest per-object query in the scanner

`Issue_Engine::resolve_missing()` runs `UPDATE ... WHERE object_id = %d
AND object_type = %s AND status = 'open' ...` — and it runs **once per
post, every scan** (called from `record()`, which `Batch_Processor`
calls for every queue row). The `issues` table (Step 3) had indexes on
`(status, severity)` and `category`, but nothing on `(object_id,
object_type)` — meaning this query was a full table scan on every single
post processed, for any site that had accumulated a non-trivial number
of issue rows. This is the single biggest scanner performance bug found
in this pass, and it's exactly the kind of thing that's invisible in
testing against a handful of rows and only shows up as a site's issue
history grows.

**Fix:** added `KEY object_lookup (object_id, object_type)` to the
`issues` table, `Schema::DB_VERSION` bumped `1.0.0` → `1.1.0` so
`maybe_upgrade()` applies it to already-installed sites via `dbDelta`
(additive index changes are exactly what `dbDelta` handles safely).

### Redirect lookup now cache-backed

`Redirect_Matcher` runs on **every** front-end request (Step 11) and,
until now, always queried `wp_seodoc_redirects` even though the
overwhelming majority of requests match no redirect at all. New
`Redirect_Cache` wraps the lookup in WP's object cache, caching both hits
and the "no redirect for this path" negative result, invalidated on any
`Redirect_Manager` create/update/delete via a version-counter cache-bust
(bump one autoloaded option, every old cache key becomes orphaned at
once — no need to enumerate or delete individual keys).

**Honest about the actual impact**: WordPress' default object cache is
per-request only. On the median install (no Redis/Memcached plugin),
this changes nothing — the DB query still runs on every request, exactly
as before. The sites where this matters — high enough traffic for a
per-request indexed lookup to be worth avoiding — are also the sites
most likely to already run a persistent object cache, so the benefit
lands where it's actually needed. Either way it's strictly additive:
correct, standard behavior on sites without persistent caching, real
savings on sites with it.

## Confirmed correct (verified, not re-asserted)

- **At most one HTTP fetch per post per scan, no matter how many checks
  need rendered HTML.** `Scan_Context::fetch_html()`/`get_dom()` are
  memoized per-instance (Step 5), and Batch_Processor creates exactly one
  `Scan_Context` per queue row, passed to *every* scanner stage
  (`Link_Graph`, `External_Link_Collector`) and *every* applicable Check
  (roughly 15 of the 26 Free checks need DOM access — Missing_H1 through
  Insecure_Internal_Link). Traced the actual call sites to confirm this
  holds rather than assuming the memoization from Step 5 was still wired
  correctly after Steps 9-10 added more DOM-consuming stages.
- **Duplicate/orphan/suggestion cross-object passes never trigger the
  per-object HTTP fetch.** `Duplicate_Title_Detector` and
  `Duplicate_Meta_Description_Detector` construct a `Scan_Context` per
  published post (necessarily — they compare every post against every
  other), but only ever call `Seo_Meta_Reader`'s postmeta-only /
  `get_the_title()` paths, never `get_dom()`. `Orphan_Detector` and
  `Suggestion_Engine` don't construct `Scan_Context` at all. So the
  "visit every published post" passes that run after the main scan add
  zero extra HTTP fetches, only cheap DB/postmeta reads.
- **Admin assets stay scoped.** `Admin\Assets::maybe_enqueue()`'s
  `strpos( $hook, 'seodoc' )` check is the very first thing that runs,
  before any file-existence checks or option reads — negligible overhead
  on every *other* admin page, confirmed by reading the method top to
  bottom rather than assuming the Step 8 description still matched the
  code.
- **No unbounded work anywhere in the batch pipeline.** Scanner batch
  size (20/tick), broken-link-check batch size (15/tick), and the
  suggestion engine's monthly quota are all filterable but never
  unbounded by default — re-confirmed no `-1`/`0`/`PHP_INT_MAX` default
  limits crept in anywhere across Steps 5-13.

## Acknowledged design characteristic, not a bug

A full scan makes one self-referential HTTP request per post back to the
site's own web server (needed for DOM-based checks — canonical tags,
meta robots, schema presence, and 15+ other checks all require actual
rendered `<head>`/theme output that raw `post_content` doesn't contain).
For a 1,000-post site that's 1,000 requests, spread across ~50 Action
Scheduler ticks at the default batch size rather than one request burst.
This is inherent to doing DOM-based technical SEO checks accurately (the
same reason dedicated site-audit tools fetch rendered pages rather than
trusting stored content) — the batching is what keeps it from causing
timeouts or resource spikes, not something to eliminate.

## Not re-litigated here

The duplicate-detector and orphan-detector in-memory scaling notes from
Steps 6/9 (loading one title/hash per published post to compare) stand
as already documented — re-confirmed still accurate, not re-explained
here.
