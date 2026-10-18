#!/usr/bin/env python3
"""ITC NetCore identity: visual and functional check of the surfaces that show the product identity (test tooling only).

Two phases, selected by the environment (the operator's CompanyName is switched in the disposable lab database between runs):
  BRAND_PHASE=operator  the lab's own operator name (Arabic) is configured: titles / shell show it, the product appears in
                        the footer, sign-in, About page and the customer footer
  BRAND_PHASE=product   CompanyName = the stock placeholder 'PHPNuxBill': the product name ITC NetCore and the text mark ITC
                        are shown in its place
For each phase: staff sign-in, dashboard (footer), About page and customer sign-in, Arabic and English, desktop and phone.
Per capture: required / forbidden texts, page title, brand mark, horizontal overflow, axe-core wcag2a/aa serious + critical
(whole page, and those on the identity elements), console errors. Plus the PWA manifest name.

Usage: BRAND_PHASE=operator|product python3 dev/pwa-lab/brand_visual.py --out DIR --axe axe.min.js [--state ...]
"""
import json
import os
import sys
import urllib.request

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pwa_suite as P  # noqa: E402  (shared lab helpers; parses --out / --axe / --state)
from playwright.sync_api import sync_playwright  # noqa: E402

PHASE = os.environ.get('BRAND_PHASE', 'operator')
OPERATOR = 'شبكة النور للإنترنت'
DESKTOP = dict(viewport={'width': 1366, 'height': 900})
ROOT = P.LAB['root']
ROWS = []
IDENTITY = '.itc-wordmark, .itc-footer-product, .itc-footer-credits, .itc-about, .itc-powered, .nuxr-brand, .nuxr-login-brand, .itc-login-product, .main-footer, .itc-sheet-product'
AXE_IDENTITY = """async (sel) => { const r = await axe.run({ include: Array.from(document.querySelectorAll(sel)) }, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] }, resultTypes: ['violations'] });
  return r.violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => v.id + ' x' + v.nodes.length + ' ' + v.nodes.slice(0, 2).map(n => n.target.join(' ')).join(' | ')); }"""
PROBE = """() => ({ overflow: document.documentElement.scrollWidth > window.innerWidth + 1, dir: document.documentElement.dir || '',
  clipped: Array.from(document.querySelectorAll('.itc-about, .itc-about *, .main-footer, .main-footer *, .itc-login-product, .itc-powered, .nuxr-brand'))
    .filter(e => e.offsetParent !== null && e.scrollWidth > e.clientWidth + 1 && getComputedStyle(e).overflowX !== 'visible' && !e.classList.contains('nuxr-brand-name'))
    .map(e => e.tagName + '.' + (e.className || '')),
  title: document.title, text: document.body.innerText, mark: (document.querySelector('.nuxr-brand-mark') || {}).textContent || '',
  footer: (document.querySelector('.main-footer') || {}).innerText || '',
  sheet: (document.querySelector('#nuxr-more-sheet[open]') || {}).innerText || '' })"""

company = 'ITC NetCore' if PHASE == 'product' else OPERATOR
TAGLINE = {'english': 'ISP Management & Billing Platform', 'arabic': 'منصة إدارة وفوترة مزوّدي خدمة الإنترنت'}
MAINTAINED = {'english': 'Developed and maintained by', 'arabic': 'تطوير وصيانة'}


def page_checks(surface, lang, probe):
    must, never = [], ['PHPNuxBill by', 'Update Now']
    if surface == 'staff-login':
        must = [company, 'ITC', 'NetCore', TAGLINE[lang], 'V1.0']
    elif surface == 'dashboard':
        must = ['ITC NetCore', 'V1.0', TAGLINE[lang], MAINTAINED[lang], 'PHPNuxBill', 'iBNuX', 'GNU GPL v2', 'AdminLTE']
    elif surface == 'about':
        must = ['ITC NetCore', 'V1.0', TAGLINE[lang], MAINTAINED[lang], 'PHPNuxBill', 'iBNuX', 'GNU GPL v2', 'LICENSE', 'NOTICE']
    elif surface == 'more-sheet':
        must = ['ITC NetCore V1.0']
    elif surface == 'customer-login':
        must = [company, 'ITC NetCore']
    text = probe['footer'] if surface == 'dashboard' else probe['sheet'] if surface == 'more-sheet' else probe['text']
    missing = [m for m in must if m not in text]
    present = [n for n in never if n in probe['text']]
    title_ok = probe['title'].endswith(company)
    mark_ok = True
    if surface in ('dashboard', 'about'):
        mark_ok = probe['mark'].strip() == ('ITC' if PHASE == 'product' else OPERATOR[0])
    return missing, present, title_ok, mark_ok


