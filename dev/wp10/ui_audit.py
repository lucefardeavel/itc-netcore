#!/usr/bin/env python3
"""WP10 final UI audit (test tooling only; the disposable PWA lab of dev/pwa-lab, 127.0.0.1 only; never production).

For every role and its main pages, in three layouts (desktop English 1366x800, phone Arabic RTL 390x844, tablet Arabic
RTL 820x1180): HTTP status of the page load, horizontal overflow, Arabic pages rendered right-to-left, axe-core
wcag2a/aa serious + critical violations, console / page errors, failed external assets (SRI), and a screenshot.
A role's page that the role may not open is expected to be refused (403 page / redirect), never a 500.

Usage: python3 dev/wp10/ui_audit.py --out DIR --axe axe.min.js [--state /srv/phpnuxbill/pwalab]
"""
import json
import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'pwa-lab'))
import pwa_suite as P  # noqa: E402  (shared lab helpers; parses --out / --axe / --state)
from playwright.sync_api import sync_playwright  # noqa: E402

LAYOUTS = {
    'desktop-en': ({'viewport': {'width': 1366, 'height': 800}}, 'english', False),
    'phone-ar': ({'viewport': {'width': 390, 'height': 844}, 'is_mobile': True, 'has_touch': True, 'device_scale_factor': 2}, 'arabic', True),
    'tablet-ar': ({'viewport': {'width': 820, 'height': 1180}, 'is_mobile': True, 'has_touch': True}, 'arabic', True),
}
ROLE_PAGES = {
    'sa': ['dashboard', 'customers/list', 'customers/add', 'activation/list', 'finance/overview', 'finance/wallets', 'finance/transfers',
           'operations/overview', 'operations/drift', 'operations/held', 'pricing/plans', 'reseller/tree', 'plan/list', 'plan/voucher',
           'services/hotspot', 'services/pppoe', 'settings/app', 'settings/users', 'settings/change-password', 'reports/by-date', 'radius/nas-list', 'logs/list'],
    'ad': ['dashboard', 'customers/list', 'activation/list', 'finance/overview', 'operations/overview', 'settings/users'],
    'A': ['dashboard', 'customers/list', 'customers/add', 'activation/list', 'finance/overview', 'finance/send', 'finance/wallets',
          'pricing/catalog', 'reseller/tree', 'settings/users', 'settings/app'],
    'B': ['dashboard', 'customers/list', 'activation/list', 'finance/overview'],
    'A1': ['dashboard', 'customers/list', 'activation/list', 'finance/overview', 'settings/users'],
    'rp': ['dashboard', 'reports/by-date', 'customers/list'],
}
# pages a role must not open (expected refusal, never 500)
DENIED = {('A', 'settings/app'), ('A1', 'settings/users'), ('A1', 'finance/overview'), ('B', 'settings/app')}

PROBE = """() => {
  const vw = window.innerWidth;
  const overflow = document.documentElement.scrollWidth > vw + 1;
  const dir = (document.documentElement.getAttribute('dir') || document.body.getAttribute('dir') || getComputedStyle(document.body).direction || '').toLowerCase();
  const title = document.title;
  const text = (document.body.innerText || '').slice(0, 4000);
  return { overflow, dir, title, denied: /403|forbidden|not allowed|لا تملك|غير مسموح|Access denied/i.test(text), fatal: /Fatal error|Stack trace|Uncaught|Internal Server Error/i.test(text) };
}"""

ROWS = []


