"""Guide images: the captured PNGs (WORK/screens, WORK/flows) -> WORK/booklet/img/*.webp and WORK/dims.json.
Form shots captured by demo_flows.py are full-page desktop shots: the application footer (about 46 px) is cut off, as the
screens captured by shots.py already end above it."""
import glob
import json
import os

from PIL import Image

WORK = os.environ.get('GUIDE_WORK', '/tmp/itc-guide')
out = os.path.join(WORK, 'booklet', 'img')
os.makedirs(out, exist_ok=True)
dims = {}
for f in sorted(glob.glob(os.path.join(WORK, 'screens', '*.png'))) + sorted(glob.glob(os.path.join(WORK, 'flows', '*.png'))):
    name = os.path.basename(f)[:-4]
    im = Image.open(f).convert('RGB')
    if os.sep + 'flows' + os.sep in f:
        name = 'k-' + name
        if im.width == 1440 and im.height > 900:
            im = im.crop((0, 0, im.width, im.height - 46))
    if im.width > 1440:
        im = im.resize((1440, round(im.height * 1440 / im.width)), Image.LANCZOS)
    im.save(os.path.join(out, name + '.webp'), 'WEBP', quality=80, method=6)
    dims[name] = im.size
json.dump(dims, open(os.path.join(WORK, 'dims.json'), 'w'))
print(len(dims), 'images')
