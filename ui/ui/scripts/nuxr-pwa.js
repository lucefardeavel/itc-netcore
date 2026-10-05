/*
 * WP7 staff PWA client (owner plan 245e46bf). Progressive enhancement only: the application works unchanged without it,
 * and it holds NO authority - no price, permission, scope or business state is decided or stored here.
 *   - registers the static service worker (natural scope = the application root)
 * Feature detection only (never user-agent / device sniffing).
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var cfg = window.nuxrPwa || {};
    var swUrl = script && script.getAttribute('data-nuxr-sw');
    var secure = window.isSecureContext === true && 'serviceWorker' in navigator;

    /* ---------- registration ---------- */
    var reg = null;
    if (secure && swUrl) {
        navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).then(function (r) {
            reg = r;
            document.dispatchEvent(new CustomEvent('nuxr-pwa-registered', { detail: r }));
        }).catch(function () { /* no worker: the application keeps working as a normal web page */ });
    }

    window.nuxrPwa = cfg;
    cfg.registration = function () { return reg; };
})();
