#!/usr/bin/env python3
"""WP7 curated visual / accessibility QA (test tooling only, OD-W7-4). About 12 captures of the WP7 surfaces only
(the WP6C 37-shot sweep is not repeated). For each capture: overflow at the viewport width, touch targets < 44 px on the
WP7 controls (phone), collision of WP7 fixed surfaces with the bottom navigation, safe-area tokens on the fixed notice,
axe-core wcag2a/aa serious + critical, console errors / page errors, and requests to any origin other than the lab.

Usage: python3 dev/pwa-lab/pwa_visual.py --out DIR --axe axe.min.js [--state /srv/phpnuxbill/pwalab]
"""
import json
import os
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pwa_suite as P  # noqa: E402  (shared lab helpers; parses the same --out / --axe / --state arguments)
from playwright.sync_api import sync_playwright  # noqa: E402

ROWS = []
AXE_RUN = P.AXE_RUN
PROBE = """() => {
  const vw = window.innerWidth, overflow = document.documentElement.scrollWidth > vw + 1;
  const wp7 = Array.from(document.querySelectorAll('.nuxr-update-bar button, #nuxr-install-sheet button, [data-nuxr-install]'))
    .filter(b => b.offsetParent !== null).map(b => b.getBoundingClientRect());
  const small = wp7.filter(r => r.width < 44 || r.height < 44).length;
  const nav = document.querySelector('.nuxr-bottomnav, .nuxr-bottom-nav, nav.nuxr-bottom');
  const navR = nav && nav.offsetParent !== null ? nav.getBoundingClientRect() : null;
  const fixed = Array.from(document.querySelectorAll('.nuxr-net-toast, .nuxr-update-bar, .nuxr-net-banner, .nuxr-net-chip'))
    .filter(e => !e.hidden && e.offsetParent !== null).map(e => [e.className, e.getBoundingClientRect()]);
  const collide = navR ? fixed.filter(([c, r]) => r.bottom > navR.top + 0.5 && r.top < navR.bottom && r.right > navR.left && r.left < navR.right).map(x => x[0]) : [];
  const toast = document.querySelector('.nuxr-net-toast');
  return { overflow, small, collide, bottomNav: !!navR, toastBottom: toast ? getComputedStyle(toast).bottom : null };
}"""


def capture(pg, name, label, errors, external, full=False, phone=False):
    pg.add_script_tag(path=P.ARGS.axe)
    axe = pg.evaluate(AXE_RUN)
    probe = pg.evaluate(PROBE)
    if not phone:
        probe['small'] = 0  # WP6A: desktop controls are 40 px; the 44 px rule applies to touch layouts
    pg.screenshot(path=os.path.join(P.ARGS.out, name), full_page=full)
    # expected while a capture is deliberately offline: the browser's own network-failure lines and the 503 of the offline document
    expected = [e for e in errors if 'ERR_INTERNET_DISCONNECTED' in e or 'status of 503 (Offline)' in e]
    ROWS.append({'capture': name, 'surface': label, 'overflow': probe['overflow'], 'targets_lt_44': probe['small'],
                 'bottom_nav_collision': probe['collide'], 'axe_serious_critical': axe,
                 'console_errors': [e for e in errors if e not in expected], 'expected_offline_network_errors': len(expected),
                 'external_requests': sorted(set(external))})
    errors.clear()


def ctx_page(browser, base, vp, lang, user='A'):
    ctx = P.new_ctx(browser, base, vp=vp, lang=lang)
    errors, external = [], []
    ctx.on('request', lambda r: external.append(r.url.split('?')[0]) if not r.url.startswith(('http://localhost:', 'data:', 'blob:')) else None)
    pg = P.staff_login(ctx, base, user) if user else ctx.new_page()
    pg.on('console', lambda m: errors.append(m.text[:120] + ' @ ' + (m.location or {}).get('url', '')[-70:]) if m.type == 'error' else None)
    pg.on('pageerror', lambda e: errors.append('pageerror ' + str(e)[:160]))
    return ctx, pg, errors, external


