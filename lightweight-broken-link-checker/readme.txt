=== Lightweight Broken Link Checker ===
Contributors: lightweightplugins
Tags: broken links, link checker, seo, 404, maintenance
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds broken links in your content using small, controlled background batches instead of constant scanning, so your site stays fast.

== Description ==

Most broken link checkers scan continuously, store enormous amounts of data and slow the whole site down. This plugin takes the opposite approach: it does a strictly limited amount of work at a time and then stops.

* **Controlled batches.** Content is scanned 50 posts at a time, and each batch queues the next one. Nothing runs in parallel, and no request ever processes your entire site.
* **A small, fixed check budget.** A recurring background job verifies at most 15 links every five minutes. That is the ceiling, whether you have 500 links or 500,000.
* **One compact table.** Links live in a single custom table with one row per link per post, not scattered across postmeta.
* **Powered by Action Scheduler.** All background work runs through Action Scheduler, the same queue WooCommerce uses. Native wp-cron is not used, so batches are not tied to page views and are retried reliably.

= Careful checking, fewer false alarms =

* Each link is checked with a lightweight `HEAD` request first. If that fails or is refused, the plugin retries with `GET`, because many servers answer `HEAD` with 403, 405 or 500 while the page is perfectly fine.
* `403` and `429` responses, timeouts and DNS errors do not immediately mark a link broken. The failure is counted, and only three consecutive failures confirm it.
* When several links in one batch point at the same host, requests are spaced out so the target server is not hammered.
* Links that never had a check are always verified first. After that, working links are re-verified once a week, oldest content first, and links already settled as broken or redirecting are revisited once a month so a link that gets fixed returns to the healthy list on its own.
* Publishing or editing a post queues a rescan of just that post, so new links are picked up without running a full scan.

= Dashboard =

The **Broken Links** screen lists every link with its source post, status badge, HTTP code and last check time. Filter by Broken, Redirect, Pending or OK, search across URLs and post titles, recheck a single link on demand, or jump straight to editing the post that contains it.

= Privacy =

The plugin sends HTTP requests to the URLs found in your own content in order to check whether they still work. Those requests identify themselves with a user agent containing your site address. No data is sent to the plugin author or any third party service.

== Installation ==

1. Upload the `lightweight-broken-link-checker` folder to `/wp-content/plugins/`, or install the plugin through the **Plugins > Add New** screen.
2. Activate the plugin through the **Plugins** screen. Activation creates the link table and schedules the recurring check.
3. Open **Broken Links** in the admin menu and press **Scan Now** to collect the links in your content.
4. Leave it alone. The background checker works through the list on its own and the dashboard shows what it found.

Action Scheduler is bundled with the plugin, so there is nothing else to install. If another plugin (such as WooCommerce) already ships Action Scheduler, the newest available copy is used automatically.

== Frequently Asked Questions ==

= How long does the first check take? =

Fifteen links every five minutes is 180 links per hour, so around 4,000 links a day. A large site therefore takes a few days to work through its backlog the first time. That is deliberate: the point of the plugin is that it never spikes your resource usage. You can raise the batch size with the `lwblc_check_batch_size` filter if your host can take it.

= Does it slow down my site? =

No. Front end requests do no database work for this plugin at all; scanning and checking happen in background jobs. Each background run is capped, so it cannot grow into a long request.

= Why is a link I know is broken still shown as pending? =

A single failure is not enough. Timeouts, `403` and `429` responses are counted but not trusted, because bot protection and rate limits produce them constantly on perfectly healthy pages. After three consecutive failures the link is confirmed as broken.

= Why is a working link reported as broken? =

Some servers block automated requests entirely. Use the **Recheck now** row action to verify by hand. If a whole host is affected, the `lwblc_request_args` and `lwblc_user_agent` filters let you adjust how requests are sent.

= Which content is scanned? =

Every published post, page and public custom post type, minus attachments. Adjust the list with the `lwblc_scan_post_types` filter. Only links inside the post content are collected; `mailto:`, `tel:`, `javascript:` and same page anchors are skipped.

= What happens to links I remove from a post? =

They are removed from the list as soon as that post is saved. All links of a post are dropped when the post is deleted, trashed or switched back to draft.

= Do I have to run a scan after publishing a post? =

No. Saving a published post queues a rescan of that one post, so its links are collected within a minute or so. The full **Scan Now** button is only needed for the first run, or after importing content in bulk. Switch the behaviour off with the `lwblc_auto_scan_on_save` filter.

