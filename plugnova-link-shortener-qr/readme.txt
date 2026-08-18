=== Plugnova Link Shortener & QR ===
Contributors: plugnova, freemius
Tags: url shortener, qr code, affiliate links, link cloaking, bio link
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted URL shortener with automatic QR codes, click analytics, a bio-link page builder, keyword auto-linking, and a REST API.

== Description ==

Plugnova Link Shortener & QR turns your WordPress site into a self-hosted link shortener with your own domain. Every shortened link automatically gets a customizable QR code, and every click is recorded for detailed analytics — device, browser, operating system, referrer, and UTM parameters — all stored in your own database, with no third-party tracking.

= Link shortening & QR codes =

* Unlimited short links with random or custom slugs
* Automatic QR code generation (PNG or SVG) with square/rounded/circle styles, custom colors, and an optional brand-logo band
* Optional caption text baked directly into the QR image (e.g. "Check Price on Amazon") — ideal for videos and thumbnails
* Multi-destination URL rotation (Round Robin, Random, or Weighted Random) with per-destination analytics and an optional fallback URL
* 301 / 302 / 307 redirect types
* Password-protected links, expiration dates, and click limits
* Geo (country) and device targeting rules — send visitors to different URLs based on where they are or what they're using
* Quick Add: paste a URL and press Enter to create a link instantly
* Bulk actions: enable/disable, trash/restore/delete, regenerate QR, set category, add tag, export selected
* Filters by status, category, tag, color label, favorite, link health, and creation date
* Broken-link checker with a Smart Link Score — on-demand always, and on an optional, off-by-default daily schedule (see "External services" below)

= Smart Bio Link pages =

* Link-in-bio landing pages at your own domain (`/bio/your-name`)
* Drag-and-drop buttons, product-card buttons (image + text), link groups (accordions), and a social-icon row
* Announcement bar, countdown timer, floating contact buttons, campaign scheduling, and per-button country/device visibility
* Optional email-capture gate and password protection
* Ready-made templates, one-click import/export, and a live preview while editing
* Per-page and per-button analytics

= Analytics & tools =

* Real-time click/view analytics: device, browser, OS, referrer (categorized), and UTM breakdowns, with a date-range picker and optional bot inclusion
* QR-scan vs. direct-traffic split
* Offline GeoIP country detection — visitor IPs never leave your server
* Bot/crawler filtering — link-preview and search-engine bots are excluded from counts
* Keyword auto-linking — automatically link chosen keywords in your post content
* Activity Log audit trail, Database Repair Tool, and a System Health checker
* Full REST API (`/wp-json/qlqr/v1/`) secured with capability checks and rate limiting
* Shortcodes: `[qlqr]`, `[qlqr_qr]`, `[qlqr_button]`, `[qlqr_stats]`
* Bulk CSV import and migration from Pretty Links / ThirstyAffiliates
* CSV export of links and of analytics

= Privacy =

* IP addresses are never stored — only a salted, one-way SHA-256 hash
* No per-visitor external requests; all redirects, QR generation, and geolocation happen on your own server
* Clean uninstall routine with opt-in data removal

== External services ==

This plugin relies on three external services, detailed below.

**Freemius (licensing and update SDK)**

This plugin uses the Freemius SDK to manage plugin updates, licensing for the premium version,
and (only if you opt in) anonymous usage data used to improve the plugin and offer support.

On activation, an administrator is shown a one-time opt-in screen asking permission to connect the
site to Freemius. Connecting sends non-sensitive data such as this site's URL, admin email, WordPress
and PHP versions, and this plugin's version — never post content, visitor data, or link/analytics data
stored by this plugin. An administrator may skip connecting; the plugin remains fully usable, and
Freemius still performs basic update checks for the plugin itself.

* Service: Freemius, https://freemius.com/
* Terms of use: https://freemius.com/terms/
* Privacy policy: https://freemius.com/privacy/

**GeoIP country dataset (npm registry)**

This service is used to power the optional, offline "Top Countries" analytics report. The plugin
downloads a public IP-to-country dataset once and looks up visitor countries locally afterwards —
no per-visitor lookup is ever sent anywhere.

This service is called only when a site administrator clicks "Download / Update Database" on the
Settings screen. It is never called automatically, on a schedule, or in response to a visitor. If
that button is never clicked, this plugin makes no request to this service at all, and the Top
Countries report simply stays empty.

What is sent: a plain package-download request for the `@ip-location-db/asn-country` package —
no visitor data, IP address, or site data of any kind.

The dataset itself is released into the public domain (CC0) by the ip-location-db project:
https://github.com/sapics/ip-location-db

