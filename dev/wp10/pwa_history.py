#!/usr/bin/env python3
"""WP9-C red-team row #15 (T3, PWA lab only): history navigation after logout never shows the previous user's pages.

Staff A (Agent) opens a customer page and the finance overview, logs out; then the browser goes BACK / FORWARD (online and
offline, with the worker active). The page then shown must not contain A's data (customer name, wallet figures, A's
name): it must be the login / offline document, never a bfcache or HTTP-cache copy. Then staff A1 (Sales) logs in on the
same browser and goes back: still no A content. Also records the Cache-Control of an authenticated page.

Usage: python3 dev/wp10/pwa_history.py --out DIR [--state /srv/phpnuxbill/pwalab]
"""
import json
import os
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'pwa-lab'))
import pwa_suite as P  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

MARKERS = ['محمد عبد الله الحسيني', 'mohammed.h', 'Staff A', 'initial provider funding']


def leaked(pg):
    try:
        text = pg.evaluate('() => document.documentElement.outerHTML')
    except Exception as e:  # navigation in progress
        return [f'unreadable: {e}'[:80]]
    return [m for m in MARKERS if m in text]


def main():
    os.makedirs(P.ARGS.out, exist_ok=True)
    base = P.LAB['root']
    rows = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        ctx = P.new_ctx(browser, base, vp={'viewport': {'width': 1280, 'height': 800}}, lang='english')
        pg = P.staff_login(ctx, base, 'A')
        P.sw_ready(pg)
        resp = pg.goto(base + '?_route=customers/view/%d' % P.LAB['customers']['mohammed'], wait_until='networkidle')
        cache_control = resp.headers.get('cache-control', '') if resp else ''
        before = leaked(pg)
        pg.goto(base + '?_route=finance/overview', wait_until='networkidle')
        rows.append({'step': 'A viewed customer + finance', 'markers_present': before, 'cache_control': cache_control})
        P.logout(pg, base)
        for label, action in [('back x1 online', lambda: pg.go_back(wait_until='load')), ('back x2 online', lambda: pg.go_back(wait_until='load')),
                              ('forward online', lambda: pg.go_forward(wait_until='load'))]:
            action()
            pg.wait_for_timeout(500)
            rows.append({'step': label, 'url': P.route_of(pg), 'leaked': leaked(pg)})
        ctx.set_offline(True)
        for label, action in [('back offline', lambda: pg.go_back(wait_until='load')), ('forward offline', lambda: pg.go_forward(wait_until='load'))]:
            try:
                action()
            except Exception as e:
                rows.append({'step': label, 'error': str(e)[:120]})
            pg.wait_for_timeout(500)
            rows.append({'step': label, 'url': P.route_of(pg), 'leaked': leaked(pg)})
        ctx.set_offline(False)
        # the next user on the same browser
        pg.goto(base + '?_route=admin', wait_until='networkidle')
        pg.fill('input[name=username]', 'A1')
        pg.fill('input[name=password]', P.LAB['staff']['A1'])
        with pg.expect_navigation():
            pg.click('button[type=submit]')
        pg.goto(base + '?_route=dashboard', wait_until='networkidle')
        for i in range(4):
            try:
                pg.go_back(wait_until='load')
            except Exception:
                break
            pg.wait_for_timeout(400)
            rows.append({'step': f'A1 back x{i + 1}', 'url': P.route_of(pg), 'leaked': leaked(pg)})
        ctx.close()
        browser.close()
    bad = [r for r in rows if r.get('leaked')]
    if bad:
        result = 'FAIL'
    elif not rows[0]['markers_present']:
        result = 'INCONCLUSIVE (positive control: the customer page did not show the markers)'
    else:
        result = 'PASS'
    out = {'result': result, 'rows': rows}
    json.dump(out, open(os.path.join(P.ARGS.out, 'pwa_history.json'), 'w'), indent=1, ensure_ascii=False)
    print(json.dumps(out, ensure_ascii=False, indent=1))


if __name__ == '__main__':
    main()
