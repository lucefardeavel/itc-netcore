/*
 * WP7 staff PWA service worker (owner plan 245e46bf; decisions OD-W7-1..4, amendments A-O).
 *
 * Presentation / safe-offline helper only - it holds NO authority and stores NO business data:
 *  - natural scope: the directory of this file (the application root, e.g. "/" or "/billing/");
 *  - CacheStorage holds ONLY the literal PRECACHE list (the static offline document and the interim icons), in ONE
 *    cache "nuxr-pwa:<scope-hash>:<VERSION>"; there is no runtime cache and nothing else is ever stored;
 *  - every navigation is network-only (never stored): a failed GET navigation gets the offline document; a failed
 *    non-GET navigation is never queued or replayed - it is redirected (303) to the offline document with
 *    reason=unconfirmed, so a reload cannot resend it;
 *  - every other request (all dynamic data, the application's own CSS / JS / fonts / images, uploads, exports, the
 *    API, the customer portal, cross-origin CDN / maps) is not intercepted;
 *  - a new version WAITS: it is activated only by an explicit SKIP_WAITING message sent after the user chose Update;
 *  - no Background Sync, no IndexedDB, no push subscription, no notification permission.
 * The server (RouteGuard, CSRF, DataScope, Finance, Activation, sessions) stays authoritative for everything.
 */
'use strict';

var VERSION = 'wp7.3';
var SCOPE = new URL(self.registration.scope);
var SCOPE_PATH = SCOPE.pathname;
var OFFLINE = 'nuxr-offline.html';
var PRECACHE = [
    OFFLINE,
    'ui/ui/images/pwa/icon-180.png',
    'ui/ui/images/pwa/icon-192.png',
    'ui/ui/images/pwa/icon-512.png',
    'ui/ui/images/pwa/icon-maskable-512.png'
];

/* scope-namespaced cache name, so another application on the same origin is never touched (FNV-1a of the scope URL) */
function scopeHash(s) {
    var h = 0x811c9dc5;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0; }
    return ('0000000' + h.toString(16)).slice(-8);
}
var NS = 'nuxr-pwa:' + scopeHash(SCOPE.href) + ':';
var CACHE = NS + VERSION;
var PRECACHE_URLS = PRECACHE.map(function (p) { return new URL(p, SCOPE).href; });
var OFFLINE_URL = new URL(OFFLINE, SCOPE).href;

function inScope(url) {
    return url.origin === SCOPE.origin && url.pathname.indexOf(SCOPE_PATH) === 0;
}

function isLogout(url) {
    var r = url.searchParams.get('_route') || url.pathname.slice(SCOPE_PATH.length);
    return /^logout(\/|$)/.test(r);
}

/* remove every other cache of THIS installation's namespace and any entry that is not on the precache list */
function prune() {
    return caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k.indexOf(NS) === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () {
        return caches.open(CACHE);
    }).then(function (cache) {
        return cache.keys().then(function (reqs) {
            return Promise.all(reqs.filter(function (r) { return PRECACHE_URLS.indexOf(r.url) < 0; }).map(function (r) { return cache.delete(r); }));
        });
    });
}

self.addEventListener('install', function (event) {
    // the precache list only; fetched without credentials, so no user-specific response can enter the cache
    event.waitUntil(caches.open(CACHE).then(function (cache) {
        return Promise.all(PRECACHE_URLS.map(function (u) {
            return fetch(u, { credentials: 'omit', cache: 'no-store' }).then(function (r) {
                if (!r.ok) { throw new Error('precache failed: ' + u + ' ' + r.status); }
                return cache.put(u, r);
            });
        }));
    }));
    // no automatic skip-waiting: a new version waits until the user explicitly chooses Update
});

self.addEventListener('activate', function (event) {
    event.waitUntil(prune().then(function () {
        return self.registration.navigationPreload ? self.registration.navigationPreload.enable() : null;
    }).then(function () { return self.clients.claim(); }));
});

function offlineResponse() {
    return caches.match(OFFLINE_URL, { cacheName: CACHE }).then(function (cached) {
        if (!cached) { return Response.error(); }
        return cached.text().then(function (html) {
            return new Response(html, { status: 503, statusText: 'Offline', headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });
        });
    });
}

function navigate(event) {
    var req = event.request;
    var get = req.method === 'GET' || req.method === 'HEAD';
    var net = (get && event.preloadResponse ? Promise.resolve(event.preloadResponse) : Promise.resolve(null)).then(function (pre) {
        return pre || fetch(req);
    });
    return net.then(function (res) {
        if (isLogout(new URL(req.url))) { event.waitUntil(prune()); }
        return res; // network response, never stored
    }, function () {
        if (!get) {
            // never queued, never replayed: the request may or may not have reached the server
            return Response.redirect(OFFLINE_URL + '?reason=unconfirmed', 303);
        }
        return offlineResponse();
    });
}

self.addEventListener('fetch', function (event) {
    var req = event.request;
    var url = new URL(req.url);
    if (!inScope(url)) { return; }
    if (req.mode === 'navigate') {
        event.respondWith(navigate(event));
        return;
    }
    if (req.method === 'GET' && PRECACHE_URLS.indexOf(url.origin + url.pathname) >= 0 && url.search === '') {
        event.respondWith(caches.match(url.href, { cacheName: CACHE }).then(function (r) { return r || fetch(req); }));
    }
    // everything else is not intercepted: the browser fetches it from the network and nothing is stored
});

self.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.type === 'SKIP_WAITING' && event.source && event.source.type === 'window') {
        self.skipWaiting(); // only after the user chose Update in a page that is not in a critical flow
    } else if (data.type === 'PRUNE') {
        event.waitUntil(prune());
    }
});
