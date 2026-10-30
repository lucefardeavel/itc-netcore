"""Markdown document folder -> self-contained print HTML (fonts, logo and images inlined as data URIs).

usage: render_html.py <docdir> <out.html> [--ast out.json]
Stdlib only. The HTML is printed to PDF by render_pdf.js (Playwright Chromium).
"""
import base64
import html
import json
import mimetypes
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from common import (ASSETS, HERE, LABELS, PRODUCT, RESPONSIBILITY, TAGLINE, latin_runs, load_doc)  # noqa: E402
import mdparse  # noqa: E402

ICONS = {
    "note": '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="9" y="8.5" width="2" height="6" rx="1" fill="currentColor"/><circle cx="10" cy="5.8" r="1.25" fill="currentColor"/></svg>',
    "warning": '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.2 19 17.8H1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><rect x="9" y="7.2" width="2" height="5.6" rx="1" fill="currentColor"/><circle cx="10" cy="15" r="1.2" fill="currentColor"/></svg>',
    "finance": '<svg viewBox="0 0 20 20" aria-hidden="true"><ellipse cx="10" cy="5.5" rx="7" ry="3" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 5.5v4.5c0 1.7 3.1 3 7 3s7-1.3 7-3V5.5M3 10v4.5c0 1.7 3.1 3 7 3s7-1.3 7-3V10" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    "security": '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.8 17 4.5v5c0 4.4-3 7.4-7 8.7-4-1.3-7-4.3-7-8.7v-5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><rect x="9" y="6" width="2" height="5.5" rx="1" fill="currentColor"/><circle cx="10" cy="14" r="1.2" fill="currentColor"/></svg>',
}


def data_uri(path, mime=None):
    mime = mime or mimetypes.guess_type(path)[0] or "application/octet-stream"
    if path.endswith(".svg"):
        mime = "image/svg+xml"
    if path.endswith(".woff2"):
        mime = "font/woff2"
    with open(path, "rb") as f:
        return "data:%s;base64,%s" % (mime, base64.b64encode(f.read()).decode("ascii"))


def font_faces_css():
    """@font-face rules from the brand CSS (assets/fonts/fontfaces.css) with the WOFF2 files inlined."""
    fdir = os.path.join(ASSETS, "fonts")
    with open(os.path.join(fdir, "fontfaces.css"), encoding="utf-8") as f:
        css = f.read()
    return re.sub(r'url\("([^"]+)"\)', lambda m: 'url("%s")' % data_uri(os.path.join(fdir, m.group(1))), css)


