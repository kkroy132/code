# WP SEO Doctor — Step 10: Broken Links + 404 Monitor

## What landed

**Broken Link Intelligence:**
- `includes/links/class-external-link-collector.php` — catalogs outgoing
  external links as a scanner stage, mirroring Step 9's `Link_Graph` but
  for `link_type = 'external'` edges (`Link_Graph` stayed internal-only,
  as scoped in Step 9's doc).
- `includes/links/class-broken-link-checker.php` — the actual HTTP
  verification. Runs on its **own** Action Scheduler loop
  (`seodoc_check_broken_links`, 15 targets/tick), never inline during the
  main content scan — checking every discovered link's live HTTP status
  while scanning every page would multiply scan time. Kicked off once per
  scan (`seodoc_scan_completed`, priority 40 — after suggestion
  generation's 30) and keeps re-scheduling itself while unverified/stale
  (7+ days old) targets remain.
- `Http_Client` gained `get_no_redirect()`/`head_no_redirect()` — single-
  hop, non-auto-following variants of the existing `get()`/`head()`. The
  original methods silently resolve through redirects and return only the
  final response, which is correct for "fetch me the page" callers but
  wrong here: a broken-link check needs to see the redirect *itself*
  (status code, `Location` header) as data, not have it disappear. Both
  variants go through the same `validate_url()` SSRF gate. This groundwork
  also directly sets up Step 11's redirect-chain detector, which has the
  same "must see each hop" requirement.
- `Issue_Engine` gained `upsert_single()`/`resolve_single()`. The existing
  `record()`/`record_for_check()` both assume "this call evaluates its
  entire domain in one pass" (a scan, or a scan-level check's full run) —
  wrong for `Broken_Link_Checker`, which verifies a small batch per tick.
  Calling `record_for_check()` per 15-URL batch would have incorrectly
  **resolved** every other still-broken link the batch didn't happen to
  touch. The single-row variants avoid that: upsert what was just found,
  resolve exactly the one check_id+url that's now confirmed OK, no bulk
  side effect.
- Broken links are recorded **one Issue per broken target**, not per edge
  — a dead URL linked from 5 pages is one issue row (`details.source_pages`
  lists all 5), matching how the brief's Broken Link Intelligence fields
  read (Step 1 §8): the broken target is the fact, the linking pages are
  context on it. `check_id` is `broken-internal-link` (severity high) or
  `broken-external-link` (severity medium) depending on `link_type`.

**404 Monitor:**
- `includes/monitor-404/class-monitor.php` — the only new frontend hook
  in the plugin (`template_redirect`, gated on `is_404()`). Logs
  **aggregates**: one row per URL, `hit_count` incremented on repeat
  hits, not one row per request — the schema decision from Step 3.
- `includes/monitor-404/class-suggestion-matcher.php` — suggests a
  redirect destination using WordPress' own post search (the 404 path's
  slug as a search term) rather than a bespoke similarity algorithm.
  Deliberately **not** computed on every 404 hit — that would be exactly
  the "expensive work during a normal frontend request" Step 1 §30 rules
  out. It's computed lazily, per-row, only for the page of results an
  admin is actually viewing (`Monitor_404_Controller`, 20 rows/page).
- `GET /404s` is the new REST route. "Create 301 Redirect" as an action
  is correctly deferred to Step 11, once `Redirect_Manager` exists.

## Correction: the top-level `public/` folder is gone

Step 2's plan had `public/class-frontend-hooks.php` as a plugin-root
sibling to `includes/`. Two problems surfaced only when actually writing
this class: `public` is a reserved PHP word and cannot be used as a
namespace segment (`namespace SEODoc\Public` is a parse error), and the
Autoloader (Step 4) only ever maps `SEODoc\*` to paths under `includes/`
— a class outside that tree was never actually autoloadable under the
convention every other class in this plugin uses. Since `Monitor` already
self-registers its own hook in its constructor, exactly like every other
module in `seodoc_core_modules`, the planned "frontend hooks aggregator"
file would have added nothing beyond what the existing pattern already
does. `includes/monitor-404/class-monitor.php` (namespace
`SEODoc\Monitor_404`) needed no such folder at all; the empty `public/`
directory has been removed.

## Known gap, carried forward from Step 9's design

`Health_Score::compute()` normalizes against
`count(Module_Registry::get_checks()) + count(get_scan_level_checks())`.
Broken-link issues bypass both registries entirely — `Broken_Link_Checker`
calls `Issue_Engine::upsert_single()`/`resolve_single()` directly, since
its incremental, multi-tick nature doesn't fit the `Check`/`Scan_Level_Check`
contract (a "check" that "runs once" isn't what this is). That means the
Health Score's normalizing denominator slightly undercounts the total
possible check surface once broken-link monitoring is active. Documented
here rather than silently left as an unexplained inconsistency between
Step 7's scoring model and this step's async checker; not fixed now
because the right fix (does the denominator need a third, non-registry
term for "ongoing async checks," and how many "check slots" does that
represent per object) is a scoring-model decision, not a bug to patch
inline.
