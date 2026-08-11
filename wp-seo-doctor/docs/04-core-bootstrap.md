# WP SEO Doctor — Step 4: Core Plugin Bootstrap

## What landed

- `wp-seo-doctor.php` — plugin header, constants, PHP/WP version guards
  (admin notice + early return, never a fatal), autoloader registration,
  the Action Scheduler `require`, activation/deactivation hooks, and the
  `plugins_loaded` → `seodoc()->boot()` entry point.
- `includes/class-autoloader.php` — maps `SEODoc\Sub\Class_Name` to
  `includes/sub/class-class-name.php` by construction, so namespace and
  file path can't drift out of sync as more classes land in Steps 5+.
- `includes/functions.php` — `seodoc()` accessor and the three
  `seodoc_register_*()` functions promised in Step 1 §3, plus
  `seodoc_is_pro_active()`.
- `includes/class-module-registry.php` — the storage backing those
  register functions (checks / scanner stages / admin pages).
- `includes/class-plugin.php` — the boot sequence: textdomain → schema
  upgrade → core modules → `seodoc_register_modules` fires → `seodoc_loaded`
  fires. Later steps add their bootstrap class to the `seodoc_core_modules`
  filter rather than this file accreting per-feature logic.
- `includes/class-capabilities.php` — single source of truth for the
  capability gate (`manage_options`, filterable).
- `includes/class-http-client.php` — the SSRF-guarded fetch wrapper
  every URL-fetching feature must use (see below).
- `includes/class-activator.php` / `class-deactivator.php` — table
  creation + Cron scheduling on activate; Cron cleanup only (no data
  deletion) on deactivate.
- `includes/compat/class-plugin-detector.php` — Yoast/Rank Math/AIOSEO
  detection + the complementary-mode notice, scoped to WP SEO Doctor's own
  admin screens only.
- `includes/scanner/class-action-scheduler-init.php` — reports whether
  the bundled Action Scheduler actually loaded; Step 5's batch processor
  checks `is_available()` before scheduling.
- `uninstall.php` — opt-in-only data deletion (default: keep everything).

## Http_Client: the SSRF guard, explained

Every outbound fetch in this plugin funnels through
`SEODoc\Http_Client::get()`/`head()`. Layered defenses:

1. Scheme allowlist (`http`/`https` only).
2. `reject_unsafe_urls => true` on the underlying `wp_remote_request()` —
   WP core's own unsafe-URL rejection.
3. An explicit check: resolve the hostname ourselves and reject if the
   **resolved IP** (not the hostname string) falls in a private, loopback,
   link-local, or reserved range (`FILTER_FLAG_NO_PRIV_RANGE |
   FILTER_FLAG_NO_RES_RANGE`). This specifically matters for
   `169.254.169.254`-style cloud metadata endpoints, which live in the
   link-local range.
4. Redirects are followed **manually** (`redirection => 0` on the
   underlying request, then re-validate before following each `Location`),
   so a redirect chain that lands on a private IP is blocked at the hop
   that reaches it, not just at the original URL. Capped at 3 hops.

**Documented residual risk, not silently ignored:** step 3's IP check and
the actual HTTP request each resolve DNS independently, so a
sub-second DNS-rebind between the two lookups isn't closed by this alone.
Fully closing that requires pinning the resolved IP at the transport layer
(e.g. `CURLOPT_RESOLVE`), which WP's HTTP API doesn't expose without a
custom transport. Flagged explicitly for the Step 15 security audit rather
than papered over here.

## Why textdomain loading isn't conditional on `is_admin()`

Some Checks and email reports (Steps 6/12) run during Cron/background
requests where translated strings still need to load, so `load_textdomain()`
runs unconditionally in `boot()`, not gated to admin-only requests.

## What's intentionally still a stub

- `Plugin_Detector::maybe_show_complementary_notice()` references a
  `seodoc_dismissed_compat_notice` option with no writer yet — Step 8 wires
  the actual dismiss control. Until then the notice just shows on WP SEO
  Doctor's own screens (there are none yet, so in practice it's inert).
- `Action_Scheduler_Init` reports availability but nothing schedules a job
  yet — that's Step 5.
