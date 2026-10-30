"""WOFF2 brand fonts -> TrueType (glyf) TTF for DOCX embedding. Needs fontTools + brotli (system python3).

usage: fonts_ttf.py <woff2-dir> <ttf-dir>
Noto Sans Arabic is already glyf-based (only decompressed). Inter is CFF-based: its outlines are converted to
quadratic TrueType outlines with fontTools cu2qu (max error 1 unit), because Word only embeds TrueType outlines.
"""
import os
import sys

from fontTools.pens.cu2quPen import Cu2QuPen
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.ttLib import TTFont, newTable


def otf_to_ttf(font):
    order = font.getGlyphOrder()
    gs = font.getGlyphSet()
    glyphs = {}
    for name in order:
        pen = TTGlyphPen(gs)
        gs[name].draw(Cu2QuPen(pen, 1.0, reverse_direction=True))
        glyphs[name] = pen.glyph()
    font["loca"] = newTable("loca")
    font["glyf"] = glyf = newTable("glyf")
    glyf.glyphOrder = order
    glyf.glyphs = glyphs
    del font["CFF "]
    if "VORG" in font:
        del font["VORG"]
    glyf.compile(font)
    hmtx = font["hmtx"]
    for name, g in glyf.glyphs.items():
        if hasattr(g, "xMin"):
            hmtx[name] = (hmtx[name][0], g.xMin)
    maxp = newTable("maxp")
    maxp.tableVersion = 0x00010000
    for k in ("maxZones", "maxTwilightPoints", "maxStorage", "maxFunctionDefs", "maxInstructionDefs",
              "maxStackElements", "maxSizeOfInstructions", "maxComponentElements"):
        setattr(maxp, k, 0)
    maxp.maxZones = 1
    font["maxp"] = maxp
    post = font["post"]
    post.formatType = 2.0
    post.extraNames = []
    post.mapping = {}
    post.glyphOrder = order
    font["head"].glyphDataFormat = 0
    font.sfntVersion = "\x00\x01\x00\x00"


def main():
    src, dst = sys.argv[1], sys.argv[2]
    os.makedirs(dst, exist_ok=True)
    for name in ("inter-latin-regular", "inter-latin-bold", "noto-sans-arabic-regular", "noto-sans-arabic-bold"):
        f = TTFont(os.path.join(src, name + ".woff2"))
        f.flavor = None
        if "CFF " in f:
            otf_to_ttf(f)
        out = os.path.join(dst, name + ".ttf")
        f.save(out)
        chk = TTFont(out)
        assert "glyf" in chk and chk["OS/2"].fsType == 0, out
        print("fonts_ttf: %s (%d glyphs, family %r)" % (out, len(chk.getGlyphOrder()), chk["name"].getDebugName(1)))


if __name__ == "__main__":
    main()
