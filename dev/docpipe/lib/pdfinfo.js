// Minimal reader for the PDFs Chromium (Skia) writes: page order from the page tree and the page number of every
// named destination (Catalog /Dests). Uses the classic xref table; no object streams are expected.
'use strict';

function pdfPageMap(buf) {
  const s = buf.toString('latin1');
  const sx = s.lastIndexOf('startxref');
  const xrefOff = parseInt(s.slice(sx + 9).trim().split(/\s+/)[0], 10);
  const offsets = {};
  let p = xrefOff;
  if (s.slice(p, p + 4) !== 'xref') throw new Error('pdfinfo: no classic xref table');
  const lines = s.slice(p).split(/\r?\n|\r/);
  let li = 1;
  while (li < lines.length) {
    const hdr = lines[li].trim().split(/\s+/);
    if (hdr[0] === 'trailer' || hdr.length !== 2) break;
    let num = parseInt(hdr[0], 10);
    const cnt = parseInt(hdr[1], 10);
    li++;
    for (let k = 0; k < cnt; k++, li++, num++) {
      const e = lines[li].trim().split(/\s+/);
      if (e[2] === 'n') offsets[num] = parseInt(e[0], 10);
    }
  }
  const obj = (n) => {
    const o = offsets[n];
    if (o === undefined) return '';
    let end = s.indexOf('endobj', o);
    const st = s.indexOf('stream', o);
    if (st >= 0 && st < end) end = st;
    return s.slice(o, end);
  };
  const trailer = s.slice(s.lastIndexOf('trailer'));
  const root = parseInt(/\/Root\s+(\d+)\s+0\s+R/.exec(trailer)[1], 10);
  const cat = obj(root);
  const pagesRoot = parseInt(/\/Pages\s+(\d+)\s+0\s+R/.exec(cat)[1], 10);
  const order = [];
  (function walk(n) {
    const o = obj(n);
    if (/\/Type\s*\/Pages\b/.test(o)) {
      const kids = /\/Kids\s*\[([^\]]*)\]/.exec(o)[1];
      for (const m of kids.matchAll(/(\d+)\s+0\s+R/g)) walk(parseInt(m[1], 10));
    } else {
      order.push(n);
    }
  })(pagesRoot);
  const pageNo = {};
  order.forEach((n, i) => { pageNo[n] = i + 1; });
  let destsText = '';
  const dref = /\/Dests\s+(\d+)\s+0\s+R/.exec(cat);
  if (dref) destsText = obj(parseInt(dref[1], 10));
  else { const di = /\/Dests\s*<<([\s\S]*?)>>/.exec(cat); if (di) destsText = di[1]; }
  const dests = {};
  for (const m of destsText.matchAll(/\/([^\s\[\]/<>()]+)\s*\[\s*(\d+)\s+0\s+R/g)) {
    const name = m[1].replace(/#([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
    dests[name] = pageNo[parseInt(m[2], 10)] || null;
  }
  return { pageCount: order.length, dests };
}

module.exports = { pdfPageMap };

if (require.main === module) {
  const fs = require('fs');
  console.log(JSON.stringify(pdfPageMap(fs.readFileSync(process.argv[2])), null, 1));
}
