# WP SEO Doctor — Step 6: SEO Checks

## What landed

26 concrete checks (20 per-object `Check`s + 6 `Scan_Level_Check`s), all
registered centrally in `includes/class-default-checks.php`:

| Category | Per-object | Scan-level | Total |
|---|---:|---:|---:|
| On-page | 9 | 2 (duplicate title/description) | 11 |
| Technical | 6 | 3 (HTTPS, robots.txt, sitemap) | 9 |
| Links | 6 | 0 | 6 |
| **Total** | **20** | **6** | **26** |

Plus one shared helper: `includes/checks/class-seo-meta-reader.php`, used
by every title/description check to read whatever Yoast/Rank Math/AIOSEO
has already set (falling back to core WP title/nothing) instead of
computing a value that would compete with an active SEO plugin (Step 1
§10).

Every on-page bullet and every technical bullet from the brief's Free
feature list (§3) now has exactly one check covering it. See the mapping
table below.

## Why `checks/links/` isn't broken-link/orphan/404 detection

The brief's Free "Links" bullets are: basic broken internal links, limited
external broken-link detection, basic 404 monitoring, basic orphan-page
detection. None of those can run as a per-object `Check` against a single
`Scan_Context` — they need either the site-wide link graph (orphan
detection needs to know every page's *incoming* link count, not just what
one page links out to) or live HTTP verification of every discovered URL
(broken-link detection). Both were already scoped to their own modules in
Step 1/2 (`includes/links/`, Step 9's Internal Linking Engine; broken-link
scanning and the 404 monitor, Step 10) — building a second, incompatible
broken-link mechanism here would mean throwing it away when Step 9/10
lands.

What Step 6's `checks/links/` covers instead: six **page-level
link-hygiene** issues detectable from a single page's DOM — empty anchor
text, placeholder hrefs (`#`, `javascript:`), an unusually high external
link count, missing `rel="noopener"` on `target="_blank"` links, internal
links marked `nofollow`, and internal links still pointing to `http://` on
an https site. These are genuine, real checks, just not the ones named in
the brief's Links bullets — those land in Step 9/10 as planned.

## Cross-object checks: duplicate title/description

`Duplicate_Title_Detector` and `Duplicate_Meta_Description_Detector`
extend `Scan_Level_Check` rather than `Check`, because flagging a
duplicate requires comparing every post's title against every other
post's — there's no way to answer "is this duplicated?" from one object in
isolation. They run once per completed scan (`Scan_Level_Check_Runner`,
hooked to `seodoc_scan_completed`), grouping titles/descriptions in memory
across a paginated `WP_Query`.

**Documented limitation:** this loads one effective title per published
post into memory to group them, which doesn't scale indefinitely for a
site with hundreds of thousands of posts. Free's MVP scope accepts that;
if it proves to be a real problem in practice, an SQL-side grouped
comparison (via a dedicated computed-title column, refreshed per scan)
is the fix — flagged as a candidate improvement rather than solved
speculatively here.

## Site-wide checks also went `Scan_Level_Check`

HTTPS/robots.txt/sitemap availability aren't about any one page either —
`Https_Site_Check`, `Robots_Txt_Check`, and `Sitemap_Availability_Check`
all run once per scan, using the same base class as the duplicate
detectors even though they're not doing cross-object comparison. This
reuses one execution model for "anything that isn't naturally per-object"
rather than inventing a third abstraction.

## The registration file, not self-registering checks

Every check is listed once, in `Default_Checks::get_checks()` /
`get_scan_level_checks()`, rather than each check class calling
`seodoc_register_check()` on itself. For 26 (soon ~40-50 with Pro's
additions) checks, one auditable list beats 26 scattered `add_action`
calls — it's the single place to see everything that's registered, and
the single place Pro's `seodoc_register_check()` calls (from its own,
separate registration file) get added alongside without touching this
one.

## Reconciling the "~40-50 checks" figure

Free alone lands at 26 in this step. The roadmap's ~40-50 is Free + Pro
combined: Step 12 adds Pro's thin-content-per-post-type nuance,
duplicate-content signals, archive/author/attachment checks, and deeper
indexability analysis on top of this same `Check`/`Scan_Level_Check`
registry — via `seodoc_register_check()`/`seodoc_register_scan_level_check()`
from Pro's own bootstrap, the same extension points Free itself uses
internally.

## Testing note

These checks are lint-clean (`php -l`) but not yet run against a live
WordPress install in this session — there's no WP environment attached
here to scan a real site against. Steps 15/18 (security and final QA)
are the right point to run an actual scan end-to-end and sanity-check
real output, not this step.
