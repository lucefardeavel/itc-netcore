"""Structural check of a built DOCX (python-docx): headings, TOC field, tables + repeating header rows, images,
RTL markup (section / paragraphs / Arabic runs), fonts referenced and embedded, footer page fields.

usage: check_docx.py <file.docx>     exit 0 PASS, 3 FAIL
"""
import re
import sys
import zipfile

from docx import Document
from docx.oxml.ns import qn

AR = re.compile(r"[؀-ۿ]")


def main():
    path = sys.argv[1]
    d = Document(path)
    body = d.element.body
    problems = []
    heads = {}
    for p in d.paragraphs:
        sn = p.style.name if p.style is not None else ""
        if sn.startswith("Heading"):
            heads[sn] = heads.get(sn, 0) + 1
    instr = " ".join(x.text or "" for x in body.iter(qn("w:instrText")))
    has_toc = "TOC" in instr
    tables = body.findall(".//" + qn("w:tbl"))
    hdr_rows = len(body.findall(".//" + qn("w:tblHeader")))
    images = len(body.findall(".//" + qn("w:drawing")))
    sect = body.find(qn("w:sectPr"))
    sect_rtl = sect is not None and sect.find(qn("w:bidi")) is not None
    paras = body.findall(".//" + qn("w:p"))
    bidi_paras = [p for p in paras if p.find(qn("w:pPr")) is not None and p.find(qn("w:pPr")).find(qn("w:bidi")) is not None
                  and p.find(qn("w:pPr")).find(qn("w:bidi")).get(qn("w:val")) not in ("0", "false")]
    ar_runs = no_rtl = 0
    for r in body.iter(qn("w:r")):
        t = "".join(x.text or "" for x in r.findall(qn("w:t")))
        if AR.search(t):
            ar_runs += 1
            rPr = r.find(qn("w:rPr"))
            if rPr is None or rPr.find(qn("w:rtl")) is None:
                no_rtl += 1
    missing_img = sum(1 for p in d.paragraphs if "[missing image:" in p.text)
    z = zipfile.ZipFile(path)
    ft = z.read("word/fontTable.xml").decode("utf-8")
    embedded = sorted(set(re.findall(r'<w:font w:name="([^"]+)">(?:(?!</w:font>).)*?<w:embed', ft, re.S)))
    rfonts = set()
    for name in z.namelist():
        if name.startswith("word/") and name.endswith(".xml"):
            rfonts.update(re.findall(r'w:(?:ascii|cs)="([^"]+)"', z.read(name).decode("utf-8")))
    footer_fields = " ".join(x.text or "" for s in d.sections for x in s.footer._element.iter(qn("w:instrText")))
    lang_ar = d.core_properties.language.startswith("ar")

    if not has_toc:
        problems.append("no TOC field")
    if not heads:
        problems.append("no Heading styles")
    if tables and hdr_rows == 0:
        problems.append("tables without repeating header row")
    if missing_img:
        problems.append("%d missing image placeholder(s)" % missing_img)
    if "PAGE" not in footer_fields:
        problems.append("no PAGE field in footer")
    if lang_ar:
        if not sect_rtl:
            problems.append("Arabic document without RTL section (w:bidi)")
        if no_rtl:
            problems.append("%d Arabic run(s) without w:rtl" % no_rtl)
    print("docx     : %s" % path)
    print("result   : %s%s" % ("FAIL" if problems else "PASS", (" — " + "; ".join(problems)) if problems else ""))
    print("headings : %s" % ", ".join("%s=%d" % kv for kv in sorted(heads.items())))
    print("toc field: %s   footer fields: %s" % (has_toc, footer_fields.split()))
    print("tables   : %d (header rows marked repeat: %d)   images: %d" % (len(tables), hdr_rows, images))
    print("rtl      : section=%s  bidi paragraphs=%d/%d  Arabic runs=%d (without w:rtl: %d)"
          % (sect_rtl, len(bidi_paras), len(paras), ar_runs, no_rtl))
    print("fonts    : referenced %s; embedded %s" % (sorted(rfonts), embedded))
    sys.exit(3 if problems else 0)


if __name__ == "__main__":
    main()
