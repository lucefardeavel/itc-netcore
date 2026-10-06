#!/usr/bin/env python3
"""WP7 PWA browser / security QA suite (test tooling only, owner decision OD-W7-4).

Runs against the disposable PWA lab started by dev/pwa-lab/lab.sh. The origins are http://localhost:18099/ (root
installation) and http://localhost:18098/billing/ (subdirectory installation): potentially trustworthy origins, so the
browser treats them as secure contexts. Every check first asserts window.isSecureContext === true; a check that cannot
prove a secure context FAILS (never skipped). Requires the Python Playwright package and its Chromium on the QA host
only; nothing here is a production dependency.

Usage:
  python3 dev/pwa-lab/pwa_suite.py [--out DIR] [--only T01,T05,...] [--state /srv/phpnuxbill/pwalab] [--axe axe.min.js]
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time
import traceback

from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument('--out', default='/tmp/pwalab-out')
ap.add_argument('--only', default='')
ap.add_argument('--state', default=os.environ.get('PWALAB_STATE', '/srv/phpnuxbill/pwalab'))
ap.add_argument('--axe', default=os.environ.get('PWALAB_AXE', ''), help='path to axe-core (axe.min.js) for T20')
ARGS = ap.parse_args()
os.makedirs(ARGS.out, exist_ok=True)
LAB = json.load(open(os.path.join(ARGS.state, 'pwalab-ready.json')))
ROOT, SUB = LAB['root'], LAB['sub']
PHONE = dict(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, device_scale_factor=2)
DESK = dict(viewport={'width': 1440, 'height': 900})
RESULTS = []
PLAYWRIGHT = None
CHECKS = []


def check(cid, title):
    def deco(fn):
        CHECKS.append((cid, title, fn))
        return fn
    return deco


class Fail(Exception):
    pass


def expect(cond, msg):
    if not cond:
        raise Fail(msg)


def new_ctx(browser, base, vp=PHONE, lang='english', **kw):
    ctx = browser.new_context(**vp, **kw)
    host = re.match(r'https?://([^/]+)', base).group(1).split(':')[0]
    ctx.add_cookies([{'name': 'user_language', 'value': lang, 'domain': host, 'path': '/'}])
    return ctx


def secure(pg):
    ok = pg.evaluate('() => window.isSecureContext === true && "serviceWorker" in navigator')
    expect(ok, 'not a secure context with serviceWorker support: ' + pg.url)
    return True


def staff_login(ctx, base, user):
    pg = ctx.new_page()
    pg.goto(base + '?_route=admin', wait_until='networkidle')
    secure(pg)
    pg.fill('input[name=username]', user)
    pg.fill('input[name=password]', LAB['staff'][user])
    with pg.expect_navigation():
        pg.click('button[type=submit]')
    pg.wait_for_load_state('networkidle')
    # the legacy login result is an interstitial (admin/post) that meta-refreshes after ~3 s: go to the shell directly
    pg.goto(base + '?_route=dashboard', wait_until='networkidle')
    return pg


def route_of(pg):
    m = re.search(r'_route=([^&#]*)', pg.url)
    return m.group(1) if m else pg.url


# ---------------------------------------------------------------- shared helpers (PWA state inspection)
CACHE_DUMP = """async () => { const out = [];
  for (const k of await caches.keys()) { const c = await caches.open(k);
    for (const r of await c.keys()) { const res = await c.match(r); const ct = res.headers.get('content-type') || '';
      const text = /^(text|application\\/(json|javascript))/.test(ct) ? await res.clone().text() : '';
      out.push({ cache: k, url: r.url, type: ct, text: text }); } }
  return out; }"""
STORAGE_DUMP = """async () => { const ls = {}, ss = {};
  for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); ls[k] = localStorage.getItem(k); }
  for (let i = 0; i < sessionStorage.length; i++) { const k = sessionStorage.key(i); ss[k] = sessionStorage.getItem(k); }
  const idb = indexedDB.databases ? (await indexedDB.databases()).map(d => d.name) : ['(unsupported)'];
  return { local: ls, session: ss, idb: idb }; }"""
PRECACHE = ['nuxr-offline.html', 'ui/ui/images/pwa/icon-180.png', 'ui/ui/images/pwa/icon-192.png', 'ui/ui/images/pwa/icon-512.png',
            'ui/ui/images/pwa/icon-maskable-512.png']


def sw_ready(pg):
    """wait until the worker is active and this page is controlled (claim on activate, else one reload)"""
    pg.evaluate('() => navigator.serviceWorker.ready.then(() => true)')
    for _ in range(2):
        if pg.evaluate('() => !!navigator.serviceWorker.controller'):
            return True
        pg.reload(wait_until='networkidle')
    expect(pg.evaluate('() => !!navigator.serviceWorker.controller'), 'page not controlled by the service worker')
    return True


def cache_entries(pg):
    return pg.evaluate(CACHE_DUMP)


def expected_precache(base):
    return sorted(base + p for p in PRECACHE)



def go_offline(ctx, pg):
    """browser offline + an offline hint: the client must CONFIRM it with a failing heartbeat"""
    ctx.set_offline(True)
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'offline'", timeout=20000)


def record_posts(pg):
    posts = []
    pg.on('request', lambda r: posts.append(r.url) if r.method not in ('GET', 'HEAD') else None)
    return posts


# ---------------------------------------------------------------- T00 secure context + both installations
@check('T00', 'Secure context at the root and the /billing/ subdirectory origin; staff login works on both')
def t00(browser):
    notes = []
    for base in (ROOT, SUB):
        ctx = new_ctx(browser, base)
        pg = staff_login(ctx, base, 'ad')
        expect(route_of(pg) == 'dashboard', 'login did not reach the dashboard: ' + pg.url)
        pg.goto(base + '?_route=dashboard', wait_until='networkidle')
        app_url = pg.evaluate('() => window.appUrl')
        expect(app_url.rstrip('/') + '/' == base, f'APP_URL {app_url} for {base}')
        notes.append(f'{base}: isSecureContext=true, appUrl={app_url}')
        ctx.close()
    return '; '.join(notes)


@check('T01', 'Valid user-neutral manifest at both installations')
def t01(browser):
    notes = []
    for base in (ROOT, SUB):
        ctx = new_ctx(browser, base)
        pg = ctx.new_page()
        pg.goto(base + '?_route=admin', wait_until='networkidle')
        secure(pg)
        href = pg.get_attribute('link[rel=manifest]', 'href')
        expect(href and href.endswith('?_route=pwa/manifest'), f'manifest link {href}')
        expect(pg.get_attribute('link[rel=manifest]', 'crossorigin') is None, 'manifest must be fetched without credentials')
        cdp = ctx.new_cdp_session(pg)
        m = cdp.send('Page.getAppManifest')
        expect(not m.get('errors'), f"manifest errors {m.get('errors')}")
        data = json.loads(m['data'])
        path = re.match(r'https?://[^/]+(/.*)$', base).group(1)
        expect(data['start_url'] == './?_route=admin' and data['scope'] == './' and data['display'] == 'standalone', 'manifest fields')
        expect(data['id'] == path + '?_route=admin', f"id {data['id']}")
        parsed = m.get('parsed') or {}
        notes.append(f"{base}: id={data['id']} icons={len(data['icons'])} start={(parsed.get('startUrl') or data['start_url'])}")
        ctx.close()
    return '; '.join(notes)


@check('T02', 'Chromium installability inspection (CDP Page.getInstallabilityErrors, persistent profile)')
def t02(browser):
    import tempfile
    with tempfile.TemporaryDirectory() as prof:
        ctx = PLAYWRIGHT.chromium.launch_persistent_context(prof, **PHONE)
        try:
            pg = ctx.pages[0] if ctx.pages else ctx.new_page()
            pg.goto(ROOT + '?_route=admin', wait_until='networkidle')
            secure(pg)
            sw_ready(pg)
            cdp = ctx.new_cdp_session(pg)
            errs = cdp.send('Page.getInstallabilityErrors').get('installabilityErrors', [])
            ids = [e.get('errorId') for e in errs]
            expect(not ids, f'installability errors: {ids}')
            return 'no installability errors (root, persistent profile)'
        finally:
            ctx.close()


@check('T03', 'Service worker installs, activates and controls - root and /billing/ (natural scope only)')
def t03(browser):
    notes = []
    for base in (ROOT, SUB):
        ctx = new_ctx(browser, base)
        pg = staff_login(ctx, base, 'ad')
        sw_ready(pg)
        scope = pg.evaluate('() => navigator.serviceWorker.ready.then(r => r.scope)')
        expect(scope == base, f'scope {scope} != {base}')
        state = pg.evaluate('() => navigator.serviceWorker.controller.state')
        notes.append(f'{base}: scope={scope} controller={state}')
        ctx.close()
    # the subdirectory worker does not control the rest of the origin
    ctx = new_ctx(browser, SUB)
    pg = ctx.new_page()
    pg.goto(SUB + '?_route=admin', wait_until='networkidle')
    sw_ready(pg)
    pg.goto(re.sub(r'billing/$', '', SUB), wait_until='load')
    outside = pg.evaluate('() => !!(navigator.serviceWorker && navigator.serviceWorker.controller)')
    expect(not outside, 'origin root outside /billing/ is controlled by the /billing/ worker')
    notes.append('outside /billing/: not controlled')
    ctx.close()
    return '; '.join(notes)


@check('T04', 'CacheStorage contains exactly the literal precache list (one cache, this installation)')
def t04(browser):
    notes = []
    for base in (ROOT, SUB):
        ctx = new_ctx(browser, base)
        pg = staff_login(ctx, base, 'ad')
        sw_ready(pg)
        ents = cache_entries(pg)
        urls = sorted(e['url'] for e in ents)
        caches_ = sorted(set(e['cache'] for e in ents))
        expect(urls == expected_precache(base), f'cache entries {urls}')
        expect(len(caches_) == 1 and re.match(r'^nuxr-pwa:[0-9a-f]{8}:wp7\.\d+$', caches_[0]), f'cache names {caches_}')
        notes.append(f'{base}: {caches_[0]} = {len(urls)} precache entries')
        ctx.close()
    return '; '.join(notes)


@check('T05', 'After a full staff session no _route / authenticated response exists in CacheStorage')
def t05(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    for r in ['dashboard', 'customers/list', 'customers/view/%d' % LAB['customers']['mohammed'], 'finance/overview', 'finance/ledger',
              'activation/view/' + LAB['refs']['mohammed'], 'pricing', 'reports/activation', 'search_user&query=moh']:
        pg.goto(ROOT + '?_route=' + r, wait_until='networkidle')
    ents = cache_entries(pg)
    bad = [e['url'] for e in ents if '_route=' in e['url'] or e['url'] not in expected_precache(ROOT)]
    expect(not bad, f'unexpected cache entries {bad}')
    blob = json.dumps(ents, ensure_ascii=False)
    for needle in ['mohammed', 'محمد', LAB['refs']['mohammed'], 'Staff A', 'csrf']:
        expect(needle not in blob, f'cache contains {needle!r}')
    ctx.close()
    return f'{len(ents)} entries, all on the precache list; no identity, customer, finance or token text'


@check('T06', 'Offline launch (start_url) shows only the static offline document')
def t06(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    ctx.set_offline(True)
    pg2 = ctx.new_page()
    resp = pg2.goto(ROOT + '?_route=admin', wait_until='load')
    txt = pg2.inner_text('body')
    expect('You are offline' in txt and 'أنت غير متصل' in txt, 'offline document not shown')
    expect(resp is None or resp.status == 503, f'status {resp.status if resp else None}')
    expect(pg2.locator('[data-reason="unconfirmed"]:visible').count() == 0, 'unconfirmed text shown for a GET')
    for needle in ['Staff A', 'mohammed', 'csrf']:
        expect(needle not in pg2.content(), f'offline document contains {needle!r}')
    pg2.screenshot(path=os.path.join(ARGS.out, 'T06-offline-launch.png'))
    ctx.set_offline(False)
    ctx.close()
    return f'start_url offline -> offline document (status {resp.status if resp else "n/a"}), no user data'


@check('T07', 'Offline deep link (customer / activation / finance) shows only the generic offline document')
def t07(browser):
    ctx = new_ctx(browser, SUB)
    pg = staff_login(ctx, SUB, 'A')
    sw_ready(pg)
    ctx.set_offline(True)
    seen = []
    for r in ['customers/view/%d' % LAB['customers']['mohammed'], 'activation/view/' + LAB['refs']['mohammed'], 'finance/ledger']:
        pg.goto(SUB + '?_route=' + r, wait_until='load')
        txt = pg.inner_text('body')
        expect('You are offline' in txt, f'{r}: no offline document')
        expect('محمد' not in pg.content() and LAB['refs']['mohammed'] not in pg.content(), f'{r}: object data shown offline')
        seen.append(r.split('/')[0])
    ctx.set_offline(False)
    ctx.close()
    return 'offline deep links (subdirectory) -> generic document only: ' + ', '.join(seen)


@check('T07b', 'Failed POST navigation (backstop) -> 303 to the GET offline document, reason=unconfirmed; reload does not resend')
def t07b(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    pg.goto(ROOT + '?_route=customers/edit/%d' % LAB['customers']['mohammed'], wait_until='networkidle')
    posts = []
    pg.on('request', lambda r: posts.append(r.url) if r.method == 'POST' else None)
    ctx.set_offline(True)
    # bypass the page-level gate on purpose (test-only) to exercise the service-worker backstop
    pg.evaluate("""() => { window.nuxrMutationGate = null; const f = document.createElement('form'); f.method = 'post';
        f.action = location.href.replace(/_route=[^&]+/, '_route=customers/edit-post'); document.body.appendChild(f);
        HTMLFormElement.prototype.requestSubmit ? f.requestSubmit() : f.submit(); }""")
    pg.wait_for_load_state('load')
    pg.wait_for_timeout(500)
    url = pg.url
    expect(url.endswith('nuxr-offline.html?reason=unconfirmed'), f'landed on {url}')
    expect(pg.locator('[data-reason="unconfirmed"]:visible').count() == 2, 'unconfirmed explanation not visible')
    txt = pg.inner_text('body')
    expect('may or may not have reached the server' in txt, 'unconfirmed wording')
    expect('nothing changed' not in txt.lower(), 'must not claim nothing changed')
    pg.screenshot(path=os.path.join(ARGS.out, 'T07b-unconfirmed.png'))
    n = len(posts)
    pg.reload(wait_until='load')
    expect(len(posts) == n, 'reload resent a POST')
    ctx.set_offline(False)
    ctx.close()
    return f'POST navigation offline -> {url.split("/")[-1]}; reload sent no POST'


@check('T08', 'OFFLINE: an ordinary POST form (WP6C confirmation sheet -> requestSubmit) is stopped before sending')
def t08(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'ad')  # Admin may edit the customer (an Agent gets the 403 shell there)
    sw_ready(pg)
    pg.goto(ROOT + '?_route=customers/edit/%d' % LAB['customers']['mohammed'], wait_until='networkidle')
    posts = record_posts(pg)
    go_offline(ctx, pg)
    url = pg.url
    pg.locator('form > .nuxr-actionbar-form button[type=submit] >> visible=true').first.click()
    pg.wait_for_timeout(300)
    if pg.evaluate('() => { const d = document.getElementById("nuxr-ask-sheet"); return !!(d && d.open); }'):
        pg.click('[data-nuxr-ask-ok]')
    pg.wait_for_timeout(800)
    expect(not posts, f'POST sent offline: {posts}')
    expect(pg.url == url, 'navigated away')
    toast = pg.locator('.nuxr-net-toast')
    expect(toast.is_visible() and 'Not sent' in toast.inner_text(), 'no "not sent" notice')
    pending = pg.evaluate('() => document.querySelectorAll("form[data-nuxr-submitted]").length')
    expect(pending == 0, 'form left in the pending state')
    pg.screenshot(path=os.path.join(ARGS.out, 'T08-form-blocked.png'))
    ctx.set_offline(False)
    ctx.close()
    return 'requestSubmit via the confirmation sheet stopped: 0 POST requests, notice shown, form not left pending'


@check('T09', 'OFFLINE: a[data-method=post], window.nuxrPost and native form.submit() are stopped')
def t09(browser):
    ctx = new_ctx(browser, ROOT, vp=DESK)  # desktop: the staff table shows the row Delete link directly
    pg = staff_login(ctx, ROOT, 'ad')
    sw_ready(pg)
    pg.goto(ROOT + '?_route=settings/users', wait_until='networkidle')
    pg.on('dialog', lambda d: d.accept())
    posts = record_posts(pg)
    go_offline(ctx, pg)
    link = pg.locator('a[data-method="post"] >> visible=true').first
    expect(link.count() == 1, 'no data-method link on the staff list')
    link.click()
    pg.wait_for_timeout(500)
    n1 = len(posts)
    pg.evaluate("() => window.nuxrPost(location.href)")
    pg.wait_for_timeout(500)
    n2 = len(posts)
    pg.evaluate("""() => { const f = document.createElement('form'); f.method = 'post'; f.action = location.href; document.body.appendChild(f); f.submit(); }""")
    pg.wait_for_timeout(500)
    expect(len(posts) == 0, f'POST sent offline: data-method {n1}, nuxrPost {n2 - n1}, form.submit {len(posts) - n2}')
    expect('settings/users' in pg.url, 'navigated away')
    ctx.set_offline(False)
    ctx.close()
    return 'data-method link, nuxrPost and native form.submit(): 0 POST requests'


@check('T10', 'OFFLINE: fetch POST, XMLHttpRequest POST and jQuery POST are stopped (error reported to the caller)')
def t10(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'ad')
    sw_ready(pg)
    pg.goto(ROOT + '?_route=customers/list', wait_until='networkidle')
    posts = record_posts(pg)
    go_offline(ctx, pg)
    res = pg.evaluate("""async () => {
        const out = {};
        try { await fetch(location.href, { method: 'POST', body: 'x=1' }); out.fetch = 'sent'; } catch (e) { out.fetch = 'blocked: ' + e.message; }
        out.xhr = await new Promise(res => { const x = new XMLHttpRequest(); x.open('POST', location.href); x.onerror = () => res('error event');
            x.onload = () => res('sent'); x.send('x=1'); setTimeout(() => res('no event'), 2000); });
        out.jquery = await new Promise(res => { window.jQuery.ajax({ url: location.href, method: 'POST', data: { x: 1 } })
            .done(() => res('sent')).fail((j, t) => res('fail: ' + t)); setTimeout(() => res('no callback'), 2000); });
        return out; }""")
    expect(not posts, f'POST sent offline: {posts}')
    expect(res['fetch'].startswith('blocked') and res['xhr'] == 'error event' and res['jquery'].startswith('fail'), f'{res}')
    ctx.set_offline(False)
    ctx.close()
    return f'0 POST requests; fetch={res["fetch"]}; xhr={res["xhr"]}; jquery={res["jquery"]}'


@check('T10b', 'Customer portal and public pages: no gate defined (behaviour unchanged), CSRF shim intact')
def t10b(browser):
    ctx = new_ctx(browser, ROOT)
    pg = ctx.new_page()
    pg.goto(ROOT + '?_route=login', wait_until='networkidle')
    secure(pg)
    info = pg.evaluate("() => ({ gate: typeof window.nuxrMutationGate, token: typeof window.nuxrCsrfToken, post: typeof window.nuxrPost })")
    expect(info['gate'] == 'undefined' and info['token'] == 'string' and info['post'] == 'function', f'{info}')
    ctx.close()
    return f'customer login: gate {info["gate"]}, csrf token {info["token"]}, nuxrPost {info["post"]}'


@check('T11', 'Reconnect: OFFLINE -> RECONNECTING -> ONLINE confirmed by the heartbeat; mutations blocked while RECONNECTING')
def t11(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    pg.evaluate("() => { window.__net = []; document.addEventListener('nuxr-net', e => window.__net.push(e.detail.state)); }")
    go_offline(ctx, pg)
    ctx.set_offline(False)
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'reconnecting'", timeout=20000)
    during = pg.evaluate("() => window.nuxrMutationGate('test', 'POST', location.href)")
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=20000)
    after = pg.evaluate("() => window.nuxrMutationGate('test', 'POST', location.href)")
    seq = pg.evaluate('() => window.__net')
    expect(seq[-3:] == ['offline', 'reconnecting', 'online'], f'sequence {seq}')
    expect(during is False and after is True, f'gate during reconnecting {during}, after {after}')
    ctx.close()
    return f'states {seq}; POST blocked while reconnecting, allowed once online'


@check('T12', 'SLOW/UNSTABLE (CDP latency 3 s): chip shown, mutations still allowed')
def t12(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    cdp = ctx.new_cdp_session(pg)
    cdp.send('Network.enable')
    cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 3000, 'downloadThroughput': -1, 'uploadThroughput': -1})
    pg.evaluate("() => window.dispatchEvent(new Event('online'))")  # a hint: probe now
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'slow'", timeout=30000)
    chip = pg.locator('.nuxr-net-chip')
    expect(chip.is_visible() and chip.inner_text().strip() != '', 'slow chip not shown')
    allowed = pg.evaluate("() => window.nuxrMutationGate('test', 'POST', location.href)")
    expect(allowed is True, 'POST blocked while SLOW')
    pg.screenshot(path=os.path.join(ARGS.out, 'T12-slow.png'))
    label = chip.inner_text().strip()
    cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1})
    ctx.close()
    return f'slow chip "{label}"; POST allowed while SLOW'



# ---------------------------------------------------------------- owner fix round 1: heartbeat identity, network hint
PING_WAIT = 3000  # the immediate probe must appear well inside this, far below the 60 s ONLINE cadence


def ping_requests(pg):
    pings = []
    pg.on('request', lambda r: pings.append((time.time(), r)) if '_route=pwa/ping' in r.url else None)
    return pings


@check('T12h', 'Heartbeat from an authenticated staff page carries no Cookie, Authorization, X-CSRF-Token or other identifier (root + /billing/)')
def t12h(browser):
    notes = []
    for base in (ROOT, SUB):
        ctx = new_ctx(browser, base, vp=DESK)
        pg = staff_login(ctx, base, 'A')
        sw_ready(pg)
        pg.wait_for_function(TOKEN_READY, timeout=10000)
        token = pg.evaluate('() => window.nuxrCsrfToken')
        sess = [c['value'] for c in ctx.cookies() if c['value']]
        pings = ping_requests(pg)
        pg.evaluate("() => window.dispatchEvent(new Event('online'))")  # a hint: probe now
        pg.wait_for_timeout(PING_WAIT)
        expect(pings, base + ': no heartbeat observed')
        req = pings[0][1]
        headers = req.all_headers()
        resp = req.response()
        names = sorted(headers)
        expect(not any(h in headers for h in ('cookie', 'authorization', 'x-csrf-token', 'proxy-authorization', 'referer')), f'{base}: identity header sent {names}')
        blob = json.dumps(headers) + req.url
        leaked = [v[:6] + '...' for v in [token] + sess if v and v in blob] + [u for u in ('Staff A', LAB['staff']['A']) if u and u in blob]
        expect(not leaked, f'{base}: identifier in the heartbeat: {leaked}')
        expect(req.url == base + '?_route=pwa/ping', f'{base}: unexpected heartbeat URL {req.url}')
        expect(resp.status == 200 and resp.text() == '{"app":"nuxr","ok":true}', f'{base}: marker {resp.status} {resp.text()[:60]}')
        expect('set-cookie' not in resp.all_headers(), f'{base}: heartbeat response sets a cookie')
        # control: an ordinary same-origin application fetch still goes through the CSRF shim
        app = []
        pg.on('request', lambda r: app.append(r) if 'nuxr_control=1' in r.url else None)
        pg.evaluate("() => fetch('?_route=dashboard&nuxr_control=1').then(r => r.status)")
        expect(app and app[0].all_headers().get('x-csrf-token') == token, f'{base}: ordinary fetch lost its CSRF header')
        notes.append(f'{base}: heartbeat headers {names}; marker exact; no Set-Cookie; ordinary fetch keeps X-CSRF-Token')
        ctx.close()
    return '; '.join(notes)


@check('T12n', 'Same-origin network error (fetch rejection, XHR error) triggers an immediate heartbeat; state only from the ping; no retry')
def t12n(browser):
    ctx = new_ctx(browser, ROOT, vp=DESK)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    pg.wait_for_function(TOKEN_READY, timeout=10000)
    pg.wait_for_timeout(3500)  # the load probe (2 s after load) is done: the next scheduled ping is ~60 s away
    expect(pg.evaluate("() => navigator.onLine === true && document.body.getAttribute('data-nuxr-net') === 'online'"), 'not online at start')
    pg.evaluate("() => { window.__net = []; document.addEventListener('nuxr-net', e => window.__net.push([Date.now(), e.detail.state])); }")
    pings = ping_requests(pg)
    failed = []
    ctx.route(re.compile(r'nuxr_netfail='), lambda r: (failed.append((r.request.url, r.request.method, r.request.headers.get('x-csrf-token'))), r.abort('failed')))
    # A: fetch POST fails at network level while the heartbeat works -> immediate ping, state stays online
    t0 = time.time()
    out = pg.evaluate("() => fetch('?_route=dashboard&nuxr_netfail=fetch', { method: 'POST', body: 'x=1' }).then(r => 'resolved ' + r.status, e => 'rejected ' + e.name + ': ' + e.message)")
    pg.wait_for_timeout(PING_WAIT)
    a_pings = [t for t, _ in pings if t >= t0]
    expect(out.startswith('rejected TypeError'), 'caller did not see the network rejection: ' + out)
    expect(len(a_pings) == 1 and a_pings[0] - t0 < 2.0, f'fetch: immediate pings {[round(t - t0, 2) for t in a_pings]}')
    expect(pg.evaluate('() => window.__net.length') == 0 and pg.evaluate("() => document.body.getAttribute('data-nuxr-net')") == 'online',
           'state changed although the heartbeat succeeded')
    fetch_tries = [x for x in failed if x[0].endswith('nuxr_netfail=fetch')]
    expect(len(fetch_tries) == 1 and fetch_tries[0][1] == 'POST' and fetch_tries[0][2], f'fetch request retried or lost its CSRF header: {fetch_tries}')
    # B: XHR fails at network level and the heartbeat fails too -> the state changes only from the ping result
    pg.wait_for_timeout(1200)  # past the 1 s hint throttle
    ctx.route(re.compile(r'_route=pwa/ping'), lambda r: r.abort('failed'))
    t1 = time.time()
    xhr = pg.evaluate("""() => new Promise(res => { const x = new XMLHttpRequest(); let errors = 0;
        x.onerror = () => { errors++; setTimeout(() => res({ errors, status: x.status, state: x.readyState, net: document.body.getAttribute('data-nuxr-net') }), 0); };
        x.onload = () => res({ loaded: x.status });
        x.open('POST', '?_route=dashboard&nuxr_netfail=xhr'); x.send('x=1'); })""")
    expect(xhr.get('errors') == 1 and xhr.get('status') == 0 and xhr.get('state') == 4, f'XHR error not delivered once to the caller: {xhr}')
    expect(xhr.get('net') == 'online', 'state changed before the heartbeat answered: ' + str(xhr))
    pg.wait_for_function("() => window.__net.length > 0", timeout=PING_WAIT)
    b_pings = [t for t, _ in pings if t >= t1]
    first_change = pg.evaluate('() => window.__net[0]')
    expect(b_pings and b_pings[0] - t1 < 2.0, f'xhr: immediate pings {[round(t - t1, 2) for t in b_pings]}')
    expect(first_change[1] == 'slow' and first_change[0] / 1000 >= b_pings[0], f'first state change {first_change} (expected slow, after the ping)')
    pg.wait_for_timeout(1500)
    xhr_tries = [x for x in failed if x[0].endswith('nuxr_netfail=xhr')]
    expect(len(xhr_tries) == 1, f'XHR request retried: {len(xhr_tries)}')
    ctx.unroute(re.compile(r'_route=pwa/ping'))
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=40000)
    seq = [s for _, s in pg.evaluate('() => window.__net')]
    ctx.close()
    return (f'fetch: rejected TypeError to the caller, 1 immediate ping after {a_pings[0] - t0:.2f} s, state stayed online, 1 request (with X-CSRF-Token); '
            f'xhr: error once to the caller, ping after {b_pings[0] - t1:.2f} s, states from pings only {seq}, 1 request; no retry')


@check('T10c', 'Root worker installed by staff, then logout: public / customer pages behave exactly as without a worker; no gate, no cache, no client storage')
def t10c(browser):
    cust_user, cust_pw = 'mohammed.h', 'lab-mohammed.h'
    flows = {}
    for mode in ('worker', 'blocked'):
        kw = {} if mode == 'worker' else {'service_workers': 'block'}
        ctx = new_ctx(browser, ROOT, vp=PHONE, **kw)
        pg = staff_login(ctx, ROOT, 'A')
        if mode == 'worker':
            sw_ready(pg)
        steps = []

        def facts(label, res):
            page_ready(pg, res)
            f = {'step': label, 'status': res.status if res else None, 'route': route_of(pg), **pg.evaluate(PAGE_FACTS),
                 'gate': pg.evaluate('() => typeof window.nuxrMutationGate'), 'pwa': pg.locator('script[src*="nuxr-pwa.js"]').count(),
                 'manifest': pg.locator('link[rel=manifest]').count()}
            f['controlled'] = pg.evaluate('() => !!(navigator.serviceWorker && navigator.serviceWorker.controller)')
            steps.append(f)

        res = pg.goto(ROOT + '?_route=logout', wait_until='networkidle')
        facts('staff logout landing', res)
        for r in ('login', 'register', 'forgot'):
            facts('public ' + r, pg.goto(ROOT + '?_route=' + r, wait_until='networkidle'))
        pg.goto(ROOT + '?_route=login', wait_until='networkidle')
        pg.fill('input[name=username]', cust_user)
        pg.fill('input[name=password]', cust_pw)
        with pg.expect_navigation() as nav:
            pg.locator('form[action*="login/post"] button[type=submit]').click()
        pg.wait_for_load_state('networkidle')
        facts('customer login result', nav.value)
        facts('customer home', pg.goto(ROOT + '?_route=home', wait_until='networkidle'))
        facts('customer profile (GET)', pg.goto(ROOT + '?_route=accounts/profile', wait_until='networkidle'))
        pg.goto(ROOT + '?_route=accounts/change-password', wait_until='networkidle')
        pg.fill('input[name=password]', 'not-the-current-password')
        for n in ('npass', 'cnpass'):
            if pg.locator(f'input[name={n}]').count():
                pg.fill(f'input[name={n}]', 'unused-new-password-1')
        with pg.expect_navigation() as nav:
            pg.locator('form[action*="change-password-post"] [type=submit]').first.click()
        pg.wait_for_load_state('networkidle')
        facts('customer POST change-password (wrong current password: refused, nothing changed)', nav.value)
        if mode == 'worker':
            entries = cache_entries(pg)
            urls = sorted(e['url'] for e in entries)
            expect(urls == expected_precache(ROOT), f'CacheStorage changed by public / customer pages: {urls}')
            blob = json.dumps(entries, ensure_ascii=False)
            expect(not any(x in blob for x in (cust_user, 'محمد', '_route=home', '_route=login', 'nuxrCsrfToken')), 'customer / public data in CacheStorage')
        store = pg.evaluate(STORAGE_DUMP)
        nuxr_keys = [k for k in list(store['local']) + list(store['session']) if k.startswith('nuxr')]
        expect(not nuxr_keys and store['idb'] in ([], ['(unsupported)']), f'{mode}: WP7 client storage on customer pages: {nuxr_keys} {store["idb"]}')
        flows[mode] = steps
        ctx.close()
    for a, b in zip(flows['worker'], flows['blocked']):
        ca, cb = dict(a), dict(b)
        expect(ca.pop('controlled') is True and cb.pop('controlled') is False, f"{a['step']}: worker control not as set up")
        expect(ca == cb, f"{a['step']}: with the staff root worker {ca} != without a worker {cb}")
        expect(a['gate'] == 'undefined' and a['pwa'] == 0 and a['manifest'] == 0, f"{a['step']}: staff PWA client present on a public / customer page")
    home = flows['worker'][5]
    expect(home['status'] == 200 and home['route'] == 'home' and home['token'] is True, f'customer not signed in on home: {home}')
    expect(len(flows['worker']) == len(flows['blocked']) == 8, 'step count')
    return '; '.join(f"{s['step']} -> {s['status']} {s['route']}" for s in flows['worker']) + '; all identical with / without the worker, gate undefined, precache unchanged, no WP7 storage'


def logout(pg, base):
    pg.goto(base + '?_route=logout', wait_until='load')
    pg.wait_for_timeout(800)


def leak_scan(pg, secrets):
    """dump every client store of this origin and search it for the previous users' identifiers / tokens"""
    blob = json.dumps({'cache': pg.evaluate(CACHE_DUMP), 'storage': pg.evaluate(STORAGE_DUMP)}, ensure_ascii=False)
    found = [k for k, v in secrets.items() if v and v in blob]
    return found, blob