class Renderer:
    def __init__(self, meta, docdir):
        self.meta = meta
        self.docdir = docdir
        self.lang = meta["language"]
        self.rtl = self.lang == "ar"
        self.L = LABELS[self.lang]
        self.fig = 0
        self.warnings = []

    # ------------------------------------------------------------ inline
    def text(self, s):
        """Escape text; in RTL documents isolate Latin runs (product name, protocols, versions) with <bdi>."""
        if not self.rtl:
            out = html.escape(s, quote=False)
            return out.replace(html.escape(PRODUCT), '<span class="itc-wordmark">%s</span>' % html.escape(PRODUCT))
        parts = []
        for seg, is_latin in latin_runs(s):
            e = html.escape(seg, quote=False)
            if is_latin:
                # short runs ("4 Mbps", "V1.0 RC") never wrap inside: a line break would reorder them visually
                cls = ' class="itc-wordmark"' if PRODUCT in seg else (' class="nw"' if len(seg) <= 18 else "")
                parts.append('<bdi dir="ltr"%s>%s</bdi>' % (cls, e))
            else:
                parts.append(e)
        return "".join(parts)

    def inl(self, nodes):
        out = []
        for n in nodes:
            t = n["t"]
            if t == "text":
                out.append(self.text(n["v"]))
            elif t == "strong":
                out.append("<strong>%s</strong>" % self.inl(n["c"]))
            elif t == "em":
                out.append("<em>%s</em>" % self.inl(n["c"]))
            elif t == "code":
                # break opportunities after technical separators, so long identifiers wrap inside table cells
                # without letting ordinary words / numbers break anywhere
                # (only in long identifiers; never inside numbers / addresses such as 10.10.0.5)
                code = html.escape(n["v"], quote=False)
                if len(n["v"]) > 20:
                    code = re.sub(r"([-/=_:,]|\.(?!\d))", lambda mm: mm.group(1) + "<wbr>", code)
                out.append('<code dir="ltr">%s</code>' % code)
            elif t == "br":
                out.append("<br>")
            elif t == "link":
                out.append('<a href="%s">%s</a>' % (html.escape(n["href"]), self.inl(n["c"])))
        return "".join(out)

    # ------------------------------------------------------------ blocks
    def blocks(self, blocks):
        return "\n".join(self.block(b) for b in blocks)

    def block(self, b):
        t = b["t"]
        if t == "heading":
            lvl = b["level"]
            if b.get("id"):
                num = '<span class="secnum" dir="ltr">%s</span>' % b["num"] if b.get("num") else ""
                return ('<h%d id="%s" class="h">%s<span class="htext">%s</span></h%d>'
                        % (lvl, b["id"], num, self.inl(b["inl"]), lvl))
            return "<h%d>%s</h%d>" % (lvl, self.inl(b["inl"]), lvl)
        if t == "para":
            return "<p>%s</p>" % self.inl(b["inl"])
        if t == "list":
            tag = "ol" if b["ordered"] else "ul"
            start = ' start="%d"' % b["start"] if b["ordered"] and b["start"] != 1 else ""
            items = []
            for it in b["items"]:
                inner = self.blocks(it)
                inner = re.sub(r"^<p>(.*?)</p>", r"\1", inner, count=1, flags=re.S)
                items.append("<li>%s</li>" % inner)
            return "<%s%s>%s</%s>" % (tag, start, "".join(items), tag)
        if t == "table":
            def cell(tag, inl, al):
                style = ' class="al-%s"' % al if al else ""
                return "<%s%s>%s</%s>" % (tag, style, self.inl(inl), tag)
            head = "".join(cell("th", h, b["align"][k]) for k, h in enumerate(b["head"]))
            rows = "".join("<tr>%s</tr>" % "".join(cell("td", c, b["align"][k]) for k, c in enumerate(r))
                           for r in b["rows"])
            return ('<div class="tablewrap"><table><thead><tr>%s</tr></thead><tbody>%s</tbody></table></div>'
                    % (head, rows))
        if t == "image":
            path = os.path.join(self.docdir, b["src"])
            self.fig += 1
            if os.path.isfile(path):
                src = data_uri(path)
            else:
                self.warnings.append("missing image: %s" % b["src"])
                src = b["src"]  # left unresolved on purpose: check.js reports it as a broken image
            style = ' style="width:%s"' % b["width"] if b.get("width") else ""
            cap = b["title"] or b["alt"]
            caption = ('<figcaption><span class="figlabel">%s %d</span> %s</figcaption>'
                       % (self.L["figure"], self.fig, self.text(cap))) if cap else ""
            return ('<figure><img src="%s" alt="%s" data-src="%s"%s>%s</figure>'
                    % (src, html.escape(b["alt"]), html.escape(b["src"]), style, caption))
        if t == "quote":
            return "<blockquote>%s</blockquote>" % self.blocks(b["blocks"])
        if t == "callout":
            k = b["kind"]
            title = self.text(b["title"]) if b.get("title") else self.L[k]
            return ('<aside class="callout callout-%s"><div class="callout-title"><span class="callout-icon">%s</span>'
                    '<span>%s</span></div><div class="callout-body">%s</div></aside>'
                    % (k, ICONS[k], title, self.blocks(b["blocks"])))
        if t == "code":
            return '<pre dir="ltr"><code>%s</code></pre>' % html.escape(b["text"])
        if t == "hr":
            return "<hr>"
        if t == "pagebreak":
            return '<div class="pagebreak"></div>'
        return ""

    # ------------------------------------------------------------ page parts
    def cover(self):
        m, L = self.meta, self.L
        brand = os.path.join(ASSETS, "brand")
        rows = [("version", m["version"]), ("date", m["date"]), ("status", m["status"]), ("owner", m["owner"]),
                ("sha", m["source_sha"]), ("audience", m["audience"])]
        meta_rows = "".join(
            '<div class="cm-row"><div class="cm-k">%s</div><div class="cm-v"%s>%s</div></div>'
            % (L[k], ' dir="ltr"' if k == "sha" else "", self.text(str(v))) for k, v in rows)
        kicker = self.text(m.get("doc_type", "")) if m.get("doc_type") else ""
        return """<section class="cover">
  <div class="cover-top">
    <img class="cover-wordmark" src="%s" alt="ITC NetCore — ISP Management &amp; Billing Platform">
    %s
  </div>
  <div class="cover-rule"></div>
  <div class="cover-main">
    %s
    <h1 class="cover-title">%s</h1>
    <p class="cover-subtitle">%s</p>
    <div class="cover-meta">%s</div>
  </div>
  <div class="cover-band">
    <img class="cover-mark" src="%s" alt="">
    <div class="cover-band-text">
      <div class="cb-product"><bdi dir="ltr" class="itc-wordmark">ITC NetCore</bdi></div>
      <div class="cb-tagline">%s</div>
      <div class="cb-resp">%s</div>
    </div>
  </div>
</section>""" % (
            data_uri(os.path.join(brand, "netcore-wordmark.svg")),
            '<div class="cover-tagline-ar" lang="ar" dir="rtl">%s</div>' % TAGLINE["ar"] if self.rtl else "",
            '<div class="cover-kicker">%s</div>' % kicker if kicker else "",
            self.text(m["title"]), self.text(m["subtitle"]), meta_rows,
            data_uri(os.path.join(brand, "netcore-mark-white.svg")),
            self.text(TAGLINE[self.lang]), self.text(RESPONSIBILITY[self.lang]))

    def toc(self, entries):
        items = []
        for e in entries:
            items.append('<li class="toc-l%d"><a href="#%s"><span class="toc-num" dir="ltr">%s</span>'
                         '<span class="toc-text">%s</span><span class="toc-dots"></span>'
                         '<span class="toc-page" data-for="%s">00</span></a></li>'
                         % (e["level"], e["id"], e["num"], self.inl(e["inl"]), e["id"]))
        return ('<nav class="toc"><h1 class="toc-title">%s</h1><ol>%s</ol></nav>'
                % (self.L["contents"], "".join(items)))

    def css_string(self, text):
        """A CSS content string; in RTL documents Latin runs are wrapped in LRI/PDI isolates."""
        if self.rtl:
            text = "".join(("\u2066%s\u2069" % seg) if lat else seg for seg, lat in latin_runs(text))
        text = text.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")
        return '"%s"' % text

    def page_rules(self):
        """Running header / footer as CSS page-margin boxes (Chromium >= 131), suppressed on the cover by
        @page :first. Physical sides are swapped for RTL so the brand sits on the reading start side."""
        m, L = self.meta, self.L
        with open(os.path.join(ASSETS, "brand", "netcore-mark.svg"), "rb") as f:
            mark_b64 = base64.b64encode(f.read()).decode("ascii")
        # fixed-size wrapper that only references the unmodified mark artwork (margin-box images cannot be sized)
        wrapper = ('<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 224 224">'
                   '<image width="224" height="224" href="data:image/svg+xml;base64,%s"/></svg>' % mark_b64)
        mark = "data:image/svg+xml;base64," + base64.b64encode(wrapper.encode()).decode("ascii")
        start, end = ("right", "left") if self.rtl else ("left", "right")
        d = "rtl" if self.rtl else "ltr"
        brand = 'url("%s") "\\00a0\\00a0" %s' %(mark, self.css_string("ITC NetCore  ·  " + TAGLINE[self.lang]))
        title = self.css_string(m["title"])
        status = self.css_string("%s · %s" % (m["version"], m["status"]))
        pages = '"%s " counter(page) " %s " counter(pages)' % (L["page"], L["of"])
        box = ('font-family: "Inter", "Noto Sans Arabic", sans-serif; font-size: 7.5pt; color: #5a6e86; '
               'direction: %s; ' % d)
        top = box + "vertical-align: bottom; padding-bottom: 2.2mm; margin-bottom: 6mm; border-bottom: 0.6pt solid #dce5ef;"
        bottom = box + "vertical-align: top; padding-top: 2.2mm; margin-top: 6mm; border-top: 0.6pt solid #dce5ef;"
        return """@page {
  @top-%(start)s { content: %(brand)s; %(top)s width: 64%%; text-align: %(start)s; color: #193768; }
  @top-%(end)s { content: %(title)s; %(top)s width: 36%%; text-align: %(end)s; }
  @bottom-%(start)s { content: %(status)s; %(bottom)s width: 75%%; text-align: %(start)s; }
  @bottom-%(end)s { content: %(pages)s; %(bottom)s width: 25%%; text-align: %(end)s; }
}
@page :first {
  @top-left { content: none; } @top-right { content: none; } @bottom-left { content: none; } @bottom-right { content: none; }
}
""" % dict(start=start, end=end, brand=brand, title=title, status=status, pages=pages, top=top, bottom=bottom)

    def page(self, blocks, toc):
        with open(os.path.join(HERE, "template.css"), encoding="utf-8") as f:
            css = f.read()
        return """<!doctype html>
<html lang="%s" dir="%s">
<head>
<meta charset="utf-8">
<title>%s</title>
<meta name="docpipe-version" content="%s">
<style>
%s
%s
%s
</style>
</head>
<body class="lang-%s">
%s
%s
<main class="content">
%s
</main>
</body>
</html>
""" % (self.lang, "rtl" if self.rtl else "ltr", html.escape(self.meta["title"]), html.escape(self.meta["version"]),
       font_faces_css(), css, self.page_rules(), self.lang, self.cover(), self.toc(toc), self.blocks(blocks))


def main():
    args = sys.argv[1:]
    if len(args) < 2:
        sys.exit(__doc__)
    docdir, out = args[0], args[1]
    meta, text, _ = load_doc(docdir)
    blocks = mdparse.parse(text)
    toc = mdparse.number_headings(blocks, int(meta.get("toc_depth", 3)))
    r = Renderer(meta, docdir)
    page = r.page(blocks, toc)
    with open(out, "w", encoding="utf-8") as f:
        f.write(page)
    if "--ast" in args:
        with open(args[args.index("--ast") + 1], "w", encoding="utf-8") as f:
            json.dump({"meta": meta, "blocks": blocks, "toc": toc}, f, ensure_ascii=False, indent=1)
    for w in r.warnings:
        print("render_html: WARNING " + w, file=sys.stderr)


if __name__ == "__main__":
    main()
