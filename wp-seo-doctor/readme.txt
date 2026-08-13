=== WP SEO Doctor ===
Contributors: wpseodoctor
Tags: seo, audit, broken links, redirects, internal linking
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete SEO audit toolkit: on-page and technical audits, internal linking, broken links, a 404 monitor, redirects, content SEO, Search Console and AI assistance.

== Description ==

WP SEO Doctor audits every published page on your site and tells you, in priority order, what to fix.

It is a diagnostic tool, not another meta-tag plugin: it reads the SEO title, description, canonical and robots settings from whichever SEO plugin you already use (Yoast, Rank Math, AIOSEO, SEOPress) and reports on what it finds. Nothing conflicts.

**SEO audit**

* SEO health score, graded and trended over time
* Complete website audit, or on-page / technical / content / linking audits separately
* Every issue classified critical, high, medium or low, with a specific fix
* Scan history and scheduled scans
* A "Fix First" list ranked by severity multiplied by pages affected

**On-page checks**

SEO title, meta description, H1, heading structure, keyword usage and density, content length, thin content, image ALT text, duplicate titles, duplicate meta descriptions, canonical, noindex, and nofollowed internal links.

**Technical checks**

XML sitemap, robots.txt, HTTPS, indexability, canonical URL resolution, HTTP status, redirect chains, redirect loops, 404 detection, crawl depth, pagination, structured data, and Open Graph tags.

**Internal linking**

A full internal link graph: incoming and outgoing link analysis, orphan page finder, weak-link detection, anchor text analysis, relevance-scored link suggestions in both directions, one-click link insertion, and a visual link map.

**Broken links**

Internal, external and affiliate link checking with HTTP status monitoring, source and target tracking, and replace / remove / ignore / recheck actions that edit the underlying posts for you.

**404 monitor**

Tracks every 404 with hit counts, first and last seen, and referrer. Suggests the closest matching page and creates a 301 redirect in one click.

**Redirect manager**

301, 302, 307, 308 and 410 rules with exact or regular-expression matching, CSV import and export, hit counters, redirect history, and automatic chain and loop detection with one-click flattening.

**Content SEO**

Thin content, duplicate content signals via word-shingle comparison, content decay against Search Console history, outdated content, and prioritised content opportunities.

**Google Search Console**

OAuth connection, cached performance data, clicks / impressions / CTR / average position, top queries and pages, striking-distance ranking opportunities, low-CTR pages, declining pages, and performance trends.

**AI SEO** (optional, uses your own API key)

Plain-English issue explanations, title and meta description suggestions, ALT text, internal link and anchor text suggestions, content optimisation advice, a sequenced SEO action plan, and a search-readiness assessment.

**Affiliate SEO**

Detects monetised links by network domain or cloaking prefix, then reports broken affiliate links, dead product URLs, affiliate redirect chains, link density, and missing rel="sponsored" / rel="nofollow" attributes.

**WooCommerce SEO**

Product title, meta, schema, image ALT, thin description, internal linking and canonical checks — active automatically when WooCommerce is installed.

**Reports**

Audit, health, technical, broken link, internal link, 404, redirect, content and trend reports, each exportable to CSV or PDF, plus optional scheduled email summaries.

== Installation ==

1. Upload the `wp-seo-doctor` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to **SEO Doctor** and run your first scan.

== Frequently Asked Questions ==

= Does this conflict with Yoast or Rank Math? =

No. WP SEO Doctor reads their meta fields rather than writing its own, and it does not output any tags on the front end. Run them together.

= Will scanning slow down my site? =

Scans run in batches from the admin screen or WP-Cron, never on a visitor request. If your host is slow, lower "Pages per batch" in Settings. Live URL fetching can be turned off entirely, at the cost of the HTTP status, schema and Open Graph checks.

= Is Search Console required? =

No. Everything except content decay, ranking opportunities and CTR analysis works without it.

= Is AI required? =

No. AI features are off by default and only run when you supply your own API key and press a button. Page content is sent to the provider as part of the prompt.

= What data leaves my site? =

Without Search Console and AI configured: only HTTP requests to your own URLs and to external URLs you have linked to, in order to check whether they are alive.

== Screenshots ==

1. Dashboard with health score, priority breakdown and the Fix First list.
2. Issue browser with filters and bulk actions.
3. Internal link map.
4. Broken link manager.
5. Redirect manager with chain detection.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
