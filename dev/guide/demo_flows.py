"""Booklet demo activity through the application's own screens (isolated lab, synthetic data). Captures the form shots."""
import json, sys
import os
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WORK = os.environ.get('GUIDE_WORK', '/tmp/itc-guide')
sys.argv = [sys.argv[0], '--out', os.path.join(WORK, 'flows'), '--axe', '/dev/null']
sys.path.insert(0, os.path.join(REPO, 'dev', 'pwa-lab')); sys.dont_write_bytecode = True
import pwa_suite as P
from playwright.sync_api import sync_playwright
P.LAB['staff'] = json.load(open(os.path.join(WORK, 'staff.json')))
ROOT = P.LAB['root']; out = P.ARGS.out; os.makedirs(out, exist_ok=True)
DESK = dict(viewport={'width': 1440, 'height': 900})


def shot(pg, name, full=False):
    pg.screenshot(path=f'{out}/{name}.png', full_page=full)


def confirm_dialog(pg):
    """the application's own confirmation sheet (or SweetAlert) after a submit"""
    pg.wait_for_timeout(800)
    btn = pg.locator('dialog[open] button:has-text("تأكيد"):visible, .swal2-confirm:visible')
    if btn.count():
        with pg.expect_navigation(timeout=60000):
            btn.first.click()
    pg.wait_for_load_state('networkidle')


def activate(pg, cust, plan, name=None):
    pg.goto(ROOT + f'?_route=activation/create&customer={cust}&plan={plan}', wait_until='networkidle')
    if name:
        shot(pg, name, True)
    with pg.expect_navigation(timeout=60000):
        pg.locator('#nuxr-confirm-form button[type=submit]').first.click()
    pg.wait_for_load_state('networkidle')
    print('activation', cust, plan, '->', pg.url.split('_route=')[-1])


def review_confirm(pg, name):
    shot(pg, name + '-review', True)
    t = pg.locator('#nuxr-typed')
    if t.count():
        t.fill(t.get_attribute('data-nuxr-match'))
    with pg.expect_navigation(timeout=60000):
        pg.locator('form[data-nuxr-critical] button[type=submit]').first.click()
    pg.wait_for_load_state('networkidle')
    print(name, '->', pg.url.split('_route=')[-1])


