#!/usr/bin/env python3
"""WP6B curated candidate visual QA (plan section 24: ~21 captures + 3 tablet probes). Lab only.
Per capture: screenshot, axe-core (WCAG 2 A/AA) serious/critical, page-level horizontal overflow, bottom navigation fully
visible (phone / tablet), <html dir/lang>, external requests, console errors. Every phone page is re-measured at 360 px
(overflow + bottom navigation only)."""
import json, os, sys
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

IP, OUT = sys.argv[1], sys.argv[2]
ONLY = sys.argv[3].split(',') if len(sys.argv) > 3 else None
os.makedirs(OUT, exist_ok=True)
LAB = json.load(open('/srv/phpnuxbill/uxlab/uxlab-ready.json'))
BASE = f'http://{IP}:8099'
AXE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'wp6a', 'axe.min.js')
R = LAB['refs']
VP = {
    'desktop': dict(viewport={'width': 1440, 'height': 900}),
    'tablet': dict(viewport={'width': 820, 'height': 1180}, is_mobile=True, has_touch=True, device_scale_factor=2),
    'phone': dict(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, device_scale_factor=2),
    'phone360': dict(viewport={'width': 360, 'height': 780}, is_mobile=True, has_touch=True, device_scale_factor=2),
}
WALLET_A = 'link:a[href*="finance/wallet/"]'
SHOTS = [
    ('01-desktop-admin-finance-overview', 'ad', 'desktop', 'english', 'finance/overview', []),
    ('02-desktop-admin-wallets', 'ad', 'desktop', 'english', 'finance/wallets', []),
    ('03-desktop-admin-wallet-detail', 'ad', 'desktop', 'english', 'finance/wallets', [WALLET_A]),
    ('04-desktop-admin-funding-review', 'ad', 'desktop', 'english', 'finance/wallets', [WALLET_A, 'link:a[href*="op=fund"]', 'fill:#nuxr-amount|250', 'fill:input[name=reason]|monthly provider top-up', 'submit:form[action*="funding-review"] button[type=submit]']),
    ('05-phone-admin-wallet-corrections-arabic', 'ad', 'phone', 'arabic', 'finance/wallets', [WALLET_A, 'scroll:.nuxr-admin-zone']),
    ('06-desktop-admin-ledger', 'ad', 'desktop', 'english', 'finance/wallets', [WALLET_A, 'link:a[href*="finance/ledger/"]']),
    ('07-phone-agent-wallet-home-arabic', 'A', 'phone', 'arabic', 'finance/overview', []),
    ('08-phone-agent-send-review', 'A', 'phone', 'english', 'finance/send', ['click:input[name=to_id]', 'fill:#nuxr-amount|25', 'fill:#nuxr-reason|weekly top-up', 'submit:form[action*="send-review"] button[type=submit]']),
    ('09-phone-agent-ledger-arabic', 'A', 'phone', 'arabic', 'finance/ledger', []),
    ('10-desktop-admin-transfer-detail', 'ad', 'desktop', 'english', 'finance/transfers', ['link:a[href*="finance/transfer/"]']),
    ('11-desktop-admin-transfer-reverse-form', 'ad', 'desktop', 'english', 'finance/transfers', ['link:a[href*="finance/transfer/"]', 'link:a[href*="finance/reverse/"]']),
    ('12-desktop-admin-pricing-plans', 'ad', 'desktop', 'english', 'pricing/plans', []),
    ('13-desktop-admin-pricing-matrix', 'ad', 'desktop', 'english', 'pricing/plans', ['link:a[href*="pricing/plan/"]']),
    ('14-phone-agent-pricing-arabic', 'A', 'phone', 'arabic', 'pricing/plans', []),
    ('15-desktop-agent-commissions', 'A', 'desktop', 'english', 'finance/commissions', []),
    ('16-desktop-admin-provider-settlement', 'ad', 'desktop', 'english', 'finance/settlement', []),
    ('17-phone-agent-settlement-card-arabic', 'A', 'phone', 'arabic', f'activation/view/{R["karrar"]}', ['scroll:#nuxr-settle-t']),
    ('18-desktop-admin-operations-overview', 'ad', 'desktop', 'english', 'operations/overview', []),
    ('19-desktop-admin-operations-activations', 'ad', 'desktop', 'english', 'operations/activations', []),
    ('20-phone-admin-stale-cancel-dialog', 'ad', 'phone', 'english', f'activation/view/{R["stale"]}', ['click:[data-nuxr-open=nuxr-cancel-dialog]']),
    ('21-phone-agent-reseller-tree-arabic', 'A', 'phone', 'arabic', 'reseller/tree', []),
    ('22-desktop-admin-reseller-detail', 'ad', 'desktop', 'english', 'reseller/tree', ['link:a[href*="reseller/detail/"]']),
    ('23-phone-admin-dashboard-arabic', 'ad', 'phone', 'arabic', 'dashboard', []),
    ('24-phone-agent-dashboard-quick-sheet', 'A', 'phone', 'english', 'dashboard', ['click:[data-nuxr-open=nuxr-quick-sheet]']),
    ('25-phone-report-dashboard', 'rp', 'phone', 'english', 'dashboard', []),
    ('26-phone-sales-more-sheet-arabic', 'A1', 'phone', 'arabic', 'dashboard', ['click:[data-nuxr-open=nuxr-more-sheet]']),
    ('t1-tablet-agent-wallet-arabic', 'A', 'tablet', 'arabic', 'finance/overview', []),
    ('t2-tablet-admin-pricing-matrix', 'ad', 'tablet', 'english', 'pricing/plans', ['link:a[href*="pricing/plan/"]']),
    ('t3-tablet-admin-operations', 'ad', 'tablet', 'english', 'operations/activations', []),
]

