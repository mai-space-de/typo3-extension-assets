/*
 * mai_assets video helper for mai:video
 * - .mai-video-facade: replaces the poster with the iframe from its <template> on click
 *   (the embed and its cookies are only loaded after consent by click)
 * - video[data-lazy]: background videos get their source when they come near the viewport
 * - img[data-fallback-src]: YouTube posters fall back when maxresdefault does not exist
 *
 * Replace or disable it with the extension setting "videoScript".
 */
(function () {
    'use strict';

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function activateFacade(facade) {
        if (!facade || facade.getAttribute('data-mai-active') === '1') return;
        var template = facade.querySelector('template');
        var iframe = template && template.content ? template.content.querySelector('iframe') : null;
        if (!iframe) return;

        facade.setAttribute('data-mai-active', '1');
        iframe = iframe.cloneNode(true);
        while (facade.firstChild) {
            facade.removeChild(facade.firstChild);
        }
        facade.appendChild(iframe);
        iframe.focus();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('.mai-video-facade .mai-video-play') : null;
        if (!button) return;
        event.preventDefault();
        activateFacade(button.closest('.mai-video-facade'));
    });

    // YouTube answers a missing maxresdefault.jpg with a 120x90 placeholder (or an error).
    function usePosterFallback(img) {
        var fallback = img.getAttribute('data-fallback-src');
        if (!fallback) return;
        img.removeAttribute('data-fallback-src');
        img.src = fallback;
    }

    function checkPoster(img) {
        if (!img.getAttribute('data-fallback-src')) return;
        if (img.complete) {
            if (img.naturalWidth === 0 || img.naturalWidth <= 120) usePosterFallback(img);
            return;
        }
        img.addEventListener('load', function () {
            if (img.naturalWidth <= 120) usePosterFallback(img);
        }, {once: true});
        img.addEventListener('error', function () {
            usePosterFallback(img);
        }, {once: true});
    }

    function loadVideo(video) {
        var src = video.getAttribute('data-src');
        video.removeAttribute('data-lazy');
        if (src) {
            video.removeAttribute('data-src');
            video.src = src;
        }
        if (video.muted && video.loop && !reducedMotion) {
            var playing = video.play();
            if (playing && playing.catch) playing.catch(function () {});
        }
    }

    function init() {
        var posters = document.querySelectorAll('img[data-fallback-src]');
        for (var i = 0; i < posters.length; i++) checkPoster(posters[i]);

        var videos = document.querySelectorAll('video[data-lazy][data-src]');
        if (!videos.length) return;

        if (typeof IntersectionObserver === 'undefined') {
            for (var j = 0; j < videos.length; j++) loadVideo(videos[j]);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                loadVideo(entry.target);
            });
        }, {rootMargin: '200px 0px'});

        for (var k = 0; k < videos.length; k++) observer.observe(videos[k]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
