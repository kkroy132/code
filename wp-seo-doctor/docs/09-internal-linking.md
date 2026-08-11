# WP SEO Doctor — Step 9: Internal Linking Engine

## What landed

- `includes/links/class-link-graph.php` — builds the internal link graph.
  Registered as a **scanner stage** (`seodoc_register_scanner_stage`, the
  Step 4 extension point that had no consumer until now) so it runs once
  per object during the same batch pass that runs Checks, not a separate
  crawl. Replaces (delete-then-reinsert) a source's outgoing-internal-link
  rows each scan, so removed links disappear from the graph instead of
  lingering. External links are skipped entirely — Step 10 owns those.
- `includes/links/class-orphan-detector.php` — reads the graph Link_Graph
  just built to find published pages with zero current internal inbound
  links, excluding the homepage. Paginated `WP_Query`, no unbounded
  `get_posts()`.
- `includes/checks/links/class-orphan-page-check.php` — a thin
  `Scan_Level_Check` wrapper turning `Orphan_Detector`'s findings into
  Issues (severity high, matching the brief's Fix First example), so
  orphan pages flow through the exact same audit/scoring/Fix-First
  pipeline as every other problem. Registered in `Default_Checks`
  alongside the Step 6 checks, not in the Links module — detection logic
  and issue-reporting stay separate so the future Links dashboard screen
  can call `Orphan_Detector` directly without going through the issues
  table.
- `includes/links/class-suggestion-engine.php` — generates internal-link
  suggestions for weakly-linked pages (fewer than 2 internal inbound
  links) by matching against other posts sharing a taxonomy term, storing
  results in `wp_seodoc_suggestions` with `month_bucket` for quota
  tracking. Runs automatically after each scan (`seodoc_scan_completed`,
  priority 30 — after Health_Score_Recorder's 20).
- `includes/links/class-suggestion-inserter.php` — the "Insert" step:
  appends the suggested link into exactly one source post, only when
  explicitly approved, one suggestion at a time.
- `includes/links/class-links-bootstrap.php` — registers the scanner
  stage and the suggestion-generation hook.
- `includes/rest-api/class-suggestions-controller.php` — `GET /suggestions`,
  `POST /suggestions/{id}/approve`, `POST /suggestions/{id}/dismiss`.

`includes/scanner/class-batch-processor.php` now actually calls
registered scanner stages per row (`run_scanner_stages()`), wrapped in
its own `try/catch` so a stage failure can't block Issue recording for
that row — this extension point existed since Step 4/5 but had no
consumer until Link_Graph.

## Free's 25/month quota, and how Pro raises it without touching Free

`Suggestion_Engine::remaining_quota()` reads the cap through
`apply_filters( 'seodoc_suggestion_monthly_quota', 25 )`. Free ships the
full generation mechanism; the cap is what's different between tiers.
Per the Step 1 rule that Free never checks "is Pro active" to gate
functionality, Pro doesn't get a special code path here — when Step 14
builds the Pro plugin, it hooks this one filter to return a much higher
number (or `PHP_INT_MAX`). Free's code doesn't change.

## Why shared-taxonomy matching, not smarter relevance

The brief's "topic clusters" and "pillar pages" (§6) are explicitly Pro
territory. Free's matching — share a taxonomy term with the target,
don't already link to it, rank by term-overlap count — is real and
useful without needing an embeddings/NLP dependency, which would
conflict with Step 1 §30's "lightweight, no unnecessary dependencies"
principle for a feature that runs during ordinary batch scans. It's also
honestly scoped: this only surfaces a candidate when a genuine content
relationship (shared category/tag) exists, never a fabricated "AI thinks
these are related" claim.

## The one content-mutating endpoint in the plugin so far

`POST /suggestions/{id}/approve` is the first REST route that writes to
a post's content. It:
1. Re-fetches the suggestion by id and requires `status = 'pending'` —
   a second approve call on an already-inserted suggestion 404s instead
   of double-inserting.
2. Touches exactly one post (`wp_update_post` on the suggestion's
   `source_object_id`), appending one `<p><a>...</a></p>` — never a bulk
   operation.
3. Runs through `wp_kses_post()` before being appended, `esc_url()`/
   `esc_html()` on the interpolated pieces.

What it doesn't have yet: an undo. Step 1 §34 calls for
preview/confirm/apply/**undo** on every fix. Approve here is
confirm+apply; there's no stored "previous content" snapshot to revert
to yet. Flagged explicitly rather than silently shipped incomplete —
worth adding before this ships, either as a revision-based undo
(WordPress already keeps post revisions, so the simplest fix is likely
"read the pre-insert revision" rather than a bespoke snapshot table) or
tracked as a fast-follow. Not fixed in this step because it's a genuine
design decision (which mechanism) rather than a one-line gap.