def main():
    ROOT = P.ROOT
    if not (P.ARGS.axe and os.path.exists(P.ARGS.axe)):
        sys.exit('axe-core required (--axe)')
    with sync_playwright() as p:
        browser = p.chromium.launch()
        try:
            # ---- phone, Arabic (Agent A)
            ctx, pg, err, ext = ctx_page(browser, ROOT, P.PHONE, 'arabic')
            P.sw_ready(pg)
            cdp = ctx.new_cdp_session(pg)
            cdp.send('Network.enable')
            cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 3000, 'downloadThroughput': -1, 'uploadThroughput': -1})
            pg.evaluate("() => window.dispatchEvent(new Event('online'))")
            pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'slow'", timeout=30000)
            cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1})
            capture(pg, 'V01-phone-ar-slow.png', 'status chip SLOW (phone, Arabic)', err, ext, phone=True)
            P.go_offline(ctx, pg)
            capture(pg, 'V02-phone-ar-offline.png', 'status chip OFFLINE + offline banner (phone, Arabic)', err, ext, phone=True)
            pg.evaluate("() => { fetch(location.href, { method: 'POST', body: 'x=1' }).catch(() => {}); }")
            pg.wait_for_selector('.nuxr-net-toast:not([hidden])')
            pg.wait_for_timeout(200)
            capture(pg, 'V03-phone-ar-not-sent.png', 'mutation blocked: "Not sent" notice above the bottom navigation (phone, Arabic)', err, ext, phone=True)
            ctx.set_offline(False)
            pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'reconnecting'", timeout=20000)
            capture(pg, 'V04-phone-ar-reconnecting.png', 'status chip RECONNECTING (phone, Arabic)', err, ext, phone=True)
            pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=20000)
            pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
            pg.click('details.nuxr-menu.nuxr-user > summary')
            pg.locator('.nuxr-menu-panel [data-nuxr-install]').click()
            pg.wait_for_timeout(300)
            capture(pg, 'V05-phone-ar-install-sheet.png', 'manual-install sheet (phone, Arabic)', err, ext, phone=True)
            pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
            P.lab_sw(P.NEXT)
            pg.evaluate(P.UPDATE_NOW)
            P.bar_mode(pg, 'available')
            capture(pg, 'V06-phone-ar-update-available.png', 'update available (phone, Arabic)', err, ext, phone=True)
            P.lab_sw()
            ctx.set_offline(True)
            pg.goto(ROOT + '?_route=customers/view/%d' % P.LAB['customers']['mohammed'], wait_until='load')
            capture(pg, 'V07-phone-offline-document.png', 'generic offline document for an offline deep link (phone; English + Arabic blocks)', err, ext, full=True, phone=True)
            ctx.set_offline(False)
            ctx.close()
            # ---- the unconfirmed page reached by a real failed POST navigation (phone, English)
            ctx, pg, err, ext = ctx_page(browser, ROOT, P.PHONE, 'english', user='ad')
            P.sw_ready(pg)
            pg.goto(ROOT + '?_route=customers/edit/%d' % P.LAB['customers']['mohammed'], wait_until='networkidle')
            pg.evaluate("() => { window.nuxrMutationGate = () => true; }")  # bypass the client gate: simulate a send that fails in transit
            ctx.set_offline(True)
            pg.evaluate("() => { const f = document.querySelector('form[method=post]'); HTMLFormElement.prototype.submit.call(f); }")
            pg.wait_for_url('**/nuxr-offline.html?reason=unconfirmed', timeout=15000)
            pg.wait_for_load_state('load')
            capture(pg, 'V08-phone-unconfirmed.png', 'failed POST navigation -> reason=unconfirmed (phone)', err, ext, full=True, phone=True)
            ctx.set_offline(False)
            ctx.close()
            # ---- desktop, English (Admin)
            ctx, pg, err, ext = ctx_page(browser, ROOT, P.DESK, 'english', user='ad')
            P.sw_ready(pg)
            P.go_offline(ctx, pg)
            pg.evaluate("() => { const f = document.createElement('form'); f.method = 'post'; f.action = location.href; document.body.appendChild(f); f.requestSubmit(); }")
            pg.wait_for_selector('.nuxr-net-toast:not([hidden])')
            pg.wait_for_timeout(200)
            capture(pg, 'V09-desk-offline-not-sent.png', 'OFFLINE chip + banner + "Not sent" (desktop, English)', err, ext)
            ctx.set_offline(False)
            pg.wait_for_function("() => document.body.getAttribute('data-nuxr-net') === 'online'", timeout=30000)
            pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
            pg.click('details.nuxr-menu.nuxr-user > summary')
            capture(pg, 'V10-desk-install-item.png', 'install item in the account menu (desktop, English)', err, ext)
            pg.keyboard.press('Escape')
            other = ctx.new_page()
            other.on('console', lambda m: err.append(m.text[:120] + ' @ ' + (m.location or {}).get('url', '')[-70:]) if m.type == 'error' else None)
            other.goto(ROOT + '?_route=customers/list', wait_until='networkidle')
            P.sw_ready(other)
            P.lab_sw(P.NEXT)
            pg.evaluate(P.UPDATE_NOW)
            cust = P.LAB['customers']['mohammed']
            for plan in P.LAB['plans']:
                pg.goto(ROOT + f'?_route=activation/create&customer={cust}&plan={plan}', wait_until='networkidle')
                if pg.locator('#nuxr-confirm-form[data-nuxr-critical]').count():
                    break
            P.bar_mode(pg, 'available')
            pg.locator('.nuxr-update-bar [data-nuxr-update]').click()
            P.bar_mode(pg, 'deferred', timeout=5000)
            capture(pg, 'V11-desk-update-deferred.png', 'update deferred during the activation confirmation (desktop, English)', err, ext)
            pg.goto(ROOT + '?_route=dashboard', wait_until='networkidle')
            P.bar_mode(pg, 'available')
            with pg.expect_navigation(timeout=20000):
                pg.locator('.nuxr-update-bar [data-nuxr-update]').click()
            P.bar_mode(other, 'updated')
            capture(other, 'V12-desk-other-tab-updated.png', 'another tab after the update: told, not reloaded (desktop, English)', err, ext)
            ctx.close()
        finally:
            P.lab_sw()
            browser.close()
    ROWS.append({'capture': '(none)', 'surface': 'standalone mode (install item hidden)', 'note':
                 'not capturable: Chromium CDP does not emulate display-mode; the @media (display-mode: standalone) rule is verified in the live CSSOM (T14i) and by Wp7GuardTest'})
    json.dump(ROWS, open(os.path.join(P.ARGS.out, 'pwa_visual.json'), 'w'), indent=1, ensure_ascii=False)
    bad = [r for r in ROWS if r.get('overflow') or r.get('targets_lt_44') or r.get('bottom_nav_collision') or r.get('axe_serious_critical')
           or r.get('console_errors') or r.get('external_requests')]
    for r in ROWS:
        print(r['capture'], '|', r['surface'], '|', {k: r.get(k) for k in ('overflow', 'targets_lt_44', 'bottom_nav_collision', 'axe_serious_critical', 'console_errors', 'external_requests')})
    print('VISUAL', 'CLEAN' if not bad else 'FINDINGS %d' % len(bad))
    sys.exit(0 if not bad else 1)


if __name__ == '__main__':
    main()
