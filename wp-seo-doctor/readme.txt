=== WP SEO Doctor ===
Contributors: wpseodoctor
Tags: seo, audit, broken links, redirects, internal linking
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
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

== External Services ==

WP SEO Doctor contacts the following services. The first is part of its core
purpose; the other two are optional and inactive until you supply credentials.

**Your own site and the sites you link to**

To check whether a link still works, and to read the rendered HTML of your own
pages, the plugin makes HTTP requests to your site's URLs and to external URLs
found in your content. Only the URL itself is sent — no visitor or site data.
This happens during a scan, during a broken-link check, and on the schedules
you configure. It cannot be disabled for internal URLs without disabling the
corresponding checks; external link checking can be turned off in Settings.

**Google Search Console** (optional, off until connected)

Retrieves your site's own search statistics: clicks, impressions, CTR, average
position, top queries and top pages. Requests are sent to
accounts.google.com, oauth2.googleapis.com and searchconsole.googleapis.com,
and contain your OAuth token and the property name — never visitor data or
page content. Active only after you create Google OAuth credentials, connect
an account and choose a property; a daily sync then runs on WP-Cron until you
disconnect.

Google Privacy Policy: https://policies.google.com/privacy
Google API Terms of Service: https://developers.google.com/terms

**An AI provider of your choosing** (optional, off by default)

When you press an AI button, the plugin sends the relevant page's title, meta
description and an excerpt of its content to the provider you configured, and
receives a suggestion back. Nothing is sent automatically, on a schedule, or
in the background. The provider, model and API key are yours; the plugin
supplies no key of its own and has no service behind it.

The default provider is Anthropic (api.anthropic.com).
Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy
Anthropic Terms: https://www.anthropic.com/legal/consumer-terms

If you configure a different provider, consult that provider's own terms.

== Privacy ==

Almost everything the plugin stores describes your own content: page titles,
links, and the problems found. Two features can record something about a
visitor.

**404 monitor** (on by default) stores the requested address, the referring
page, the browser user-agent and the timestamps of the first and most recent
request.

**Redirect log** (on by default) stores the requested address, the
destination, the referrer and the user-agent for requests that matched a rule.

**IP addresses are not recorded** unless you switch IP logging on for either
feature. Both are off by default. When enabled, addresses are truncated before
storage — the last octet of an IPv4 address, or everything after the first
four groups of an IPv6 address, is discarded — so what is stored identifies a
network rather than a device.

**Retention.** 404 records are deleted after 90 days without a further
request; individual redirect hits after 30 days. Both are configurable, and
the totals shown in reports are counters that hold no visitor data.

The plugin adds suggested wording to Tools → Privacy so you can paste it into
your privacy policy, and registers with WordPress's personal-data exporter and
eraser. Neither returns anything, because nothing the plugin stores is keyed
to a person — a fact the eraser states explicitly rather than silently.

== Changelog ==

= 1.1.0 =
Security and correctness release.

* Security: outbound HTTP requests are validated before they are made.
  Requests to loopback, private, link-local, carrier-grade-NAT and reserved
  addresses, and to cloud metadata endpoints, are refused — including when a
  public hostname resolves to one, and at every hop of a redirect chain. Your
  own site remains reachable, so local and staging installs still scan.
* Security: redirect targets are validated on save and again when a rule is
  matched. Script and data schemes, protocol-relative targets, control
  characters and over-long values are refused. Rules written directly to the
  database are re-checked before use.
* Security: regular-expression redirects are checked for nested quantifiers
  and length before they are stored, and are matched under a bounded
  backtracking budget so a pathological pattern cannot hang the front end.
* Privacy: IP logging is now off by default for both the 404 monitor and the
  redirect log; when enabled, addresses are truncated before storage.
* Privacy: redirect hit logs now expire on a retention schedule as well as a
  row cap; Search Console rows, fingerprints and link-graph rows belonging to
  deleted posts are pruned daily.
* Privacy: suggested privacy-policy text, plus personal-data exporter and
  eraser registration.
* Fixed: pages linked only from the theme's menu, footer or homepage were
  reported as orphans. Site chrome is now detected by sampling rendered pages,
  and orphan detection and crawl depth share one definition of it.
* Fixed: internal links injected through the `the_content` filter — related
  posts, automatic internal linking, tables of contents — were invisible to
  the scanner, so densely linked sites reported almost no internal links.
* Fixed: duplicate detection loaded the entire corpus on every scan. It now
  stores per-post fingerprints, so lookups are a single indexed query and
  large sites no longer silently exceed max_allowed_packet.
* Fixed: content-decay reporting never ran, due to SQL that MariaDB rejects.
* Fixed: regex redirects lost their capture groups, sending every matching URL
  to the same destination.
* Fixed: backslashes in post content were stripped whenever a link was
  inserted, replaced or removed.
* Removed the placeholder Plugin URI; added Domain Path and text-domain
  loading.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Security release: SSRF protection on all outbound requests, redirect and regex
validation, and privacy-conscious logging defaults. Also fixes internal-link
detection, which under-reported links on most sites. After updating, visit
Internal Linking and press "Rebuild link graph".

= 1.0.0 =
Initial release.
