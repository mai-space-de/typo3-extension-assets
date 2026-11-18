(function () {
    'use strict';

    var PAGE_UID = ###PAGE_UID###;
    var ENDPOINT = '###ENDPOINT###';
    var SAMPLE_RATE = ###SAMPLE_RATE###;
    var REPORT_TTL = 86400000; // one report per image and viewport step and day
    var MAX_ITEMS = 24;
    var STEP = 160;
    var SEND_DELAY = 2000;
    var IDLE_TIMEOUT = 5000;

    if (!navigator.sendBeacon && !window.fetch) return;
    if (SAMPLE_RATE < 100 && Math.random() * 100 >= SAMPLE_RATE) return;

    function viewportStep(width) {
        if (width > 2560) return 2720;
        return Math.max(320, Math.ceil(width / STEP) * STEP);
    }

    function collect() {
        var viewport = window.innerWidth || document.documentElement.clientWidth || 0;
        if (viewport < 200 || viewport > 10000) return;

        var step = viewportStep(viewport);
        var now = Date.now();
        var items = [];
        var marks = [];
        var images = document.querySelectorAll('img[data-mai-m]');

        for (var i = 0; i < images.length && items.length < MAX_ITEMS; i++) {
            var raw = images[i].getAttribute('data-mai-m') || '';
            var split = raw.lastIndexOf(':');
            if (split < 1) continue;

            // Hidden images (carousel slides, closed tabs, display:none) have no layout width.
            var width = Math.round(images[i].getBoundingClientRect().width);
            if (!(width > 0) || width > 3840) continue;

            var key = raw.slice(0, split);
            var storageKey = 'mai_m_' + key + '_' + step;
            try {
                if (now - (parseInt(localStorage.getItem(storageKey), 10) || 0) < REPORT_TTL) continue;
            } catch (e) {
                // Storage unavailable: report anyway, the server caps the data.
            }
            items.push({k: key, t: raw.slice(split + 1), w: width});
            marks.push(storageKey);
        }

        if (!items.length) return;

        var payload = JSON.stringify({p: PAGE_UID, v: viewport, m: items});

        function remember() {
            try {
                for (var j = 0; j < marks.length; j++) {
                    localStorage.setItem(marks[j], String(now));
                }
            } catch (e) {
                // Ignore storage errors
            }
        }

        // Lowest priority transport: a beacon never competes with page resources and
        // never shows up in the critical request chain. Its response is not readable,
        // so an accepted beacon counts as delivered.
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

        if (!window.fetch) return;
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

    var done = false;

    function run() {
        if (done) return;
        done = true;
        window.removeEventListener('pagehide', run);
        document.removeEventListener('visibilitychange', onHidden);
        collect();
    }

    function onHidden() {
        if (document.visibilityState === 'hidden') run();
    }

    function schedule() {
        // Let the layout settle (fonts, late CSS) and stay out of the loading phase;
        // leaving the page earlier still sends the measurement.
        setTimeout(function () {
            if (typeof requestIdleCallback === 'function') {
                requestIdleCallback(run, {timeout: IDLE_TIMEOUT});
            } else {
                run();
            }
        }, SEND_DELAY);
    }

    window.addEventListener('pagehide', run);
    document.addEventListener('visibilitychange', onHidden);

    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule);
    }
}());