@check('T13', 'Cross-user hygiene: Admin -> logout -> Agent -> logout -> Sales leaves no previous identity, data or token client-side')
def t13(browser):
    ctx = new_ctx(browser, ROOT)
    secrets = {}
    steps = []
    plan = [('ad', ['dashboard', 'customers/list', 'customers/view/%d' % LAB['customers']['mohammed'], 'finance/wallets', 'finance/ledger/%d' % LAB['staff_ids']['A'],
                    'activation/view/' + LAB['refs']['mohammed'], 'settings/users', 'pricing/plans', 'reports/activation']),
            ('A', ['dashboard', 'customers/list', 'customers/view/%d' % LAB['customers']['fatima'], 'finance/overview', 'finance/ledger', 'pricing', 'search_user&query=fat']),
            ('A1', ['dashboard', 'customers/list'])]
    pg = None
    for i, (user, routes) in enumerate(plan):
        pg = staff_login(ctx, ROOT, user) if pg is None else pg
        if i > 0:
            pg.goto(ROOT + '?_route=admin', wait_until='networkidle')
            pg.fill('input[name=username]', user)
            pg.fill('input[name=password]', LAB['staff'][user])
            with pg.expect_navigation():
                pg.click('button[type=submit]')
            pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
        sw_ready(pg)
        found, _ = leak_scan(pg, secrets)
        expect(not found, f'after {user} login: previous identifiers present client-side: {found}')
        for r in routes:
            pg.goto(ROOT + '?_route=' + r, wait_until='networkidle')
        # this user's identifiers become the secrets the NEXT user must never find
        secrets.update({
            f'{user}:fullname': 'Staff ' + user, f'{user}:username': f'"{user}"',
            f'{user}:csrf': pg.evaluate('() => window.nuxrCsrfToken || ""'),
        })
        for c in ctx.cookies():
            if c['name'] in ('PHPSESSID', 'aid') and c['value']:
                secrets[f'{user}:cookie:{c["name"]}'] = c['value']
        secrets.update({'customer:mohammed': 'mohammed.h', 'customer:محمد': 'محمد', 'customer:fatima': 'fatima.z',
                        'activation-ref': LAB['refs']['mohammed'], 'finance:750': '750.00', 'finance:funding': 'initial provider funding'})
        logout(pg, ROOT)
        found, blob = leak_scan(pg, secrets)
        expect(not found, f'after {user} logout: present client-side: {found}')
        ents = pg.evaluate(CACHE_DUMP)
        expect(sorted(e['url'] for e in ents) == expected_precache(ROOT), f'after {user} logout: cache {[e["url"] for e in ents]}')
        steps.append(f'{user}: {len(routes)} pages, logout clean')
    store = pg.evaluate(STORAGE_DUMP)
    ctx.close()
    return '; '.join(steps) + f'; final local={sorted(store["local"])}, session={sorted(store["session"])}, idb={store["idb"]}'


