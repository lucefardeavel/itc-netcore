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


def main():
    only = set(filter(None, ARGS.only.split(',')))
    with sync_playwright() as p:
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
