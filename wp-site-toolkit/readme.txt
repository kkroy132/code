=== WP Site Toolkit – Free ===
Contributors: wpsitetoolkit
Tags: seo, broken links, site health, audit, 404
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free all-in-one WordPress website health, SEO, link, image, technical and security audit toolkit.

== Description ==

WP Site Toolkit runs a full audit of your WordPress site from one dashboard and tells you, in plain language, what is wrong, why it matters and what to do about it.

Everything runs locally. There is no account to create, no API key, no licence, no upsell and no premium tier — the plugin you install is the whole plugin.

= What it checks =

**SEO**

* Meta titles: missing, empty, too long, too short and duplicated
* Meta descriptions: missing, unusually short or unusually long
* Canonical URLs: present, duplicated, or pointing somewhere unexpected
* XML sitemap: found and reachable
* robots.txt: reachable, and whether it blocks the whole site
* Heading structure: missing H1, multiple H1s and skipped heading levels
* Open Graph tags: og:title, og:description and og:image
* Indexability: the site-wide search visibility setting and per-page noindex directives

Titles and descriptions are read from Yoast SEO, Rank Math, SEOPress and All in One SEO when one of them is active, and fall back to the post title and excerpt otherwise.

**Links**

* Broken link scanner for internal links, distinguishing not found, access denied, server errors, timeouts and unreachable addresses
* External link checking, off by default and entirely optional
* Redirect detection, including redirect chains
* Internal link counts and entries that link to nothing else on the site
* Orphaned content that nothing else links to
* A 404 monitor that records the addresses visitors requested and did not find
* A built-in redirect manager: send an old address straight to its new one in one click from the 404 monitor, or add redirects by hand — 301, 302 or 307, with a hit counter

**Images**

* Images used in your content that have no alt attribute
* Media library items with no alt text saved
* Image files above a size you choose
* Images with unusually large dimensions
* A media library size report, including the largest files
* Media that appears to be unused — clearly labelled as "potentially unused", never deleted

**Performance**

* Published content totals
* Database size and the largest tables
* Post revision count
* Autoloaded option weight, with the largest entries listed
* Trash, auto-drafts, spam comments and stored transients
* Average media file size
* A basic server response time reading
* Whether a persistent object cache is in use

**Security — a Basic Security Health Check**

* HTTPS on the site and in the admin area
* WordPress version and available updates
* PHP version against the published end-of-life dates
* Waiting plugin and theme updates
* Debug mode and whether errors could be displayed to visitors
* Security response headers
* XML-RPC status
* Theme and plugin file editing
* Registration settings and administrator accounts

This is a configuration health check. It does not test for vulnerabilities, does not probe third-party systems and is not a substitute for a full security review.

**Technical**

WordPress version, PHP version, MySQL or MariaDB version, web server, both site URLs, HTTPS status, permalink structure, WP-Cron status, REST API availability, debug mode, memory limits, upload limit, maximum execution time, sitemap availability, robots.txt availability, active theme, plugin count, multisite status, language and timezone.

= Where you already work =

A full audit is not the only way findings reach you:

* A "Site Toolkit" column on the Posts and Pages list shows title length, meta description length and missing image alt text for every entry, computed locally with no extra page load
* A matching panel appears in the block editor sidebar while you write, with a link through to the full audit
* These signals check the same thresholds as the SEO audit and never make an outbound request

= Built for real sites =

* Audits run in small batches through AJAX, with a progress bar you can watch or walk away from
* Two audits can never run at the same time
* Every request has a timeout, and response sizes are capped
* Limits for posts, links and sampled pages are all configurable
* Automatic audits are available but switched off by default

= Reports =

Every audit is stored with its scores and findings. You can compare a report against the previous one, print it, export it as CSV and delete old scans. History is trimmed automatically according to the retention settings.

== Privacy ==

WP Site Toolkit is designed to keep everything on your own server.

* No site content, statistics or personal data is sent to the plugin author or anyone else
* No telemetry, no analytics, no tracking and no advertising
* No external account, licence server or third-party API
* The 404 monitor stores the requested address, the referring URL, a hit counter and timestamps — it does not store IP addresses, user agents or any other visitor identifier
* The redirect manager only checks incoming requests against redirects you created yourself, entirely with local database lookups — it makes no outbound request of any kind, and the check is skipped completely on sites with no redirects configured

During an audit the plugin makes HTTP requests to:

1. Your own site, to inspect the markup it serves and to check the REST API, sitemap and robots.txt.
2. The internal links found in your content, to see whether they still work.
3. External domains you link to — only when you switch "Check external links" on. This setting is off by default.

No other outbound connection is ever made.

Deleting the plugin leaves your data in place unless you tick "Remove all WP Site Toolkit data when uninstalling" in the settings first. That option is off by default, and even when it is on, only data created by this plugin is removed.

== Installation ==

1. Upload the `wp-site-toolkit` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open **Site Toolkit** in the admin menu.
4. Press **Run Full Site Audit** and leave the page open while it works.

The plugin creates three database tables for scan summaries, check results and the 404 log.

== Frequently Asked Questions ==

= Is anything held back for a paid version? =

No. There is no paid version, no licence key and no upsell. Every check described above is included.

= Does the plugin change my site? =

No. It reads and reports. It never edits posts, never deletes media, never rewrites files and never changes settings on your behalf. Every suggested fix links you to the relevant WordPress screen so you stay in control.

= How long does an audit take? =

On a small site, under a minute. Sites with thousands of posts take longer because the work is deliberately spread across many small batches. You can lower the limits in Settings if your host is slow, or raise them if it is not.

= Can I run an audit on a very large site? =

Yes, within the limits you set. By default an audit analyses 500 published entries, checks 150 links and loads 10 rendered pages. Raise those numbers in Settings if your server can handle it. Some checks — potentially unused media in particular — are skipped rather than guessed at when they cannot see the whole site, and say so.

= Why does a check say "not checked"? =

Because it could not run reliably. Common reasons are external link checking being turned off, no content of the relevant kind existing, or the plugin being unable to load pages from your own site. The reason is always stated on the check itself, and skipped checks are excluded from the score rather than counted as failures.

= How is the score calculated? =

Each section runs a fixed set of checks. A passing check earns full marks, a recommendation earns 85%, a warning earns 50% and a critical finding earns nothing. Section scores are the weighted average of their checks; the overall score is the weighted average of the sections. Scores are a guide to where to spend your time — they are not a prediction of search rankings and not a guarantee of security.

= Does it work with my SEO plugin? =

Yes. Titles and descriptions saved by Yoast SEO, Rank Math, SEOPress and All in One SEO are read directly. Without one of those plugins, the post title and excerpt are used instead.

= Does it work on multisite? =

Yes. Each site in the network keeps its own tables, settings and results. Network activation provisions each existing site, and new sites are provisioned the first time the plugin loads on them.

= Will the 404 monitor slow my site down? =

It only runs on requests that have already resulted in a 404 page, and it writes a single row. It stores at most 500 distinct addresses, and you can turn it off entirely in Settings.

= Will the redirect manager slow my site down? =

If you have not created any redirects, the check is skipped entirely — nothing is added to the request. Once you do create one, each front-end request does a single indexed database lookup, the same kind of cost as the 404 monitor.

= Can editors use the toolkit? =

By default only users who can manage options can. The `wpstk_capability` filter lets you grant access to other roles, separately for viewing results, running audits and changing settings.

== Screenshots ==

1. The dashboard, showing the overall website health score, section scores and the findings that need attention first.
2. The Full Audit screen with the batched progress bar while an audit runs.
3. A section screen with individual check results, expanded to show what is wrong, why it matters and what to do.
4. The Reports screen with score history and a comparison against the previous audit.
5. The 404 monitor.
6. The settings screen.

== Changelog ==

= 1.1.0 =
* Added a redirect manager: create 301, 302 or 307 redirects by hand, or in one click from a 404 monitor entry. Redirects are only checked on the front end when at least one exists.
* Added a "Site Toolkit" column to the Posts and Pages list and a matching panel in the post editor, showing title length, meta description length and image alt-text status computed locally, with no extra requests.
* Added a redirect summary check to the Links section of the audit.

= 1.0.0 =
* Initial release.
* Full site audit engine with resumable, batched scanning.
* SEO, Links, Images, Performance, Security and Technical audit sections.
* 404 monitor.
* Reports with score history, comparison, printing and CSV export.
* Read-only REST endpoints for stored results.
* Optional scheduled audits, disabled by default.

== Upgrade Notice ==

= 1.1.0 =
Adds a redirect manager and post-list SEO signals. Existing scans, settings and 404 data are kept; the new redirects table is created automatically.

= 1.0.0 =
Initial release.