@check('T13b', 'Prune stays inside this installation namespace: an unrelated cache on the origin survives, a stale nuxr-pwa cache is removed')
def t13b(browser):
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    current = sorted(set(e['cache'] for e in pg.evaluate(CACHE_DUMP)))[0]
    stale = current.rsplit(':', 1)[0] + ':wp7.0'
    pg.evaluate("""async ([stale]) => {
        await (await caches.open('another-app-on-this-origin')).put('/another-app/data.json', new Response('{"x":1}'));
        await (await caches.open(stale)).put('/stale.txt', new Response('old')); }""", [stale])
    logout(pg, ROOT)            # the worker prunes on the logout navigation
    pg.goto(ROOT + '?_route=admin', wait_until='networkidle')  # and the login page asks it to prune
    pg.wait_for_timeout(800)
    keys = pg.evaluate('() => caches.keys()')
    expect('another-app-on-this-origin' in keys, f'an unrelated cache was deleted: {keys}')
    expect(stale not in keys, f'stale namespace cache survived: {keys}')
    expect(current in keys, 'current precache deleted')
    ctx.close()
    return f'kept another-app-on-this-origin and {current}; removed {stale}'


@check('T14i', 'Install UX: user-initiated only; deferred beforeinstallprompt used on click; manual sheet otherwise; hidden when standalone')
def t14i(browser):
    notes = []
    ctx = new_ctx(browser, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    expect(pg.evaluate("() => !document.getElementById('nuxr-install-sheet') && !document.querySelector('dialog[open]')"), 'something opened automatically after login')
    # branch 1: no beforeinstallprompt -> generic manual instructions (menu item)
    pg.click('details.nuxr-menu.nuxr-user > summary')
    item = pg.locator('.nuxr-menu-panel [data-nuxr-install]')
    expect(item.is_visible(), 'install item not offered in the account menu')
    item.click()
    pg.wait_for_timeout(300)
    sheet = pg.locator('#nuxr-install-sheet')
    expect(sheet.is_visible() and 'Install' in sheet.inner_text(), 'manual instructions not shown')
    pg.screenshot(path=os.path.join(ARGS.out, 'T14i-install-manual.png'))
    pg.keyboard.press('Escape')
    notes.append('manual sheet when the browser offers no prompt')
    # branch 2: a (simulated) deferred beforeinstallprompt is used only on the user's click
    pg.evaluate("""() => { window.__prompted = 0; const e = new Event('beforeinstallprompt', { cancelable: true });
        e.prompt = () => { window.__prompted++; return Promise.resolve(); }; e.userChoice = Promise.resolve({ outcome: 'dismissed' });
        window.dispatchEvent(e); }""")
    expect(pg.evaluate('() => window.__prompted') == 0, 'prompted without a user action')
    pg.click('[data-nuxr-open=nuxr-more-sheet] >> visible=true')
    pg.wait_for_timeout(300)
    pg.locator('#nuxr-more-sheet [data-nuxr-install]').click()
    pg.wait_for_timeout(300)
    expect(pg.evaluate('() => window.__prompted') == 1, 'deferred prompt not used on click')
    notes.append('deferred prompt used once, on the More-sheet click')
    # standalone: the item is not offered (CSS display-mode)
    cdp = ctx.new_cdp_session(pg)
    cdp.send('Emulation.setEmulatedMedia', {'features': [{'name': 'display-mode', 'value': 'standalone'}]})
    if pg.evaluate("() => matchMedia('(display-mode: standalone)').matches"):
        hidden = pg.evaluate("() => getComputedStyle(document.querySelector('.nuxr-menu-panel [data-nuxr-install]')).display === 'none'")
        expect(hidden, 'install item visible in standalone mode')
        notes.append('hidden in emulated standalone mode')
    else:
        # this Chromium ignores display-mode emulation (prefers-color-scheme emulation works): verify the live CSSOM rule
        rule = pg.evaluate(r"""() => { for (const sh of document.styleSheets) { let rules; try { rules = sh.cssRules; } catch (e) { continue; }
            for (const r of rules) { if (r.media && /display-mode:\s*standalone/.test(r.conditionText || r.media.mediaText)) {
                for (const i of r.cssRules) { if (i.selectorText === '[data-nuxr-install]' && i.style.getPropertyValue('display') === 'none'
                    && i.style.getPropertyPriority('display') === 'important') { return true; } } } } } return false; }""")
        expect(rule, 'no standalone rule hiding the install item')
        notes.append('display-mode emulation unsupported by this Chromium; standalone CSS rule verified in the live CSSOM')
    ctx.close()
    return '; '.join(notes)



# ---------------------------------------------------------------- T14-T16 controlled update (amendment K)
LAB_CONTAINER = 'nuxbill-reseller-test-pwalab'
NEXT = 'wp7.1-labnext'


def lab_sw(version=None):
    """publish a byte-different worker in the lab copy (a new VERSION), or restore the repository worker"""
    d = LAB['dir']
    cmd = (f"sed -i \"s/^var VERSION = '[^']*';/var VERSION = '{version}';/\" {d}/nuxr-sw.js" if version
           else f'cp /repo/nuxr-sw.js {d}/nuxr-sw.js')
    subprocess.run(['docker', 'exec', LAB_CONTAINER, 'sh', '-c', cmd], check=True)


UPDATE_NOW = "() => navigator.serviceWorker.getRegistration().then(r => r.update()).then(() => true, () => true)"
WAITING = "() => navigator.serviceWorker.getRegistration().then(r => !!(r && r.waiting && r.waiting.state === 'installed'))"
BAR = "() => { const b = document.querySelector('.nuxr-update-bar'); return b && !b.hidden ? b.getAttribute('data-mode') + '|' + b.textContent.trim() : ''; }"


def bar_mode(pg, mode, timeout=15000):
    pg.wait_for_function("(m) => { const b = document.querySelector('.nuxr-update-bar'); return b && !b.hidden && b.getAttribute('data-mode') === m; }",
                         arg=mode, timeout=timeout)
    return pg.evaluate(BAR)


def watch(pg):
    """mark the document (a reload clears it) and count controllerchange events"""
    pg.evaluate("() => { window.__nuxrMark = 1; window.__cc = 0; navigator.serviceWorker.addEventListener('controllerchange', () => window.__cc++); }")


@check('T14', 'Update waiting: a new worker installs and WAITS; the user is offered Update; nothing activates or reloads by itself')
def t14(browser):
    notes = []
    try:
        for base in (ROOT, SUB):
            ctx = new_ctx(browser, base, vp=DESK)
            pg = staff_login(ctx, base, 'ad')
            sw_ready(pg)
            expect(pg.evaluate(BAR) == '', 'update offered on the first installation')
            ctrl = pg.evaluate('() => navigator.serviceWorker.controller.scriptURL')
            watch(pg)
            lab_sw(NEXT)
            pg.evaluate(UPDATE_NOW)
            text = bar_mode(pg, 'available')
            expect('A new version of the app is available.' in text, 'update text: ' + text)
            expect(pg.evaluate(WAITING), 'new worker not waiting')
            pg.wait_for_timeout(4000)
            expect(pg.evaluate(WAITING), 'the new worker activated by itself')
            expect(pg.evaluate('() => window.__nuxrMark === 1 && window.__cc === 0'), 'page reloaded or controller changed without the user')
            expect(pg.evaluate('() => navigator.serviceWorker.controller.scriptURL') == ctrl, 'controller changed')
            keys = pg.evaluate('() => caches.keys()')
            expect(any(k.endswith(':wp7.1') for k in keys), f'the active version cache was pruned early: {keys}')
            if base == ROOT:
                pg.screenshot(path=os.path.join(ARGS.out, 'T14-update-available.png'))
            # the offer survives navigation (registration.waiting on the next page)
            pg.goto(base + '?_route=customers/list', wait_until='networkidle')
            bar_mode(pg, 'available')
            notes.append(f'{base}: waiting, offered, no self-activation for 4 s, offered again on the next page')
            ctx.close()
            lab_sw()
    finally:
        lab_sw()
    return '; '.join(notes)


@check('T15', 'Update deferred in critical flows: activation confirm, finance review, dirty POST form, open dialog, pending submit')
def t15(browser):
    notes = []
    ctx = new_ctx(browser, ROOT, vp=DESK)
    pg = staff_login(ctx, ROOT, 'ad')
    sw_ready(pg)
    try:
        lab_sw(NEXT)
        pg.evaluate(UPDATE_NOW)
        bar_mode(pg, 'available')

        def press_and_expect_deferred(label, shot=None):
            watch(pg)
            pg.locator('.nuxr-update-bar [data-nuxr-update]').click()
            text = bar_mode(pg, 'deferred', timeout=5000)
            expect('Finish or leave the current task first' in text, label + ': deferred text ' + text)
            pg.wait_for_timeout(1500)
            expect(pg.evaluate(WAITING), label + ': the worker activated')
            expect(pg.evaluate('() => window.__nuxrMark === 1 && window.__cc === 0'), label + ': reloaded / controller changed')
            if shot:
                pg.screenshot(path=os.path.join(ARGS.out, shot))
            notes.append(label + ': deferred')

        # 1. activation confirmation (#nuxr-confirm-form data-nuxr-critical)
        cust = LAB['customers']['mohammed']
        found = False
        for plan in LAB['plans']:
            pg.goto(ROOT + f'?_route=activation/create&customer={cust}&plan={plan}', wait_until='networkidle')
            if pg.locator('#nuxr-confirm-form[data-nuxr-critical]').count():
                found = True
                break
        expect(found, 'activation confirmation not reached')
        bar_mode(pg, 'available')
        press_and_expect_deferred('activation confirm', 'T15-update-deferred-activation.png')
        # 2. finance review (review.tpl form data-nuxr-critical)
        pg.goto(ROOT + '?_route=finance/funding/%d&op=fund' % LAB['staff_ids']['A'], wait_until='networkidle')
        pg.fill('#nuxr-amount', '10')
        pg.fill('#nuxr-reason', 'lab update deferral check')
        with pg.expect_navigation():
            pg.locator('form[action*="funding-review"] button[type=submit]').click()
        pg.wait_for_load_state('networkidle')
        expect(pg.locator('form[data-nuxr-critical]').count() == 1, 'finance review not reached')
        bar_mode(pg, 'available')
        press_and_expect_deferred('finance review')
        # 3. a POST form the user changed (dirty tracking)
        pg.goto(ROOT + '?_route=customers/edit/%d' % cust, wait_until='networkidle')
        bar_mode(pg, 'available')
        field = pg.locator('form[method=post] input[type=text]:visible').first
        field.click()
        pg.keyboard.type(' x')
        press_and_expect_deferred('dirty POST form')
        # 4. an open dialog; 5. a pending submit (markers set directly: same rule, presentation state only)
        pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
        bar_mode(pg, 'available')
        pg.evaluate("() => document.getElementById('nuxr-more-sheet').show()")
        press_and_expect_deferred('dialog[open]')
        pg.evaluate("() => document.getElementById('nuxr-more-sheet').close()")
        pg.evaluate("() => { const f = document.createElement('form'); f.method = 'post'; f.setAttribute('data-nuxr-submitted', '1'); document.body.appendChild(f); }")
        press_and_expect_deferred('form[data-nuxr-submitted]')
        # an unchanged GET page without markers is not critical: the offer is there again
        pg.goto(ROOT + '?_route=customers/list', wait_until='networkidle')
        expect(bar_mode(pg, 'available').startswith('available'), 'not offered again after the flow')
        notes.append('offered again on a non-critical page')
    finally:
        ctx.close()
        lab_sw()
    return '; '.join(notes)


@check('T16', 'Explicit safe update: SKIP_WAITING -> controllerchange -> exactly one reload of this tab; other tabs only notified')
def t16(browser):
    ctx = new_ctx(browser, ROOT, vp=DESK)
    pg = staff_login(ctx, ROOT, 'ad')
    sw_ready(pg)
    other = ctx.new_page()
    other.goto(ROOT + '?_route=customers/list', wait_until='networkidle')
    sw_ready(other)
    try:
        lab_sw(NEXT)
        pg.evaluate(UPDATE_NOW)
        bar_mode(pg, 'available')
        bar_mode(other, 'available')
        watch(pg)
        watch(other)
        navs = []
        pg.on('framenavigated', lambda f: navs.append(f.url) if f == pg.main_frame else None)
        other_navs = []
        other.on('framenavigated', lambda f: other_navs.append(f.url) if f == other.main_frame else None)
        with pg.expect_navigation(timeout=20000):
            pg.locator('.nuxr-update-bar [data-nuxr-update]').click()
        pg.wait_for_load_state('networkidle')
        pg.wait_for_timeout(3000)
        expect(len(navs) == 1, f'this tab navigated {len(navs)} times (expected exactly one reload)')
        expect(pg.evaluate('() => window.__nuxrMark === undefined'), 'this tab was not reloaded')
        keys = pg.evaluate('() => caches.keys()')
        expect(keys and all(k.endswith(':' + NEXT) for k in keys), f'old version cache not pruned on activation: {keys}')
        expect(pg.evaluate(BAR) == '', 'still offered after the update')
        text = bar_mode(other, 'updated')
        expect('Reload this page when convenient' in text, 'other tab text: ' + text)
        expect(other.evaluate('() => window.__nuxrMark === 1') and not other_navs, 'the other tab was reloaded')
        expect(other.locator('.nuxr-update-bar [data-nuxr-update]').is_hidden(), 'other tab still offers Update')
        other.screenshot(path=os.path.join(ARGS.out, 'T16-other-tab-updated.png'))
        return f'one reload ({navs[0][-40:]}); caches now {keys}; other tab not reloaded, shows: {text[8:90]}'
    finally:
        ctx.close()
        lab_sw()



# ---------------------------------------------------------------- T17-T18 parity with and without the worker
PAGE_FACTS = """() => ({ title: document.title, h1: (document.querySelector('h1') || {}).textContent ? document.querySelector('h1').textContent.trim() : '',
    forms: Array.from(document.forms).map(f => (f.getAttribute('method') || 'get').toLowerCase() + ' ' + (f.getAttribute('action') || '').replace(/^https?:\\/\\/[^/]+/, '').replace(/token=[0-9a-f]+/g, 'token=<session>')),
    token: typeof window.nuxrCsrfToken === 'string' && /^[0-9a-f]{64}$/.test(window.nuxrCsrfToken) ? true : typeof window.nuxrCsrfToken + ':' + String(window.nuxrCsrfToken).slice(0, 6), dir: document.documentElement.dir,
    alerts: Array.from(document.querySelectorAll('.nuxr-alert, .alert')).map(a => a.textContent.trim().slice(0, 80)) })"""


TOKEN_READY = "() => typeof window.nuxrCsrfToken === 'string' && /^[0-9a-f]{64}$/.test(window.nuxrCsrfToken)"


def page_ready(pg, res):
    """owner fix round 1 (FR1-3): a page whose server HTML renders the footer CSRF include is read only after that
    script has run (bounded). If the token never initialises, this raises and the check FAILS - nothing is skipped."""
    try:
        html = res.text() if res else ''
    except Exception:  # noqa: BLE001  (a response without a body, e.g. a redirect)
        html = ''
    if 'window.nuxrCsrfToken =' in html:
        pg.wait_for_function(TOKEN_READY, timeout=10000)


def visit(pg, base, route):
    res = pg.goto(base + '?_route=' + route, wait_until='networkidle')
    page_ready(pg, res)
    return {'status': res.status if res else None, 'route': route_of(pg), **pg.evaluate(PAGE_FACTS)}


def two_contexts(browser, base, user, vp=DESK, lang='english'):
    """the same staff member in a context with the worker active and in one with service workers blocked"""
    on = new_ctx(browser, base, vp=vp, lang=lang)
    pg_on = staff_login(on, base, user)
    sw_ready(pg_on)
    off = new_ctx(browser, base, vp=vp, lang=lang, service_workers='block')
    pg_off = staff_login(off, base, user)
    expect(pg_off.evaluate('() => !navigator.serviceWorker.controller'), 'blocked context is controlled')
    return on, pg_on, off, pg_off


@check('T17', 'Deep-link authorization parity: identical status / final route / denial with and without the worker')
def t17(browser):
    cust = LAB['customers']
    routes = {
        'A': ['customers/view/%d' % cust['mohammed'], 'customers/view/%d' % cust['fatima'], 'customers/view/%d' % cust['karrar'], 'customers/view/%d' % cust['legacy'],
              'customers/view/999999', 'activation/view/' + LAB['refs']['mohammed'], 'activation/view/ACT-DOESNOTEXIST', 'settings/users', 'settings/app',
              'finance/ledger/%d' % LAB['staff_ids']['B'], 'pricing/plans', 'routers/list', 'reports/activation', 'pwa/start', 'pwa/anything'],
        'A1': ['customers/view/%d' % cust['mohammed'], 'customers/view/%d' % cust['fatima'], 'finance/wallets', 'settings/users', 'activation/create'],
        'rp': ['customers/list', 'customers/edit/%d' % cust['mohammed'], 'finance/funding/%d&op=fund' % LAB['staff_ids']['A'], 'settings/users'],
    }
    rows = []
    for user, rs in routes.items():
        on, pg_on, off, pg_off = two_contexts(browser, ROOT, user)
        for r in rs:
            a, b = visit(pg_on, ROOT, r), visit(pg_off, ROOT, r)
            expect(a == b, f'{user} {r}: with worker {a} != without {b}')
            rows.append(f"{user} {r} -> {a['status']} {a['route']}")
        on.close()
        off.close()
    denied = [x for x in rows if ' 403 ' in x or ' 404 ' in x]
    return f'{len(rows)} deep links identical (status, final route, title, h1, forms, alerts); {len(denied)} denials among them'


@check('T18', "Core online parity: worker active vs service_workers='block' (pages, forms, CSRF token, a reviewed POST)")
def t18(browser):
    notes = []
    for base in (ROOT, SUB):
        on, pg_on, off, pg_off = two_contexts(browser, base, 'ad')
        for r in ['dashboard', 'customers/list', 'customers/view/%d' % LAB['customers']['mohammed'], 'activation/create', 'finance/wallets', 'finance/ledger',
                  'pricing/plans', 'settings/users', 'plan/list', 'reports/activation']:
            a, b = visit(pg_on, base, r), visit(pg_off, base, r)
            expect(a == b, f'{base} {r}: {a} != {b}')
            expect(a['status'] == 200 and a['token'], f'{base} {r}: {a["status"]} token {a["token"]}')
        # the same POST navigation (funding review: no write) through the worker and without it
        out = []
        for pg in (pg_on, pg_off):
            pg.goto(base + '?_route=finance/funding/%d&op=fund' % LAB['staff_ids']['A'], wait_until='networkidle')
            pg.fill('#nuxr-amount', '12.50')
            pg.fill('#nuxr-reason', 'lab parity check')
            with pg.expect_navigation():
                pg.locator('form[action*="funding-review"] button[type=submit]').click()
            pg.wait_for_load_state('networkidle')
            f = pg.evaluate(PAGE_FACTS)
            f['review'] = pg.locator('form[data-nuxr-critical]').count()
            f['amount'] = '12.50' in pg.content()
            out.append(f)
        expect(out[0] == out[1] and out[0]['review'] == 1 and out[0]['amount'], f'{base} review: {out}')
        errors = []
        for pg in (pg_on, pg_off):
            pg.on('pageerror', lambda e: errors.append(str(e)))
            pg.goto(base + '?_route=dashboard', wait_until='networkidle')
        expect(not errors, f'page errors {errors}')
        notes.append(f'{base}: 10 pages + funding review POST identical; worker-controlled vs blocked')
        on.close()
        off.close()
    return '; '.join(notes)


# ---------------------------------------------------------------- T19 Arabic RTL surfaces, T20 axe
def shot(pg, name):
    pg.screenshot(path=os.path.join(ARGS.out, name))


@check('T19', 'Arabic RTL: status chip (offline / reconnecting / slow), banner, not-sent notice, install sheet, update bar, offline documents')
def t19(browser):
    ar = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'system', 'lan', 'arabic.json'), encoding='utf-8'))
    notes = []
    ctx = new_ctx(browser, ROOT, vp=PHONE, lang='arabic')
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    expect(pg.evaluate('() => document.documentElement.dir') == 'rtl', 'not RTL')
    try:
        go_offline(ctx, pg)
        expect(pg.locator('.nuxr-net-chip').inner_text().strip() == ar['Offline'], 'offline chip not Arabic')
        expect(pg.locator('.nuxr-net-banner').inner_text().strip() == ar['You_are_offline__Changes_cannot_be_sent__and_the_information_on_screen_may_not_be_current_'], 'banner')
        shot(pg, 'T19-ar-offline-phone.png')
        pg.evaluate("() => { fetch(location.href, { method: 'POST', body: 'x=1' }).catch(() => {}); }")
        pg.wait_for_function("(t) => { const n = document.querySelector('.nuxr-net-toast'); return n && !n.hidden && n.textContent === t; }",
                             arg=ar['Not_sent__you_are_offline__Nothing_was_sent_to_the_server__try_again_when_the_connection_is_back_'], timeout=5000)
        shot(pg, 'T19-ar-not-sent-phone.png')
        ctx.set_offline(False)
        pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'reconnecting'", timeout=20000)
        expect(pg.locator('.nuxr-net-chip').inner_text().strip() == ar['Reconnecting___'], 'reconnecting chip not Arabic')
        shot(pg, 'T19-ar-reconnecting-phone.png')
        pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=20000)
        notes.append('offline / not-sent / reconnecting in Arabic')
        cdp = ctx.new_cdp_session(pg)
        cdp.send('Network.enable')
        cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 3000, 'downloadThroughput': -1, 'uploadThroughput': -1})
        pg.evaluate("() => window.dispatchEvent(new Event('online'))")
        pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'slow'", timeout=30000)
        expect(pg.locator('.nuxr-net-chip').inner_text().strip() == ar['Slow_connection'], 'slow chip not Arabic')
        shot(pg, 'T19-ar-slow-phone.png')
        cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1})
        notes.append('slow in Arabic')
        pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
        pg.click('details.nuxr-menu.nuxr-user > summary')
        item = pg.locator('.nuxr-menu-panel [data-nuxr-install]')
        expect(ar['Install_app'] in item.inner_text(), 'install item not Arabic')
        item.click()
        pg.wait_for_timeout(300)
        sheet = pg.locator('#nuxr-install-sheet')
        expect(ar['Install_the_app'] in sheet.inner_text() and ar['Open_your_browser_menu_and_choose_Install_app_or_Add_to_Home_screen_'] in sheet.inner_text(), 'install sheet')
        shot(pg, 'T19-ar-install-sheet-phone.png')
        pg.keyboard.press('Escape')
        pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')  # closes the account menu
        notes.append('install item + sheet in Arabic')
        lab_sw(NEXT)
        pg.evaluate(UPDATE_NOW)
        text = bar_mode(pg, 'available')
        expect(ar['A_new_version_of_the_app_is_available_'] in text and ar['Update'] in text, 'update bar not Arabic: ' + text)
        shot(pg, 'T19-ar-update-phone.png')
        pg.evaluate("() => { document.getElementById('nuxr-more-sheet').show(); window.scrollTo(0, 0); }")
        pg.locator('.nuxr-update-bar [data-nuxr-update]').click()
        text = bar_mode(pg, 'deferred', timeout=5000)
        expect(ar['Finish_or_leave_the_current_task_first__then_update_'] in text, 'deferred not Arabic')
        notes.append('update bar + deferred in Arabic')
    finally:
        lab_sw()
        ctx.close()
    # the static offline documents (bilingual; each block carries its own dir)
    ctx = new_ctx(browser, ROOT, vp=PHONE, lang='arabic')
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    ctx.set_offline(True)
    pg.goto(ROOT + '?_route=customers/list', wait_until='load')
    expect(pg.locator('[dir=rtl][lang=ar]').count() >= 1 and pg.locator('[dir=ltr][lang=en]').count() >= 1, 'offline document blocks lack lang / dir')
    shot(pg, 'T19-offline-doc-phone.png')
    ctx.set_offline(False)
    pg.goto(ROOT + 'nuxr-offline.html?reason=unconfirmed', wait_until='load')
    expect(pg.locator('text=may or may not have reached the server').count() >= 1, 'unconfirmed block not shown')
    shot(pg, 'T19-offline-unconfirmed-phone.png')
    ctx.close()
    notes.append('offline documents: Arabic + English blocks with lang / dir')
    return '; '.join(notes)


