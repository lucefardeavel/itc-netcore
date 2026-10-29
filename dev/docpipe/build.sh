#!/usr/bin/env bash
# Build one document folder (meta.json + content.md + images) into <outdir>/<name>.pdf and <name>.docx,
# then verify it (page PNGs + check report). Run inside WSL Ubuntu-24.04.
# usage: build.sh <docdir> <outdir>
# env:   DOCPIPE_NO_VERIFY=1   skip verification
#        DOCPIPE_NO_PNG=1      verify without rendering page PNGs
#        DOCPIPE_PYTHON        python with python-docx (default /srv/itcnc/qa/docvenv/bin/python)
#        DOCPIPE_PLAYWRIGHT    playwright module path (default /srv/itcnc/qa/node_modules/playwright)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
[ $# -eq 2 ] || { echo "usage: build.sh <docdir> <outdir>" >&2; exit 2; }
DOCDIR="$(realpath "$1")"
OUTDIR="$(realpath -m "$2")"
PY_DOCX="${DOCPIPE_PYTHON:-/srv/itcnc/qa/docvenv/bin/python}"
[ -f "$HERE/assets/fonts/fontfaces.css" ] && [ -f "$HERE/assets/fonts/ttf/inter-latin-regular.ttf" ] \
  || bash "$HERE/prepare_assets.sh"

# fontconfig that also knows the brand TTFs: used by rsvg-convert (SVG images) and Chromium (SVG-as-image text,
# header/footer fallback). HTML body text always uses the embedded @font-face WOFF2 files.
mkdir -p /tmp/docpipe-fc-cache
cat > "$HERE/assets/fonts/fonts.conf" <<FC
<?xml version="1.0"?>
<!DOCTYPE fontconfig SYSTEM "fonts.dtd">
<fontconfig><include ignore_missing="yes">/etc/fonts/fonts.conf</include><dir>$HERE/assets/fonts/ttf</dir><cachedir>/tmp/docpipe-fc-cache</cachedir></fontconfig>
FC
export FONTCONFIG_FILE="$HERE/assets/fonts/fonts.conf"

NAME="$(python3 -c 'import sys; sys.path.insert(0, sys.argv[1]); from common import load_doc; print(load_doc(sys.argv[2])[2])' "$HERE/lib" "$DOCDIR")"
WORK="$OUTDIR/_work/$NAME"
mkdir -p "$OUTDIR" "$WORK"
echo "== $NAME  ($DOCDIR -> $OUTDIR)"

python3 "$HERE/lib/render_html.py" "$DOCDIR" "$OUTDIR/$NAME.html" --ast "$WORK/ast.json"
node "$HERE/lib/render_pdf.js" "$OUTDIR/$NAME.html" "$WORK/ast.json" "$OUTDIR/$NAME.pdf" "$WORK/pagemap.json"
"$PY_DOCX" "$HERE/lib/render_docx.py" "$DOCDIR" "$OUTDIR/$NAME.docx" "$WORK"

rc=0
if [ "${DOCPIPE_NO_VERIFY:-0}" != 1 ]; then
  extra=()
  [ "${DOCPIPE_NO_PNG:-0}" = 1 ] && extra+=(--no-png)
  node "$HERE/lib/verify.js" "$OUTDIR/$NAME.html" "$OUTDIR/$NAME.pdf" "$WORK/pagemap.json" \
       "$OUTDIR/$NAME.pages" "$OUTDIR/$NAME.check" "${extra[@]+"${extra[@]}"}" || rc=$?
  "$PY_DOCX" "$HERE/lib/check_docx.py" "$OUTDIR/$NAME.docx" > "$WORK/docx-check.txt" || rc=$?
  cat "$WORK/docx-check.txt" | tee -a "$OUTDIR/$NAME.check.txt"
fi
echo "== done: $OUTDIR/$NAME.pdf  $OUTDIR/$NAME.docx  (verification exit $rc)"
exit $rc
