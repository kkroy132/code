# WP SEO Doctor — Step 8: Dashboard (REST API + React Admin Shell)

## What landed

**REST API** (`seodoc/v1`, namespace registered via `Rest_Api`, hooked to
`rest_api_init`):

- `GET /overview` — health score + delta, open-issue counts by severity,
  resolved count, Fix First list (top 5 via `Action_Plan::get()`).
- `POST /scans` — starts a full scan (`Scan_Controller::start()`).
- `GET /scans/{id}` — scan status/progress.
- `GET /issues` — paginated, filterable by `severity`/`category`,
  `X-WP-Total`/`X-WP-TotalPages` headers for pagination.
- `POST /issues/{id}/ignore` — dismiss one issue.

Every route's `permission_callback` is `Capabilities::current_user_can_manage()`
(no route skips this); nonce checking for the cookie-authenticated requests
the React app makes is handled by WP core automatically via the
`X-WP-Nonce` header `apiFetch` sends by default — nothing bespoke needed.

**Admin menu** (`Admin_Menu`): registers the full menu tree from Step 1
§16 as real WP submenu pages (bookmarkable URLs, proper capability
checks per page), all rendering the same `#seodoc-app` mount point with a
`data-route` attribute. This is one React SPA, not one PHP screen per
section — Links/404 Monitor/Redirects/Content/Search Console/AI Assistant
get real menu entries now even though their backing modules don't exist
until Steps 9-13; the client renders "coming soon" for routes it doesn't
recognize, so the menu doesn't reshape release to release as those land.

**Assets** (`Admin\Assets`): enqueues the built app only when the current
admin `$hook` contains `seodoc` — never site-wide. Shows an admin notice
if `admin/build/` hasn't been built yet, rather than silently rendering
nothing.

**React app** (`admin/src/index.js` + `style.css`): built with
`@wordpress/scripts`, zero extra runtime dependencies beyond what
WordPress core already ships (`wp-element`, `wp-api-fetch`, `wp-i18n`,
`wp-components`). No `react-router` — routing is a plain `data-route`
attribute read once at mount, since there's currently exactly one real
screen. The Overview screen is fully wired: health score with delta,
severity counts, "Run a new scan" button, Fix First list.

## Actually built and verified, not just written

`npm install && npm run build` were run against this code in this
session — not assumed to work. That surfaced one real bug, now fixed:

**`Requires at least` bumped from 6.5, not left at 6.0.** The build's
`index.asset.php` declares a dependency on WordPress core's `react`
script handle:

```
dependencies => ['react', 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n']
```

That handle was only added to WordPress core in 6.5 — on 6.0-6.4 it
doesn't exist, `wp_enqueue_script()` would silently enqueue against a
missing dependency, and the app just wouldn't load (blank admin screen,
no obvious error). `wp-seo-doctor.php`'s header and `SEODOC_MIN_WP` are
now `6.5`, which is what the toolchain this plugin is actually built with
requires — not a number chosen up front and left unverified.

**CSS output filename.** `@wordpress/scripts`' webpack config extracts
styles as `style-[entry].css` (`style-index.css`), not `[entry].css`.
`Admin\Assets` originally referenced `index.css` — wrong, caught by
actually inspecting `admin/build/` after a real build, fixed before
commit.

## What's a placeholder on purpose

- Only the Overview route has a real screen. SEO Audit, Action Plan (as
  its own dedicated view — the data already exists via `Action_Plan`, just
  not its own screen yet), Links, 404 Monitor, Redirects, Content, Search
  Console, AI Assistant, Reports, and Settings all render "coming soon"
  until their backing REST endpoints exist (Steps 9-14). Building screens
  against data that doesn't exist yet would be exactly the "fake
  trialware" pattern Step 1 §22 rules out.
- `admin/build/` and `admin/node_modules/` are gitignored, as scoped in
  Step 2 — only `admin/src/`, `package.json`, and the generated
  `package-lock.json` are committed.