AXE_RUN = """async () => { const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] }, resultTypes: ['violations'] });
  return r.violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => v.id + ' x' + v.nodes.length + ' ' + v.nodes.slice(0, 2).map(n => n.target.join(' ')).join(' | ')); }"""
TARGETS = """() => Array.from(document.querySelectorAll('.nuxr-update-bar button, #nuxr-install-sheet button, [data-nuxr-install]'))
  .filter(b => b.offsetParent !== null).map(b => { const r = b.getBoundingClientRect(); return [b.className || b.tagName, Math.round(r.width), Math.round(r.height)]; })
  .filter(x => x[1] < 44 || x[2] < 44)"""


@check('T20', 'axe-core wcag2a/aa serious + critical = 0 on the WP7 surfaces (phone Arabic + desktop English); 44 px targets; no overflow')
def t20(browser):
    expect(ARGS.axe and os.path.exists(ARGS.axe), 'axe-core not provided (--axe / PWALAB_AXE)')
    results = []

    def audit(pg, label):
        pg.add_script_tag(path=ARGS.axe)
        v = pg.evaluate(AXE_RUN)
        small = pg.evaluate(TARGETS) if label.startswith('phone') else []  # 44 px touch targets: phone (WP6A: desktop controls are 40 px)
        over = pg.evaluate('() => document.documentElement.scrollWidth > window.innerWidth + 1')
        results.append((label, v, small, over))

    try:
        for vp, lang, tag in ((PHONE, 'arabic', 'phone-ar'), (DESK, 'english', 'desk-en')):
            ctx = new_ctx(browser, ROOT, vp=vp, lang=lang)
            pg = staff_login(ctx, ROOT, 'A')
            sw_ready(pg)
            go_offline(ctx, pg)
            pg.evaluate("() => { fetch(location.href, { method: 'POST' }).catch(() => {}); }")
            pg.wait_for_timeout(400)
            audit(pg, tag + ' offline chip + banner + not-sent')
            if tag == 'desk-en':
                shot(pg, 'T20-desk-offline-not-sent.png')
            ctx.set_offline(False)
            pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=30000)
            pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
            pg.click('details.nuxr-menu.nuxr-user > summary')
            pg.locator('.nuxr-menu-panel [data-nuxr-install]').click()
            pg.wait_for_timeout(300)
            audit(pg, tag + ' install sheet')
            pg.keyboard.press('Escape')
            lab_sw(NEXT)
            pg.evaluate(UPDATE_NOW)
            bar_mode(pg, 'available')
            audit(pg, tag + ' update bar')
            if tag == 'desk-en':
                shot(pg, 'T20-desk-update-available.png')
            lab_sw()
            ctx.close()
            for doc in ('nuxr-offline.html', 'nuxr-offline.html?reason=unconfirmed'):
                ctx = new_ctx(browser, ROOT, vp=vp, lang=lang)
                pg = ctx.new_page()
                pg.goto(ROOT + doc, wait_until='load')
                audit(pg, tag + ' ' + doc)
                ctx.close()
    finally:
        lab_sw()
    bad = [r for r in results if r[1] or r[2] or r[3]]
    expect(not bad, 'violations: ' + json.dumps(bad, ensure_ascii=False)[:600])
    return f'{len(results)} audits (phone Arabic + desktop English): axe serious/critical 0, no target < 44 px, no horizontal overflow'



