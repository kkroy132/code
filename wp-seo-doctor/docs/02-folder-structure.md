# WP SEO Doctor — Step 2: Folder Structure

Two plugin trees, per the Step 1 decision (Free on .org, Pro as a separate
add-on). Below is the full intended layout with a one-line purpose per
file/folder and which later step populates it. Directories are created now
(with `.gitkeep` placeholders where empty); file contents land in the steps
noted — nothing here is implemented yet except the skeleton itself.

## `wp-seo-doctor/` (Free — WordPress.org)

```
wp-seo-doctor/
├── wp-seo-doctor.php            Main bootstrap: header, constants, autoload, boots Plugin (Step 4)
├── uninstall.php                 Opt-in cleanup: drop custom tables + options on uninstall (Step 3/4)
├── readme.txt                    .org listing copy (Step 17/24)
├── LICENSE                       GPLv2+ (required for .org)
├── includes/
│   ├── class-plugin.php          Core\Plugin singleton — wires every module together (Step 4)
│   ├── class-activator.php       Runs on activation: create tables, schedule Cron (Step 4)
│   ├── class-deactivator.php     Runs on deactivation: clear scheduled events, keep data (Step 4)
│   ├── class-capabilities.php    Capability constant + nonce helpers used by every REST route (Step 4)
│   ├── class-http-client.php     SEODoc_Http — the single SSRF-guarded outbound fetch wrapper (Step 4)
│   ├── class-module-registry.php seodoc_register_* hook targets Pro/3rd-party plug into (Step 4)
│   │
│   ├── db/
│   │   ├── class-schema.php      dbDelta table definitions + schema-version upgrade routine (Step 3)
│   │   └── class-migrations.php  Versioned migration runner (Step 3)
│   │
│   ├── checks/
│   │   ├── class-check.php           Abstract SEODoc_Check base class (Step 5)
│   │   ├── class-check-registry.php  Registers/runs Check classes (Step 5)
│   │   ├── class-scan-context.php    Lazy-loaded post/HTML/header wrapper passed to checks (Step 5)
│   │   ├── on-page/                  ~15 checks: titles, meta, headings, ALT, content length (Step 6)
│   │   ├── technical/                ~15 checks: HTTPS, robots, sitemap, canonical, indexability (Step 6)
│   │   └── links/                    ~10 checks: broken links, orphan flag, basic 404 signal (Step 6)
│   │
│   ├── scanner/
│   │   ├── class-queue.php               Builds/paginates the scan queue table (Step 5)
│   │   ├── class-batch-processor.php     Pulls N queue rows per Action Scheduler tick (Step 5)
│   │   ├── class-scan-controller.php     Start/pause/cancel a scan; stale-claim sweep (Step 5)
│   │   └── class-action-scheduler-init.php  Bundled Action Scheduler bootstrap (Step 4)
│   │
│   ├── issues/
│   │   ├── class-issue.php           Issue value object (Step 7)
│   │   ├── class-issue-engine.php    Upsert/resolve issues, recompute health score (Step 7)
│   │   └── class-action-plan.php     "Fix First" ranking logic (Step 7)
│   │
│   ├── links/
│   │   ├── class-link-graph.php          Internal in/out link counts, crawl depth (Step 9/10)
│   │   ├── class-orphan-detector.php     Pages with zero internal inbound links (Step 9/10)
│   │   └── class-broken-link-checker.php Free-tier broken-link scan (Step 10)
│   │
│   ├── monitor-404/
│   │   └── class-monitor.php         template_redirect hook, dedup/rate-limited logging (Step 10)
│   │
│   ├── redirects/
│   │   ├── class-redirect-manager.php    301/302 CRUD + template_redirect matcher (Step 11)
│   │   └── class-redirect-matcher.php    Indexed path lookup, validated destinations (Step 11)
│   │
│   ├── reports/
│   │   └── class-csv-exporter.php    Free-tier CSV export (Step 8)
│   │
│   ├── compat/
│   │   └── class-seo-plugin-detector.php  Detect Yoast/Rank Math/AIOSEO, defer canonical/meta (Step 4)
│   │
│   ├── rest-api/
│   │   ├── class-rest-controller.php     Base controller: capability + nonce enforcement (Step 8)
│   │   ├── class-overview-controller.php
│   │   ├── class-issues-controller.php
│   │   ├── class-links-controller.php
│   │   ├── class-404-controller.php
│   │   ├── class-redirects-controller.php
│   │   └── class-settings-controller.php
│   │
│   └── admin/
│       ├── class-admin-menu.php      Registers the menu tree from Step 1 §16 (Step 8)
│       ├── class-assets.php          Enqueues React build, gated on admin $hook only (Step 8)
│       └── class-upsell-notices.php  Contextual, non-nagging Pro prompts (Step 14/26)
│
├── admin/                        React admin app (built with @wordpress/scripts, zero extra build dep)
│   ├── package.json
│   ├── src/
│   │   ├── index.js
│   │   ├── screens/               overview/ audit/ action-plan/ links/ monitor-404/ redirects/ reports/ settings/
│   │   └── components/            shared UI: health score ring, issue card, fix-first list, etc.
│   └── build/                     compiled output — gitignored, produced by `npm run build`
│
├── public/
│   └── class-frontend-hooks.php  Only two hooks live here: 404 log + redirect match (Step 10/11)
│
├── languages/
│   └── wp-seo-doctor.pot         Translation template (Step 17)
│
└── vendor/
    └── action-scheduler/          Bundled library (Step 4) — vendored, not Composer-installed at runtime
```

