"""Shared helpers: document metadata, labels, brand constants, bidi segmentation (stdlib only)."""
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
ASSETS = os.path.join(ROOT, "assets")

PRODUCT = "ITC NetCore"
BRAND = "ITC"
TAGLINE = {"en": "ISP Management & Billing Platform", "ar": "منصة إدارة وفوترة مزوّدي خدمة الإنترنت"}
RESPONSIBILITY = {"en": "Developed and maintained by ITC", "ar": "تطوير وصيانة ITC"}

# brand primitives / semantic tokens (ui/ui/styles/nuxr-brand.css, light theme)
PALETTE = {
    "navy": "193768", "navy_deep": "0F2648", "core_blue": "245D99", "interactive": "3275B5",
    "teal": "117C88", "teal_text": "0E7480", "text": "18283E", "muted": "5A6E86", "border": "DCE5EF",
    "surface2": "EAF2F9", "bg": "F5F8FC",
    "info": "245D99", "info_soft": "E6F2FC", "info_border": "B3CBE3",
    "warning": "A35100", "warning_soft": "FFF2DF", "warning_border": "F0C79A",
    "danger": "B42318", "danger_soft": "FCE9E6", "danger_border": "F0B3AD",
    "neutral_soft": "EDF1F6",
}

# callout kind -> (tone colour, soft background, border)
CALLOUT_TONES = {
    "note": ("info", "info_soft", "info_border"),
    "warning": ("warning", "warning_soft", "warning_border"),
    "finance": ("danger", "danger_soft", "danger_border"),
    "security": ("navy_deep", "neutral_soft", "info_border"),
}

LABELS = {
    "en": {
        "contents": "Contents", "version": "Version", "date": "Date", "status": "Status", "owner": "Document owner",
        "sha": "Source SHA", "audience": "Audience", "page": "Page", "of": "of", "figure": "Figure",
        "note": "Note", "warning": "Warning", "finance": "Finance warning", "security": "Security warning",
        "toc_hint": "Right-click and choose Update Field to refresh the table of contents.",
    },
    "ar": {
        "contents": "المحتويات", "version": "الإصدار", "date": "التاريخ", "status": "الحالة", "owner": "مالك الوثيقة",
        "sha": "مرجع المصدر (SHA)", "audience": "الجمهور المستهدف", "page": "صفحة", "of": "من", "figure": "شكل",
        "note": "ملاحظة", "warning": "تحذير", "finance": "تحذير مالي", "security": "تحذير أمني",
        "toc_hint": "انقر بزر الفأرة الأيمن واختر «تحديث الحقل» لتحديث جدول المحتويات.",
    },
}

REQUIRED_META = ["title", "subtitle", "language", "version", "date", "owner", "status", "source_sha", "audience"]


def load_doc(docdir):
    """Return (meta, markdown_text, name). Exits with a message on invalid input."""
    mp = os.path.join(docdir, "meta.json")
    cp = os.path.join(docdir, "content.md")
    for p in (mp, cp):
        if not os.path.isfile(p):
            sys.exit("docpipe: missing %s" % p)
    with open(mp, encoding="utf-8") as f:
        meta = json.load(f)
    missing = [k for k in REQUIRED_META if not str(meta.get(k, "")).strip()]
    if missing:
        sys.exit("docpipe: meta.json is missing: %s" % ", ".join(missing))
    if meta["language"] not in ("ar", "en"):
        sys.exit("docpipe: meta.json language must be ar or en")
    name = meta.get("name") or os.path.basename(os.path.normpath(docdir))
    if not re.match(r"^[A-Za-z0-9._-]+$", name):
        sys.exit("docpipe: output name %r must be [A-Za-z0-9._-]" % name)
    with open(cp, encoding="utf-8") as f:
        text = f.read()
    return meta, text, name


# ---------------------------------------------------------------- bidi

RE_ARABIC = re.compile(r"[؀-ۿݐ-ݿࡰ-࢟ࢠ-ࣿﭐ-﷿ﹰ-﻿]")
RE_LATIN = re.compile(r"[A-Za-zÀ-ɏ]")
RE_NON_ARABIC_CHUNK = re.compile(r"[^؀-ۿݐ-ݿࡰ-࢟ࢠ-ࣿﭐ-﷿ﹰ-﻿]+")
RE_LATIN_ALNUM = re.compile(r"[A-Za-z0-9À-ɏ]")


def latin_runs(text):
    """Yield (segment, is_latin_run) for RTL documents.

    A Latin run is the longest stretch between two Arabic letters that contains a Latin letter, trimmed to its
    first and last Latin letter / digit, so neutrals *between* Latin words stay inside the run
    ("V1.0 RC (a6b02eba / final-prep)", "25/5 Mbps") and keep their LTR order once the run is isolated.
    Sentence punctuation and spaces at the edges stay outside; a closing bracket whose opener is inside the run
    is pulled in (and vice versa) so brackets never split across the isolate boundary."""
    pos = 0
    for m in RE_NON_ARABIC_CHUNK.finditer(text):
        chunk = m.group(0)
        if not RE_LATIN.search(chunk):
            continue
        idx = [i for i, ch in enumerate(chunk) if RE_LATIN_ALNUM.match(ch)]
        a, b = idx[0], idx[-1] + 1
        depth = chunk[a:b].count("(") + chunk[a:b].count("[") - chunk[a:b].count(")") - chunk[a:b].count("]")
        while depth > 0 and b < len(chunk) and chunk[b] in ")]":
            b += 1; depth -= 1
        while depth < 0 and a > 0 and chunk[a - 1] in "([":
            a -= 1; depth += 1
        s, e = m.start() + a, m.start() + b
        if s > pos:
            yield text[pos:s], False
        yield text[s:e], True
        pos = e
    if pos < len(text):
        yield text[pos:], False


def rtl_segments(text):
    """Split text into runs for Word: (segment, rtl). Arabic letters and neutrals -> rtl; Latin letters and
    digits -> ltr. Neutral characters between two LTR tokens stay in the LTR run."""
    if not text:
        return []
    segs = []
    for seg, is_latin in latin_runs(text):
        if is_latin:
            segs.append((seg, False))
            continue
        # digits groups (with separators) are kept as LTR runs so Word never shapes them as Hindi digits
        pos = 0
        for m in re.finditer(r"\d[\d.,:/\-]*\d%?|\d%?", seg):
            if m.start() > pos:
                segs.append((seg[pos:m.start()], True))
            segs.append((m.group(0), False))
            pos = m.end()
        if pos < len(seg):
            segs.append((seg[pos:], True))
    out = []
    for s, r in segs:
        if out and out[-1][1] == r:
            out[-1] = (out[-1][0] + s, r)
        else:
            out.append((s, r))
    return out


def has_arabic(text):
    return bool(RE_ARABIC.search(text or ""))
