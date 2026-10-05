/*
 * WP7 staff PWA client (owner plan 245e46bf). Progressive enhancement only: the application works unchanged without it,
 * and it holds NO authority - no price, permission, scope or business state is decided or stored here.
 *   - registers the static service worker (natural scope = the application root)
 *   - connectivity: ONLINE / SLOW (slow or unstable) / OFFLINE / RECONNECTING, confirmed by a real same-origin
 *     heartbeat (pwa/ping, sent WITHOUT cookies so it never extends a session); navigator.onLine is only a hint
 *   - mutation gate: while OFFLINE or RECONNECTING a same-origin non-GET/HEAD request from the staff UI is stopped
 *     before it is sent (UX / safety only: the server stays authoritative; nothing is queued or replayed)
 * Feature detection only (never user-agent / device sniffing).
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var cfg = window.nuxrPwa || {};
    var S = cfg.s || {};
    var swUrl = script && script.getAttribute('data-nuxr-sw');
    var secure = window.isSecureContext === true && 'serviceWorker' in navigator;

    function q(sel, root) { return (root || document).querySelector(sel); }

    /* ---------- registration ---------- */
    var reg = null;
    if (secure && swUrl) {
        navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).then(function (r) {
            reg = r;
            document.dispatchEvent(new CustomEvent('nuxr-pwa-registered', { detail: r }));
        }).catch(function () { /* no worker: the application keeps working as a normal web page */ });
    }

    /* ---------- connectivity state machine ---------- */
    var SLOW_MS = 2500;
    var TIMEOUT_MS = 4000;
    var CADENCE = { online: 60000, slow: 10000, reconnecting: 2000 };
    var state = 'online';
    var fails = 0;
    var oks = 0;
    var backoff = 5000;
    var timer = null;
    var inflight = false;

    function setState(next) {
        if (next === state) { return; }
        state = next;
        if (next !== 'offline') { backoff = 5000; }
        render();
        document.dispatchEvent(new CustomEvent('nuxr-net', { detail: { state: state } }));
    }

    /** one heartbeat: valid only with HTTP 200 + JSON + the exact marker (a captive portal page is a failure) */
    function probe() {
        if (inflight || !cfg.ping) { return; }
        inflight = true;
        var ctl = window.AbortController ? new AbortController() : null;
        var t0 = Date.now();
        var to = setTimeout(function () { if (ctl) { ctl.abort(); } }, TIMEOUT_MS);
        fetch(cfg.ping, { credentials: 'omit', cache: 'no-store', redirect: 'error', signal: ctl ? ctl.signal : undefined })
            .then(function (r) {
                var rtt = Date.now() - t0;
                if (r.status >= 500) { return { reachable: true, slow: true }; }
                var ct = r.headers.get('content-type') || '';
                if (r.status !== 200 || ct.indexOf('application/json') !== 0) { return { reachable: false }; }
                return r.json().then(function (j) {
                    var valid = j && j.app === 'nuxr' && j.ok === true && Object.keys(j).length === 2;
                    return valid ? { reachable: true, slow: rtt >= SLOW_MS } : { reachable: false };
                });
            })
            .catch(function () { return { reachable: false }; })
            .then(function (res) {
                clearTimeout(to);
                inflight = false;
                onResult(res);
            });
    }

    function onResult(res) {
        if (res.reachable) {
            fails = 0;
            oks++;
            if (state === 'offline') { oks = 1; setState('reconnecting'); }
            else if (state === 'reconnecting') { if (oks >= 2) { setState(res.slow ? 'slow' : 'online'); } }
            else if (state === 'slow') { if (res.slow) { oks = 0; } else if (oks >= 2) { setState('online'); } }
            else if (res.slow) { oks = 0; setState('slow'); }
        } else {
            oks = 0;
            fails++;
            if (state === 'reconnecting' || state === 'offline') { setState('offline'); }
            else if (fails >= 2 || navigator.onLine === false) { setState('offline'); }
            else { setState('slow'); }
        }
        schedule(!res.reachable && fails === 1 && state === 'slow' ? 2000 : null);
    }

    function schedule(delay) {
        clearTimeout(timer);
        if (document.hidden) { return; } // paused while hidden; probed again when visible
        var d = delay;
        if (d == null) {
            if (state === 'offline') { d = backoff; backoff = Math.min(30000, Math.round(backoff * 1.5)); }
            else { d = CADENCE[state]; }
        }
        timer = setTimeout(probe, d);
    }

    function probeNow() { clearTimeout(timer); probe(); }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { probeNow(); } });
    window.addEventListener('online', probeNow);
    window.addEventListener('offline', function () { fails = Math.max(fails, 1); probeNow(); });

    /* ---------- status UI (chip in the top bar, banner above the content, live region) ---------- */
    var chip = null;
    var banner = null;
    var live = null;

    function ensureUi() {
        if (!chip) {
            chip = document.createElement('span');
            chip.className = 'nuxr-net-chip';
            chip.setAttribute('role', 'status');
            chip.setAttribute('aria-live', 'polite');
            chip.hidden = true;
            var bar = q('.nuxr-topbar');
            if (bar) { bar.insertBefore(chip, q('.nuxr-menu.nuxr-user', bar) || null); } else { document.body.appendChild(chip); }
        }
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'nuxr-net-banner';
            banner.hidden = true;
            var main = q('#nuxr-main') || q('main') || document.body;
            main.insertBefore(banner, main.firstChild);
        }
        if (!live) {
            live = document.createElement('div');
            live.className = 'nuxr-net-toast';
            live.setAttribute('role', 'alert');
            live.hidden = true;
            document.body.appendChild(live);
        }
    }

    function render() {
        document.body.setAttribute('data-nuxr-net', state);
        ensureUi();
        chip.hidden = state === 'online';
        chip.textContent = state === 'online' ? '' : (S[state] || state);
        chip.setAttribute('data-state', state);
        var down = state === 'offline' || state === 'reconnecting';
        banner.hidden = !down;
        banner.textContent = down ? (S.banner || '') : '';
    }

    var toastTimer = null;
    function notifyBlocked() {
        ensureUi();
        live.hidden = false;
        live.textContent = '';
        setTimeout(function () { live.textContent = S.blocked || 'Not sent'; }, 30);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { live.hidden = true; }, 6000);
        probeNow();
    }

    /* ---------- mutation gate ---------- */
    function sameOrigin(u) {
        try { return new URL(u || location.href, location.href).origin === location.origin; } catch (e) { return false; }
    }

    /**
     * true = the request may go; false = stop it (it is NOT sent, NOT queued, NOT replayed). Only same-origin non-GET/HEAD
     * requests while OFFLINE or RECONNECTING are stopped; SLOW submits normally. Never touches CSRF tokens.
     */
    window.nuxrMutationGate = function (kind, method, url) {
        var m = String(method || 'GET').toUpperCase();
        if (m === 'GET' || m === 'HEAD' || !sameOrigin(url)) { return true; }
        if (state !== 'offline' && state !== 'reconnecting') { return true; }
        notifyBlocked();
        return false;
    };

    // XMLHttpRequest / jQuery: the staff pages load Pace, which replaces window.XMLHttpRequest with a factory returning
    // native instances (so a prototype patch never reaches them). Gate each instance created through the current
    // constructor instead. Only the send decision is added: headers, tokens and everything else are untouched.
    (function () {
        var Prev = window.XMLHttpRequest;
        if (typeof Prev !== 'function') { return; }
        function GatedXHR(a) {
            var x = arguments.length ? new Prev(a) : new Prev();
            var open = x.open;
            var send = x.send;
            x.open = function (m, u) { x.__nuxrMethod = m; x.__nuxrUrl = u; return open.apply(x, arguments); };
            x.send = function () {
                if (typeof window.nuxrMutationGate === 'function' && window.nuxrMutationGate('xhr', x.__nuxrMethod, x.__nuxrUrl) === false) {
                    setTimeout(function () { x.dispatchEvent(new ProgressEvent('error')); x.dispatchEvent(new ProgressEvent('loadend')); }, 0);
                    return; // not sent: the caller sees a network error (jQuery error callbacks run)
                }
                return send.apply(x, arguments);
            };
            return x;
        }
        GatedXHR.prototype = Prev.prototype;
        ['UNSENT', 'OPENED', 'HEADERS_RECEIVED', 'LOADING', 'DONE'].forEach(function (k) {
            try { Object.defineProperty(GatedXHR, k, { value: Prev[k] }); } catch (e) { /* ignore */ }
        });
        window.XMLHttpRequest = GatedXHR;
    })();

    // jQuery 1.x reports completion only through readystatechange: abort a gated non-GET request in a prefilter so the
    // caller's fail callbacks run (the request is never sent)
    if (window.jQuery && typeof window.jQuery.ajaxPrefilter === 'function') {
        window.jQuery.ajaxPrefilter(function (o, orig, jqXHR) {
            if (typeof window.nuxrMutationGate === 'function' && window.nuxrMutationGate('jquery', o.type || o.method, o.url) === false) {
                jqXHR.abort('offline');
            }
        });
    }

    // ordinary POST forms and requestSubmit (capture phase: runs before the WP6A pending / double-submit guard)
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.getAttribute || !window.nuxrMutationGate) { return; }
        var m = f.getAttribute('method') || f.method || 'get';
        if (!window.nuxrMutationGate('submit', m, f.action)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    /* ---------- cross-user hygiene (amendment B / plan section 11) ----------
       Nothing user-specific is ever stored by the PWA. Defence in depth: the worker prunes its own namespace on the
       logout navigation; here the staff login page clears this client's transient nuxr-* session keys and every page
       asks the worker to prune (only this installation's "nuxr-pwa:<scope>:" caches; nothing else on the origin). */
    function pruneWorker() {
        if (!secure) { return; }
        navigator.serviceWorker.ready.then(function (r) { if (r.active) { r.active.postMessage({ type: 'PRUNE' }); } }).catch(function () { });
    }
    if (q('form[action*="admin/post"]')) {
        try {
            Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('nuxr-') === 0) { sessionStorage.removeItem(k); } });
        } catch (e) { /* storage unavailable */ }
    }
    pruneWorker();

    window.nuxrPwa = cfg;
    cfg.registration = function () { return reg; };
    cfg.state = function () { return state; };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { render(); schedule(2000); });
    } else {
        render();
        schedule(2000);
    }
})();