## `wp-seo-doctor-pro/` (Pro — sold separately, requires Free active)

```
wp-seo-doctor-pro/
├── wp-seo-doctor-pro.php         Checks Free is active + version; registers Pro modules via hooks (Step 14)
├── readme.txt
├── includes/
│   ├── class-pro-plugin.php      Pro singleton — calls seodoc_register_* for everything below (Step 14)
│   │
│   ├── licensing/
│   │   ├── class-license-manager.php   Activate/deactivate license key against license server (Step 14)
│   │   └── class-update-client.php     Plugin-update-checker style auto-update client (Step 14)
│   │
│   ├── checks/                   Additional Check classes: thin-content, duplicate signals,
│   │                              archive/author/attachment checks — registered into Free's
│   │                              registry, not a separate engine (Step 12)
│   │
│   ├── scanner/
│   │   └── class-full-crawl-stage.php  CPT/taxonomy/archive coverage, registered as extra
│   │                                    scanner stage via seodoc_register_scanner_stage (Step 12)
│   │
│   ├── redirects/
│   │   ├── class-chain-detector.php    A→B→C→D chain/loop detection (Step 11)
│   │   └── class-regex-redirects.php   Regex + bulk import/export (Step 11)
│   │
│   ├── gsc/
│   │   ├── class-oauth.php
│   │   ├── class-gsc-client.php
│   │   ├── class-opportunity-finder.php
│   │   └── class-content-decay.php     (Step 12)
│   │
│   ├── ai/
│   │   ├── class-ai-client.php         Calls our API layer, never a raw key in the browser (Step 13)
│   │   └── class-ai-assistant.php      Explanations, title/meta/ALT generation, action plans (Step 13)
│   │
│   ├── linking/
│   │   └── class-advanced-suggestions.php  Unlimited suggestions, topic clusters, link map data (Step 9)
│   │
│   ├── affiliate/
│   │   └── class-affiliate-mode.php    (Step 12/15)
│   │
│   ├── reporting/
│   │   └── class-white-label.php       Agency-tier branded reports (Step 12)
│   │
│   └── rest-api/                       Pro REST controllers, namespace `seodoc-pro/v1`
│
└── admin/
    └── src/                            Pro-only React screens/tabs, injected via
                                          seodoc_register_admin_page rather than editing Free's menu
```

## Notes

- No file in Pro ever `include`s or `require`s a Free file directly, and no
  Free file ever references a Pro class — the only contact surface is the
  `seodoc_register_*` hooks and the `seodoc_is_pro_active()` check, exactly
  as scoped in Step 1 §3. This is what lets Free run standalone and Pro be
  developed/tested against Free's public API instead of its internals.
- `admin/build/` and Pro's compiled JS are gitignored; only `src/` ships in
  version control, matching a normal `@wordpress/scripts` project.
- Table definitions referenced above (`wp_seodoc_scans`, `_issues`, `_links`,
  `_404`, `_redirects`, `_suggestions`, `_reports`) are fully specified next,
  in Step 3.
