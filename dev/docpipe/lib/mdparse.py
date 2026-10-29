"""Small Markdown-subset parser for the ITC NetCore document pipeline (stdlib only).

parse(text) -> list of block nodes (plain dicts, JSON-serialisable), shared by the HTML and DOCX renderers.

Block nodes
  {"t": "heading", "level": 1..4, "inl": [...], "id": "sec-1-2", "num": "1.2"}   (num/id set by number_headings)
  {"t": "para", "inl": [...]}
  {"t": "list", "ordered": bool, "start": int, "items": [[block, ...], ...]}
  {"t": "table", "align": ["left"|"center"|"right"|None, ...], "head": [inl, ...], "rows": [[inl, ...], ...]}
  {"t": "image", "src": str, "alt": str, "title": str, "width": "60%"|None}
  {"t": "quote", "blocks": [...]}
  {"t": "callout", "kind": "note"|"warning"|"finance"|"security", "title": str|None, "blocks": [...]}
  {"t": "code", "lang": str, "text": str}
  {"t": "hr"}
  {"t": "pagebreak"}
Inline nodes
  {"t": "text", "v": str} {"t": "strong", "c": [...]} {"t": "em", "c": [...]} {"t": "code", "v": str}
  {"t": "link", "href": str, "c": [...]} {"t": "br"}
"""
import re

CALLOUT_KINDS = {
    "NOTE": "note", "INFO": "note", "TIP": "note",
    "WARNING": "warning", "CAUTION": "warning", "IMPORTANT": "warning",
    "FINANCE": "finance", "FINANCE-WARNING": "finance",
    "SECURITY": "security", "SECURITY-WARNING": "security",
}

RE_HEADING = re.compile(r"^(#{1,4})\s+(.*?)\s*#*\s*$")
RE_FENCE = re.compile(r"^(```|~~~)\s*([\w+-]*)\s*$")
RE_HR = re.compile(r"^\s{0,3}([-*_])(\s*\1){2,}\s*$")
RE_PAGEBREAK = re.compile(r"^\s*<!--\s*page-?break\s*-->\s*$", re.I)
RE_COMMENT = re.compile(r"^\s*<!--.*-->\s*$")
RE_UL = re.compile(r"^(\s*)[-*+]\s+(.*)$")
RE_OL = re.compile(r"^(\s*)(\d+)[.)]\s+(.*)$")
RE_TABLE_SEP = re.compile(r"^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$")
RE_IMAGE_BLOCK = re.compile(r'^!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+"([^"]*)")?\s*\)(\{[^}]*\})?\s*$')
RE_CALLOUT = re.compile(r"^\[!([A-Za-z-]+)\]\s*(.*)$")


# ---------------------------------------------------------------- inline

def parse_inline(s):
    """Parse inline markup into a list of inline nodes."""
    out = []
    buf = []

    def flush():
        if buf:
            out.append({"t": "text", "v": "".join(buf)})
            buf.clear()

    i, n = 0, len(s)
    while i < n:
        c = s[i]
        if c == "\\" and i + 1 < n and s[i + 1] in "\\`*_[]()#+-.!|<>{}":
            buf.append(s[i + 1]); i += 2; continue
        if c == "`":
            j = s.find("`", i + 1)
            if j > i:
                flush(); out.append({"t": "code", "v": s[i + 1:j]}); i = j + 1; continue
        if s.startswith("<br>", i) or s.startswith("<br/>", i) or s.startswith("<br />", i):
            flush(); out.append({"t": "br"}); i = s.find(">", i) + 1; continue
        if s.startswith("**", i) or s.startswith("__", i):
            mark = s[i:i + 2]
            j = s.find(mark, i + 2)
            if j > i + 2:
                flush(); out.append({"t": "strong", "c": parse_inline(s[i + 2:j])}); i = j + 2; continue
        if c in "*_":
            prev = s[i - 1] if i > 0 else " "
            if c == "*" or not (prev.isalnum()):
                j = i + 1
                while True:
                    j = s.find(c, j)
                    if j < 0:
                        break
                    nxt = s[j + 1] if j + 1 < n else " "
                    if j > i + 1 and s[j - 1] != " " and (c == "*" or not nxt.isalnum()):
                        break
                    j += 1
                if j > i + 1 and s[i + 1] != " ":
                    flush(); out.append({"t": "em", "c": parse_inline(s[i + 1:j])}); i = j + 1; continue
        if c == "[" or (c == "!" and i + 1 < n and s[i + 1] == "["):
            m = re.match(r"!?\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+\"[^\"]*\")?\s*\)", s[i:])
            if m:
                flush()
                if c == "!":
                    out.append({"t": "text", "v": m.group(1)})
                else:
                    out.append({"t": "link", "href": m.group(2), "c": parse_inline(m.group(1))})
                i += m.end(); continue
        buf.append(c); i += 1
    flush()
    return out


