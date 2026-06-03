(function () {
    'use strict';

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    var cfg         = window.wnpConfig || {};
    var swReg       = null;
    var swReadyFlag = false;

    var KEY_DECIDED = 'wnp_decided';
    var KEY_VISITED = 'wnp_visited';

    // ── Utilities ─────────────────────────────────────────────────────────────

    function urlB64ToUint8(b64url) {
        var pad = '='.repeat((4 - (b64url.length % 4)) % 4);
        var b64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function bufToB64url(buf) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    function hasDecided()        { try { return !!localStorage.getItem(KEY_DECIDED); } catch(e) { return false; } }
    function recordDecision(val) { try { localStorage.setItem(KEY_DECIDED, val); }    catch(e) {} }
    function isNewVisitor() {
        try {
            if (localStorage.getItem(KEY_VISITED)) return false;
            localStorage.setItem(KEY_VISITED, '1');
            return true;
        } catch(e) {
            // localStorage blocked (private browsing, strict mode) — treat as new visitor.
            return true;
        }
    }

    // ── Popup ─────────────────────────────────────────────────────────────────

    function showPopup() {
        var popup = document.getElementById('wnp-popup');
        if (!popup) return;
        document.getElementById('wnp-popup-title').textContent = cfg.popupTitle || '';
        document.getElementById('wnp-popup-body').textContent  = cfg.popupBody  || '';
        popup.style.display = 'flex';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                popup.classList.add('wnp-popup--in');
            });
        });
    }

    function hidePopup() {
        var popup = document.getElementById('wnp-popup');
        if (!popup) return;
        popup.classList.remove('wnp-popup--in');
        popup.addEventListener('transitionend', function () {
            popup.style.display = 'none';
        }, { once: true });
    }

    // ── Subscription ──────────────────────────────────────────────────────────

    /**
     * Subscribe browser to push and POST to server.
     *
     * NOTE: No nonce or auth header is sent.
     * The /subscribe endpoint is intentionally public and protected by
     * IP-based rate limiting on the server. We removed nonce because
     * Chrome blocks third-party cookies causing nonce verification to fail.
     */
    function doSubscribe() {
        if (!swReg) {
            return Promise.reject(new Error('Service Worker not ready'));
        }

        return swReg.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: urlB64ToUint8(cfg.publicKey),
        }).then(function (sub) {
            var body = {
                endpoint:   sub.endpoint,
                public_key: bufToB64url(sub.getKey('p256dh')),
                auth_token: bufToB64url(sub.getKey('auth')),
            };

            return fetch(cfg.restUrl + '/subscribe', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                // ← No X-WNP-Nonce header: nonce removed due to third-party cookie blocking
                body:    JSON.stringify(body),
            });
        }).then(function (res) {
            if (!res.ok) {
                return res.json().then(function (d) {
                    throw new Error(d.error || 'Server error ' + res.status);
                });
            }
            return res.json();
        });
    }

    // ── Button listeners ──────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        var allowBtn   = document.getElementById('wnp-allow-btn');
        var dismissBtn = document.getElementById('wnp-dismiss-btn');

        if (allowBtn) {
            /**
             * FIX: Use a regular (non-async) handler.
             * Call Notification.requestPermission() as the VERY FIRST statement
             * so the browser gesture context is still active.
             *
             * If we use async/await here, Chrome suppresses the native permission
             * dialog because the user gesture token is consumed before the call.
             */
            allowBtn.addEventListener('click', function () {

                // Disable button to prevent double-click.
                allowBtn.disabled = true;
                allowBtn.textContent = '...';

                // FIRST call inside click handler — gesture context is still live.
                Notification.requestPermission().then(function (perm) {

                    hidePopup();

                    if (perm !== 'granted') {
                        recordDecision('denied');
                        allowBtn.disabled = false;
                        allowBtn.textContent = 'Allow Notifications';
                        return;
                    }

                    // Permission granted — subscribe now.
                    var doSub = swReadyFlag && swReg
                        ? doSubscribe()
                        : navigator.serviceWorker.ready.then(function (reg) {
                            swReg       = reg;
                            swReadyFlag = true;
                            return doSubscribe();
                        });

                    doSub.then(function () {
                        recordDecision('granted');
                    }).catch(function (err) {
                        console.error('[WNP] Subscribe failed:', err);
                        // Do not record as 'error' — let them retry next visit.
                    });

                }).catch(function (err) {
                    console.error('[WNP] requestPermission error:', err);
                    hidePopup();
                    allowBtn.disabled = false;
                    allowBtn.textContent = 'Allow Notifications';
                });
            });
        }

        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                hidePopup();
                recordDecision('dismissed');
            });
        }
    });

    // ── Main init ─────────────────────────────────────────────────────────────

    function init() {
        navigator.serviceWorker.register(cfg.swUrl, { scope: '/' })
            .then(function (reg) {
                swReg = reg;
                return navigator.serviceWorker.ready;
            })
            .then(function (readyReg) {
                swReg       = readyReg;
                swReadyFlag = true;

                if (Notification.permission === 'denied') return;

                // Already granted — silently re-subscribe if subscription was cleared.
                if (Notification.permission === 'granted') {
                    return swReg.pushManager.getSubscription().then(function (existing) {
                        if (!existing) {
                            return doSubscribe();
                        }
                    });
                }

                // Default permission — show popup to new visitors only.
                if (!isNewVisitor()) return;
                if (hasDecided())    return;

                var delay = Math.max(0, parseInt(cfg.delay, 10) || 3);
                setTimeout(showPopup, delay * 1000);
            })
            .catch(function (err) {
                console.error('[WNP] Service Worker init failed:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