# ---------------------------------------------------------------- pre-WP8 hotfix R-WP7-CARRY-01 (Pace / XHR CSRF)
def csrf_of(headers):
    """the X-CSRF-Token value as sent ('' when absent); a duplicated header would arrive comma-joined"""
    return headers.get('x-csrf-token', '')


def token_ok(value, token):
    return value == token and ',' not in value


@check('T21', 'Hotfix: plan/recharge -> autoload/server and autoload/plan send exactly one correct X-CSRF-Token and render (root + /billing/, worker active + blocked)')
def t21(browser):
    notes = []
    for base in (ROOT, SUB):
        for sw in ('allow', 'block'):
            kw = {} if sw == 'allow' else {'service_workers': 'block'}
            ctx = new_ctx(browser, base, vp=DESK, **kw)
            pg = staff_login(ctx, base, 'ad')
            if sw == 'allow':
                sw_ready(pg)
            errors = []
            pg.on('pageerror', lambda e: errors.append(str(e)[:160]))
            reqs = []
            pg.on('requestfinished', lambda r: reqs.append(r) if re.search(r'_route=autoload/(server|plan)\b', r.url) else None)
            page_ready(pg, pg.goto(base + '?_route=plan/recharge', wait_until='networkidle'))
            token = pg.evaluate('() => window.nuxrCsrfToken')
            pg.locator('input[type=radio]#Hot').check(force=True)
            pg.wait_for_function("() => !!document.querySelector('#server option[value=radius]')", timeout=10000)
            pg.select_option('#server', 'radius', force=True)
            pg.wait_for_function("() => document.querySelectorAll('#plan option').length > 1", timeout=10000)
            pg.wait_for_timeout(500)
            by = {k: [r for r in reqs if f'autoload/{k}' in r.url] for k in ('server', 'plan')}
            for k, rs in by.items():
                expect(len(rs) == 1, f'{base} {sw}: {len(rs)} autoload/{k} requests')
                r = rs[0]
                expect(r.method == 'POST' and r.response().status == 200, f'{base} {sw}: autoload/{k} {r.method} {r.response().status}')
                expect(token_ok(csrf_of(r.all_headers()), token), f'{base} {sw}: autoload/{k} X-CSRF-Token {csrf_of(r.all_headers())[:12]!r}')
            plans = pg.evaluate("() => Array.from(document.querySelectorAll('#plan option')).map(o => o.textContent.trim()).filter(Boolean)")
            expect(any('Hotspot' in p for p in plans), f'{base} {sw}: plan list not rendered {plans}')
            expect(not errors and pg.evaluate("() => typeof window.Pace === 'object'"), f'{base} {sw}: page errors {errors}')
            notes.append(f'{base} {sw}: server 200 + plan 200, one exact token each, {len(plans) - 1} plan(s) rendered')
            ctx.close()
    return '; '.join(notes)


