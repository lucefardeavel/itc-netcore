"""Guide PDFs from WORK/booklet: a full-quality A4 PDF and a light one (JPEG images, about 5 MB) for sending."""
import glob
import os

from PIL import Image
from playwright.sync_api import sync_playwright

WORK = os.environ.get('GUIDE_WORK', '/tmp/itc-guide')
B = os.path.join(WORK, 'booklet')
HEAD = ('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width, initial-scale=1"><style>img{max-width:100%}</style></head><body>')
html = open(os.path.join(B, 'index.html'), encoding='utf-8').read()
lite = os.path.join(WORK, 'lite')
os.makedirs(os.path.join(lite, 'img'), exist_ok=True)
for f in glob.glob(os.path.join(B, 'img', '*.webp')):
    im = Image.open(f).convert('RGB')
    if im.width > 1100:
        im = im.resize((1100, round(im.height * 1100 / im.width)), Image.LANCZOS)
    im.save(os.path.join(lite, 'img', os.path.basename(f)[:-5] + '.jpg'), 'JPEG', quality=72, optimize=True)
open(os.path.join(B, '_print.html'), 'w', encoding='utf-8').write(HEAD + html + '</body></html>')
open(os.path.join(lite, '_print.html'), 'w', encoding='utf-8').write(HEAD + html.replace('.webp"', '.jpg"') + '</body></html>')
with sync_playwright() as p:
    b = p.chromium.launch()
    for src, pdf in ((B, 'ITC-NetCore-Guide.pdf'), (lite, 'ITC-NetCore-Guide-lite.pdf')):
        pg = b.new_page(viewport={'width': 1100, 'height': 1400})
        pg.goto('file://' + os.path.join(src, '_print.html'), wait_until='networkidle')
        pg.emulate_media(media='print')
        pg.pdf(path=os.path.join(WORK, pdf), format='A4', print_background=True,
               margin={'top': '14mm', 'bottom': '14mm', 'left': '12mm', 'right': '12mm'})
        print(pdf, os.path.getsize(os.path.join(WORK, pdf)) // 1024, 'KB')
    b.close()
os.remove(os.path.join(B, '_print.html'))
