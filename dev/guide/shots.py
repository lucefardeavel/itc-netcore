"""Guide screenshots from the isolated PWA lab (synthetic demo data). Usage: SPEC=screens.json python3 shots.py x OUTDIR"""
import json, sys
import os
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WORK = os.environ.get('GUIDE_WORK', '/tmp/itc-guide')
sys.argv = [sys.argv[0], '--out', sys.argv[2], '--axe', '/dev/null'] + sys.argv[3:]  # pwa_suite parses argv
SPEC = json.load(open(os.environ['SPEC']))
sys.path.insert(0, os.path.join(REPO, 'dev', 'pwa-lab'))
sys.dont_write_bytecode = True
import pwa_suite as P
from playwright.sync_api import sync_playwright
ROOT = P.LAB['root']
P.LAB['staff'] = json.load(open(os.path.join(WORK, 'staff.json')))
VP = {'desk': dict(viewport={'width': 1440, 'height': 900}, device_scale_factor=1),
      'phone': dict(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, device_scale_factor=2)}
out = P.ARGS.out; os.makedirs(out, exist_ok=True)
with sync_playwright() as p:
    b = p.chromium.launch()
    for job in SPEC:
        ctx = P.new_ctx(b, ROOT, vp=VP[job.get('vp', 'desk')], lang=job.get('lang', 'arabic'))
        if job.get('user'):
            pg = P.staff_login(ctx, ROOT, job['user'])
        elif job.get('customer'):
            pg = ctx.new_page()
            pg.goto(ROOT + '?_route=login', wait_until='networkidle')
            pg.fill('input[name=username]', job['customer'][0]); pg.fill('input[name=password]', job['customer'][1])
            with pg.expect_navigation():
                pg.locator('form[action*="login/post"] button[type=submit]').first.click()
            pg.wait_for_load_state('networkidle')
        else:
            pg = ctx.new_page()
        for s in job['shots']:
            pg.goto(ROOT + '?_route=' + s['route'], wait_until='networkidle')
            for act in s.get('do', []):
                if act[0] == 'click': pg.locator(act[1]).first.click()
                elif act[0] == 'fill': pg.fill(act[1], act[2])
                elif act[0] == 'wait': pg.wait_for_timeout(act[1])
                elif act[0] == 'eval': pg.evaluate(act[1])
                elif act[0] == 'submit':
                    with pg.expect_navigation(timeout=60000):
                        pg.locator(act[1]).first.click()
                    pg.wait_for_load_state('networkidle')
                elif act[0] == 'goto': pg.goto(ROOT + '?_route=' + act[1], wait_until='networkidle')
            pg.wait_for_timeout(s.get('settle', 300))
            # booklet framing: the image ends above the application footer (the page itself is unchanged)
            full = s.get('full', False)
            ft = pg.evaluate("() => { const f = document.querySelector('.main-footer'); if (!f || f.offsetParent === null) return null; return f.getBoundingClientRect().top + scrollY; }")
            vw, vh = pg.viewport_size['width'], pg.viewport_size['height']
            if full:
                total = pg.evaluate('() => document.documentElement.scrollHeight')
                h = int(min(total, ft if ft else total, s.get('maxh', 100000)))
                pg.screenshot(path=os.path.join(out, s['name'] + '.png'), full_page=True, clip={'x': 0, 'y': 0, 'width': vw, 'height': h})
            elif ft and ft < vh:
                pg.screenshot(path=os.path.join(out, s['name'] + '.png'), clip={'x': 0, 'y': 0, 'width': vw, 'height': int(ft)})
            else:
                pg.screenshot(path=os.path.join(out, s['name'] + '.png'))
            print('shot', s['name'], pg.url.split('_route=')[-1][:60], '|', pg.title()[:60])
        ctx.close()
    b.close()
