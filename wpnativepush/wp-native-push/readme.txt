=== WP Native Push ===
Contributors:       yourhandle
Tags:               push notifications, web push, VAPID, self-hosted, notifications
Requires at least:  5.8
Tested up to:       6.7
Requires PHP:       7.3
Stable tag:         1.0.0
License:            GPL-2.0+
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted Web Push Notifications using the VAPID protocol. No third-party SaaS. No API fees. Works with Chrome, Firefox, Edge, and Safari.

== Description ==

WP Native Push lets you send browser push notifications directly from your WordPress server — no OneSignal, no Firebase, no monthly fees.

**How it works:**

1. The plugin generates a VAPID EC P-256 key pair on activation.
2. A lightweight JavaScript snippet registers a Service Worker on every page of your site.
3. A beautiful customisable popup (not the raw browser prompt) asks new visitors for permission.
4. Approved subscriptions are stored in a custom database table.
5. When you send a notification from the admin, it is encrypted using RFC 8291 (aes128gcm) and delivered via the Web Push Protocol directly from your server.
6. Delivery is processed in batches of 50 via WP-Cron to avoid server timeouts.

**Key Features:**

* ✅ 100% self-hosted — zero third-party services
* ✅ VAPID authentication (RFC 8292) — no API keys needed
* ✅ RFC 8291 aes128gcm payload encryption — pure PHP, no extra libraries
* ✅ Customisable subscription popup (title, body, delay)
* ✅ Dark mode aware popup
* ✅ Batch send via WP-Cron (50/run)
* ✅ Auto-removes expired/invalid subscriptions (HTTP 404/410)
* ✅ Live notification preview in compose screen
* ✅ WordPress media picker for notification icon
* ✅ Activity log and job history dashboard
* ✅ Works with Chrome, Firefox, Edge, and Safari (macOS/iOS 16.4+)

**Browser Support:**

| Browser         | Support |
|-----------------|---------|
| Chrome 50+      | ✅      |
| Firefox 44+     | ✅      |
| Edge 17+        | ✅      |
| Safari 16.4+    | ✅      |
| Opera 42+       | ✅      |
| IE / old Safari | ❌      |

== Installation ==

1. Upload the `wp-native-push` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Push Notify → Settings** to configure your contact email and popup text.
4. Visit your site's frontend — the subscription popup will appear to new visitors.
5. Use **Push Notify → Send Notification** to compose and send your first push.

**Nginx users** — add this to your server block so the Service Worker resolves correctly:

```
location = /wnp-service-worker.js {
    try_files $uri /index.php$is_args$args;
}
```

Apache users: the default WordPress `.htaccess` handles this automatically.

== Frequently Asked Questions ==

= Does this work without a third-party service? =
Yes. All encryption, VAPID signing, and HTTP delivery happen on your own server.

= Which PHP version is required? =
PHP 7.3 or higher. The OpenSSL extension with EC (prime256v1) curve support is also required.

= What happens when I regenerate VAPID keys? =
All existing subscribers are invalidated because their subscriptions were signed with the old public key. They will need to re-subscribe on their next visit.

= How do I make WP-Cron reliable? =
Add a real system cron job:
`* * * * * wp --path=/path/to/wordpress cron event run --due-now > /dev/null 2>&1`
Then add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

= The service worker returns a 404. What do I do? =
Go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

= Does this support iOS / Safari? =
Yes, from Safari 16.4+ on macOS and iOS 16.4+, which added Web Push support.

== Screenshots ==

1. Dashboard with subscriber stats, job history, and activity log.
2. Send Notification compose screen with live preview.
3. Subscriber management table.
4. Settings page with VAPID key management and popup configuration.
5. Subscription popup on the frontend (light and dark mode).

== Changelog ==

= 1.0.0 =
* Initial release.
* VAPID key generation and JWT ES256 signing.
* RFC 8291 aes128gcm payload encryption.
* Subscription popup (new visitor only).
* Batch sending via WP-Cron.
* Auto-removal of stale subscriptions on HTTP 404/410.
* Admin dashboard, compose, subscribers, and settings pages.
* WordPress dashboard widget.
* Dark mode support.
* Full uninstall cleanup.

== Upgrade Notice ==

= 1.0.0 =
First stable release.
