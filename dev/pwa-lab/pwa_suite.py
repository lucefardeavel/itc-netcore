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


# ---------------------------------------------------------------- T00 secure context + both installations
@check('T00', 'Secure context at the root and the /billing/ subdirectory origin; staff login works on both')
def t00(browser):
    notes = []
    for base in (ROOT, SUB):
        ctx = new_ctx(browser, base)
        pg = staff_login(ctx, base, 'ad')
        expect(route_of(pg) in ('dashboard', 'admin/post'), 'login landed on ' + pg.url)
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
