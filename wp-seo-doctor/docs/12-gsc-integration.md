# WP SEO Doctor — Step 12: Google Search Console Integration (Pro)

Built after Step 14 per the resequencing decision: Pro's bootstrap,
`seodoc_pro_loaded` hook, and `License_Manager` all already existed for
this step to attach to.

## What landed — `wp-seo-doctor-pro/includes/gsc/`

- `class-oauth.php` — the Google OAuth flow, proxied through the
  vendor's own backend (`SEODOC_PRO_API_BASE`, filterable via
  `seodoc_pro_api_base`) rather than embedding a Google client secret in
  the plugin. **Why this matters, not just style**: a client secret
  shipped inside any WordPress plugin — even a paid one — is extractable
  by anyone with a copy of the files; it can never actually stay
  confidential client-side. The correct, standard pattern (the same one
  Google's own Site Kit plugin uses) is a server-side proxy holding the
  real secret, performing the code/token exchange itself, and handing
  the plugin back only a token — never the secret. `Oauth` implements
  exactly that shape: `get_authorize_url()` sends the user to the proxy
  (which redirects to Google's real consent screen), `complete_connection()`
  exchanges a short-lived signed grant code (not a raw Google code) for
  tokens via the proxy, and `get_valid_access_token()` refreshes through
  the same proxy rather than calling Google's token endpoint directly.
- `class-gsc-client.php` — calls the real, documented Google Search
  Console API v1 `searchAnalytics.query` endpoint. Every request still
  goes through `Http_Client` (now with a new `post()` method, added this
  step for exactly this JSON-POST use case) — the Step 1 §9 rule that
  every outbound fetch in the plugin goes through one wrapper holds for
  Pro too.
- `class-opportunity-finder.php` — the "SEO Opportunity Finder" (Step 1
  §11): pages at position 8-20 with 500+ impressions in the trailing 28
  days. A documented threshold, not a ranking prediction.
- `class-content-decay.php` — "Content Decay" (Step 1 §12): compares
  click totals between two equal trailing windows per page, flags a
  ≥30% decline (pages with under 20 prior clicks are skipped — too little
  signal for a percentage to mean anything).
- `class-seo-opportunity-check.php` / `class-content-decay-check.php` —
  thin `Scan_Level_Check` wrappers (extending **Free's** base class
  directly, since Pro requires Free active) turning both into Issues, so
  they appear in the same audit/Fix-First flow as every other problem —
  category `content`, gated on `License_Manager::is_valid_license()`
  **and** `Oauth::is_connected()`, not merely "Pro is installed."
- `class-gsc-controller.php` — REST routes for status/connect/callback/
  disconnect/opportunities/content-decay. Deliberately reuses Free's
  `seodoc/v1` namespace rather than a separate `seodoc-pro/v1` — one
  React admin app, one API, and Pro can't run without Free anyway, so
  fragmenting the namespace (as originally speculated in Step 2's folder
  plan) would add nothing.
- `class-pro-registrar.php` — the one auditable place Pro hooks into
  Free's registries: adds `Gsc_Controller` via the `seodoc_rest_controllers`
  filter, registers both Scan_Level_Checks on `seodoc_pro_loaded`. Same
  "one file, not scattered `add_action` calls" principle as Free's
  `Default_Checks` (Step 6).

## No fake metrics, per the brief's own rule

Both `Opportunity_Finder` and `Content_Decay` surface real GSC numbers
against a plainly-stated, fixed rule (position range + impression floor;
percentage decline + minimum prior volume) — never a score, a prediction,
or a "ranking probability." This is the same principle Step 1 §14
states for AI features ("no fake metrics like a ChatGPT Ranking Score"),
applied here to GSC-derived Issues too.

## What required no changes to Free at all

Both checks extend `SEODoc\Checks\Scan_Level_Check` and register through
`seodoc_register_scan_level_check()` — the exact extension point built in
Step 6 and proven safe for late (post-Free-boot) registration by this
step's `Check_Registry` lazy-loading fix. Nothing in Free's codebase
changed to support GSC; the architecture built across Steps 4-11 handled
it as designed.