def capture(pg, name, surface, lang, errors, full=True):
    pg.add_script_tag(path=P.ARGS.axe)
    axe_all = pg.evaluate(P.AXE_RUN)
    axe_id = pg.evaluate(AXE_IDENTITY, IDENTITY)
    probe = pg.evaluate(PROBE)
    pg.screenshot(path=os.path.join(P.ARGS.out, name), full_page=full)
    missing, present, title_ok, mark_ok = page_checks(surface, lang, probe)
    ROWS.append({'capture': name, 'phase': PHASE, 'surface': surface, 'lang': lang, 'dir': probe['dir'], 'title': probe['title'],
                 'title_ok': title_ok, 'mark': probe['mark'].strip(), 'mark_ok': mark_ok, 'missing_text': missing, 'forbidden_text': present,
                 'overflow': probe['overflow'], 'clipped': probe['clipped'], 'axe_identity': axe_id, 'axe_page_serious_critical': axe_all, 'console_errors': list(errors)})
    errors.clear()


def manifest():
    with urllib.request.urlopen(ROOT + '?_route=pwa/manifest', timeout=20) as r:
        m = json.loads(r.read().decode('utf-8'))
    ok = m['name'] == company and m['short_name'] == (company if len(company) <= 12 else company[:12].rstrip())
    ROWS.append({'capture': '(manifest)', 'phase': PHASE, 'surface': 'pwa-manifest', 'name': m['name'], 'short_name': m['short_name'], 'ok': ok})


def main():
    os.makedirs(P.ARGS.out, exist_ok=True)
    manifest()
    with sync_playwright() as p:
        browser = p.chromium.launch()
        try:
            for lang in ('arabic', 'english'):
                for vname, vp in (('desk', DESKTOP), ('phone', P.PHONE)):
                    err = []
                    ctx = P.new_ctx(browser, ROOT, vp=vp, lang=lang)
                    pg = ctx.new_page()
                    pg.on('console', lambda m: err.append(m.text[:140]) if m.type == 'error' else None)
                    pg.goto(ROOT + '?_route=admin', wait_until='networkidle')
                    capture(pg, f'B-{PHASE}-{lang}-{vname}-staff-login.png', 'staff-login', lang, err)
                    pg.goto(ROOT + '?_route=login', wait_until='networkidle')
                    capture(pg, f'B-{PHASE}-{lang}-{vname}-customer-login.png', 'customer-login', lang, err)
                    pg.close()
                    pg = P.staff_login(ctx, ROOT, 'ad')
                    pg.on('console', lambda m: err.append(m.text[:140]) if m.type == 'error' else None)
                    capture(pg, f'B-{PHASE}-{lang}-{vname}-dashboard.png', 'dashboard', lang, err)
                    if vname == 'phone':
                        # the footer is hidden on phones (WP6A): the product line sits at the end of the More sheet
                        pg.locator('[data-nuxr-open="nuxr-more-sheet"]:visible').first.click()
                        pg.wait_for_selector('#nuxr-more-sheet[open] .itc-sheet-product')
                        capture(pg, f'B-{PHASE}-{lang}-{vname}-more-sheet.png', 'more-sheet', lang, err, full=False)
                        pg.keyboard.press('Escape')
                    pg.goto(ROOT + '?_route=community', wait_until='networkidle')
                    capture(pg, f'B-{PHASE}-{lang}-{vname}-about.png', 'about', lang, err)
                    ctx.close()
        finally:
            browser.close()
    json.dump(ROWS, open(os.path.join(P.ARGS.out, f'brand_visual-{PHASE}.json'), 'w'), indent=1, ensure_ascii=False)
    bad = [r for r in ROWS if (r['surface'] == 'pwa-manifest' and not r['ok']) or (r['surface'] != 'pwa-manifest' and (
        r['missing_text'] or r['forbidden_text'] or not r['title_ok'] or not r['mark_ok'] or r['overflow'] or r['clipped'] or r['axe_identity']))]
    for r in ROWS:
        print(json.dumps({k: v for k, v in r.items() if k not in ('title',)}, ensure_ascii=False))
    print('BRAND', PHASE, 'CLEAN' if not bad else 'FINDINGS %d' % len(bad))
    sys.exit(0 if not bad else 1)


if __name__ == '__main__':
    main()
