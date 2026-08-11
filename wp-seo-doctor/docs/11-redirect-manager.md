# WP SEO Doctor — Step 11: Redirect Manager

## What landed (Free scope)

Per Step 1/2's already-documented split (Step 1 §18 feature matrix:
"Redirect Manager: Limited" for Free, chains/regex/groups/bulk/410/307/308
are Pro):

- `includes/redirects/class-redirect-manager.php` — CRUD for exact-path
  301/302 redirects. `allowed_redirect_types()` returns `[301, 302]`
  through `apply_filters( 'seodoc_allowed_redirect_types', ... )` — Pro
  adds 307/308/410 by hooking that one filter, no change needed here,
  same extension pattern used throughout (Free's suggestion quota, Pro
  activity checks, etc.).
- `includes/redirects/class-redirect-matcher.php` — the second (and
  last) frontend hook in the plugin. Hooked to `template_redirect` at
  **priority 1**, deliberately earlier than Monitor's default priority
  10, so a matched redirect exits before 404 logging runs for that
  request — a URL with an active redirect should never also generate a
  404-monitor entry.
- `includes/rest-api/class-redirects-controller.php` — list/create/
  update/delete, all validation delegated to `Redirect_Manager`.

## Safe redirect validation (Step 1 §9's requirement, made concrete)

- **Source normalization**: whatever's typed — a full URL or a bare path
  — is reduced to just its path component (`wp_parse_url(..., PHP_URL_PATH)`)
  and untrailingslashed, so `https://example.com/old-page/`,
  `/old-page/`, and `/old-page` all normalize to the same `source_hash`
  and can't accidentally create duplicate/conflicting rules.
- **Destination validation**: must be either a site-relative path
  (starts with `/`) or a full `http`/`https` URL — `javascript:`, `data:`,
  and other schemes are rejected outright.
- **Self-loop prevention**: a same-site destination that normalizes to
  the same path as the source is rejected at creation time (`A redirect
  cannot point to itself`). This check is host-aware — a destination on a
  *different* host that happens to share the same path (a legitimate
  cross-domain redirect, e.g. an old affiliate path 301'd to a merchant's
  site with a similar URL structure) is correctly never flagged.
- **Why not `wp_safe_redirect()`**: WP core's safe-redirect helper only
  allows same-host destinations by default, which would break the
  legitimate external-redirect use case the brief explicitly calls out
  (§15 Affiliate SEO Mode — redirecting dead affiliate URLs to a live
  merchant page). The actual trust boundary here is capability, not host:
  only users who already pass `Capabilities::current_user_can_manage()`
  can create a redirect at all, so `wp_redirect()` with a
  validated/`esc_url_raw()`-sanitized destination is the correct choice,
  not a same-host-only restriction that would reject valid configurations
  for a capability-gated admin.
- Duplicate prevention: `create()` rejects a second redirect for a path
  that already has one active (`find_id_by_path`), rather than silently
  creating a conflicting second rule.

## What's deliberately not here

Redirect chain/loop detection across *multiple* redirects (A→B→C→D),
regex source matching, redirect groups, and bulk import/export are Pro
(Step 1 §10, §18) and stay unbuilt until Step 14 gives the Pro plugin a
bootstrap to attach them to — writing Pro-only classes with nothing
loading them yet would be orphaned code. What Step 11 *did* set up for
that later work: Step 10's `Http_Client::get_no_redirect()`/
`head_no_redirect()` are exactly the primitive a chain detector needs to
walk hops one at a time instead of having them auto-resolved.

## One-hop loop prevention exists now; multi-hop doesn't

`Redirect_Manager::create()` only catches a redirect pointing directly at
itself. It does **not** currently check whether the *new* redirect's
destination is itself another redirect's source, which could form a
multi-hop chain (or, in the worst case, an actual loop: A→B, then later
B→A created independently). That's intentionally the Pro chain/loop
detector's job per the brief's own feature split — flagged here so it
reads as a deliberate boundary, not an oversight.
