#!/usr/bin/env bash
# Copy the approved ITC NetCore brand assets and fonts into docpipe/assets (byte-identical, sha256-verified),
# derive the PNG renditions for DOCX (rsvg-convert, same artwork) and the TTF fonts for DOCX embedding.
# usage: prepare_assets.sh [brand-src-root]   (default /srv/itcnc/src/final)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="${1:-/srv/itcnc/src/final}"
IMG="$SRC/ui/ui/images/brand"; FONTS="$SRC/ui/ui/fonts/brand"; CSS="$SRC/ui/ui/styles/nuxr-brand.css"
A="$HERE/assets"
mkdir -p "$A/brand/png" "$A/fonts/ttf"

echo "== brand artwork (verified against ASSETS.txt)"
cp "$IMG/ASSETS.txt" "$A/brand/ASSETS.txt"
for f in netcore-mark.svg netcore-mark-navy.svg netcore-mark-white.svg netcore-mark-micro.svg \
         netcore-wordmark.svg netcore-wordmark-navy.svg netcore-wordmark-white.svg; do
  cp "$IMG/$f" "$A/brand/$f"
  want=$(awk -v f="$f" '$2==f {print $1}' "$A/brand/ASSETS.txt")
  got=$(sha256sum "$A/brand/$f" | cut -d' ' -f1)
  [ -n "$want" ] && [ "$want" = "$got" ] || { echo "SHA MISMATCH $f: $got != $want" >&2; exit 1; }
  echo "  ok $got  $f"
done

echo "== fonts (verified against FONTS.txt)"
cp "$FONTS/FONTS.txt" "$FONTS/Inter-LICENSE.txt" "$FONTS/NotoSansArabic-LICENSE.txt" "$A/fonts/"
for f in inter-latin-regular inter-latin-medium inter-latin-semibold inter-latin-bold \
         noto-sans-arabic-regular noto-sans-arabic-bold; do
  cp "$FONTS/$f.woff2" "$A/fonts/$f.woff2"
  want=$(awk -v f="$f.woff2" '$2==f {print $1}' "$A/fonts/FONTS.txt")
  got=$(sha256sum "$A/fonts/$f.woff2" | cut -d' ' -f1)
  [ "$want" = "$got" ] || { echo "SHA MISMATCH $f.woff2" >&2; exit 1; }
  echo "  ok $got  $f.woff2"
done
# the @font-face rules (with their unicode-range split Latin / Arabic) exactly as the product declares them
grep '^@font-face' "$CSS" | sed 's#url("../fonts/brand/#url("#g; s/font-display: swap; //' > "$A/fonts/fontfaces.css"
echo "  $(wc -l < "$A/fonts/fontfaces.css") @font-face rules from nuxr-brand.css"

echo "== PNG renditions for DOCX (rsvg-convert, unchanged artwork)"
rsvg-convert -w 2070 "$A/brand/netcore-wordmark.svg" -o "$A/brand/png/netcore-wordmark.png"
rsvg-convert -w 672  "$A/brand/netcore-mark.svg"       -o "$A/brand/png/netcore-mark.png"
rsvg-convert -w 672  "$A/brand/netcore-mark-white.svg" -o "$A/brand/png/netcore-mark-white.png"
# accent rule of the cover (palette gradient interactive -> teal; not part of the logo)
cat > "$A/brand/rule.svg" <<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 460 16"><defs><linearGradient id="g" x1="0" x2="1"><stop stop-color="#3275B5"/><stop offset="1" stop-color="#117C88"/></linearGradient></defs><rect width="460" height="16" rx="8" fill="url(#g)"/></svg>
SVG
rsvg-convert -w 920 "$A/brand/rule.svg" -o "$A/brand/png/rule.png"
ls -la "$A/brand/png"

echo "== TTF fonts for DOCX embedding"
python3 "$HERE/lib/fonts_ttf.py" "$A/fonts" "$A/fonts/ttf"
( cd "$A" && sha256sum brand/*.svg brand/png/*.png fonts/*.woff2 fonts/ttf/*.ttf > MANIFEST.sha256 )
echo "prepare_assets: done"
