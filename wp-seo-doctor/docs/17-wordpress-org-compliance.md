# WP SEO Doctor — Step 17: WordPress.org Compliance Audit

## Fixed

### `readme.txt` didn't exist for either plugin

A genuine gap — Step 2 scoped it, no step actually wrote it. Free's
`readme.txt` now exists in the standard WordPress.org format (header,
description, installation, FAQ, screenshot descriptions, changelog).
Written to describe **only what's actually built** as of Step 16 — no
CSV export, no Settings screen, no email reports, because none of those
exist yet. Listing unbuilt features would be exactly the "fake
trialware" pattern Step 1 §22 rules out, just aimed at .org reviewers
and users instead of a paywall.

Two fields need a human before actual submission, called out explicitly
in the file: `Contributors` (a real WordPress.org username, which
doesn't exist for this session to invent) and `Tested up to` (set
conservatively to `6.5`, the plugin's own minimum — this repo has never
been run against a live WordPress install, so claiming compatibility
with a specific later version would be an unverified claim, not a
tested one).

Pro's `readme.txt` is lighter — Pro isn't distributed through
WordPress.org (it's the paid add-on, sold separately per Step 14's
architecture), so it documents what Pro adds and its licensing model
rather than following the .org submission template.

### A notice that could never actually be dismissed

Found by re-reading `Plugin_Detector::maybe_show_complementary_notice()`
against the guideline it was written to satisfy: it checked
`get_option( 'seodoc_dismissed_compat_notice' )` before showing, but
nothing anywhere ever *set* that option. The notice would have
reappeared on every single admin-page load of WP SEO Doctor's screens,
forever — on a real WordPress.org submission, an admin notice that
can't actually be dismissed (whether or not it's marked
`is-dismissible`) is a well-documented, common rejection reason, not a
theoretical one.

**Fix:** the notice now carries `is-dismissible` plus a
`data-seodoc-notice="compat"` marker; a small inline script (attached to
the already-enqueued `seodoc-admin` handle via `wp_add_inline_script()`,
so it's guaranteed to load after `wp-api-fetch`) listens for the
dismiss-button click and calls the new
`POST /seodoc/v1/notices/dismiss-compat` route
(`Notices_Controller`), which persists the dismissal. Same capability
gate as every other route (`permission_check`).

## Confirmed clean (verified, not assumed)

- **Text domain consistency**: every `__()`/`_e()`/`esc_html__()` call
  in Free uses exactly `'wp-seo-doctor'`, every call in Pro uses exactly
  `'wp-seo-doctor-pro'` — checked by extracting every text-domain string
  literal used anywhere in either codebase and confirming there's only
  ever the one value per plugin (no typos, no copy-paste from the
  other plugin).
- **Every `sprintf()` with a placeholder has its own preceding
  `/* translators: */` comment** — checked per-file by comparing
  `sprintf(` occurrence counts against `translators:` comment counts;
  zero files where the counts disagree.
- **No hidden tracking.** Free makes zero outbound requests for
  telemetry/analytics purposes — the only outbound requests anywhere in
  Free are feature-functional: checking the site's *own* robots.txt/
  sitemap, verifying HTTP status of links found in the site's *own*
  content, and fetching the site's *own* pages for DOM-based checks. Pro's
  GSC/AI/Freemius integrations are each explicitly user-initiated (click
  "connect," enter a license key) — nothing phones home by default on
  install.
- **Uninstall is opt-in only** (Step 4) — data isn't deleted without the
  site owner explicitly enabling it first.
- **Doesn't interfere with other plugins.** `Plugin_Detector` only reads
  whether Yoast/Rank Math/AIOSEO are active to adjust its own checks
  (skip computing a competing title/meta value) — it never disables,
  modifies, or overrides anything belonging to another plugin.
- **GPL-compatible licensing declared** in both plugin headers.
- **`Requires Plugins: wp-seo-doctor`** in Pro's header uses the real
  WordPress 6.5+ dependency mechanism correctly — it checks for an
  active plugin with that slug regardless of where Pro itself is
  distributed from, which is exactly the intended use of that header.

## Honest gaps, not swept under the rug

Two things surfaced by writing the readme.txt description that are worth
stating plainly rather than only living in scattered per-step notes:

1. **Three REST-backed features have no UI screen yet.** Suggestions
   (Step 9), 404 Monitor (Step 10), and Redirects (Step 11) are fully
   functional on the backend — real endpoints, real data, real actions —
   but the React admin app (Step 8) only has a built screen for
   Overview. Clicking into Links/404 Monitor/Redirects today shows the
   "coming soon" placeholder despite working data underneath. This is a
   real completeness gap for a WordPress.org-ready Free plugin, not
   something this audit step fixes (building three more full CRUD/review
   screens is feature work, not a compliance fix) — flagged clearly here
   and carried into Step 18's final assessment.
2. **No upsell UI exists anywhere**, despite `seodoc_is_pro_active()`
   being built for exactly that purpose since Step 4. Not a compliance
   problem (showing nothing is trivially compliant), but worth noting
   as unbuilt scope from the brief's §26 rather than silently omitting
   it from the record.