* Service: npm registry, https://registry.npmjs.org/
* Terms of use: https://docs.npmjs.com/policies/terms
* Privacy policy: https://docs.npmjs.com/policies/privacy

**Link destination check (broken-link checker)**

This service check is used to tell you whether a short link's own destination is still online. The
"destination" is whichever URL you yourself configured as a link's target when creating it — this
plugin does not call any single fixed third-party service for this feature, but rather whatever
destination URLs you have added.

This is off by default. It is called only when a site administrator either clicks "Check All Links"
on the Links screen (a one-time, on-demand check), or turns on "Automatically check for broken
links" on the Settings screen (a daily scheduled check, which can be turned off again at any time).
It is never called automatically before that setting is turned on, and never in response to a visitor.

What is sent: a standard HTTP GET request to the destination URL, with a generic User-Agent string
identifying the plugin (no site-identifying information, such as this site's own URL, is included).
No visitor data is ever sent.

If the setting is never turned on and "Check All Links" is never clicked, this plugin makes no such
request at all.

If you never click that button and never turn the setting on, the plugin makes no such requests at all.

== Installation ==

1. Upload the `plugnova-link-shortener-qr` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Plugnova Link Shortener & QR > Settings to configure your URL prefix and QR defaults.
4. Go to Plugnova Link Shortener & QR > Links to create your first short link.

== Frequently Asked Questions ==

= Where are QR codes stored? =

Generated QR images are saved to `wp-content/uploads/plugnova-link-shortener-qr/`.

= Are visitor IP addresses stored? =

No. Only a one-way SHA-256 hash (salted with your site's AUTH_SALT) is stored, so individual visitors cannot be identified or tracked across sites.

= Does this require any external services? =

No per-visitor external calls are ever made. The only external request is optional and admin-triggered — see the "External services" section above for the GeoIP country dataset download.

= How does password protection remember a visitor? =

When a visitor enters the correct password for a link or bio page, the plugin sets a signed, HTTP-only cookie (keyed with your site's secret salt) so they aren't re-prompted on the next view. It does not use PHP sessions. Changing a link's password automatically invalidates existing unlock cookies.

= Why does this need PHP 8.1? =

The plugin's data objects are built on readonly properties, which PHP added in 8.1. They are what guarantee a link, click or bio page cannot be altered after it has been loaded from the database — the safety that lets the rest of the code stay simple. Supporting 8.0 would mean giving that up throughout.

PHP 8.0 and everything before it reached end of life some time ago and no longer receive security fixes, so 8.1 is a floor rather than a stretch. Most managed WordPress hosts default to 8.1 or newer; if yours is older, your host can usually switch the version from your control panel in a click.

= Will short links work on my host? =

Short links and bio pages rely on WordPress pretty permalinks. If your Permalinks setting is "Plain", set it to any other option. The built-in System Health checker (Settings tab) flags this and other common hosting issues for you.

== Support ==

For bug reports, questions and feature requests, please use the
support forum for this plugin on WordPress.org.

== Screenshots ==

1. The Links dashboard with per-link QR codes, click trends, and Smart Link Score.
2. The create/edit link modal with QR styling, targeting rules, and scheduling.
3. Per-link analytics with device/browser/country/referrer/UTM breakdowns.
4. The Smart Bio Link page builder with live preview.
5. A published Smart Bio Link page.
6. Settings, including System Health and the Database Repair Tool.

== Changelog ==

= 1.0.0 =
* Initial release.
* Link shortening with random or custom slugs, 301/302/307 redirects, password protection, expiry dates and click limits.
* Automatic QR codes (PNG or SVG) with square/rounded/circle styles, custom colours, an optional brand-logo band and baked-in caption text.
* Multi-destination rotation (round robin, random, weighted random) with per-destination analytics and a fallback URL.
* Geo and device targeting rules, backed by an offline IP-to-country database you install with one click.
* Smart Bio Link pages: drag-and-drop buttons, product cards, link groups, social row, announcement bar, countdown timer, floating contact buttons, campaign scheduling, email capture, password protection, themes and live preview.
* Click and view analytics: charts over time, device/browser/OS/country/referrer/UTM breakdowns, unique and bot-filtered counts, QR-scan vs. direct split, and CSV export.
* Keyword auto-linking, broken-link checker with a Smart Link Score, and a live destination preview.
* Bulk CSV import plus migration from Pretty Links and ThirstyAffiliates.
* Activity Log audit trail, Database Repair Tool and System Health checker.
* REST API under /wp-json/qlqr/v1/ and the [qlqr], [qlqr_qr], [qlqr_button], [qlqr_stats], [qlqr_bio] and [qlqr_bio_qr] shortcodes.