PROBE_JS = """(cross) => new Promise(done0 => {
  const out = {}; const T = setTimeout(() => done0(Object.assign({ TIMEOUT: true }, out)), 15000);
  const done = () => { clearTimeout(T); done0(out); };
  const xhr = (key, method, url, body, upload) => new Promise(res => {
    const x = new XMLHttpRequest(); const ev = [];
    x.addEventListener('readystatechange', () => ev.push('rs' + x.readyState));
    ['load', 'error', 'abort', 'timeout', 'loadend'].forEach(t => x.addEventListener(t, () => ev.push(t)));
    if (upload) { ['loadstart', 'progress', 'load', 'loadend'].forEach(t => x.upload.addEventListener(t, () => ev.push('up-' + t))); }
    x.open(method, url); x.onloadend = () => { out[key] = { status: x.status, text: x.responseText, events: [...new Set(ev)].join(',') }; res(); };
    x.send(body === undefined ? null : body); });
  jQuery.ajax({ type: 'POST', url: '?_route=dashboard&nuxr_probe=jq-post', data: 'a=1',
    success: (d, s) => { out.jqPost = { cb: 'success', data: d }; }, error: (x, s) => { out.jqPost = { cb: 'error:' + s }; },
    complete: () => {
      jQuery.get('?_route=dashboard&nuxr_probe=jq-get', d => { out.jqGet = { cb: 'success', data: d }; })
        .always(() => xhr('nativePost', 'POST', '?_route=dashboard&nuxr_probe=native-post', 'a=1')
        .then(() => xhr('nativeGet', 'GET', '?_route=dashboard&nuxr_probe=native-get'))
        .then(() => xhr('upload', 'POST', '?_route=dashboard&nuxr_probe=upload', new Blob(['x'.repeat(4096)]), true))
        .then(() => xhr('crossPost', 'POST', cross + '/upload?nuxr_probe=cross-post', new Blob(['y'.repeat(4096)]), true))
        .then(() => xhr('crossGet', 'GET', cross + '/v?nuxr_probe=cross-get'))
        .then(() => fetch('?_route=dashboard&nuxr_probe=fetch-post', { method: 'POST', body: 'a=1' }).then(r => r.text()).then(t => { out.fetchPost = { text: t }; }))
        .then(done, done));
    } });
})"""
CROSS = 'https://cross.example.invalid'