with sync_playwright() as p:
    b = p.chromium.launch()
    # agent and sub-agent activations, the sales rep activates for his assigned customer
    for user, jobs in (('rafidain', [(1, 2), (2, 2), (3, 2)]), ('haider', [(10, 3)]), ('karrada', [(4, 2), (11, 2)])):
        ctx = P.new_ctx(b, ROOT, vp=DESK, lang='arabic'); pg = P.staff_login(ctx, ROOT, user)
        for c, pl in jobs:
            activate(pg, c, pl)
        ctx.close()
    ctx = P.new_ctx(b, ROOT, vp=DESK, lang='arabic'); pg = P.staff_login(ctx, ROOT, 'ali.admin')
    # a 50 GB data-quota plan
    pg.goto(ROOT + '?_route=services/add', wait_until='networkidle')
    pg.fill('#name', 'هوتسبوت 50 غيغا - 30 يوم')
    pg.check('input[name=radius]'); pg.wait_for_timeout(400)
    pg.select_option('#device', 'Radius')
    pg.check('#Limited'); pg.wait_for_timeout(200); pg.check('#Data_Limit'); pg.wait_for_timeout(200)
    pg.fill('#data_limit', '50'); pg.select_option('#data_unit', 'GB')
    opts = pg.eval_on_selector_all('select[name=id_bw] option', 'os => os.map(o => o.value)')
    pg.select_option('select[name=id_bw]', opts[-1])
    pg.fill('input[name=price]', '25'); pg.fill('input[name=validity]', '30'); pg.select_option('select[name=validity_unit]', 'Days')
    shot(pg, 'plan-add', True)
    pg.locator('form[action*="services/add-post"] button[type=submit]').first.click()
    confirm_dialog(pg)
    qplan = pg.url.rstrip('/').split('/')[-1]
    print('quota plan', qplan)
    # pricing: base cost, then reseller commerce on
    pg.goto(ROOT + '?_route=pricing/plans', wait_until='networkidle')
    pg.locator(f'[data-nuxr-open="nuxr-pb-{qplan}"]').first.click(); pg.wait_for_selector(f'#nuxr-pb-{qplan}[open]')
    pg.fill(f'#nuxr-b-{qplan}', '15'); pg.fill(f'#nuxr-br-{qplan}', 'تسعير باقة 50 غيغا')
    shot(pg, 'pricing-sheet')
    pg.locator(f'#nuxr-pb-{qplan} form[action*="base-cost-post"] button[type=submit]').first.click(); confirm_dialog(pg)
    pg.goto(ROOT + '?_route=pricing/plans', wait_until='networkidle')
    pg.locator(f'[data-nuxr-open="nuxr-pb-{qplan}"]').first.click(); pg.wait_for_selector(f'#nuxr-pb-{qplan}[open]')
    pg.locator(f'#nuxr-pb-{qplan} form[action*="resale-post"] button[type=submit]').first.click(); confirm_dialog(pg)
    # provider funding of the agent wallet
    pg.goto(ROOT + '?_route=finance/funding/5&op=fund', wait_until='networkidle')
    pg.fill('#nuxr-amount', '500'); pg.fill('#nuxr-reason', 'تمويل شهري - إيداع بنكي'); pg.fill('#nuxr-ext', 'BANK-2026-1018')
    shot(pg, 'funding-form', True)
    with pg.expect_navigation():
        pg.locator('form[action*="funding-review"] button[type=submit]').first.click()
    pg.wait_for_load_state('networkidle')
    review_confirm(pg, 'funding')
    # a NAS (documentation address, never contacted)
    pg.goto(ROOT + '?_route=radius/nas-add', wait_until='networkidle')
    for k, v in {'shortname': 'NAS-Karrada', 'nasname': '192.0.2.10', 'secret': 'lab-demo-only', 'ports': '3799', 'description': 'راوتر فرع الكرادة'}.items():
        pg.fill(f'[name={k}]', v)
    shot(pg, 'nas-form', True)
    pg.locator('form[action*="nas-add-post"] button[type=submit]').first.click(); confirm_dialog(pg)
    print('nas ->', pg.url.split('_route=')[-1])
    # vouchers for the Hotspot plan (RADIUS)
    pg.goto(ROOT + '?_route=plan/add-voucher', wait_until='networkidle')
    pg.check('#Hot'); pg.wait_for_timeout(1200)
    print('servers', pg.eval_on_selector_all('#server option', 'os => os.map(o => [o.value, o.textContent.trim()])'))
    pg.evaluate("() => { const s = document.getElementById('server'); s.value = 'radius'; $(s).trigger('change'); }")
    pg.wait_for_timeout(1500)
    plans = pg.eval_on_selector_all('#plan option', 'os => os.map(o => [o.value, o.textContent.trim()])'); print('plans', plans)
    hp = [v for v, t in plans if 'هوتسبوت 4' in t]
    if hp:
        pg.evaluate(f"() => {{ const s = document.getElementById('plan'); s.value = '{hp[0]}'; $(s).trigger('change'); }}")
        pg.fill('#nxf-numbervoucher', '6'); pg.fill('#nxf-prefix', 'NUR-')
        shot(pg, 'voucher-form', True)
        pg.locator('form[action*="voucher-post"] button[type=submit]').first.click(); confirm_dialog(pg)
        print('vouchers ->', pg.url.split('_route=')[-1])
    ctx.close()
    # the agent changes a plan and sends credit to a sub-agent
    ctx = P.new_ctx(b, ROOT, vp=DESK, lang='arabic'); pg = P.staff_login(ctx, ROOT, 'rafidain')
    activate(pg, 2, qplan, 'change-plan-preview')
    pg.goto(ROOT + '?_route=finance/send', wait_until='networkidle')
    pg.check('input[name=to_id][value="6"]'); pg.fill('[name=amount]', '50'); pg.fill('[name=reason]', 'تعبئة رصيد موزع الكرادة')
    shot(pg, 'send-form', True)
    with pg.expect_navigation():
        pg.locator('form[action*="send-review"] button[type=submit]').first.click()
    pg.wait_for_load_state('networkidle')
    review_confirm(pg, 'send')
    b.close()
print('flows done')
