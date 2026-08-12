# WP SEO Doctor — Step 15: Security Audit

A systematic pass across both plugins against Step 1 §31's checklist —
not a re-read of what was already documented, but actual `grep`-driven
verification of every instance of each pattern, which surfaced two real
issues (fixed) and confirmed the rest hold. Findings below are organized
by outcome: fixed, evaluated-and-accepted, and confirmed-clean.

## Fixed

### 1. Open redirect via protocol-relative destination (`Redirect_Manager`)

`validate_destination()` treated any string starting with `/` as a safe
"site-relative path," including `//evil.com/x` — which a browser resolves
as `https://evil.com/x` (protocol-relative), not a relative path.
Because `wp_parse_url('//evil.com/x', PHP_URL_SCHEME)` returns null for
such a string, it would have been correctly *rejected* had it fallen
through to the scheme-validation branch instead — the bug was the
site-relative check swallowing it before that branch ever ran.

**Practical severity: low.** Creating a redirect already requires
`manage_options` (or the filtered equivalent), and Free's Redirect
Manager already permits arbitrary external `http(s)` destinations by
design (documented in Step 11 — affiliate-link use cases need this). An
admin who could exploit this bypass could already create the identical
redirect through the legitimate scheme-checked path. Fixed anyway: input
validation should behave as documented — accept exactly the two stated
forms (site-relative path, full `http(s)` URL) and nothing else — not
because of a privilege-escalation risk here, but because "validated" and
"actually is what it claims to be" shouldn't quietly diverge.

**Fix:** the site-relative branch now explicitly excludes strings
starting with `//`, so they fall through to (and are correctly rejected
by) the scheme check.

### 2. `seodoc_daily_maintenance` scheduled since Step 4, nothing listened to it

Found while checking whether the 404 monitor has any real defense
against unbounded row growth: the schema's per-URL aggregation (Step 3)
bounds repeat hits to the *same* URL to one row, but does nothing to
bound the number of *distinct* URLs recorded over time — a scanner
probing thousands of nonexistent paths creates thousands of rows, no
limit. Step 3's doc stated a retention policy ("capped by count/date
range via a scheduled prune... not enforced by the schema itself") that
was never actually implemented — `Activator::activate()` has scheduled
the `seodoc_daily_maintenance` cron event since Step 4, but no code
anywhere hooked it.

**Fix:** new `includes/class-maintenance.php` hooks
`seodoc_daily_maintenance` and prunes:
- `wp_seodoc_404` rows with `last_seen` older than
  `apply_filters( 'seodoc_404_retention_days', 90 )`.
- `wp_seodoc_scans` rows with `status IN ('completed','cancelled')` and
  `finished_at` older than `apply_filters( 'seodoc_scan_retention_days', 90 )`
  — queued/running/paused scans are live state and are never touched
  regardless of age.

Registered in `Plugin`'s core-module list. This closes both the "cron
fires into the void" bug and the resource-exhaustion concern in the same
fix, since it was the same missing piece.

## Evaluated and accepted (documented, not changed)

**`Http_Client`'s DNS-rebind TOCTOU window** (flagged since Step 4,
re-examined here rather than re-stated): the IP is validated once, then
the actual HTTP request resolves DNS again independently, so a rebind in
that gap isn't closed. A proper fix requires pinning the resolved IP at
the transport layer (`CURLOPT_RESOLVE` via the `http_api_curl` hook),
which is real but non-trivial: it's cURL-transport-specific, needs a
Streams-transport fallback story, and risks breaking legitimate requests
if done incorrectly. Weighed against that: the practical attack surface
is narrow. Every caller only ever fetches URLs *discovered from the site
owner's own published content* (links found while scanning their own
pages) or well-known third-party API endpoints (Google's, the vendor
proxy's) — an attacker needs an existing foothold (ability to get a
malicious link into scanned content, e.g. via a compromised
contributor account) before this matters at all. Accepted as a
documented residual risk rather than rushed into a bespoke transport
layer during an audit pass; a real fix is a dedicated task, not a
same-day patch.

## Confirmed clean (verified by direct search, not assumption)

- **Every REST route has an explicit `permission_callback`** checking
  `Capabilities::current_user_can_manage()` (24 routes across both
  plugins, zero exceptions, zero `__return_true`).
- **Every `$wpdb` query goes through `->prepare()`** wherever a variable
  value is involved; the only raw string interpolation anywhere in any
  SQL string is `{$table}`/`{$prefix}` (internally computed, never user
  input) and static literal SQL fragments. Dynamic `WHERE`/`IN(...)`
  clauses (Issues_Controller, Issue_Engine, Queue) build placeholder
  tokens only, never values, into the query string.
- **No `unserialize()`, `eval()`, or `create_function()`** anywhere in
  either plugin — no PHP object-injection surface.
- **`Http_Client` is genuinely the only outbound-fetch path** — a repo
  grep for `wp_remote_get(`/`wp_remote_post(` as actual calls (not
  docblock mentions) finds zero outside `Http_Client` itself.
- **No direct filesystem writes** (`fwrite`/`file_put_contents`/`fputcsv`)
  anywhere — nothing to audit for path traversal because nothing writes
  files yet (CSV export is unbuilt — a Step 18 gap, not a Step 15 one).
- **Every REST route ID parameter is regex-constrained to `\d+`** and
  cast to `(int)` before use — 11 route definitions checked.
- **Admin-notice output is consistently escaped**
  (`esc_html__`/`esc_html`/`esc_attr`) at all 6 `echo`/`printf` sites
  across both plugins — no unescaped interpolation of dynamic values.
- **React never uses `dangerouslySetInnerHTML`** — all rendered data
  (Issue titles, suggestion anchors, 404 URLs) goes through JSX's
  automatic escaping.
- **Suggestion_Inserter's content mutation** (the one place either
  plugin writes into `post_content`) escapes both the anchor text
  (`sanitize_text_field` + `esc_html`) and the URL (`esc_url_raw` +
  `esc_url`), then runs the assembled snippet through `wp_kses_post()`
  as a final layer.
- **No admin-lockout vector via the Redirect Manager**: `Redirect_Matcher`
  hooks `template_redirect`, which structurally never fires for
  `/wp-admin/*` or `wp-login.php` requests (both are separate entry
  points from the front-end template-loader flow) — a redirect rule
  can't accidentally lock an admin out of the dashboard regardless of
  its configured source path.
- **No CORS headers set anywhere** — the REST API relies on WordPress'
  standard same-origin cookie-auth model, not loosened.
- **Mass-assignment is not possible** on any update endpoint —
  `Redirects_Controller::update_item()` explicitly allowlists
  `destination`/`redirect_type`/`status`; every other mutating endpoint
  is a fixed single-purpose action (ignore/approve/dismiss/apply), not a
  generic field-patcher.

## Minor, non-security cleanup noted

`Capabilities::verify_nonce()` (built in Step 4 for "non-REST paths") is
never called anywhere — every state-changing action in the plugin ended
up going through REST, which gets CSRF protection from WP core's
cookie-auth nonce checking automatically. Not a vulnerability (dead
code, not a gap), left as-is rather than removed mid-audit; worth
deleting in a future cleanup pass if no non-REST path ever materializes.