def inline_text(inl):
    """Plain text of an inline list."""
    parts = []
    for x in inl:
        if x["t"] in ("text", "code"):
            parts.append(x["v"])
        elif x["t"] == "br":
            parts.append(" ")
        else:
            parts.append(inline_text(x["c"]))
    return "".join(parts)


# ---------------------------------------------------------------- blocks

def _split_row(line):
    line = line.strip()
    if line.startswith("|"):
        line = line[1:]
    if line.endswith("|") and not line.endswith("\\|"):
        line = line[:-1]
    cells, cur, i = [], [], 0
    while i < len(line):
        if line[i] == "\\" and i + 1 < len(line) and line[i + 1] == "|":
            cur.append("|"); i += 2; continue
        if line[i] == "|":
            cells.append("".join(cur).strip()); cur = []
        else:
            cur.append(line[i])
        i += 1
    cells.append("".join(cur).strip())
    return cells


def _is_block_start(line):
    return bool(RE_HEADING.match(line) or RE_FENCE.match(line) or RE_HR.match(line) or RE_PAGEBREAK.match(line)
                or RE_UL.match(line) or RE_OL.match(line) or line.lstrip().startswith(">")
                or RE_IMAGE_BLOCK.match(line.strip()))


def _parse_image(line):
    m = RE_IMAGE_BLOCK.match(line.strip())
    if not m:
        return None
    width = None
    if m.group(4):
        wm = re.search(r"width\s*=\s*([\d.]+%?)", m.group(4))
        if wm:
            width = wm.group(1) if wm.group(1).endswith("%") else wm.group(1) + "%"
    return {"t": "image", "alt": m.group(1), "src": m.group(2), "title": m.group(3) or "", "width": width}


def _parse_list(lines, i):
    """Parse a (possibly nested) list starting at lines[i]. Returns (node, next_index)."""
    first = lines[i]
    m = RE_OL.match(first) or RE_UL.match(first)
    base_indent = len(m.group(1))
    ordered = bool(RE_OL.match(first))
    start = int(RE_OL.match(first).group(2)) if ordered else 1
    items = []
    cur = None
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            # blank line: the list continues only if the next non-blank line is an item / indented continuation
            j = i + 1
            while j < len(lines) and not lines[j].strip():
                j += 1
            if j < len(lines):
                nm = RE_OL.match(lines[j]) or RE_UL.match(lines[j])
                ind = len(lines[j]) - len(lines[j].lstrip())
                if (nm and len(nm.group(1)) >= base_indent) or ind > base_indent:
                    i = j; continue
            break
        mo, mu = RE_OL.match(line), RE_UL.match(line)
        mm = mo or mu
        indent = len(line) - len(line.lstrip())
        if mm and indent == base_indent and (bool(mo) == ordered):
            text = mo.group(3) if mo else mu.group(2)
            cur = {"text": [text], "children": []}
            items.append(cur); i += 1; continue
        if mm and indent > base_indent and cur is not None:
            sub, i = _parse_list(lines, i)
            cur["children"].append(sub); continue
        if not mm and indent > base_indent and cur is not None:
            cur["text"].append(line.strip()); i += 1; continue
        break
    out_items = []
    for it in items:
        blocks = [{"t": "para", "inl": parse_inline(" ".join(it["text"]))}]
        blocks.extend(it["children"])
        out_items.append(blocks)
    return {"t": "list", "ordered": ordered, "start": start, "items": out_items}, i


