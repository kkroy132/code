# WP SEO Doctor — Step 13: AI Layer (Pro)

## Product decision this step follows

AI provider: **provider-agnostic client, vendor decided later.** In
practice this meant the natural design was already the right one for a
different reason too — see below.

## What landed

- `includes/class-vendor-api.php` — a shared license-key-authed JSON
  client for the vendor's own backend, generalizing the proxy pattern
  Step 12's `Oauth` introduced. `Oauth` itself wasn't refactored onto
  this (already correct, no functional reason to touch it) — both read
  the same `seodoc_pro_api_base` filter, so they can't diverge on the
  actual base URL despite not sharing code.
- `includes/ai/class-ai-client.php` — `request( $task, array $payload )`
  maps a task name to a vendor endpoint and posts structured JSON.
  **No AI vendor is named anywhere in this codebase** — not because a
  choice was deferred as a placeholder, but because the correct
  architecture for shipping AI features in a distributed plugin routes
  through a server-side proxy regardless of which model answers the
  call, for the identical reason Step 12's OAuth does: an AI provider
  API key embedded in plugin code is exactly as extractable as an OAuth
  client secret. Which model the vendor's backend calls is entirely that
  server's decision, changeable there without a plugin update, and this
  file never needs to know.
- `includes/ai/class-ai-assistant.php` — builds the actual task payloads
  for the brief's §13 feature list: `explain_issue()`, `generate_title()`,
  `generate_description()`, `generate_alt_text()`, `summarize_action_plan()`,
  and `answer_question()` (the brief's own "Why is this page not SEO
  optimized?" example). Every payload is assembled from this plugin's
  **own real data** — a `wp_seodoc_issues` row, actual post content, or
  `Action_Plan::get()`'s real severity-weighted ranking — never a raw,
  open-ended prompt string built from arbitrary input alone.
- `includes/rest-api/class-ai-controller.php` — REST routes for each
  capability, plus `POST /ai/apply-alt-text/{id}`.
- `Pro_Registrar` gained the AI controller alongside GSC's.

## Why structured payloads, not free-text prompts

This is the same rule the brief states for the AI Assistant generally:
"analyze available plugin data and answer using actual detected issues
... do not invent SEO problems" (§13). Sending `{check_id, severity,
details}` from a real Issue row — rather than relaying whatever text a
user typed — is what keeps the model's context grounded in what the
plugin actually found. It also keeps prompt engineering entirely
server-side, so it can be iterated on without a plugin release, and
narrows the surface for prompt-injection-style abuse: the plugin isn't a
generic pass-through for arbitrary text into a model call.

## Generate vs. apply — one complete loop, one deliberately incomplete

`apply_alt_text()` is the one AI output wired all the way through to an
actual applied change (`update_post_meta` on `_wp_attachment_image_alt`),
mirroring Step 9's `Suggestion_Inserter`: generate, then a **separate**,
explicit apply action — never auto-applied.

Title and meta-description "apply" are **not** built yet. This is a
correctness call, not a shortcut: which meta key to write depends on
which SEO plugin is active — `_yoast_wpseo_title`, `rank_math_title`,
`_aioseo_title`, or falling back to `post_title` if none is active. The
detection groundwork already exists (`SEODoc\Compat\Plugin_Detector`,
Step 4), but deciding the exact fallback behavior when no SEO plugin is
active (overwrite `post_title` itself? refuse?) is a real product
decision, not something to guess at silently. Flagged here the same way
Step 9's missing suggestion-undo was: a genuine boundary, not an
oversight.

## What isn't fabricated, again

No `freemius/`-style SDK question applies here — this step's "not
fabricated" item is `SEODOC_PRO_API_BASE` itself: a placeholder pointing
at a backend that doesn't exist yet. Every method in `Ai_Client` and
`Vendor_Api` is written to be correct the moment that backend is real
(structured request/response shape, license-key auth header, JSON in/
JSON out), and to fail as a clear `WP_Error` — never a silent success
guess — until then.
