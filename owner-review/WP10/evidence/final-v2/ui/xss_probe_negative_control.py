import json,re,sys
sys.path.insert(0,'/srv/phpnuxbill/dev/dev/pwa-lab')
sys.argv=['x','--out','/tmp/xx']
import pwa_suite as P
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch()
    ctx=P.new_ctx(b,P.ROOT,vp=P.DESK); pg=P.staff_login(ctx,P.ROOT,'ad')
    pg.goto(P.ROOT+'?_route=message/send_bulk',wait_until='networkidle')
    evil='<img src=x onerror="window.__nuxrXss=1">'
    fake=json.dumps({'status':'success','totalSent':1,'totalFailed':0,'page':0,'hasMore':False,'batchStatus':[{'name':evil,'phone':evil,'status':'Test Mode','message':evil,'router':evil,'service':evil}]})
    pg.route(re.compile('send_bulk_ajax'),lambda r:r.fulfill(status=200,content_type='application/json',body=fake))
    pg.select_option('#group','all'); pg.select_option('#service','all'); pg.select_option('#via','sms'); pg.fill('#message','x')
    pg.locator('#test').check(force=True); pg.locator('#startBulk').click(); pg.wait_for_timeout(2500)
    print('executed:',pg.evaluate('()=>window.__nuxrXss'),'imgs:',pg.locator('#historyTable img').count())
    b.close()
