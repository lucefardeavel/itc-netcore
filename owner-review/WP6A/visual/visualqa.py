#!/usr/bin/env python3
"""WP6A curated candidate visual QA (owner OD-K: ~12-18 screenshots). Lab only.
For every capture: screenshot (viewport), axe-core (WCAG 2 A/AA) serious/critical violations, page-level horizontal
overflow, <html dir/lang>, external requests, console errors, touch targets < 44px inside the main content (phone)."""
import json, os, sys
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

IP = sys.argv[1]
OUT = sys.argv[2]
os.makedirs(OUT, exist_ok=True)
LAB = json.load(open('/srv/phpnuxbill/uxlab/uxlab-ready.json'))
BASE = f'http://{IP}:8099'
AXE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'axe.min.js')
C, R, P = LAB['customers'], LAB['refs'], LAB['plans']
VP = {
    'desktop': dict(viewport={'width': 1440, 'height': 900}),
    'phone': dict(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, device_scale_factor=2),
}
NEXT = '[data-nuxr-step]:not([hidden]) [data-nuxr-step-next]'
SHOTS = [
    # name, role, viewport, lang, route, steps
    ('01-desktop-admin-dashboard', 'ad', 'desktop', 'english', 'dashboard', []),
    ('02-desktop-agent-subscribers', 'A', 'desktop', 'english', 'customers/list', []),
    ('03-desktop-admin-subscriber360', 'ad', 'desktop', 'english', f'customers/view/{C["fatima"]}', []),
    ('04-desktop-agent-activation-confirm', 'A', 'desktop', 'english', f'activation/create&customer={C["mohammed"]}&plan={P[0]}', ['scroll:#nuxr-confirm-t']),
    ('05-desktop-admin-activation-reconciliation', 'ad', 'desktop', 'english', f'activation/view/{R["ali"]}', []),
    ('06-desktop-admin-360-arabic-rtl', 'ad', 'desktop', 'arabic', f'customers/view/{C["zainab"]}', []),
    ('07-phone-agent-dashboard-arabic', 'A', 'phone', 'arabic', 'dashboard', []),
    ('08-phone-sales-subscriber-cards', 'A1', 'phone', 'english', 'customers/list', []),
    ('09-phone-agent-360-arabic-sticky-action', 'A', 'phone', 'arabic', f'customers/view/{C["fatima"]}', []),
    ('10-phone-sales-create-step1', 'A1', 'phone', 'english', 'customers/add', []),
    ('11-phone-sales-create-review', 'A1', 'phone', 'english', 'customers/add', ['fill:#fullname=زينب علي', f'click:{NEXT}', 'fill:#username=zainab.ali', 'fill:#password=lab-only-7731', f'click:{NEXT}', f'click:{NEXT}']),
    ('12-phone-sales-confirm-retail-only', 'A1', 'phone', 'english', f'activation/create&customer={C["ali"]}&plan={P[0]}', ['scroll:#nuxr-confirm-t']),
    ('13-phone-agent-activation-status-arabic', 'A', 'phone', 'arabic', f'activation/view/{R["zainab"]}', []),
    ('14-phone-admin-danger-delete-dialog', 'ad', 'phone', 'english', f'customers/view/{C["huda"]}', ['click:[data-nuxr-open=nuxr-delete-dialog]']),
    ('15-phone-report-empty-state', 'rp', 'phone', 'english', 'dashboard', []),
    ('16-phone-agent-search-arabic-name', 'A', 'phone', 'arabic', 'dashboard', ['click:.nuxr-search-open', 'fill:#nuxr-search-sheet-input=فاطمة']),
    ('17-phone-sales-more-sheet', 'A1', 'phone', 'english', 'dashboard', ['click:[data-nuxr-open=nuxr-more-sheet]']),
    ('18-desktop-agent-search-empty-state', 'A', 'desktop', 'english', 'customers/list&filter=All&search=zz-no-match', []),
]

CHECK = """() => {
  const vw = innerWidth, de = document.documentElement;
  const main = document.querySelector('#nuxr-main') || document.body;
  const vis = el => { const r = el.getBoundingClientRect(), s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const targets = [...main.querySelectorAll('a[href], button, input:not([type=hidden]), select, summary')].filter(vis);
  const small = targets.filter(el => { const r = el.getBoundingClientRect(); return (r.height < 44 && r.width < 44); }).map(el => (el.innerText || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30));
  return { dir: de.dir, lang: de.lang, overflow: de.scrollWidth > vw + 1, targets: targets.length, small: small.slice(0, 8), smallCount: small.length };
}"""


def run():
    out = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for name, role, vp, lang, route, steps in SHOTS:
            ctx = browser.new_context(**VP[vp])
            ctx.add_cookies([{'name': 'user_language', 'value': lang, 'url': BASE}])
            pg = ctx.new_page()
            ext, errs = [], []
            pg.on('request', lambda r: ext.append(r.url[:100]) if urlparse(r.url).hostname not in (IP,) and not r.url.startswith('data:') else None)
            pg.on('console', lambda m: errs.append(m.text[:160]) if m.type == 'error' else None)
            pg.on('pageerror', lambda e: errs.append('JS ' + str(e)[:160]))
            pg.goto(BASE + '/?_route=admin')
            pg.fill('input[name=username]', role)
            pg.fill('input[name=password]', LAB['staff'][role])
            with pg.expect_navigation():
                pg.click('button[type=submit]')
            ext.clear(); errs.clear()
            pg.goto(BASE + '/?_route=' + route, wait_until='networkidle')
            for st in steps:
                if st.startswith('click:'):
                    pg.locator(st[6:] + ' >> visible=true').first.click(); pg.wait_for_timeout(700)
                elif st.startswith('fill:'):
                    sel, val = st[5:].split('=', 1); pg.locator(sel + ' >> visible=true').first.fill(val); pg.wait_for_timeout(1000)
                elif st.startswith('scroll:'):
                    pg.locator(st[7:]).scroll_into_view_if_needed(); pg.wait_for_timeout(300)
            pg.wait_for_timeout(400)
            m = pg.evaluate(CHECK)
            pg.add_script_tag(path=AXE)
            axe = pg.evaluate("""async () => { const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } });
                return r.violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length,
                    sample: v.nodes.slice(0, 2).map(x => x.target.join(' ')) })); }""")
            pg.screenshot(path=os.path.join(OUT, name + '.png'))
            out.append({'name': name, 'role': role, 'viewport': vp, 'lang': lang, 'route': route, **m, 'axe': axe, 'external': sorted(set(ext)), 'errors': errs[:5]})
            print(name, 'dir', m['dir'], 'overflow', m['overflow'], 'small<44', m['smallCount'], 'axe', [(a['id'], a['n']) for a in axe], 'ext', len(set(ext)), 'err', len(errs))
            ctx.close()
        browser.close()
    json.dump(out, open(os.path.join(OUT, 'visualqa.json'), 'w'), ensure_ascii=False, indent=1)


run()
