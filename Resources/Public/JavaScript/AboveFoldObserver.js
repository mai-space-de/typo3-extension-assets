(function () {
    'use strict';

    var PAGE_UID = ###PAGE_UID###;
    var SERVER_RESET_TIMESTAMP = ###SERVER_RESET_TIMESTAMP###;

    // Determine viewport bucket
    function getViewportBucket() {
        var width = window.innerWidth || document.documentElement.clientWidth;
        if (width <= 768) return 'mobile';
        if (width <= 1024) return 'tablet';
        return 'desktop';
    }

    var bucket = (function () {
        // Check cookie first
        var cookieMatch = document.cookie.match(/(?:^|;\s*)viewport_bucket=([^;]+)/);
        if (cookieMatch) return cookieMatch[1];
        return getViewportBucket();
    }());

    var storageKey = 'mai_assets_p' + PAGE_UID + '_' + bucket + '_ts';

    // Skip if we already have a fresh report for this reset timestamp
    try {
        var stored = parseInt(localStorage.getItem(storageKey), 10) || 0;
        if (stored >= SERVER_RESET_TIMESTAMP && SERVER_RESET_TIMESTAMP > 0) {
            return;
        }
    } catch (e) {
        // localStorage may not be available — continue
    }

    var criticalUids = [];
    var observer = null;

    function observeElements() {
        var elements = document.querySelectorAll('[data-ce-uid]');
        if (!elements.length) return;

        var options = {
            root: null,
            rootMargin: '0px',
            threshold: 0
        };

        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var uid = parseInt(entry.target.getAttribute('data-ce-uid'), 10);
                    if (uid > 0 && criticalUids.indexOf(uid) === -1) {
                        criticalUids.push(uid);
                    }
                }
            });
        }, options);

        elements.forEach(function (el) {
            observer.observe(el);
        });
    }

    function sendReport() {
        if (observer) {
            observer.disconnect();
            observer = null;
        }

        var payload = JSON.stringify({
            pageUid: PAGE_UID,
            url: window.location.href,
            bucket: bucket,
            criticalUids: criticalUids
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/mai-assets/above-fold-report', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    localStorage.setItem(storageKey, String(SERVER_RESET_TIMESTAMP));
                } catch (e) {
                    // Ignore storage errors
                }
            }
        };
        xhr.send(payload);
    }

    // Wait for window load, then disconnect and send report
    function init() {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        observeElements();

        if (document.readyState === 'complete') {
            if (typeof requestIdleCallback === 'function') {
                requestIdleCallback(sendReport);
            } else {
                setTimeout(sendReport, 0);
            }
        } else {
            window.addEventListener('load', function () {
                if (typeof requestIdleCallback === 'function') {
                    requestIdleCallback(sendReport);
                } else {
                    setTimeout(sendReport, 0);
                }
            });
        }
    }

    init();
}());