def probe_routes(ctx, base):
    seen = []
    origin = base.split('/', 3)[0] + '//' + base.split('/', 3)[2]

    def rec(route):
        seen.append({'probe': re.search(r'nuxr_probe=([a-z-]+)', route.request.url).group(1), 'method': route.request.method,
                     'csrf': csrf_of(route.request.headers)})
        route.fulfill(status=200, body='ok', headers={'access-control-allow-origin': origin, 'content-type': 'text/plain'})
    ctx.route(re.compile(r'nuxr_probe='), rec)
    return seen


@check('T22', 'Hotfix: XHR transport matrix - jQuery / native POST + GET, upload, fetch, cross-origin, customer portal, Pace; exactly one token, none cross-origin')
def t22(browser):
    notes = []
    want_same = {'jq-post', 'jq-get', 'native-post', 'native-get', 'upload', 'fetch-post'}
    for base in (ROOT, SUB):
        for sw in ('allow', 'block'):
            kw = {} if sw == 'allow' else {'service_workers': 'block'}
            ctx = new_ctx(browser, base, vp=DESK, **kw)
            seen = probe_routes(ctx, base)
            pg = staff_login(ctx, base, 'ad')
            if sw == 'allow':
                sw_ready(pg)
            errors = []
            pg.on('pageerror', lambda e: errors.append(str(e)[:160]))
            page_ready(pg, pg.goto(base + '?_route=dashboard', wait_until='networkidle'))
            token = pg.evaluate('() => window.nuxrCsrfToken')
            out = pg.evaluate(PROBE_JS, CROSS)
            expect('TIMEOUT' not in out, f'{base} {sw}: probes timed out {out}')
            counts = {}
            for x in seen:
                counts[x['probe']] = counts.get(x['probe'], 0) + 1
            expect(all(counts.get(p) == 1 for p in want_same | {'cross-post', 'cross-get'}), f'{base} {sw}: request counts {counts}')
            for x in seen:
                if x['probe'] in want_same:
                    expect(token_ok(x['csrf'], token), f"{base} {sw}: {x['probe']} token {x['csrf'][:12]!r}")
                else:
                    expect(x['csrf'] == '', f"{base} {sw}: cross-origin {x['probe']} carried X-CSRF-Token")
            expect(out['jqPost'] == {'cb': 'success', 'data': 'ok'} and out['jqGet'] == {'cb': 'success', 'data': 'ok'}, f'jQuery callbacks {out}')
            for k in ('nativePost', 'nativeGet'):
                expect(out[k]['status'] == 200 and out[k]['text'] == 'ok' and out[k]['events'] == 'rs1,rs2,rs3,rs4,load,loadend',
                       f'{base} {sw}: {k} {out[k]}')
            # a test-fulfilled request (interception) emits only upload.loadstart in Chromium: the full sequence is checked on
            # the real, un-intercepted upload below
            for k in ('upload', 'crossPost'):
                expect(out[k]['status'] == 200 and 'up-loadstart' in out[k]['events'] and out[k]['events'].endswith('load,loadend'), f'{base} {sw}: {k} {out[k]}')
            real = []
            pg.on('requestfinished', lambda r: real.append(r) if 'nuxr_upload=real' in r.url else None)
            up = pg.evaluate("""() => new Promise(done => { const x = new XMLHttpRequest(); const ev = [];
                ['loadstart', 'progress', 'load', 'loadend'].forEach(t => x.upload.addEventListener(t, () => ev.push('up-' + t)));
                ['load', 'loadend'].forEach(t => x.addEventListener(t, () => ev.push(t)));
                x.open('POST', '?_route=dashboard&nuxr_upload=real'); x.onloadend = () => setTimeout(() => done({ status: x.status, events: [...new Set(ev)].join(',') }), 0);
                x.send(new Blob(['z'.repeat(256 * 1024)])); })""")
            pg.wait_for_timeout(300)
            expect(up['events'] == 'up-loadstart,up-progress,up-load,up-loadend,load,loadend', f'{base} {sw}: real upload events {up}')
            expect(len(real) == 1 and token_ok(csrf_of(real[0].all_headers()), token), f'{base} {sw}: real upload request / token')
            expect(out['fetchPost'] == {'text': 'ok'}, f'fetch {out}')
            chain = pg.evaluate("""() => { const x = new XMLHttpRequest(); const p = Object.getPrototypeOf(x);
                return { pace: typeof window.Pace, paceEl: !!document.querySelector('.pace'), ctorReplaced: !/native code/.test(String(window.XMLHttpRequest)),
                         marker: Object.prototype.propertyIsEnumerable.call(p, '__nuxrCsrfXhr') ? 'enumerable' : (p.__nuxrCsrfXhr === true ? 'non-enumerable' : 'missing') }; }""")
            expect(chain['pace'] == 'object' and chain['paceEl'] and chain['ctorReplaced'] and chain['marker'] == 'non-enumerable', f'{base} {sw}: chain {chain}')
            expect(not errors, f'{base} {sw}: page errors {errors}')
            notes.append(f'{base} {sw}: 6 same-origin probes one exact token, 2 cross-origin none, events + callbacks unchanged, upload events, Pace active')
            ctx.close()
    # customer portal: unchanged behaviour (no Pace; the native constructor is not replaced)
    ctx = new_ctx(browser, ROOT, vp=DESK)
    seen = probe_routes(ctx, ROOT)
    pg = ctx.new_page()
    pg.goto(ROOT + '?_route=login', wait_until='networkidle')
    pg.fill('input[name=username]', 'mohammed.h')
    pg.fill('input[name=password]', 'lab-mohammed.h')
    with pg.expect_navigation():
        pg.locator('form[action*="login/post"] button[type=submit]').click()
    page_ready(pg, pg.goto(ROOT + '?_route=home', wait_until='networkidle'))
    token = pg.evaluate('() => window.nuxrCsrfToken')
    info = pg.evaluate("""() => ({ ctorNative: /native code/.test(String(window.XMLHttpRequest)), inst: (new XMLHttpRequest()) instanceof XMLHttpRequest,
        protoIsCtor: Object.getPrototypeOf(new XMLHttpRequest()) === XMLHttpRequest.prototype, pace: typeof window.Pace, gate: typeof window.nuxrMutationGate })""")
    out = pg.evaluate(PROBE_JS, CROSS)
    expect(info == {'ctorNative': True, 'inst': True, 'protoIsCtor': True, 'pace': 'undefined', 'gate': 'undefined'}, f'customer page {info}')
    for x in seen:
        if x['probe'] in want_same:
            expect(token_ok(x['csrf'], token), f"customer {x['probe']} token {x['csrf'][:12]!r}")
        else:
            expect(x['csrf'] == '', f"customer cross-origin {x['probe']} carried X-CSRF-Token")
    expect(out['jqPost']['cb'] == 'success' and out['nativePost']['events'] == 'rs1,rs2,rs3,rs4,load,loadend', f'customer callbacks {out}')
    ctx.close()
    notes.append('customer portal: native constructor, instanceof intact, same-origin one exact token, cross-origin none (as before the hotfix)')
    return '; '.join(notes)


@check('T23', 'Hotfix: WP7 SLOW sends the XHR POST with the token; a genuine XHR network error keeps its semantics, triggers the heartbeat, carried the token, no retry')
def t23(browser):
    ctx = new_ctx(browser, ROOT, vp=DESK)
    seen = probe_routes(ctx, ROOT)
    pg = staff_login(ctx, ROOT, 'A')
    sw_ready(pg)
    page_ready(pg, pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle'))
    token = pg.evaluate('() => window.nuxrCsrfToken')
    cdp = ctx.new_cdp_session(pg)
    cdp.send('Network.enable')
    cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 3000, 'downloadThroughput': -1, 'uploadThroughput': -1})
    pg.evaluate("() => window.dispatchEvent(new Event('online'))")
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'slow'", timeout=30000)
    slow = pg.evaluate("""() => new Promise(r => jQuery.ajax({ type: 'POST', url: '?_route=dashboard&nuxr_probe=slow-post', data: 'a=1',
        success: () => r('success'), error: (x, s) => r('error:' + s) }))""")
    cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1})
    slow_reqs = [x for x in seen if x['probe'] == 'slow-post']
    expect(slow == 'success' and len(slow_reqs) == 1 and token_ok(slow_reqs[0]['csrf'], token), f'SLOW: {slow} {slow_reqs}')
    pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=40000)
    pg.wait_for_timeout(1500)
    pings = ping_requests(pg)
    failed = []
    ctx.route(re.compile(r'nuxr_netfail=hotfix'), lambda r: (failed.append(csrf_of(r.request.headers)), r.abort('failed')))
    t0 = time.time()
    res = pg.evaluate("""() => new Promise(r => { const x = new XMLHttpRequest(); let n = 0;
        x.onerror = () => { n++; setTimeout(() => r({ errors: n, status: x.status, state: x.readyState }), 0); };
        x.open('POST', '?_route=dashboard&nuxr_netfail=hotfix'); x.send('a=1'); })""")
    pg.wait_for_timeout(PING_WAIT)
    near = [t - t0 for t, _ in pings if t >= t0]
    expect(res == {'errors': 1, 'status': 0, 'state': 4}, f'caller error semantics {res}')
    expect(len(failed) == 1 and token_ok(failed[0], token), f'attempted request: {len(failed)} sent, token {failed[:1]}')
    expect(near and near[0] < 2.0, f'immediate heartbeat {near}')
    ctx.close()
    return f'SLOW: jQuery POST success, one request with the exact token; network error: caller error once (status 0, readyState 4), request sent once with the exact token, heartbeat after {near[0]:.2f} s, no retry'


