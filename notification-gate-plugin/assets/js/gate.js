(function () {
    'use strict';

    var redirectUrl = NGP.redirect_url;
    var autoPrompt  = NGP.auto_prompt === '1';

    var lockScreen    = document.getElementById('ngp-lock-screen');
    var deniedScreen  = document.getElementById('ngp-denied-screen');
    var loadingScreen = document.getElementById('ngp-loading-screen');
    var allowBtn      = document.getElementById('ngp-allow-btn');
    var retryBtn      = document.getElementById('ngp-retry-btn');

    function showScreen(screen) {
        [lockScreen, deniedScreen, loadingScreen].forEach(function (s) {
            s.style.display = 'none';
        });
        screen.style.display = 'flex';
    }

    function requestPermission() {
        if (!('Notification' in window)) {
            alert('আপনার ব্রাউজার Push Notification সাপোর্ট করে না।');
            return;
        }

        // Already granted — redirect immediately
        if (Notification.permission === 'granted') {
            showScreen(loadingScreen);
            setTimeout(function () {
                window.location.href = redirectUrl;
            }, 800);
            return;
        }

        // Already denied — show denied screen
        if (Notification.permission === 'denied') {
            showScreen(deniedScreen);
            return;
        }

        // Ask permission
        Notification.requestPermission().then(function (permission) {
            if (permission === 'granted') {
                showScreen(loadingScreen);
                // Small notification to confirm
                new Notification('সাফল্য!', {
                    body: 'Notification চালু হয়েছে। Redirecting...',
                    icon: ''
                });
                setTimeout(function () {
                    window.location.href = redirectUrl;
                }, 1200);
            } else {
                showScreen(deniedScreen);
            }
        });
    }

    // Button click
    if (allowBtn) {
        allowBtn.addEventListener('click', requestPermission);
    }

    // Retry button — just re-ask (works if user changed browser settings)
    if (retryBtn) {
        retryBtn.addEventListener('click', function () {
            showScreen(lockScreen);
            setTimeout(requestPermission, 300);
        });
    }

    // Auto prompt on page load
    if (autoPrompt) {
        // Small delay so page renders first
        setTimeout(requestPermission, 800);
    }

    // If already granted on load, redirect straight away
    if (Notification.permission === 'granted') {
        showScreen(loadingScreen);
        setTimeout(function () {
            window.location.href = redirectUrl;
        }, 500);
    }

})();
