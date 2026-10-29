// Verification of a built document.
//  1. PDF -> one PNG per page, rendered by Chromium's own PDF viewer (PDFium) for visual review;
//     falls back to an HTML screenshot when the full Chromium build is unavailable.
//  2. Checks on the print HTML: page count (from the PDF), fonts actually used per glyph (CDP
//     CSS.getPlatformFontsForNode, i.e. detects silent font substitution), missing images, text overflow
//     (block elements whose scrollWidth > clientWidth), cover overflow, headings without a PDF page.
// usage: node verify.js <in.html> <in.pdf> <pagemap.json> <png-dir> <report-base> [--no-png]
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.DOCPIPE_PLAYWRIGHT || '/srv/itcnc/qa/node_modules/playwright');
const { pdfPageMap } = require('./pdfinfo');

const BRAND_FONTS = ['Inter', 'Noto Sans Arabic'];
const CODE_FONTS = ['DejaVu Sans Mono', 'Liberation Mono'];
const MM = 96 / 25.4;

async function renderPages(pdf, pngDir, pageCount) {
  fs.mkdirSync(pngDir, { recursive: true });
  for (const f of fs.readdirSync(pngDir)) if (/^page-\d+\.png$/.test(f)) fs.unlinkSync(path.join(pngDir, f));
  let browser;
  try {
    browser = await chromium.launch({ channel: 'chromium' });
  } catch (e) {
    return { ok: false, why: 'full Chromium build unavailable: ' + e.message.split('\n')[0] };
  }
  const files = [];
  try {
    for (let n = 1; n <= pageCount; n++) {
      const ctx = await browser.newContext({ viewport: { width: 1060, height: 1480 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto('file://' + path.resolve(pdf) + `#page=${n}&toolbar=0&navpanes=0&view=Fit`);
      await page.waitForTimeout(1800);
      const out = path.join(pngDir, `page-${String(n).padStart(2, '0')}.png`);
      await page.screenshot({ path: out });
      files.push(out);
      await ctx.close();
    }
  } finally { await browser.close(); }
  return { ok: true, files };
}

async function htmlChecks(html, pngDir, fallbackShots) {
  const browser = await chromium.launch();
  const res = {};
  try {
    // the text column of the A4 page: 210mm - 2 x 18mm
    const ctx = await browser.newContext({ viewport: { width: Math.round(174 * MM), height: 1100 } });
    const page = await ctx.newPage();
    await page.goto('file://' + path.resolve(html), { waitUntil: 'load' });
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(() => document.fonts.ready);
    res.images = await page.evaluate(() => [...document.images].map((i) => ({
      src: i.dataset.src || i.src.slice(0, 60), ok: i.complete && i.naturalWidth > 0,
      w: i.naturalWidth, h: i.naturalHeight, shownW: Math.round(i.getBoundingClientRect().width),
      shownH: Math.round(i.getBoundingClientRect().height),
    })));
    res.overflow = await page.evaluate(() => {
      const out = [];
      for (const el of document.querySelectorAll('.content *, .toc *')) {
        const cs = getComputedStyle(el);
        if (cs.display.startsWith('inline') || el.closest('svg')) continue;
        if (el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0) {
          out.push({ tag: el.tagName.toLowerCase(), cls: el.className || '', scrollWidth: el.scrollWidth,
            clientWidth: el.clientWidth, text: (el.textContent || '').trim().slice(0, 80) });
        }
        const r = el.getBoundingClientRect();
        if (r.width && (r.right > document.documentElement.clientWidth + 1 || r.left < -1)) {
          out.push({ tag: el.tagName.toLowerCase(), cls: el.className || '', outsideColumn: true,
            left: Math.round(r.left), right: Math.round(r.right), text: (el.textContent || '').trim().slice(0, 80) });
        }
      }
      const c = document.querySelector('.cover');
      if (c && c.scrollHeight > c.clientHeight + 1) out.push({ tag: 'section', cls: 'cover', coverOverflowPx: c.scrollHeight - c.clientHeight });
      if (c) for (const el of c.querySelectorAll('*')) {
        if (!getComputedStyle(el).display.startsWith('inline') && el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0)
          out.push({ tag: el.tagName.toLowerCase(), cls: el.className.baseVal !== undefined ? '' : el.className, cover: true, text: (el.textContent || '').trim().slice(0, 60) });
      }
      return out;
    });
    // fonts actually used for the glyphs of every element (detects fallback / substitution)
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('DOM.enable');
    await cdp.send('CSS.enable');
    const { root } = await cdp.send('DOM.getDocument', { depth: -1 });
    const { nodeIds } = await cdp.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: 'body, body *' });
    const fonts = {};
    const substitutions = [];
    for (const id of nodeIds) {
      let r;
      try { r = await cdp.send('CSS.getPlatformFontsForNode', { nodeId: id }); } catch (e) { continue; }
      if (!r.fonts || !r.fonts.length) continue;
      const desc = await cdp.send('DOM.describeNode', { nodeId: id });
      const tag = desc.node.localName;
      let inCode = false;
      const cls = await cdp.send('DOM.resolveNode', { nodeId: id }).then((o) => cdp.send('Runtime.callFunctionOn', {
        objectId: o.object.objectId, returnByValue: true,
        functionDeclaration: 'function(){return {code: !!(this.closest("pre,code") || this.querySelector("pre,code")), text: (this.textContent||"").trim().slice(0,50)}}',
      })).then((x) => x.result.value);
      inCode = cls.code;
      for (const f of r.fonts) {
        const k = `${f.familyName}${f.isCustomFont ? ' (embedded @font-face)' : ' (system)'}`;
        fonts[k] = (fonts[k] || 0) + f.glyphCount;
        // embedded files report their internal family name ("Inter SemiBold" for the 600 weight file)
        const brand = f.isCustomFont && BRAND_FONTS.some((b) => f.familyName === b || f.familyName.startsWith(b + ' '));
        const allowed = brand || (inCode && CODE_FONTS.includes(f.familyName));
        if (!allowed) substitutions.push({ tag, font: f.familyName, glyphs: f.glyphCount, text: cls.text });
      }
    }
    res.fonts = fonts;
    res.substitutions = substitutions;
    if (fallbackShots) {
      fs.mkdirSync(pngDir, { recursive: true });
      await page.setViewportSize({ width: Math.round(210 * MM), height: 1123 });
      await page.screenshot({ path: path.join(pngDir, 'html-full.png'), fullPage: true });
    }
  } finally { await browser.close(); }
  return res;
}

async function main() {
  const args = process.argv.slice(2);
  if (args[0] === '--pages') {
    // pages-only mode for any PDF (e.g. the Word export of the DOCX): node verify.js --pages <pdf> <png-dir>
    // node verify.js --pages <pdf> <png-dir> [page-count]  (count needed for PDFs with xref streams)
    const n = args[3] ? parseInt(args[3], 10) : pdfPageMap(fs.readFileSync(args[1])).pageCount;
    const r = await renderPages(args[1], args[2], n);
    console.log(r.ok ? `${n} page PNGs in ${path.resolve(args[2])}` : r.why);
    process.exit(r.ok ? 0 : 1);
  }
  const [html, pdf, mapPath, pngDir, reportBase] = args;
  const noPng = args.includes('--no-png');
  const map = pdfPageMap(fs.readFileSync(pdf));
  const saved = mapPath && fs.existsSync(mapPath) ? JSON.parse(fs.readFileSync(mapPath, 'utf8')) : { headings: map.dests };
  let shots = { ok: false, why: 'skipped (--no-png)' };
  if (!noPng) shots = await renderPages(pdf, pngDir, map.pageCount);
  const h = await htmlChecks(html, pngDir, !noPng && !shots.ok);
  const tocPages = [...fs.readFileSync(html, 'utf8').matchAll(/data-for="([^"]+)"/g)].map((m) => m[1]);
  const noPage = tocPages.filter((id) => !map.dests[id]);
  const brokenImages = h.images.filter((i) => !i.ok);
  const problems = [];
  if (brokenImages.length) problems.push(`${brokenImages.length} missing/broken image(s)`);
  if (h.overflow.length) problems.push(`${h.overflow.length} overflowing element(s)`);
  if (h.substitutions.length) problems.push(`${h.substitutions.length} element(s) rendered with a non-brand font`);
  if (noPage.length) problems.push(`headings without a PDF page: ${noPage.join(', ')}`);
  const report = {
    pdf: path.resolve(pdf), pageCount: map.pageCount, headingPages: map.dests, fonts: h.fonts,
    substitutions: h.substitutions, images: h.images, overflow: h.overflow,
    pagePngs: shots.ok ? shots.files : [], pngNote: shots.ok ? 'PDF pages rendered by Chromium PDF viewer' : shots.why,
    result: problems.length ? 'FAIL' : 'PASS', problems,
  };
  fs.writeFileSync(reportBase + '.json', JSON.stringify(report, null, 1));
  const lines = [
    `document : ${path.basename(pdf)}`,
    `result   : ${report.result}${problems.length ? ' — ' + problems.join('; ') : ''}`,
    `pages    : ${map.pageCount}`,
    `fonts    : ` + Object.entries(h.fonts).map(([k, v]) => `${k}: ${v} glyphs`).join('; '),
    `images   : ${h.images.length} (${brokenImages.length} missing)` + brokenImages.map((i) => `\n           MISSING ${i.src}`).join(''),
    ...h.images.filter((i) => i.ok).map((i) => `           ${i.src} ${i.w}x${i.h}px shown ${i.shownW}x${i.shownH}`),
    `overflow : ${h.overflow.length}` + h.overflow.map((o) => `\n           ${JSON.stringify(o)}`).join(''),
    `non-brand fonts: ${h.substitutions.length}` + h.substitutions.slice(0, 20).map((s) => `\n           <${s.tag}> ${s.font} ${s.glyphs} glyphs "${s.text}"`).join(''),
    `TOC pages: ` + Object.entries(map.dests).map(([k, v]) => `${k}=p${v}`).join(' '),
    `PNGs     : ${shots.ok ? shots.files.length + ' page images in ' + path.resolve(pngDir) : report.pngNote}`,
  ];
  fs.writeFileSync(reportBase + '.txt', lines.join('\n') + '\n');
  console.log(lines.join('\n'));
  void saved;
  process.exit(problems.length ? 3 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
