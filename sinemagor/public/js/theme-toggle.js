/* Sinemagor dark/light mode toggle */
(function () {
    'use strict';

    var STORAGE_KEY = 'sg_theme';
    var root = document.documentElement;

    function applyTheme(theme) {
        root.setAttribute('data-sg-theme', theme);
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
    }

    function getPreferred() {
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (stored === 'dark' || stored === 'light') return stored;
        } catch (e) {}
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark' : 'light';
    }

    // Apply immediately (before paint) to avoid flash
    applyTheme(getPreferred());

    // Wire up toggle buttons once DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.sg-theme-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var current = root.getAttribute('data-sg-theme') || 'dark';
                applyTheme(current === 'dark' ? 'light' : 'dark');
            });
        });
    });
})();