def parse_blocks(lines):
    blocks = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1; continue
        if RE_PAGEBREAK.match(line):
            blocks.append({"t": "pagebreak"}); i += 1; continue
        if RE_COMMENT.match(line):
            i += 1; continue
        m = RE_FENCE.match(line)
        if m:
            fence, lang = m.group(1), m.group(2)
            j = i + 1
            body = []
            while j < len(lines) and not lines[j].strip().startswith(fence):
                body.append(lines[j]); j += 1
            blocks.append({"t": "code", "lang": lang, "text": "\n".join(body)})
            i = j + 1; continue
        m = RE_HEADING.match(line)
        if m:
            blocks.append({"t": "heading", "level": len(m.group(1)), "inl": parse_inline(m.group(2)), "raw": m.group(2)})
            i += 1; continue
        if RE_HR.match(line):
            blocks.append({"t": "hr"}); i += 1; continue
        if line.lstrip().startswith(">"):
            inner = []
            while i < len(lines) and lines[i].lstrip().startswith(">"):
                s = lines[i].lstrip()[1:]
                inner.append(s[1:] if s.startswith(" ") else s)
                i += 1
            cm = RE_CALLOUT.match(inner[0].strip()) if inner else None
            if cm and cm.group(1).upper() in CALLOUT_KINDS:
                blocks.append({"t": "callout", "kind": CALLOUT_KINDS[cm.group(1).upper()],
                               "title": cm.group(2).strip() or None, "blocks": parse_blocks(inner[1:])})
            else:
                blocks.append({"t": "quote", "blocks": parse_blocks(inner)})
            continue
        if RE_UL.match(line) or RE_OL.match(line):
            node, i = _parse_list(lines, i)
            blocks.append(node); continue
        img = _parse_image(line)
        if img:
            blocks.append(img); i += 1; continue
        if "|" in line and i + 1 < len(lines) and RE_TABLE_SEP.match(lines[i + 1]):
            head = _split_row(line)
            aligns = []
            for spec in _split_row(lines[i + 1]):
                spec = spec.strip()
                if spec.startswith(":") and spec.endswith(":"):
                    aligns.append("center")
                elif spec.endswith(":"):
                    aligns.append("right")
                elif spec.startswith(":"):
                    aligns.append("left")
                else:
                    aligns.append(None)
            rows = []
            i += 2
            while i < len(lines) and lines[i].strip() and "|" in lines[i]:
                cells = _split_row(lines[i])
                cells = (cells + [""] * len(head))[:len(head)]
                rows.append([parse_inline(c) for c in cells]); i += 1
            blocks.append({"t": "table", "align": (aligns + [None] * len(head))[:len(head)],
                           "head": [parse_inline(h) for h in head], "rows": rows})
            continue
        # paragraph
        para = [line.strip()]
        i += 1
        while i < len(lines) and lines[i].strip() and not _is_block_start(lines[i]) \
                and not ("|" in lines[i] and i + 1 < len(lines) and RE_TABLE_SEP.match(lines[i + 1])):
            para.append(lines[i].strip()); i += 1
        text = ""
        for k, p in enumerate(para):
            if k and text.endswith("  "):
                text = text.rstrip() + "<br>"
            elif k:
                text = text.rstrip() + " "
            text += p
        blocks.append({"t": "para", "inl": parse_inline(text.strip())})
    return blocks


RE_MANUAL_NUM = re.compile(r"^\s*(?:\d+(?:\.\d+)*\.?|[A-Z](?:\.\d+)*\.)\s")


def number_headings(blocks, max_level=3):
    """Assign section numbers (1, 1.2, 1.2.3) and ids to headings up to max_level; returns the TOC entry list."""
    counters = [0, 0, 0, 0]
    toc = []
    for b in blocks:
        if b["t"] != "heading":
            continue
        lvl = b["level"]
        if lvl > max_level:
            b["num"], b["id"] = None, None
            continue
        counters[lvl - 1] += 1
        for k in range(lvl, 4):
            counters[k] = 0
        nums = counters[:lvl]
        # a hand-numbered heading ("2. Title", "2.1 Title", "A. Title") keeps its own number: no automatic one in front
        b["num"] = "" if RE_MANUAL_NUM.match(b.get("raw", "")) else ".".join(str(x) for x in nums)
        b["id"] = "sec-" + "-".join(str(x) for x in nums)
        toc.append({"level": lvl, "num": b["num"], "id": b["id"], "inl": b["inl"]})
    return toc


def parse(text):
    text = text.replace("\r\n", "\n").replace("\t", "    ")
    if text.startswith("﻿"):
        text = text[1:]
    return parse_blocks(text.split("\n"))


def iter_images(blocks):
    for b in blocks:
        if b["t"] == "image":
            yield b
        elif b["t"] in ("quote", "callout"):
            yield from iter_images(b["blocks"])
        elif b["t"] == "list":
            for it in b["items"]:
                yield from iter_images(it)


if __name__ == "__main__":
    import json, sys
    doc = parse(open(sys.argv[1], encoding="utf-8").read())
    number_headings(doc)
    json.dump(doc, sys.stdout, ensure_ascii=False, indent=1)
