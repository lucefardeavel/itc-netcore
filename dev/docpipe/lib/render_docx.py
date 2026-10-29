"""Markdown document folder -> branded DOCX (python-docx 1.1.2).

usage: render_docx.py <docdir> <out.docx> <workdir> [--no-embed-fonts]

Structure: cover (wordmark, title, subtitle, metadata, navy band with the reversed mark), TOC field (Heading 1-3),
numbered headings, tables with a repeating header row, images (SVG rasterised with rsvg-convert), callouts,
code, page breaks; header (mark, product, title) and footer (version, status, "Page X of Y") from page 2 on.
Arabic: w:bidi on the section, styles and every paragraph, w:bidiVisual on tables, w:rtl on Arabic runs (Latin
words and digits stay in LTR runs), lang bidi ar-IQ. Brand fonts (Inter, Noto Sans Arabic) are embedded
(obfuscated .odttf, ECMA-376 Part 1 17.8.1) from assets/fonts/ttf so Word renders them without installing.
"""
import io
import os
import re
import struct
import subprocess
import sys
import uuid
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from common import (ASSETS, CALLOUT_TONES, LABELS, PALETTE, RESPONSIBILITY, TAGLINE, has_arabic,  # noqa: E402
                    load_doc, rtl_segments)
import mdparse  # noqa: E402

from docx import Document  # noqa: E402
from docx.enum.section import WD_ORIENT  # noqa: E402,F401
from docx.enum.style import WD_STYLE_TYPE  # noqa: E402
from docx.enum.table import WD_TABLE_ALIGNMENT  # noqa: E402,F401
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK  # noqa: E402
from docx.opc.constants import RELATIONSHIP_TYPE as RT  # noqa: E402
from docx.oxml import OxmlElement  # noqa: E402
from docx.oxml.ns import qn  # noqa: E402
from docx.shared import Mm, Pt, RGBColor  # noqa: E402

CONTENT_W_MM = 174.0
MAX_IMG_H_MM = 190.0

ORDER = {
    "rPr": "rStyle rFonts b bCs i iCs caps smallCaps strike dstrike outline shadow emboss imprint noProof snapToGrid "
           "vanish webHidden color spacing w kern position sz szCs highlight u effect bdr shd fitText vertAlign rtl cs "
           "em lang eastAsianLayout specVanish oMath".split(),
    "pPr": "pStyle keepNext keepLines pageBreakBefore framePr widowControl numPr suppressLineNumbers pBdr shd tabs "
           "suppressAutoHyphens kinsoku wordWrap overflowPunct topLinePunct autoSpaceDE autoSpaceDN bidi "
           "adjustRightInd snapToGrid spacing ind contextualSpacing mirrorIndents suppressOverlap jc textDirection "
           "textAlignment textboxTightWrap outlineLvl divId cnfStyle rPr sectPr pPrChange".split(),
    "tblPr": "tblStyle tblpPr tblOverlap bidiVisual tblStyleRowBandSize tblStyleColBandSize tblW jc tblCellSpacing "
             "tblInd tblBorders shd tblLayout tblCellMar tblLook".split(),
    "tcPr": "cnfStyle tcW gridSpan hMerge vMerge tcBorders shd noWrap tcMar textDirection tcFitText vAlign "
            "hideMark".split(),
    "trPr": "cnfStyle divId gridBefore gridAfter wBefore wAfter cantSplit trHeight tblHeader tblCellSpacing jc "
            "hidden".split(),
    "sectPr": "headerReference footerReference footnotePr endnotePr type pgSz pgMar paperSrc pgBorders lnNumType "
              "pgNumType cols formProt vAlign noEndnote titlePg textDirection bidi rtlGutter docGrid "
              "printerSettings".split(),
    "settings": "writeProtection view zoom removePersonalInformation removeDateAndTime doNotDisplayPageBoundaries "
                "displayBackgroundShape printPostScriptOverText printFractionalCharacterWidth printFormsData "
                "embedTrueTypeFonts embedSystemFonts saveSubsetFonts saveFormsData mirrorMargins "
                "alignBordersAndEdges bordersDoNotSurroundHeader bordersDoNotSurroundFooter gutterAtTop "
                "hideSpellingErrors hideGrammaticalErrors activeWritingStyle proofState formsDesign "
                "attachedTemplate linkStyles stylePaneFormatFilter stylePaneSortMethod documentType mailMerge "
                "revisionView trackRevisions doNotTrackMoves doNotTrackFormatting documentProtection "
                "autoFormatOverride styleLockTheme styleLockQFSet defaultTabStop autoHyphenation "
                "consecutiveHyphenLimit hyphenationZone doNotHyphenateCaps showEnvelope summaryLength "
                "clickAndTypeStyle defaultTableStyle evenAndOddHeaders bookFoldRevPrinting bookFoldPrinting "
                "bookFoldPrintingSheets drawingGridHorizontalSpacing drawingGridVerticalSpacing "
                "displayHorizontalDrawingGridEvery displayVerticalDrawingGridEvery doNotUseMarginsForDrawingGridOrigin "
                "drawingGridHorizontalOrigin drawingGridVerticalOrigin doNotShadeFormData noPunctuationKerning "
                "characterSpacingControl printTwoOnOne strictFirstAndLastChars noLineBreaksAfter noLineBreaksBefore "
                "savePreviewPicture doNotValidateAgainstSchema saveInvalidXml ignoreMixedContent "
                "alwaysShowPlaceholderText doNotDemarcateInvalidXml saveXmlDataOnly useXSLTWhenSaving "
                "saveThroughXslt showXMLTags alwaysMergeEmptyNamespace updateFields hdrShapeDefaults footnotePr "
                "endnotePr compat docVars rsids mathPr attachedSchema themeFontLang clrSchemeMapping "
                "doNotIncludeSubdocsInStats doNotAutoCompressPictures forceUpgrade captions readModeInkLockDown "
                "smartTagType schemaLibrary shapeDefaults doNotEmbedSmartTags decimalSymbol listSeparator".split(),
}