CHECK = """() => {
  const vw = innerWidth, de = document.documentElement;
  const nav = document.querySelector('.nuxr-bottomnav');
  let navOk = null;
  if (nav && getComputedStyle(nav).display !== 'none') {
    const items = [...nav.querySelectorAll('a, button')].filter(e => e.getBoundingClientRect().width > 0);
    navOk = items.length > 0 && items.every(e => { const r = e.getBoundingClientRect(); return r.left >= -1 && r.right <= vw + 1; });
  }
  return { dir: de.dir, lang: de.lang, overflow: de.scrollWidth > vw + 1, scrollWidth: de.scrollWidth, vw, bottomnav: navOk };
}"""


def login(ctx, role):
    pg = ctx.new_page()
    pg.goto(BASE + '/?_route=admin')
    pg.fill('input[name=username]', role)
    pg.fill('input[name=password]', LAB['staff'][role])
    with pg.expect_navigation():
        pg.click('button[type=submit]')
    return pg


def steps_run(pg, steps):
    for st in steps:
        kind, arg = st.split(':', 1)
        if kind == 'link':
            href = pg.locator(arg).first.get_attribute('href')
            pg.goto(href if href.startswith('http') else BASE + '/' + href.lstrip('/'), wait_until='networkidle')
        elif kind == 'click':
            pg.locator(arg + ' >> visible=true').first.click(); pg.wait_for_timeout(600)
        elif kind == 'fill':
            sel, val = arg.split('|', 1); pg.locator(sel + ' >> visible=true').first.fill(val)
        elif kind == 'submit':
            with pg.expect_navigation():
                pg.locator(arg + ' >> visible=true').first.click()
            pg.wait_for_load_state('networkidle')
        elif kind == 'scroll':
            loc = pg.locator(arg).first
            if loc.count():
                loc.scroll_into_view_if_needed(); pg.wait_for_timeout(300)


def run():
    out = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for name, role, vp, lang, route, steps in SHOTS:
            if ONLY and not any(name.startswith(o) for o in ONLY):
                continue
            ctx = browser.new_context(**VP[vp])
            ctx.add_cookies([{'name': 'user_language', 'value': lang, 'url': BASE}])
            pg = login(ctx, role)
            ext, errs = [], []
            pg.on('request', lambda r: ext.append(r.url[:100]) if urlparse(r.url).hostname not in (IP,) and not r.url.startswith('data:') else None)
            pg.on('console', lambda m: errs.append(m.text[:160]) if m.type == 'error' else None)
            pg.on('pageerror', lambda e: errs.append('JS ' + str(e)[:160]))
            pg.goto(BASE + '/?_route=' + route, wait_until='networkidle')
            try:
                steps_run(pg, steps)
                failed = None
            except Exception as e:  # recorded, never hidden
                failed = str(e)[:200]
            pg.wait_for_timeout(400)
            m = pg.evaluate(CHECK)
            pg.add_script_tag(path=AXE)
            axe = pg.evaluate("""async () => { const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } });
                return r.violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length,
                    sample: v.nodes.slice(0, 2).map(x => x.target.join(' ')) })); }""")
            pg.screenshot(path=os.path.join(OUT, name + '.png'))
            final = pg.url.replace(BASE, '')
            row = {'name': name, 'role': role, 'viewport': vp, 'lang': lang, 'route': route, 'final': final, **m, 'axe': axe,
                   'external': sorted(set(ext)), 'errors': errs[:5], 'step_failure': failed}
            if vp == 'phone':  # the same page at 360 px: overflow + bottom navigation only
                pg.set_viewport_size({'width': 360, 'height': 780}); pg.wait_for_timeout(300)
                m360 = pg.evaluate(CHECK)
                row['at360'] = {'overflow': m360['overflow'], 'scrollWidth': m360['scrollWidth'], 'bottomnav': m360['bottomnav']}
            out.append(row)
            print(name, final[:60], 'dir', m['dir'], 'overflow', m['overflow'], 'nav', m['bottomnav'], '360', row.get('at360'), 'axe', [(a['id'], a['n']) for a in axe],
                  'ext', len(set(ext)), 'err', errs[:2], 'FAIL ' + failed if failed else '')
            ctx.close()
        browser.close()
    json.dump(out, open(os.path.join(OUT, 'visualqa.json'), 'w'), ensure_ascii=False, indent=1)


run()
