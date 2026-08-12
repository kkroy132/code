=== WP SEO Doctor ===
Contributors: (add your WordPress.org username before submission)
Tags: seo, technical seo, broken links, internal linking, redirects
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find SEO problems before they cost you traffic — technical SEO audit, broken links, orphan pages, internal linking and 404 monitoring.

== Description ==

**Find. Understand. Fix. Grow.**

WP SEO Doctor audits your WordPress site for technical SEO issues, broken
links, orphan pages, indexing problems and internal-link opportunities —
then helps you fix them from one dashboard.

It works **alongside** Yoast SEO, Rank Math, AIOSEO and other SEO
plugins — this is not a replacement for them. When one of those is
active, WP SEO Doctor detects it and runs in complementary mode: it
never duplicates title/meta/canonical management, and focuses on the
things those plugins don't cover as deeply — technical diagnosis,
internal linking, broken links, and monitoring.

= SEO Audit =

A background scanner (never a single slow page load — work is processed
in small batches) runs 26 checks across three categories:

* **On-page**: missing/duplicate SEO titles, title length, missing meta
  description, description length, missing/multiple H1, heading
  hierarchy, missing image ALT text, thin content.
* **Technical**: HTTPS, robots.txt, XML sitemap availability, canonical
  tags, noindex/nofollow pages, structured data presence, pagination,
  mixed content, orphan pages (pages with no internal links pointing to
  them).
* **Links**: empty link text, placeholder links, excessive external
  links, missing `rel="noopener"`, nofollow internal links, insecure
  (http) internal links.

Every issue is scored by severity (Critical/High/Medium/Low) and rolled
into an overall **SEO Health Score**, with a **Fix First** list ranking
problems by real impact — not just a flat list of everything that's
wrong.

= Internal Linking =

WP SEO Doctor builds an internal link graph while it scans, and
suggests new internal links between related pages (up to 25
suggestions/month on the free plan). Every suggestion goes through a
**review → approve → insert** flow — nothing is ever added to your
content automatically or in bulk.

= Broken Link Intelligence =

Discovered internal and external links are checked in the background and
flagged when they return an error or point somewhere unexpected, with
the affected page(s), anchor text, and HTTP status shown for each.

= 404 Monitor =

Tracks 404 hits on your site (aggregated per URL, not one row per
request) and suggests a redirect destination for each based on your
existing published content.

= Redirect Manager =

Create 301/302 redirects from one screen, with built-in validation
against invalid destinations and self-referencing loops.

= Privacy =

WP SEO Doctor does not track site visitors or send any usage data to
third parties. The only outbound requests it makes are for its own
advertised functionality: checking your own site's robots.txt/sitemap,
verifying the HTTP status of links found in your own content, and (only
where explicitly relevant) fetching your own pages to run technical
checks against their real rendered output.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-seo-doctor`, or
   install directly through the WordPress plugin screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **WP SEO Doctor → Overview** and click "Run a new scan."

== Frequently Asked Questions ==

= Does this replace Yoast SEO / Rank Math / AIOSEO? =

No. WP SEO Doctor is designed to run alongside your existing SEO
plugin, not replace it. It focuses on technical diagnosis, internal
linking, broken links, and monitoring — areas most all-in-one SEO
plugins don't cover as deeply.

= Will scanning my site slow it down? =

No. Scans run in the background in small batches via WordPress' own
Cron/Action Scheduler system, never as one long page load. Visitors to
your site are never affected by a scan in progress.

= Does WP SEO Doctor automatically change my content? =

No. Every fix — an internal link suggestion, for example — requires
your explicit review and approval before anything is changed, and only
ever affects one page at a time. Nothing is changed in bulk without
confirmation.

== Screenshots ==

1. Overview dashboard — SEO Health Score, issue counts by severity, and
   the Fix First list.
2. SEO Audit — the full list of detected issues, filterable by severity
   and category.
3. Internal link suggestions — review, approve, and insert.
4. 404 Monitor — tracked hits with suggested redirect destinations.
5. Redirect Manager — create and manage 301/302 redirects.

== Changelog ==

= 0.1.0 =
* Initial release: SEO audit engine (26 checks), SEO Health Score, Fix
  First action plan, internal linking suggestions, broken link
  intelligence, 404 monitor, redirect manager.