def put(parent, tag, attrs=None, kind=None, replace=True):
    """Insert w:<tag> into parent at its schema position (ORDER[kind]); replaces an existing one."""
    kind = kind or parent.tag.split("}")[1]
    order = ORDER[kind]
    if replace:
        old = parent.find(qn("w:" + tag))
        if old is not None:
            parent.remove(old)
    el = OxmlElement("w:" + tag)
    for k, v in (attrs or {}).items():
        el.set(qn("w:" + k), str(v))
    idx = order.index(tag)
    for i, child in enumerate(parent):
        name = child.tag.split("}")[1]
        if name in order and order.index(name) > idx:
            parent.insert(i, el)
            return el
    parent.append(el)
    return el


def rgb(key):
    return RGBColor.from_string(PALETTE[key])


class DocxRenderer:
    def __init__(self, meta, docdir, workdir, embed_fonts=True):
        self.meta = meta
        self.docdir = docdir
        self.workdir = workdir
        self.lang = meta["language"]
        self.rtl = self.lang == "ar"
        self.L = LABELS[self.lang]
        fonts = meta.get("docx_fonts", {})
        self.f_latin = fonts.get("latin", "Inter")
        self.f_arabic = fonts.get("arabic", "Noto Sans Arabic")
        self.f_mono = fonts.get("mono", "Consolas")
        self.embed_fonts = embed_fonts
        self.fig = 0
        self.warnings = []
        self.doc = Document()
        self._num_restart = {}

    # ---------------------------------------------------------------- low-level helpers
    def fonts_el(self, rPr, latin=None, cs=None):
        put(rPr, "rFonts", {"ascii": latin or self.f_latin, "hAnsi": latin or self.f_latin,
                            "eastAsia": latin or self.f_latin, "cs": cs or self.f_arabic})

    def size(self, rPr, pt):
        put(rPr, "sz", {"val": int(round(pt * 2))})
        put(rPr, "szCs", {"val": int(round(pt * 2))})

    def para_dir(self, p, rtl=None):
        rtl = self.rtl if rtl is None else rtl
        pPr = p._p.get_or_add_pPr()
        if rtl:
            put(pPr, "bidi")
        else:
            put(pPr, "bidi", {"val": 0})

    def add_text(self, p, text, bold=False, italic=False, color=None, size=None, font=None, code=False):
        """Add text to paragraph p as one or more runs (split into RTL/LTR runs in Arabic documents)."""
        segs = rtl_segments(text) if (self.rtl or has_arabic(text)) and not code and not font else [(text, False)]
        runs = []
        for seg, is_rtl in segs:
            if not seg:
                continue
            if not is_rtl and re.fullmatch(r"\d[\d.,/]* [A-Za-z]{1,5}", seg):
                seg = seg.replace(" ", "\u00a0")  # keep "4 Mbps" / "200 GB" on one line
            elif not is_rtl and self.rtl and len(seg) <= 18 and " " not in seg:
                seg = seg.replace("-", "\u2011")  # no line break inside "interim-update" (reorders in RTL)
            r = p.add_run(seg)
            rPr = r._r.get_or_add_rPr()
            if code or font:
                self.fonts_el(rPr, latin=font or self.f_mono, cs=font or self.f_mono)
            if bold:
                put(rPr, "b"); put(rPr, "bCs")
            if italic:
                put(rPr, "i"); put(rPr, "iCs")
            if color:
                put(rPr, "color", {"val": PALETTE.get(color, color)})
            if size:
                self.size(rPr, size)
            if code:
                put(rPr, "shd", {"val": "clear", "color": "auto", "fill": PALETTE["neutral_soft"]})
            if is_rtl:
                put(rPr, "rtl")
                put(rPr, "lang", {"bidi": "ar-IQ"})
            runs.append(r)
        return runs

    def add_inlines(self, p, nodes, bold=False, italic=False, color=None, size=None):
        for n in nodes:
            t = n["t"]
            if t == "text":
                self.add_text(p, n["v"], bold, italic, color, size)
            elif t == "strong":
                self.add_inlines(p, n["c"], True, italic, color, size)
            elif t == "em":
                self.add_inlines(p, n["c"], bold, True, color, size)
            elif t == "code":
                self.add_text(p, n["v"], bold, italic, color, (size or 10.5) * 0.9, code=True)
            elif t == "br":
                p.add_run().add_break()
            elif t == "link":
                self.add_link(p, n["href"], mdparse.inline_text(n["c"]), size)

    def add_link(self, p, href, text, size=None):
        if href.startswith("#"):
            self.add_text(p, text, color="core_blue", size=size)
            return
        rid = p.part.relate_to(href, RT.HYPERLINK, is_external=True)
        h = OxmlElement("w:hyperlink")
        h.set(qn("r:id"), rid)
        for seg, is_rtl in (rtl_segments(text) if self.rtl else [(text, False)]):
            r = OxmlElement("w:r")
            rPr = OxmlElement("w:rPr")
            r.append(rPr)
            put(rPr, "color", {"val": PALETTE["core_blue"]})
            put(rPr, "u", {"val": "single"})
            if size:
                self.size(rPr, size)
            if is_rtl:
                put(rPr, "rtl")
            t = OxmlElement("w:t")
            t.text = seg
            t.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
            r.append(t)
            h.append(r)
        p._p.append(h)

    def paragraph(self, container=None, style=None, rtl=None):
        container = container or self.doc
        p = container.add_paragraph(style=style)
        self.para_dir(p, rtl)
        return p

    def shade(self, el_pr, fill, kind):
        put(el_pr, "shd", {"val": "clear", "color": "auto", "fill": fill}, kind=kind)

    def cell_borders(self, cell, color, size=4, start=None):
        tcPr = cell._tc.get_or_add_tcPr()
        b = put(tcPr, "tcBorders")
        for side in ("top", "left", "bottom", "right"):
            el = OxmlElement("w:" + side)
            sz, col = size, color
            # Word reads left/right of a bidiVisual table logically (start/end): "left" is the reading start
            if start and side == "left":
                sz, col = start
            el.set(qn("w:val"), "single"); el.set(qn("w:sz"), str(sz)); el.set(qn("w:space"), "0")
            el.set(qn("w:color"), col)
            b.append(el)

    def cell_margins(self, cell, top=60, side=100, bottom=60):
        tcPr = cell._tc.get_or_add_tcPr()
        m = put(tcPr, "tcMar")
        for s, v in (("top", top), ("left", side), ("bottom", bottom), ("right", side)):
            el = OxmlElement("w:" + s)
            el.set(qn("w:w"), str(v)); el.set(qn("w:type"), "dxa")
            m.append(el)

    def table(self, rows, cols, container=None, borders=True, width_pct=100):
        container = container or self.doc
        t = container.add_table(rows=rows, cols=cols)
        tblPr = t._tbl.tblPr
        if self.rtl:
            put(tblPr, "bidiVisual")
        put(tblPr, "tblW", {"w": int(width_pct * 50), "type": "pct"})
        put(tblPr, "tblLayout", {"type": "autofit"})
        if not borders:
            bd = put(tblPr, "tblBorders")
            for side in ("top", "left", "bottom", "right", "insideH", "insideV"):
                el = OxmlElement("w:" + side)
                el.set(qn("w:val"), "nil")
                bd.append(el)
        return t

    def cell_par(self, cell, rtl=None):
        p = cell.paragraphs[0]
        self.para_dir(p, rtl)
        return p

    def png_for(self, path, width_px=None):
        """Return a PNG/JPEG path usable by Word: SVG is rasterised (rsvg-convert) into the workdir."""
        if not path.lower().endswith(".svg"):
            return path
        os.makedirs(self.workdir, exist_ok=True)
        out = os.path.join(self.workdir, re.sub(r"[^A-Za-z0-9._-]", "_", os.path.relpath(path, self.docdir)) + ".png")
        cmd = ["rsvg-convert", "-o", out]
        cmd += ["-w", str(width_px)] if width_px else ["-z", "3"]
        subprocess.run(cmd + [path], check=True)
        return out

    @staticmethod
    def image_size(path):
        with open(path, "rb") as f:
            head = f.read(32)
            if head[:8] == b"\x89PNG\r\n\x1a\n":
                return struct.unpack(">II", head[16:24])
            f.seek(0)
            data = f.read()
        if data[:2] == b"\xff\xd8":
            i = 2
            while i < len(data):
                if data[i] != 0xFF:
                    i += 1; continue
                marker = data[i + 1]
                if marker in (0xC0, 0xC1, 0xC2):
                    h, w = struct.unpack(">HH", data[i + 5:i + 9])
                    return w, h
                i += 2 + struct.unpack(">H", data[i + 2:i + 4])[0]
        return None

    # ---------------------------------------------------------------- styles / document setup
    def setup(self):
        d = self.doc
        sec = d.sections[0]
        sec.page_width, sec.page_height = Mm(210), Mm(297)
        sec.top_margin, sec.bottom_margin = Mm(25), Mm(20)
        sec.left_margin = sec.right_margin = Mm(18)
        sec.header_distance, sec.footer_distance = Mm(9), Mm(8)
        sec.different_first_page_header_footer = True
        if self.rtl:
            put(sec._sectPr, "bidi")

        st = d.styles
        normal = st["Normal"]
        rPr = normal.element.get_or_add_rPr()
        self.fonts_el(rPr)
        self.size(rPr, 10.5)
        put(rPr, "color", {"val": PALETTE["text"]})
        put(rPr, "lang", {"val": "en-US", "bidi": "ar-IQ"})
        pf = normal.paragraph_format
        pf.space_after = Pt(6)
        pf.line_spacing = 1.25 if self.rtl else 1.15
        if self.rtl:
            put(normal.element.get_or_add_pPr(), "bidi")
        # doc defaults too (tables, headers and footers start from them)
        dd = d.styles.element.find(qn("w:docDefaults"))
        if dd is not None:
            rpd = dd.find(qn("w:rPrDefault"))
            if rpd is not None and rpd.find(qn("w:rPr")) is not None:
                self.fonts_el(rpd.find(qn("w:rPr")))
                put(rpd.find(qn("w:rPr")), "lang", {"val": "en-US", "bidi": "ar-IQ"})

        spec = {1: (17, "navy", 18, 8), 2: (13.5, "core_blue", 14, 5), 3: (11.5, "navy", 10, 4)}
        for lvl, (size, color, before, after) in spec.items():
            s = st["Heading %d" % lvl]
            r = s.element.get_or_add_rPr()
            self.fonts_el(r)
            self.size(r, size)
            put(r, "b"); put(r, "bCs")
            put(r, "i", {"val": 0}); put(r, "iCs", {"val": 0})
            put(r, "color", {"val": PALETTE[color]})
            s.paragraph_format.space_before = Pt(before)
            s.paragraph_format.space_after = Pt(after)
            s.paragraph_format.keep_with_next = True
            if lvl == 1:
                pPr = s.element.get_or_add_pPr()
                bd = put(pPr, "pBdr")
                el = OxmlElement("w:bottom")
                for k, v in (("val", "single"), ("sz", "12"), ("space", "4"), ("color", PALETTE["teal"])):
                    el.set(qn("w:" + k), v)
                bd.append(el)
            if self.rtl:
                put(s.element.get_or_add_pPr(), "bidi")

        def pstyle(name, size, color=None, bold=False, before=0, after=4, base="Normal"):
            try:
                s = st[name]
            except KeyError:
                s = st.add_style(name, WD_STYLE_TYPE.PARAGRAPH)
                s.base_style = st[base]
            r = s.element.get_or_add_rPr()
            self.fonts_el(r)
            self.size(r, size)
            if color:
                put(r, "color", {"val": PALETTE[color]})
            if bold:
                put(r, "b"); put(r, "bCs")
            s.paragraph_format.space_before = Pt(before)
            s.paragraph_format.space_after = Pt(after)
            return s

        title = pstyle("Title", 26, "navy", True, 0, 10)
        tpPr = title.element.get_or_add_pPr()
        if tpPr.find(qn("w:pBdr")) is not None:
            tpPr.remove(tpPr.find(qn("w:pBdr")))
        for name, size, color, before, after in (("Subtitle", 13.5, "muted", 0, 18), ("Caption", 9, "muted", 2, 10)):
            r = pstyle(name, size, color, False, before, after).element.get_or_add_rPr()
            for tag in ("b", "bCs", "i", "iCs"):
                put(r, tag, {"val": 0})
            put(r, "spacing", {"val": 0})  # the default template's Subtitle is letter-spaced
        th = pstyle("TOC Heading", 18, "navy", True, 0, 12)
        th.element.set(qn("w:styleId"), "TOCHeading")
        for k in (1, 2, 3):
            s = pstyle("toc %d" % k, 10.5 if k < 3 else 9.5, "navy" if k == 1 else "text", k == 1, 4 if k == 1 else 1, 1)
            s.element.set(qn("w:styleId"), "TOC%d" % k)  # the built-in id Word uses when it rebuilds the TOC
            s.paragraph_format.left_indent = Mm(6 * (k - 1))

    # ---------------------------------------------------------------- cover / toc / header / footer
    def picture(self, p, path, width_mm):
        p.add_run().add_picture(path, width=Mm(width_mm))

    def cover(self):
        m, L = self.meta, self.L
        png = os.path.join(ASSETS, "brand", "png")
        p = self.paragraph()
        p.paragraph_format.space_before = Pt(6)
        p.paragraph_format.space_after = Pt(2)
        self.picture(p, os.path.join(png, "netcore-wordmark.png"), 80)
        if self.rtl:
            p = self.paragraph()
            self.add_text(p, TAGLINE["ar"], color="muted", size=11)
        p = self.paragraph()
        p.paragraph_format.space_before = Pt(26)
        p.paragraph_format.space_after = Pt(22)
        self.picture(p, os.path.join(png, "rule.png"), 46)
        if m.get("doc_type"):
            p = self.paragraph()
            self.add_text(p, m["doc_type"], bold=True, color="teal_text", size=10)
        p = self.paragraph(style="Title")
        self.add_text(p, m["title"])
        p = self.paragraph(style="Subtitle")
        self.add_text(p, m["subtitle"])

        rows = [("version", m["version"]), ("date", m["date"]), ("status", m["status"]), ("owner", m["owner"]),
                ("sha", m["source_sha"]), ("audience", m["audience"])]
        t = self.table(len(rows), 2, borders=False)
        for i, (k, v) in enumerate(rows):
            for j, cell in enumerate(t.rows[i].cells):
                tcPr = cell._tc.get_or_add_tcPr()
                b = put(tcPr, "tcBorders")
                for side in ("top", "bottom") if i == 0 else ("bottom",):
                    el = OxmlElement("w:" + side)
                    for a, val in (("val", "single"), ("sz", "4"), ("space", "0"), ("color", PALETTE["border"])):
                        el.set(qn("w:" + a), val)
                    b.append(el)
                self.cell_margins(cell, 90, 60, 90)
                put(tcPr, "tcW", {"w": 2600 if j == 0 else 7300, "type": "dxa"})
                cp = self.cell_par(cell)
                cp.paragraph_format.space_after = Pt(0)
                if j == 0:
                    self.add_text(cp, L[k], color="muted", size=10)
                else:
                    self.add_text(cp, str(v), bold=True, size=10)
        p = self.paragraph()
        p.paragraph_format.space_before = Pt(60)
        band = self.table(1, 2, borders=False)
        c0, c1 = band.rows[0].cells
        for c in (c0, c1):
            self.shade(c._tc.get_or_add_tcPr(), PALETTE["navy"], "tcPr")
            self.cell_margins(c, 260, 260, 260)
            put(c._tc.get_or_add_tcPr(), "vAlign", {"val": "center"})
        put(c0._tc.get_or_add_tcPr(), "tcW", {"w": 1500, "type": "dxa"})
        put(c1._tc.get_or_add_tcPr(), "tcW", {"w": 8400, "type": "dxa"})
        cp = self.cell_par(c0)
        cp.paragraph_format.space_after = Pt(0)
        self.picture(cp, os.path.join(png, "netcore-mark-white.png"), 16)
        cp = self.cell_par(c1)
        cp.paragraph_format.space_after = Pt(0)
        self.add_text(cp, "ITC NetCore", bold=True, color="FFFFFF", size=14)
        cp = c1.add_paragraph(); self.para_dir(cp); cp.paragraph_format.space_after = Pt(0)
        self.add_text(cp, TAGLINE[self.lang], color="FFFFFF", size=10)
        cp = c1.add_paragraph(); self.para_dir(cp); cp.paragraph_format.space_after = Pt(0)
        cp.paragraph_format.space_before = Pt(4)
        self.add_text(cp, RESPONSIBILITY[self.lang], color="DCE5EF", size=8.5)
        p = self.paragraph()
        p.add_run().add_break(WD_BREAK.PAGE)

    def field(self, p, instr, placeholder="", size=None, color=None):
        def run_with(child):
            r = OxmlElement("w:r")
            rPr = OxmlElement("w:rPr")
            r.append(rPr)
            if size:
                self.size(rPr, size)
            if color:
                put(rPr, "color", {"val": PALETTE.get(color, color)})
            r.append(child)
            p._p.append(r)
            return r
        b = OxmlElement("w:fldChar"); b.set(qn("w:fldCharType"), "begin")
        run_with(b)
        it = OxmlElement("w:instrText"); it.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        it.text = " %s " % instr
        run_with(it)
        s = OxmlElement("w:fldChar"); s.set(qn("w:fldCharType"), "separate")
        run_with(s)
        t = OxmlElement("w:t"); t.text = placeholder
        run_with(t)
        e = OxmlElement("w:fldChar"); e.set(qn("w:fldCharType"), "end")
        run_with(e)

    def toc(self, entries):
        p = self.paragraph(style="TOC Heading")
        self.add_text(p, self.L["contents"])
        # TOC field: begin/separate in the first entry, end after the last; the result is pre-filled with the
        # heading list (no page numbers) and marked dirty so Word refreshes it (settings: updateFields).
        first = True
        paras = []
        for e in entries:
            p = self.paragraph(style="toc %d" % e["level"])
            if first:
                r = OxmlElement("w:r"); fc = OxmlElement("w:fldChar")
                fc.set(qn("w:fldCharType"), "begin"); fc.set(qn("w:dirty"), "true"); r.append(fc); p._p.append(r)
                r = OxmlElement("w:r"); it = OxmlElement("w:instrText")
                it.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
                it.text = ' TOC \\o "1-3" \\h \\z \\u '
                r.append(it); p._p.append(r)
                r = OxmlElement("w:r"); fc = OxmlElement("w:fldChar"); fc.set(qn("w:fldCharType"), "separate")
                r.append(fc); p._p.append(r)
                first = False
            self.add_text(p, "%s  " % e["num"])
            self.add_inlines(p, e["inl"])
            paras.append(p)
        if paras:
            r = OxmlElement("w:r"); fc = OxmlElement("w:fldChar"); fc.set(qn("w:fldCharType"), "end")
            r.append(fc); paras[-1]._p.append(r)
        p = self.paragraph()
        p.paragraph_format.space_before = Pt(10)
        self.add_text(p, self.L["toc_hint"], italic=True, color="muted", size=8.5)
        p = self.paragraph()
        p.add_run().add_break(WD_BREAK.PAGE)

    def header_footer(self):
        sec = self.doc.sections[0]
        m = self.meta
        png = os.path.join(ASSETS, "brand", "png")
        # header: [mark ITC NetCore | tagline]  ...  [title]
        hdr = sec.header
        hdr.is_linked_to_previous = False
        t = hdr.add_table(1, 2, Mm(174))
        hdr.paragraphs[0]._p.addprevious(t._tbl)
        tblPr = t._tbl.tblPr
        if self.rtl:
            put(tblPr, "bidiVisual")
        put(tblPr, "tblW", {"w": 5000, "type": "pct"})
        c0, c1 = t.rows[0].cells
        for c in (c0, c1):
            tcPr = c._tc.get_or_add_tcPr()
            b = put(tcPr, "tcBorders")
            el = OxmlElement("w:bottom")
            for a, val in (("val", "single"), ("sz", "4"), ("space", "0"), ("color", PALETTE["border"])):
                el.set(qn("w:" + a), val)
            b.append(el)
            put(tcPr, "vAlign", {"val": "center"})
        put(c0._tc.get_or_add_tcPr(), "tcW", {"w": 5600, "type": "dxa"})
        put(c1._tc.get_or_add_tcPr(), "tcW", {"w": 4300, "type": "dxa"})
        p = self.cell_par(c0)
        p.paragraph_format.space_after = Pt(3)
        r = p.add_run()
        r.add_picture(os.path.join(png, "netcore-mark.png"), width=Mm(3.6))
        self.add_text(p, "  ")
        self.add_text(p, "ITC NetCore", bold=True, color="navy", size=7.5)
        self.add_text(p, "  " + TAGLINE[self.lang], color="muted", size=7.5)
        p = self.cell_par(c1)
        p.paragraph_format.space_after = Pt(3)
        p.alignment = self.jc_visual("end")
        self.add_text(p, m["title"], color="muted", size=7.5)
        self.tiny(hdr.paragraphs[-1])
        # footer: [version · status] ... [Page X of Y]
        ftr = sec.footer
        ftr.is_linked_to_previous = False
        t = ftr.add_table(1, 2, Mm(174))
        ftr.paragraphs[0]._p.addprevious(t._tbl)
        tblPr = t._tbl.tblPr
        if self.rtl:
            put(tblPr, "bidiVisual")
        put(tblPr, "tblW", {"w": 5000, "type": "pct"})
        c0, c1 = t.rows[0].cells
        for c in (c0, c1):
            tcPr = c._tc.get_or_add_tcPr()
            b = put(tcPr, "tcBorders")
            el = OxmlElement("w:top")
            for a, val in (("val", "single"), ("sz", "4"), ("space", "0"), ("color", PALETTE["border"])):
                el.set(qn("w:" + a), val)
            b.append(el)
        put(c0._tc.get_or_add_tcPr(), "tcW", {"w": 7400, "type": "dxa"})
        put(c1._tc.get_or_add_tcPr(), "tcW", {"w": 2500, "type": "dxa"})
        p = self.cell_par(c0)
        p.paragraph_format.space_before = Pt(3)
        self.add_text(p, "%s · %s" % (m["version"], m["status"]), color="muted", size=7.5)
        p = self.cell_par(c1)
        p.paragraph_format.space_before = Pt(3)
        p.alignment = self.jc_visual("end")
        self.add_text(p, self.L["page"] + " ", color="muted", size=7.5)
        self.field(p, "PAGE", "1", 7.5, "muted")
        self.add_text(p, " %s " % self.L["of"], color="muted", size=7.5)
        self.field(p, "NUMPAGES", "1", 7.5, "muted")
        self.tiny(ftr.paragraphs[-1])

    def jc_visual(self, side):
        """w:jc for a visual side. In bidi (RTL) paragraphs Word reads jc left/right as start/end, so the
        visual sides swap; side may also be "start" / "end"."""
        if side in ("start", "end"):
            side = {"start": "right", "end": "left"}[side] if self.rtl else {"start": "left", "end": "right"}[side]
        if self.rtl:
            side = {"left": "right", "right": "left"}[side]
        return WD_ALIGN_PARAGRAPH.LEFT if side == "left" else WD_ALIGN_PARAGRAPH.RIGHT

    def tiny(self, p):
        """Shrink the mandatory trailing paragraph of a header/footer."""
        pf = p.paragraph_format
        pf.space_after = Pt(0); pf.space_before = Pt(0); pf.line_spacing = Pt(2)
        self.size(put(p._p.get_or_add_pPr(), "rPr"), 1)

    # ---------------------------------------------------------------- body blocks
    def numbering_restart(self, style_name):
        """A new w:num restarting at 1 for the numbering used by a list style (each Markdown list restarts)."""
        st = self.doc.styles[style_name]
        numPr = st.element.pPr.find(qn("w:numPr")) if st.element.pPr is not None else None
        if numPr is None:
            return None
        num_id = int(numPr.find(qn("w:numId")).get(qn("w:val")))
        numbering = self.doc.part.numbering_part.element
        abstract = None
        for n in numbering.findall(qn("w:num")):
            if int(n.get(qn("w:numId"))) == num_id:
                abstract = n.find(qn("w:abstractNumId")).get(qn("w:val"))
        new_id = max(int(n.get(qn("w:numId"))) for n in numbering.findall(qn("w:num"))) + 1
        num = OxmlElement("w:num"); num.set(qn("w:numId"), str(new_id))
        a = OxmlElement("w:abstractNumId"); a.set(qn("w:val"), abstract); num.append(a)
        for lvl in range(9):
            o = OxmlElement("w:lvlOverride"); o.set(qn("w:ilvl"), str(lvl))
            so = OxmlElement("w:startOverride"); so.set(qn("w:val"), "1"); o.append(so); num.append(o)
        numbering.append(num)
        return new_id

    def blocks(self, blocks, container=None, depth=0):
        for b in blocks:
            self.block(b, container, depth)

    def block(self, b, container=None, depth=0):
        t = b["t"]
        if t == "heading":
            lvl = min(b["level"], 3)
            p = self.paragraph(container, style="Heading %d" % lvl)
            if b.get("num"):
                self.add_text(p, b["num"], color="teal_text")
                self.add_text(p, "  ")
            self.add_inlines(p, b["inl"])
        elif t == "para":
            p = self.paragraph(container)
            self.add_inlines(p, b["inl"])
        elif t == "list":
            self.list_(b, container, depth)
        elif t == "table":
            self.table_(b, container)
        elif t == "image":
            self.image_(b, container)
        elif t == "quote":
            for inner in b["blocks"]:
                if inner["t"] == "para":
                    p = self.paragraph(container)
                    p.paragraph_format.left_indent = Mm(6)
                    self.add_inlines(p, inner["inl"], color="muted")
                else:
                    self.block(inner, container, depth)
        elif t == "callout":
            self.callout_(b, container)
        elif t == "code":
            for k, line in enumerate(b["text"].split("\n")):
                p = self.paragraph(container, rtl=False)
                p.alignment = WD_ALIGN_PARAGRAPH.LEFT
                pf = p.paragraph_format
                pf.space_after = Pt(0); pf.line_spacing = 1.0
                pPr = p._p.get_or_add_pPr()
                self.shade(pPr, PALETTE["bg"], "pPr")
                self.add_text(p, line or " ", font=self.f_mono, size=8.6, code=False)
            self.paragraph(container).paragraph_format.space_after = Pt(2)
        elif t == "hr":
            p = self.paragraph(container)
            bd = put(p._p.get_or_add_pPr(), "pBdr")
            el = OxmlElement("w:bottom")
            for a, val in (("val", "single"), ("sz", "4"), ("space", "1"), ("color", PALETTE["border"])):
                el.set(qn("w:" + a), val)
            bd.append(el)
        elif t == "pagebreak":
            p = self.paragraph(container)
            p.add_run().add_break(WD_BREAK.PAGE)

    def list_(self, b, container, depth):
        level = min(depth, 1)
        if b["ordered"]:
            style = "List Number" if level == 0 else "List Number 2"
            num_id = self.numbering_restart(style)
        else:
            style = "List Bullet" if level == 0 else "List Bullet 2"
            num_id = None
        for item in b["items"]:
            first = True
            for blk in item:
                if first and blk["t"] == "para":
                    p = self.paragraph(container, style=style)
                    p.paragraph_format.space_after = Pt(3)
                    if num_id:
                        numPr = put(p._p.get_or_add_pPr(), "numPr")
                        il = OxmlElement("w:ilvl"); il.set(qn("w:val"), "0"); numPr.append(il)
                        ni = OxmlElement("w:numId"); ni.set(qn("w:val"), str(num_id)); numPr.append(ni)
                    self.add_inlines(p, blk["inl"])
                    first = False
                elif blk["t"] == "list":
                    self.list_(blk, container, depth + 1)
                else:
                    self.block(blk, container, depth + 1)

    def table_(self, b, container):
        ncols = len(b["head"])
        t = self.table(len(b["rows"]) + 1, ncols, container)
        t.style = self.doc.styles["Table Grid"]
        tblPr = t._tbl.tblPr
        bd = put(tblPr, "tblBorders")
        for side in ("top", "left", "bottom", "right", "insideH", "insideV"):
            el = OxmlElement("w:" + side)
            for a, val in (("val", "single"), ("sz", "4"), ("space", "0"), ("color", PALETTE["border"])):
                el.set(qn("w:" + a), val)
            bd.append(el)
        cm = put(tblPr, "tblCellMar")
        for s, v in (("top", 70), ("left", 100), ("bottom", 70), ("right", 100)):
            el = OxmlElement("w:" + s); el.set(qn("w:w"), str(v)); el.set(qn("w:type"), "dxa"); cm.append(el)
        # header row repeats on every page; rows never split across pages
        hdr_tr = t.rows[0]._tr
        trPr = hdr_tr.get_or_add_trPr()
        put(trPr, "tblHeader")
        put(trPr, "cantSplit")
        # column widths proportional to the longest text (bounded), so narrow code columns do not take space
        lens = [max([len(mdparse.inline_text(b["head"][j]))] + [len(mdparse.inline_text(r[j])) for r in b["rows"]])
                for j in range(ncols)]
        lens = [min(max(x, 6), 60) for x in lens]
        total_tw = int(CONTENT_W_MM / 25.4 * 1440)
        widths = [int(total_tw * x / sum(lens)) for x in lens]
        grid = t._tbl.tblGrid
        for j, gc in enumerate(grid.findall(qn("w:gridCol"))):
            gc.set(qn("w:w"), str(widths[j]))
        align_map = {"center": WD_ALIGN_PARAGRAPH.CENTER, "right": WD_ALIGN_PARAGRAPH.RIGHT,
                     "left": WD_ALIGN_PARAGRAPH.LEFT}
        for i, row in enumerate([b["head"]] + b["rows"]):
            tr = t.rows[i]
            if i:
                put(tr._tr.get_or_add_trPr(), "cantSplit")
            for j, cell in enumerate(tr.cells):
                tcPr = cell._tc.get_or_add_tcPr()
                put(tcPr, "tcW", {"w": widths[j], "type": "dxa"})
                if i == 0:
                    self.shade(tcPr, PALETTE["navy"], "tcPr")
                elif i % 2 == 0:
                    self.shade(tcPr, PALETTE["bg"], "tcPr")
                p = self.cell_par(cell)
                p.paragraph_format.space_after = Pt(0)
                p.paragraph_format.line_spacing = 1.1
                al = b["align"][j]
                if al == "center":
                    p.alignment = align_map[al]
                elif al:
                    p.alignment = self.jc_visual(al)
                if i == 0:
                    self.add_inlines(p, row[j], bold=True, color="FFFFFF", size=9.3)
                else:
                    self.add_inlines(p, row[j], size=9.3)
        self.paragraph(container).paragraph_format.space_after = Pt(2)

    def image_(self, b, container):
        path = os.path.join(self.docdir, b["src"])
        self.fig += 1
        if not os.path.isfile(path):
            self.warnings.append("missing image: %s" % b["src"])
            p = self.paragraph(container)
            self.add_text(p, "[missing image: %s]" % b["src"], color="danger", bold=True)
            return
        pct = float(b["width"].rstrip("%")) / 100 if b.get("width") else 1.0
        target_w = CONTENT_W_MM * pct
        png = self.png_for(path, int(target_w / 25.4 * 300) if path.lower().endswith(".svg") else None)
        size = self.image_size(png)
        if size:
            w_px, h_px = size
            if not path.lower().endswith(".svg"):
                natural_mm = w_px / 150 * 25.4  # never upscale bitmaps beyond 150 dpi
                target_w = min(target_w, natural_mm)
            if target_w * h_px / w_px > MAX_IMG_H_MM:
                target_w = MAX_IMG_H_MM * w_px / h_px
        p = self.paragraph(container)
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p.paragraph_format.keep_with_next = True
        p.paragraph_format.space_before = Pt(4)
        p.add_run().add_picture(png, width=Mm(target_w))
        cap = b["title"] or b["alt"]
        if cap:
            p = self.paragraph(container, style="Caption")
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            self.add_text(p, "%s %d  " % (self.L["figure"], self.fig), bold=True, color="navy")
            self.add_text(p, cap)

    def callout_(self, b, container):
        tone, soft, border = (PALETTE[k] for k in CALLOUT_TONES[b["kind"]])
        t = self.table(1, 1, container, borders=False)
        cell = t.rows[0].cells[0]
        tcPr = cell._tc.get_or_add_tcPr()
        put(tcPr, "tcW", {"w": int(CONTENT_W_MM / 25.4 * 1440), "type": "dxa"})
        self.shade(tcPr, soft, "tcPr")
        self.cell_borders(cell, border, 4, start=(24, tone))
        self.cell_margins(cell, 100, 160, 60)
        put(t.rows[0]._tr.get_or_add_trPr(), "cantSplit")
        p = self.cell_par(cell)
        p.paragraph_format.space_after = Pt(2)
        p.paragraph_format.keep_with_next = True
        self.add_text(p, b.get("title") or self.L[b["kind"]], bold=True, color=tone, size=10)
        for inner in b["blocks"]:
            self.block(inner, cell)
        last = cell.paragraphs[-1]
        last.paragraph_format.space_after = Pt(4)
        self.paragraph(container).paragraph_format.space_after = Pt(2)

    # ---------------------------------------------------------------- build
    def build(self, blocks, toc, out):
        self.setup()
        self.cover()
        self.toc(toc)
        self.header_footer()
        self.blocks(blocks)
        # the document starts with an empty paragraph in the default template
        body = self.doc.element.body
        first = body[0]
        if first.tag == qn("w:p") and not first.xpath(".//w:t") and not first.xpath(".//w:drawing"):
            body.remove(first)
        cp = self.doc.core_properties
        cp.title = self.meta["title"]
        cp.subject = self.meta["subtitle"]
        cp.author = self.meta["owner"]
        cp.keywords = "ITC NetCore; %s; %s" % (self.meta["version"], self.meta["source_sha"])
        cp.comments = self.meta["status"]
        cp.language = "ar-IQ" if self.rtl else "en-US"
        cp.category = self.meta["audience"]
        settings = self.doc.settings.element
        put(settings, "updateFields", {"val": "true"})
        if self.rtl:
            put(settings, "themeFontLang", {"val": "en-US", "bidi": "ar-IQ"})
        buf = io.BytesIO()
        self.doc.save(buf)
        data = buf.getvalue()
        if self.embed_fonts:
            data = embed_fonts(data, {self.f_latin: "inter", self.f_arabic: "noto-sans-arabic"})
        with open(out, "wb") as f:
            f.write(data)


