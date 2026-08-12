# WP SEO Doctor — Step 19: Merged Into a Single Plugin

Per explicit request: the Free/Pro split from Step 1 (two separate
plugins, Pro requiring Free active) is retired in favor of one plugin
with license-gated features — the standard "freemium in one download"
pattern (as opposed to Free-on-.org-plus-separate-paid-download).

## What changed

- `wp-seo-doctor-pro/` is deleted entirely. Its GSC (`includes/gsc/`),
  AI (`includes/ai/`), and licensing (`includes/licensing/`) code, plus
  the two content-category checks and their REST controllers, now live
  directly under `wp-seo-doctor/includes/`.
- Every moved class's namespace changed `SEODocPro\*` → `SEODoc\*` (and
  the two check classes' folder gained a matching `Content\` namespace
  segment, since they're filed under `includes/checks/content/`) — one
  namespace for one plugin, not two namespaces implying two codebases.
  Verified by actually triggering the autoloader against all 33
  core/controller/check classes and confirming every one resolves to a
  real class, not just a syntax check.
- Text domain in every moved file: `'wp-seo-doctor-pro'` → `'wp-seo-doctor'`
  (there's only one plugin's translations to load now).
- `Feature_Gates` and the two GSC/AI REST controllers are now registered
  directly in `Plugin`'s core-module list / `Rest_Api`'s controller
  list — no more `Pro_Registrar` indirection, since there's no cross-plugin
  boundary left to bridge. The two license-gated checks are registered
  in `Default_Checks` alongside every other check, same as any other
  check — their own `run()` already returns no issues at all when
  unlicensed or GSC isn't connected, so unconditional registration is
  safe.
- `wp-seo-doctor-pro.php`'s entire reason to exist — the `Requires
  Plugins` dependency header, the `plugins_loaded` priority-20 boot
  delay, the `function_exists('seodoc')` guard — is gone, because
  there's no second plugin file to coordinate with anymore. Everything
  boots once, in `Plugin::boot()`.
- `License_Manager`'s Freemius accessor constant renamed
  `wp_seo_doctor_pro_fs` → `wp_seo_doctor_fs`, matching a single-product
  Freemius integration instead of a Pro-specific one.
- `readme.txt` gained a "Premium Features (License Required)" section
  describing what a license key unlocks in this same download, replacing
  the separate Pro `readme.txt` (deleted).

## What did NOT need to change

The license-gating logic itself was already correct for this model
without modification — `Feature_Gates` was always checking
`License_Manager::is_valid_license()`, never "is a second plugin active."
The GSC/AI REST endpoints already returned locked/empty responses when
unlicensed. The only thing that made it a "Pro plugin" before was
*distribution* (a second zip), not the gating mechanism — so removing
the second zip didn't require touching how any feature decides whether
it's unlocked.

## Why "This section is coming soon" still shows on some screens

Unrelated to this merge — Suggestions, 404 Monitor, Redirects, GSC, and
AI Assistant have working REST APIs (now all in this one plugin) but no
dedicated React screen yet; only Overview is built (Step 8/17's
documented gap). Merging the plugins didn't add or remove any screens.
