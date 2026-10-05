#!/usr/bin/env python3
"""WP7 PWA browser / security QA suite (test tooling only, owner decision OD-W7-4).

Runs against the disposable PWA lab started by dev/pwa-lab/lab.sh. The origins are http://localhost:18099/ (root
installation) and http://localhost:18098/billing/ (subdirectory installation): potentially trustworthy origins, so the
browser treats them as secure contexts. Every check first asserts window.isSecureContext === true; a check that cannot
prove a secure context FAILS (never skipped). Requires the Python Playwright package and its Chromium on the QA host
only; nothing here is a production dependency.

Usage:
  python3 dev/pwa-lab/pwa_suite.py [--out DIR] [--only T01,T05,...] [--state /srv/phpnuxbill/pwalab]
"""
import argparse
import json
import os
import re
import sys
import time
import traceback

from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument('--out', default='/tmp/pwalab-out')
ap.add_argument('--only', default='')
ap.add_argument('--state', default=os.environ.get('PWALAB_STATE', '/srv/phpnuxbill/pwalab'))
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