# -------------------------------------------------------------------- font embedding
TTF_FILES = {
    "inter": {"embedRegular": "inter-latin-regular.ttf", "embedBold": "inter-latin-bold.ttf"},
    "noto-sans-arabic": {"embedRegular": "noto-sans-arabic-regular.ttf", "embedBold": "noto-sans-arabic-bold.ttf"},
}
W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
R_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"


def obfuscate(font_bytes, guid):
    key = bytes.fromhex(guid.strip("{}").replace("-", ""))[::-1]
    head = bytearray(font_bytes[:32])
    for i in range(32):
        head[i] ^= key[i % 16]
    return bytes(head) + font_bytes[32:]


def embed_fonts(docx_bytes, families):
    """Embed the TTF brand fonts into a saved DOCX (fontTable entries + obfuscated .odttf parts)."""
    from lxml import etree
    ttf_dir = os.path.join(ASSETS, "fonts", "ttf")
    zin = zipfile.ZipFile(io.BytesIO(docx_bytes))
    files = {n: zin.read(n) for n in zin.namelist()}
    ft = etree.fromstring(files["word/fontTable.xml"])
    rels_name = "word/_rels/fontTable.xml.rels"
    rels = etree.fromstring(files[rels_name]) if rels_name in files else etree.fromstring(
        b'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>')
    n = 0
    for family, key in families.items():
        if key not in TTF_FILES:
            continue
        paths = {k: os.path.join(ttf_dir, v) for k, v in TTF_FILES[key].items()}
        if not all(os.path.isfile(p) for p in paths.values()):
            print("render_docx: WARNING TTF fonts missing in %s (run prepare_assets.sh); %s not embedded"
                  % (ttf_dir, family), file=sys.stderr)
            continue
        font_el = None
        for f in ft.findall("{%s}font" % W_NS):
            if f.get("{%s}name" % W_NS) == family:
                font_el = f
        if font_el is None:
            font_el = etree.SubElement(ft, "{%s}font" % W_NS)
            font_el.set("{%s}name" % W_NS, family)
            cs = etree.SubElement(font_el, "{%s}charset" % W_NS); cs.set("{%s}val" % W_NS, "00")
            fam = etree.SubElement(font_el, "{%s}family" % W_NS); fam.set("{%s}val" % W_NS, "swiss")
            pitch = etree.SubElement(font_el, "{%s}pitch" % W_NS); pitch.set("{%s}val" % W_NS, "variable")
        for kind in ("embedRegular", "embedBold"):
            n += 1
            guid = "{%s}" % str(uuid.uuid4()).upper()
            part = "fonts/font%d.odttf" % n
            with open(paths[kind], "rb") as fh:
                files["word/" + part] = obfuscate(fh.read(), guid)
            rid = "rIdFont%d" % n
            rel = etree.SubElement(rels, "{http://schemas.openxmlformats.org/package/2006/relationships}Relationship")
            rel.set("Id", rid)
            rel.set("Type", "http://schemas.openxmlformats.org/officeDocument/2006/relationships/font")
            rel.set("Target", part)
            e = etree.SubElement(font_el, "{%s}%s" % (W_NS, kind))
            e.set("{%s}id" % R_NS, rid)
            e.set("{%s}fontKey" % W_NS, guid)
    files["word/fontTable.xml"] = etree.tostring(ft, xml_declaration=True, encoding="UTF-8", standalone=True)
    files[rels_name] = etree.tostring(rels, xml_declaration=True, encoding="UTF-8", standalone=True)
    ct = etree.fromstring(files["[Content_Types].xml"])
    CT = "http://schemas.openxmlformats.org/package/2006/content-types"
    if not any(d.get("Extension") == "odttf" for d in ct.findall("{%s}Default" % CT)):
        d = etree.SubElement(ct, "{%s}Default" % CT)
        d.set("Extension", "odttf")
        d.set("ContentType", "application/vnd.openxmlformats-officedocument.obfuscatedFont")
    files["[Content_Types].xml"] = etree.tostring(ct, xml_declaration=True, encoding="UTF-8", standalone=True)
    st = etree.fromstring(files["word/settings.xml"])
    if st.find("{%s}embedTrueTypeFonts" % W_NS) is None:
        # embedTrueTypeFonts / saveSubsetFonts sit after zoom etc.; insert at the schema position
        order = ORDER["settings"]
        for tag in ("embedTrueTypeFonts", "saveSubsetFonts"):
            el = etree.Element("{%s}%s" % (W_NS, tag))
            idx = order.index(tag)
            pos = len(st)
            for i, child in enumerate(st):
                name = etree.QName(child).localname
                if name in order and order.index(name) > idx:
                    pos = i
                    break
            st.insert(pos, el)
    files["word/settings.xml"] = etree.tostring(st, xml_declaration=True, encoding="UTF-8", standalone=True)
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        order_names = ["[Content_Types].xml"] + [k for k in files if k != "[Content_Types].xml"]
        for name in order_names:
            z.writestr(name, files[name])
    return out.getvalue()


def main():
    args = sys.argv[1:]
    if len(args) < 3:
        sys.exit(__doc__)
    docdir, out, workdir = args[:3]
    meta, text, _ = load_doc(docdir)
    blocks = mdparse.parse(text)
    toc = mdparse.number_headings(blocks, int(meta.get("toc_depth", 3)))
    r = DocxRenderer(meta, docdir, workdir, embed_fonts="--no-embed-fonts" not in args)
    r.build(blocks, toc, out)
    for w in r.warnings:
        print("render_docx: WARNING " + w, file=sys.stderr)
    print("render_docx: wrote %s" % out)


if __name__ == "__main__":
    main()
