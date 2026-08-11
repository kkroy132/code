# WP SEO Doctor — Step 7: Issue Engine

Step 5 already built the upsert/resolve core (`Issue_Engine::record()` /
`record_for_check()`). This step adds the two pieces the brief calls out
specifically: SEO Health Score and Fix First / Action Plan ranking.

## Health Score (`includes/issues/class-health-score.php`)

```
score = 100 × (1 − weighted_open_issues / max_possible_weighted_issues)
```

- `weighted_open_issues` — every currently-open issue site-wide (not just
  this scan's new findings), each multiplied by a severity weight
  (critical 10, high 5, medium 2, low 1).
- `max_possible_weighted_issues` — `total_items × check_count × 10`, a
  normalizing ceiling roughly meaning "every check flagged critical on
  every scanned object." This is what keeps the score comparable between
  a 20-page site and a 5,000-page site with the same *rate* of problems,
  rather than penalizing the larger site just for having more pages.

This is documented as a **heuristic**, not presented as a precise
industry-standard metric — the brief's "no fake metrics" principle (§14,
about AI features) applies just as much to a first-party score: it should
be legible and explained, not a black-box number implying more precision
than it has.

Computed and written back onto the scan row by
`Health_Score_Recorder`, hooked to `seodoc_scan_completed` at **priority
20** — deliberately after `Scan_Level_Check_Runner`'s default priority 10
on the same action, so HTTPS/robots.txt/sitemap/duplicate-title issues
are already recorded before the score is computed. Scoring against a
partial issue set (before scan-level checks ran) would produce a
misleadingly-high number every time.

`Scan_Controller::start()` already captures the previous completed scan's
`health_score` into the new scan's `previous_health_score` column (built
in Step 5) — that's what powers the "↑ 8 points since last scan" delta in
the Overview dashboard (Step 8).

## Fix First / Action Plan (`includes/issues/class-action-plan.php`)

Groups open issues by `(check_id, category, severity)`, ranks by
`impact_score = severity_weight × affected_count`, returns the top N with
a representative sample title and up to 5 sample URLs per group. This is
what turns "146 open issues" into:

```
1. Fix 8 broken internal links  — impact 40 (high × 8)
2. Fix 3 noindex pages          — impact 15 (high × 3)
3. Add links to 5 orphan pages  — impact 10 (medium × 5)
```

matching the brief's §5/§17 "Fix First" examples directly — same ranking
mechanism drives both the dedicated Action Plan screen and the Overview
dashboard's top section.

**Why `MIN(title)` instead of grouping on the raw `title` column:** each
open issue's `title` is instance-specific (e.g. "3 image(s) missing ALT
text" has the count baked into the string per-row), so it can't be part of
`GROUP BY` under `ONLY_FULL_GROUP_BY` (MySQL's default since 5.7.5) without
being aggregated. `MIN(title)` picks one representative example for
display — Step 8's admin UI is expected to build the actual grouped
copy ("18 pages missing ALT text") from `affected_count`, not display
`sample_title` as authoritative.

## What Step 7 deliberately doesn't touch

The upsert/resolve mechanics from Step 5 (`Issue_Engine::record()` /
`record_for_check()`) are unchanged — this step only adds two read-side
consumers (`Health_Score`, `Action_Plan`) on top of the same
`wp_seodoc_issues` rows, exactly as flagged when the minimal engine landed
in Step 5.