= A broken link got fixed. Do I have to recheck it by hand? =

No. Links settled as broken or redirecting are revisited automatically once a month, at the lowest priority, and return to the healthy list when they answer normally again. **Recheck now** on the row is only there for when you do not want to wait.

= What does the Skipped status mean? =

The address is not something the checker will contact: a link to `localhost`, to an address on a private or reserved network, or on a protocol other than http and https. Those are refused deliberately, so the plugin can never be used to probe machines inside your network. The row shows a short reason, and the link is revisited monthly in case it becomes publicly reachable. To check a host on your own network on purpose, add it with the `lwblc_trusted_hosts` filter.

= Does it modify my content? =

Never. The plugin only reads your content and reports what it finds. Fixing a link is always your decision, made in the post editor.

= Can I use it next to the old Broken Link Checker plugin? =

Yes. That plugin uses a table with the same name but a different structure, so if it is already installed this plugin detects it during activation and stores its own links in a separate table instead. Neither plugin touches the other's data.

= What is removed when I delete the plugin? =

The link table, the plugin options and any queued background jobs. Deactivating alone keeps your data, so you can deactivate and reactivate without losing scan results.

== Screenshots ==

1. The Broken Links dashboard with status filters, the summary box and the scan progress bar.
2. Row actions for rechecking a link or editing the post that contains it.

== Changelog ==

= 1.2.0 =
* Link uniqueness is now based on a SHA-256 hash of the whole address instead of an index over its first 180 characters. Two long URLs that only differed past that point used to collide, and the second one was quietly dropped; both are now tracked. Existing installations migrate themselves, keeping every stored link.
* Outbound checks are guarded against server-side request forgery: only http and https on the usual ports are contacted, addresses on loopback, private, shared, link-local, multicast and reserved ranges are refused in both IPv4 and IPv6, internal host names are refused, resolved addresses are checked before connecting and pinned for the request, and redirects are never followed automatically.
* Links that cannot safely be contacted are listed as **Skipped** with a short reason instead of being counted as broken.
* Check results now record why a link failed — timeout, host not found, certificate problem, rate limited, and so on — shown under the status.
* Relative links are resolved against the post's own permalink, so `about/`, `../about/` and `//host/path` are checked correctly rather than ignored.
* Full scans page by post ID instead of by offset, so a long scan cannot skip or repeat posts when content is added or deleted while it runs.
* Cancelling a scan now takes effect even if a batch was already in flight.

= 1.1.1 =
* Tested against WordPress 7.0.
* Reworked every direct database query so each one is a single literal statement: table names go through `esc_sql()`, sort order through `sanitize_sql_orderby()`, and the list table builds one complete query per case instead of stitching a WHERE clause together from fragments. No behaviour change, but the queries now pass the WordPress Plugin Check security sniff cleanly.

= 1.1.0 =
* Saving a published post now queues a rescan of that post, so new and edited links are picked up without a full scan. Unpublishing, trashing or drafting a post removes its links.
* Broken and redirecting links are rechecked once a month at the lowest priority, so a link that gets fixed returns to the healthy list without a manual recheck.
* The priority queue now runs one query per tier instead of a single sorted query, which keeps it index driven on large sites.
* Added a `(status, post_modified_date)` index. The schema updates itself on load, no reactivation needed.
* Fixed: the uninstall routine cancelled queued jobs using the wrong Action Scheduler group, leaving them behind.

= 1.0.0 =
* Initial release.
* Batched content scanner (50 posts per batch) chained through Action Scheduler.
* Recurring link checker: 15 links every five minutes, never-checked links first, then working links older than a week.
* `HEAD` request with a `GET` fallback, per host request spacing, and a three strike rule before a link is confirmed broken.
* Broken Links dashboard with status filters, search, sorting, per link recheck and a summary box.

== Upgrade Notice ==

= 1.2.0 =
Security and reliability release: SSRF protection for outbound checks, and URL uniqueness by full hash instead of a 180 character prefix. The database migrates itself on the first load and keeps all existing links.

= 1.1.1 =
Compatibility with WordPress 7.0, plus hardened database query construction. No database changes.

= 1.1.0 =
Posts are now rescanned as you save them, and broken links are rechecked monthly so fixed links clear themselves. Adds a database index; the update runs automatically.

= 1.0.0 =
Initial release.