def audit(browser, layout, user, pages):
    vp, lang, rtl = LAYOUTS[layout]
    ctx = P.new_ctx(browser, P.LAB['root'], vp=vp, lang=lang)
    errors, failed, external = [], [], set()
    ctx.on('request', lambda r: external.add(re.sub(r'\?.*', '', r.url)) if not r.url.startswith(('http://localhost:', 'data:', 'blob:')) else None)
    ctx.on('requestfailed', lambda r: failed.append(r.url[:120] + ' ' + (r.failure or '')))
    pg = P.staff_login(ctx, P.LAB['root'], user)
    pg.on('console', lambda m: errors.append(m.text[:140]) if m.type == 'error' else None)
    pg.on('pageerror', lambda e: errors.append('pageerror ' + str(e)[:160]))
    for route in pages:
        errors.clear()
        failed.clear()
        resp = pg.goto(P.LAB['root'] + '?_route=' + route, wait_until='networkidle')
        status = resp.status if resp else 0
        pr = pg.evaluate(PROBE)
        pg.add_script_tag(path=P.ARGS.axe)
        axe = pg.evaluate(P.AXE_RUN)
        shot = f"{layout}-{user}-{route.replace('/', '_')}.png"
        pg.screenshot(path=os.path.join(P.ARGS.out, shot), full_page=False)
        expected_denied = (user, route) in DENIED
        problems = []
        if status >= 500 or pr['fatal']:
            problems.append(f'server error {status}')
        if not expected_denied and status >= 400:
            problems.append(f'HTTP {status}')
        if pr['overflow']:
            problems.append('horizontal overflow')
        if rtl and pr['dir'] != 'rtl':
            problems.append('not RTL: ' + pr['dir'])
        if axe:
            problems.append('axe ' + '; '.join(axe))
        errs = [e for e in errors if 'favicon' not in e]
        if errs:
            problems.append('console ' + ' | '.join(errs[:3]))
        if failed:
            problems.append('failed requests ' + ' | '.join(failed[:3]))
        ROWS.append({'layout': layout, 'user': user, 'route': route, 'status': status, 'final_url': P.route_of(pg), 'rtl': pr['dir'],
                     'expected_denied': expected_denied, 'denied_page': pr['denied'], 'problems': problems, 'screenshot': shot})
    ctx.close()
    return sorted(external)


def customer_audit(browser, layout):
    vp, lang, rtl = LAYOUTS[layout]
    ctx = P.new_ctx(browser, P.LAB['root'], vp=vp, lang=lang)
    pg = ctx.new_page()
    errors = []
    pg.on('console', lambda m: errors.append(m.text[:140]) if m.type == 'error' else None)
    resp = pg.goto(P.LAB['root'] + '?_route=login', wait_until='networkidle')
    pr = pg.evaluate(PROBE)
    pg.add_script_tag(path=P.ARGS.axe)
    axe = pg.evaluate(P.AXE_RUN)
    shot = f'{layout}-customer-login.png'
    pg.screenshot(path=os.path.join(P.ARGS.out, shot))
    problems = ([f'HTTP {resp.status}'] if resp and resp.status >= 400 else []) + (['horizontal overflow'] if pr['overflow'] else []) \
        + (['not RTL: ' + pr['dir']] if rtl and pr['dir'] != 'rtl' else []) + (['axe ' + '; '.join(axe)] if axe else []) + (['console ' + ' | '.join(errors[:3])] if errors else [])
    ROWS.append({'layout': layout, 'user': 'customer', 'route': 'login', 'status': resp.status if resp else 0, 'rtl': pr['dir'], 'problems': problems, 'screenshot': shot})
    ctx.close()


def main():
    os.makedirs(P.ARGS.out, exist_ok=True)
    externals = set()
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for layout in LAYOUTS:
            for user, pages in ROLE_PAGES.items():
                if layout != 'desktop-en' and user not in ('sa', 'A', 'A1'):
                    continue
                externals.update(audit(browser, layout, user, pages))
            customer_audit(browser, layout)
        browser.close()
    bad = [r for r in ROWS if r['problems']]
    summary = {'pages': len(ROWS), 'with_problems': len(bad), 'external_origins': sorted({re.sub(r'^(https?://[^/]+).*', r'\1', u) for u in externals})}
    json.dump({'summary': summary, 'rows': ROWS}, open(os.path.join(P.ARGS.out, 'ui_audit.json'), 'w'), indent=1, ensure_ascii=False)
    print(json.dumps(summary, ensure_ascii=False))
    for r in bad:
        print(f"{r['layout']:10} {r['user']:8} {r['route']:26} {r['status']} {' || '.join(r['problems'])[:400]}")


if __name__ == '__main__':
    main()