def swal_confirm(pg):
    pg.wait_for_selector('.swal2-confirm', timeout=10000)
    pg.locator('.swal2-confirm').click()


@check('T24', 'Hotfix: restored legacy XHR operations on isolated lab fixtures (coupon / voucher bulk delete, bulk message TEST mode); Agent / Sales still refused')
def t24(browser):
    notes = []
    ctx = new_ctx(browser, ROOT, vp=DESK)
    pg = staff_login(ctx, ROOT, 'ad')
    errors = []
    pg.on('pageerror', lambda e: errors.append(str(e)[:160]))
    xhrs = []
    pg.on('requestfinished', lambda r: xhrs.append(r) if re.search(r'coupons/delete|voucher-delete-many|send_bulk_ajax', r.url) else None)
    token = None
    # coupons: two isolated fixtures created through the Admin form, then deleted through the restored XHR
    codes = ['HFXC%d%s' % (i, str(int(time.time()))[-5:]) for i in (1, 2)]
    for code in codes:
        page_ready(pg, pg.goto(ROOT + '?_route=coupons/add', wait_until='networkidle'))
        pg.fill('#code', code)
        pg.select_option('#type', 'fixed')
        pg.fill('#value', '1')
        pg.fill('#nxf-description', 'hotfix lab fixture')
        pg.fill('#nxf-min_order_amount', '1')
        pg.fill('#nxf-max_discount_amount', '1')
        pg.fill('#nxf-start_date', '2026-01-01')
        pg.fill('#nxf-end_date', '2027-01-01')
        with pg.expect_navigation():
            pg.locator('form[action*="coupons/add-post"] [type=submit]').first.click()
        pg.wait_for_load_state('networkidle')
    page_ready(pg, pg.goto(ROOT + '?_route=coupons/list', wait_until='networkidle'))
    token = pg.evaluate('() => window.nuxrCsrfToken')
    for code in codes:
        row = pg.locator('tr', has_text=code)
        expect(row.count() == 1, f'coupon fixture {code} not listed')
        row.locator('input[name="coupon_ids[]"]').check(force=True)
    pg.locator('#deleteSelectedCoupons').click()
    swal_confirm(pg)
    pg.wait_for_selector('.swal2-title:has-text("Deleted")', timeout=10000)
    r = [x for x in xhrs if 'coupons/delete' in x.url]
    expect(len(r) == 1 and r[0].response().status == 200 and token_ok(csrf_of(r[0].all_headers()), token), 'coupon delete request')
    expect(json.loads(r[0].response().text()).get('status') == 'success', 'coupon delete response ' + r[0].response().text()[:120])
    swal_confirm(pg)
    pg.wait_for_load_state('networkidle')
    expect(all(pg.locator('tr', has_text=c).count() == 0 for c in codes), 'coupon fixtures still listed')
    notes.append('coupon bulk delete: 1 XHR, 200 success, exact token, 2 lab fixtures removed')
    # vouchers: generated through the voucher form (which itself uses the restored autoload/server + autoload/plan)
    prefix = 'HFX%s-' % str(int(time.time()))[-5:]
    page_ready(pg, pg.goto(ROOT + '?_route=plan/add-voucher', wait_until='networkidle'))
    pg.locator('input[type=radio]#Hot').check(force=True)
    pg.wait_for_function("() => !!document.querySelector('#server option[value=radius]')", timeout=10000)
    pg.select_option('#server', 'radius', force=True)
    pg.wait_for_function("() => document.querySelectorAll('#plan option').length > 1", timeout=10000)
    first_plan = pg.evaluate("() => Array.from(document.querySelectorAll('#plan option')).map(o => o.value).filter(Boolean)[0]")
    pg.select_option('#plan', first_plan, force=True)
    pg.fill('#nxf-numbervoucher', '2')
    pg.fill('#nxf-prefix', prefix)
    pg.fill('#nxf-lengthcode', '8')
    if pg.locator('#print_now').is_checked():
        pg.locator('#print_now').uncheck(force=True)
    pg.locator('form[action*="plan/voucher-post"] button[type=submit] >> visible=true').first.click()
    with pg.expect_navigation():
        pg.click('[data-nuxr-ask-ok]')  # the WP6C confirmation sheet of the voucher form
    pg.wait_for_load_state('networkidle')
    page_ready(pg, pg.goto(ROOT + '?_route=plan/voucher&search=' + prefix, wait_until='networkidle'))
    boxes = pg.locator('tr', has_text=prefix).locator('input[name="voucher_ids[]"]')
    expect(boxes.count() == 2, f'voucher fixtures listed: {boxes.count()}')
    for i in range(boxes.count()):
        boxes.nth(i).check(force=True)
    pg.locator('#deleteSelectedVouchers').click()
    swal_confirm(pg)
    pg.wait_for_selector('.swal2-title:has-text("Deleted")', timeout=10000)
    r = [x for x in xhrs if 'voucher-delete-many' in x.url]
    expect(len(r) == 1 and r[0].response().status == 200 and token_ok(csrf_of(r[0].all_headers()), token), 'voucher delete request')
    body = json.loads(r[0].response().text())
    expect(body.get('status') == 'success' and body.get('skipped') == 0, 'voucher delete response ' + str(body))
    swal_confirm(pg)
    page_ready(pg, pg.goto(ROOT + '?_route=plan/voucher&search=' + prefix, wait_until='networkidle'))
    expect(pg.locator('tr', has_text=prefix).count() == 0, 'voucher fixtures still listed')
    notes.append('voucher bulk delete: generated 2 lab vouchers via the restored autoload paths, 1 XHR, 200 success (0 skipped), exact token, removed')
    # bulk message: TEST mode only (no message is delivered); the whole lab customer set
    page_ready(pg, pg.goto(ROOT + '?_route=message/send_bulk', wait_until='networkidle'))
    pg.select_option('#group', 'all')
    pg.select_option('#service', 'all')
    pg.select_option('#via', 'sms')
    pg.fill('#message', 'hotfix lab test [[name]]')
    pg.locator('#test').check(force=True)
    expect(pg.locator('#test').is_checked(), 'test mode not set')
    pg.locator('#startBulk').click()
    pg.wait_for_function("() => /All batches sent|Error/.test(document.getElementById('status').textContent)", timeout=30000)
    r = [x for x in xhrs if 'send_bulk_ajax' in x.url]
    expect(r and all(x.response().status == 200 and token_ok(csrf_of(x.all_headers()), token) for x in r), 'bulk message requests')
    expect(all('test=on' in (x.post_data or '') for x in r), 'bulk message was not in TEST mode')
    payload = json.loads(r[0].response().text())
    statuses = {b.get('status') for b in payload.get('batchStatus', [])} if isinstance(payload.get('batchStatus'), list) else set()
    expect(payload.get('status') == 'success' and statuses <= {'Test Mode', 'No Phone Number'}, f'bulk message response {str(payload)[:200]}')
    # the legacy page has two id="status" elements; its jQuery writes to the first
    expect('All batches sent' in pg.evaluate("() => document.getElementById('status').textContent"), 'bulk message UI did not complete')
    notes.append(f'bulk message TEST mode: {len(r)} XHR(s), 200 success, exact token, statuses {sorted(statuses)} (nothing delivered)')
    # pre-existing upstream legacy errors, unrelated to XHR: coupons/list calls $j('#datatable').DataTable() but never loads
    # DataTables (upstream 2024-12 code); every other page error fails the check
    legacy = [e for e in errors if 'DataTable is not a function' in e]
    other = [e for e in errors if e not in legacy]
    expect(not other, f'page errors {other}')
    notes.append(f'pre-existing legacy page errors (DataTables not loaded on coupons/list): {len(legacy)}; other page errors: 0')
    ctx.close()
    # no privilege expansion: Agent and Sales stay refused by RouteGuard, from the browser transport too
    for user in ('A', 'A1'):
        ctx = new_ctx(browser, ROOT, vp=DESK)
        pg = staff_login(ctx, ROOT, user)
        page_ready(pg, pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle'))
        res = pg.evaluate("""() => Promise.all([
            ['coupons/delete', 'couponIds=[999999]'], ['plan/voucher-delete-many', 'voucherIds=[999999]'],
            ['message/send_bulk_ajax', 'group=all&service=all&via=sms&message=x&test=on']
          ].map(([r, b]) => new Promise(done => { const x = new XMLHttpRequest(); x.open('POST', '?_route=' + r);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); x.onloadend = () => done(r + ':' + x.status); x.send(b); })))""")
        expect(all(x.endswith(':403') for x in res), f'{user}: {res}')
        notes.append(f'{user}: {res} (RouteGuard; token present, still refused)')
        ctx.close()
    return '; '.join(notes)


def main():
    only = set(filter(None, ARGS.only.split(',')))
    global PLAYWRIGHT
    with sync_playwright() as p:
        PLAYWRIGHT = p
        browser = p.chromium.launch()
        for cid, title, fn in CHECKS:
            if only and cid not in only:
                continue
            t0 = time.time()
            try:
                ev = fn(browser)
                RESULTS.append({'id': cid, 'title': title, 'result': 'PASS', 'evidence': ev, 'seconds': round(time.time() - t0, 1)})
            except Exception as e:  # recorded, never hidden
                RESULTS.append({'id': cid, 'title': title, 'result': 'FAIL', 'evidence': f'{type(e).__name__}: {str(e)[:400]}',
                                'trace': traceback.format_exc()[-1200:], 'seconds': round(time.time() - t0, 1)})
            r = RESULTS[-1]
            print(f"{r['id']} {r['result']} | {r['evidence'][:300]}", flush=True)
        browser.close()
    json.dump(RESULTS, open(os.path.join(ARGS.out, 'pwa_suite.json'), 'w'), indent=1, ensure_ascii=False)
    passed = sum(1 for r in RESULTS if r['result'] == 'PASS')
    print(f'PASS {passed} of {len(RESULTS)}')
    sys.exit(0 if passed == len(RESULTS) else 1)


if __name__ == '__main__':
    main()
