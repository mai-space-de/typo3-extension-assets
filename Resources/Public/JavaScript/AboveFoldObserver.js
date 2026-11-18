(function () {
    'use strict';

    var PAGE_UID = ###PAGE_UID###;
    var SERVER_RESET_TIMESTAMP = ###SERVER_RESET_TIMESTAMP###;
    var REPORT_TOKEN = '###REPORT_TOKEN###';
    var VALID_BUCKETS = ###VALID_BUCKETS###;
    var ENDPOINT = '/api/mai-assets/above-fold-report';
    var SEND_DELAY = 2000;
    var IDLE_TIMEOUT = 5000;

    // Determine viewport bucket
    function getViewportBucket() {
        var width = window.innerWidth || document.documentElement.clientWidth;
        if (width <= 768) return 'mobile';
        if (width <= 1024) return 'tablet';
        return 'desktop';
    }

    var bucket = (function () {
        // Check cookie first, validate against server-provided allowlist
        var cookieMatch = document.cookie.match(/(?:^|;\s*)viewport_bucket=([^;]+)/);
        if (cookieMatch && VALID_BUCKETS.indexOf(cookieMatch[1]) !== -1) return cookieMatch[1];
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
    var observed = false; // first IntersectionObserver callback arrived
    var done = false;

    function freeze() {
        // Only the initial viewport counts: once the user scrolls, stop collecting.
        if (observer) {
            observer.disconnect();
            observer = null;
        }
    }

    function observeElements() {
        var elements = document.querySelectorAll('[data-ce-uid]');
        if (!elements.length) return false;

        observer = new IntersectionObserver(function (entries) {
            observed = true;
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var uid = parseInt(entry.target.getAttribute('data-ce-uid'), 10);
                    if (uid > 0 && criticalUids.indexOf(uid) === -1) {
                        criticalUids.push(uid);
                    }
                }
            });
        }, {
            root: null,
            rootMargin: '0px',
            threshold: 0
        });

        elements.forEach(function (el) {
            observer.observe(el);
        });

        return true;
    }

    function remember() {
        try {
            localStorage.setItem(storageKey, String(SERVER_RESET_TIMESTAMP));
        } catch (e) {
            // Ignore storage errors
        }
    }

    // Lowest priority transport: a beacon never competes with page resources and
    // never shows up in the critical request chain. Its response is not readable,
    // so an accepted beacon counts as delivered.
    function post(payload) {
        if (navigator.sendBeacon) {
            try {
                if (navigator.sendBeacon(ENDPOINT, new Blob([payload], {type: 'application/json'}))) {
                    remember();
                    return;
                }
            } catch (e) {
                // Fall through to fetch
            }
        }

        if (window.fetch) {
            fetch(ENDPOINT, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: payload,
                keepalive: true,
                credentials: 'omit',
                priority: 'low'
            }).then(function (response) {
                if (response.ok) remember();
            }).catch(function () {});
        }
    }

    function sendReport() {
        if (done) return;
        done = true;
        freeze();
        removeListeners();

        // Scrolled before the first observation: the data would not describe the initial viewport.
        if (!observed) return;

        post(JSON.stringify({
            pageUid: PAGE_UID,
            resetTimestamp: SERVER_RESET_TIMESTAMP,
            token: REPORT_TOKEN,
            bucket: bucket,
            criticalUids: criticalUids
        }));
    }

    function onScroll() {
        freeze();
        window.removeEventListener('scroll', onScroll);
    }

    function onHidden() {
        if (document.visibilityState === 'hidden') sendReport();
    }

    function removeListeners() {
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('pagehide', sendReport);
        document.removeEventListener('visibilitychange', onHidden);
    }

    function scheduleReport() {
        // Well after load and only when the main thread is idle; leaving the page sends earlier.
        setTimeout(function () {
            if (typeof requestIdleCallback === 'function') {
                requestIdleCallback(sendReport, {timeout: IDLE_TIMEOUT});
            } else {
                sendReport();
            }
        }, SEND_DELAY);
    }

    function init() {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        // Only observe when the page starts at the top of the scroll position.
        // If the browser has restored scroll position (e.g. history.back() or
        // bfcache restore), elements that are NOT above the fold would be
        // detected as intersecting, producing incorrect criticality data.
        var scrollTop = window.pageYOffset !== undefined
            ? window.pageYOffset
            : (document.documentElement.scrollTop || document.body.scrollTop || 0);
        if (scrollTop > 0) {
            return;
        }

        if (!observeElements()) {
            return;
        }

        window.addEventListener('scroll', onScroll, {passive: true});
        window.addEventListener('pagehide', sendReport);
        document.addEventListener('visibilitychange', onHidden);

        if (document.readyState === 'complete') {
            scheduleReport();
        } else {
            window.addEventListener('load', scheduleReport);
        }
    }

    init();
}());
