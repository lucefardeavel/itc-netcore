// Print HTML -> PDF with Playwright Chromium (A4, running header/footer, PDF outline, tagged PDF).
// Two-pass TOC: pass 1 prints, the PDF's named destinations give the page of every heading, the numbers are
// written into the TOC and the document is printed again (repeated until the page map is stable).
// usage: node render_pdf.js <in.html> <ast.json> <out.pdf> [pagemap.json]
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.DOCPIPE_PLAYWRIGHT || '/srv/itcnc/qa/node_modules/playwright');
const { pdfPageMap } = require('./pdfinfo');

const ASSETS = path.join(__dirname, '..', 'assets');
const LABELS = {
  en: { page: 'Page', of: 'of', tagline: 'ISP Management & Billing Platform' },
  ar: { page: 'صفحة', of: 'من', tagline: 'منصة إدارة وفوترة مزوّدي خدمة الإنترنت' },
};

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function b64(p) { return fs.readFileSync(p).toString('base64'); }

function templateFonts() {
  const f = path.join(ASSETS, 'fonts');
  const face = (fam, w, file) => `@font-face{font-family:"${fam}";font-weight:${w};src:url(data:font/woff2;base64,${b64(path.join(f, file))}) format("woff2");}`;
  return face('Inter', 400, 'inter-latin-regular.woff2') + face('Inter', 600, 'inter-latin-semibold.woff2') +
    face('Noto Sans Arabic', 400, 'noto-sans-arabic-regular.woff2') + face('Noto Sans Arabic', 700, 'noto-sans-arabic-bold.woff2');
}

function templates(meta) {
  const lang = meta.language;
  const L = LABELS[lang];
  const dir = lang === 'ar' ? 'rtl' : 'ltr';
  const mark = 'data:image/svg+xml;base64,' + b64(path.join(ASSETS, 'brand', 'netcore-mark.svg'));
  const base = `<style>${templateFonts()}
    .hf{width:100%;margin:0 18mm;font-family:"Inter","Noto Sans Arabic",sans-serif;font-size:7.5pt;color:#5a6e86;
        display:flex;align-items:center;justify-content:space-between;gap:6mm;-webkit-print-color-adjust:exact;}
    .hf .s{display:flex;align-items:center;gap:2mm;white-space:nowrap;}
    .hf .e{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:end;min-width:0;}
    .hf b{color:#193768;font-weight:600;} bdi{unicode-bidi:isolate;}
    .hdr{border-bottom:0.6pt solid #dce5ef;padding-bottom:2mm;margin-top:9mm;}
    .ftr{border-top:0.6pt solid #dce5ef;padding-top:2mm;margin-bottom:8mm;}
  </style>`;
  const header = `${base}<div class="hf hdr" dir="${dir}" lang="${lang}">
      <span class="s"><img src="${mark}" style="height:3.6mm;width:3.6mm"><b><bdi dir="ltr">ITC NetCore</bdi></b><span>${esc(L.tagline)}</span></span>
      <span class="e">${esc(meta.title)}</span></div>`;
  const footer = `${base}<div class="hf ftr" dir="${dir}" lang="${lang}">
      <span class="e" style="text-align:start"><bdi>${esc(meta.version)}</bdi> · <bdi>${esc(meta.status)}</bdi></span>
      <span class="s">${L.page} <span class="pageNumber"></span> ${L.of} <span class="totalPages"></span></span></div>`;
  return { header, footer };
}

async function main() {
  const [inHtml, astPath, outPdf, mapOut] = process.argv.slice(2);
  if (!outPdf) { console.error('usage: render_pdf.js <in.html> <ast.json> <out.pdf> [pagemap.json]'); process.exit(2); }
  const ast = JSON.parse(fs.readFileSync(astPath, 'utf8'));
  const meta = ast.meta;
  const { header, footer } = templates(meta);
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto('file://' + path.resolve(inHtml), { waitUntil: 'load' });
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(() => document.fonts.ready);
    // Header/footer: CSS page-margin boxes from the HTML (default; the cover page has none). DOCPIPE_HF=template
    // switches to Chromium displayHeaderFooter templates instead (they are also drawn on the cover page).
    const templateMode = process.env.DOCPIPE_HF === 'template';
    if (templateMode) {
      await page.addStyleTag({ content: '@page { @top-left { content: none } @top-right { content: none } ' +
        '@bottom-left { content: none } @bottom-right { content: none } }' });
    }
    const opts = {
      format: 'A4', printBackground: true, preferCSSPageSize: true, displayHeaderFooter: templateMode,
      headerTemplate: header, footerTemplate: footer, outline: true, tagged: true,
    };
    const ids = ast.toc.map((e) => e.id);
    let buf, map, prev = null;
    for (let pass = 1; pass <= 4; pass++) {
      buf = await page.pdf(opts);
      map = pdfPageMap(buf);
      const pages = {};
      for (const id of ids) pages[id] = map.dests[id] || null;
      const key = JSON.stringify(pages);
      console.log(`render_pdf: pass ${pass}: ${map.pageCount} pages`);
      if (key === prev) break;
      prev = key;
      await page.evaluate((pg) => {
        for (const el of document.querySelectorAll('.toc-page')) {
          const n = pg[el.dataset.for];
          el.textContent = n == null ? '–' : String(n);
        }
      }, pages);
    }
    const missing = ids.filter((id) => !map.dests[id]);
    if (missing.length) console.error('render_pdf: WARNING no PDF destination for ' + missing.join(', '));
    fs.writeFileSync(outPdf, buf);
    if (mapOut) fs.writeFileSync(mapOut, JSON.stringify({ pageCount: map.pageCount, headings: map.dests }, null, 1));
    console.log(`render_pdf: wrote ${outPdf} (${map.pageCount} pages, ${buf.length} bytes)`);
  } finally {
    await browser.close();
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
