#!/usr/bin/env bash
# ITC NetCore product guide: the whole pipeline, in the isolated test lab only (synthetic data, never production).
#   dev/guide/build.sh            lab up -> demo data -> screens -> images -> page -> PDFs -> lab down
# Output: $GUIDE_WORK (default /tmp/itc-guide): booklet/index.html + booklet/img/, ITC-NetCore-Guide.pdf, ITC-NetCore-Guide-lite.pdf.
# Nothing is written to the repository. Requires: Docker (the nuxbill-reseller-test compose project), Python 3 with
# playwright (Chromium) and Pillow.
set -euo pipefail
G="$(cd "$(dirname "$0")" && pwd)"; R="$(cd "$G/../.." && pwd)"
export GUIDE_WORK="${GUIDE_WORK:-/tmp/itc-guide}"; mkdir -p "$GUIDE_WORK"
T1A_ENV="$(sed -n 's/^STATE=//p' "$R/dev/wp9/t1a_run.sh")"
"$R/dev/wp9/t1a_run.sh" up
set -a; . "$T1A_ENV"; set +a
trap '"$R/dev/pwa-lab/lab.sh" down >/dev/null 2>&1 || true; "$R/dev/wp9/t1a_run.sh" down >/dev/null 2>&1 || true' EXIT
"$R/dev/pwa-lab/lab.sh" up
bash "$G/demo_prep.sh"
python3 "$G/demo_flows.py"
rm -rf "$GUIDE_WORK/screens"; SPEC="$G/screens.json" python3 "$G/shots.py" x "$GUIDE_WORK/screens"
python3 - <<PY
import os, sys
sys.argv = ['x', '--out', os.environ['GUIDE_WORK'] + '/screens', '--axe', '/dev/null']
sys.path.insert(0, '$R/dev/pwa-lab'); sys.dont_write_bytecode = True
import pwa_suite as P
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(); pg = P.new_ctx(b, P.LAB['root'], vp=P.PHONE, lang='arabic').new_page()
    pg.goto(P.LAB['root'] + 'nuxr-offline.html', wait_until='networkidle'); pg.screenshot(path=os.environ['GUIDE_WORK'] + '/screens/f09-phone-offline.png'); b.close()
PY
rm -rf "$GUIDE_WORK/booklet"; python3 "$G/build_images.py"
python3 "$G/gen.py"
python3 "$G/export_pdf.py"
echo "guide built in $GUIDE_WORK"
