/* jshint esversion: 6 */
'use strict';

const WNP_SW_VERSION = 'wnp-v1';

// ── Lifecycle ─────────────────────────────────────────────────────────────────

self.addEventListener('install', function (event) {
    // Activate immediately without waiting for existing tabs to close.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    // Take control of all open pages right away.
    event.waitUntil(self.clients.claim());
});

// ── Push event ────────────────────────────────────────────────────────────────

self.addEventListener('push', function (event) {

    // BUG FIX: if event.data is null (e.g. empty push ping from server),
    // show a generic notification rather than silently dropping it.
    var payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (e) {
            payload = { title: 'New notification', body: event.data.text() };
        }
    } else {
        payload = { title: 'New notification', body: 'You have a new update.' };
    }

    var title = String(payload.title || 'Notification');

    var options = {
        body:               String(payload.body  || ''),
        icon:               String(payload.icon  || '/favicon.ico'),
        badge:              String(payload.badge || '/favicon.ico'),
        data:               { url: String(payload.url || self.location.origin + '/') },
        vibrate:            [150, 75, 150],
        requireInteraction: false,
        // 'tag' replaces an existing notification with the same tag.
        // Use the notification ID if provided so multiple notifs stack properly.
        tag:                String(payload.tag || 'wnp-notification'),
        renotify:           true,
        actions: [
            { action: 'open',    title: 'Open'    },
            { action: 'dismiss', title: 'Dismiss' },
        ],
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// ── Notification click ────────────────────────────────────────────────────────

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    if (event.action === 'dismiss') return;

    var targetUrl = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : self.location.origin + '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(function (clientList) {
                // Focus an existing tab already showing the target URL.
                for (var i = 0; i < clientList.length; i++) {
                    var client = clientList[i];
                    try {
                        var clientUrl = new URL(client.url);
                        var target    = new URL(targetUrl);
                        if (clientUrl.href === target.href && 'focus' in client) {
                            return client.focus();
                        }
                    } catch (e) { /* ignore URL parse errors */ }
                }
                // No existing tab — open a new window.
                if (clients.openWindow) {
                    return clients.openWindow(targetUrl);
                }
            })
    );
});

// ── Subscription change ───────────────────────────────────────────────────────
// Push services (Chrome/Firefox) can silently rotate subscriptions.
// When that happens, forward the new subscription to the WP server.

self.addEventListener('pushsubscriptionchange', function (event) {
    // BUG FIX: guard against missing oldSubscription (can happen in some browsers).
    if (!event.oldSubscription) return;

    event.waitUntil(
        self.registration.pushManager
            .subscribe(event.oldSubscription.options)
            .then(function (sub) {
                function toB64url(buf) {
                    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
                        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
                }

                // NOTE: No nonce available in SW context.
                // The server must accept subscription renewals without nonce
                // (the endpoint URL itself is a sufficient secret here).
                return fetch(self.location.origin + '/wp-json/wp-native-push/v1/subscribe', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        endpoint:   sub.endpoint,
                        public_key: toB64url(sub.getKey('p256dh')),
                        auth_token: toB64url(sub.getKey('auth')),
                        // Signal that this is a subscription renewal, not a new subscribe.
                        renew:      true,
                    }),
                });
            })
            .catch(function (err) {
                console.error('[WNP SW] pushsubscriptionchange failed:', err);
            })
    );
});
