#!/usr/bin/env python3
"""WP7 interim PWA identity icons (owner decision OD-W7-1 = B). Development tooling only.

Deterministic rendering of the accepted WP6C application mark: one initial letter in white (#ffffff, --nx-on-primary)
on the WP6C primary #3442c9, a simple (rounded) square, maskable artwork inside the central safe zone. No AI, no
upscaling of an existing bitmap, no logo or wordmark reinterpretation. INTERIM identity: replace the four PNGs under
ui/ui/images/pwa/ (same names and sizes) to rebrand; nothing else in the PWA depends on them.

The committed icons carry the interim ITC NetCore text mark "ITC" (owner decision 2026-10-18: product ITC NetCore, brand
ITC; a text identity, not a final logo):
    python3 dev/pwa-lab/make_icons.py --text ITC --out ui/ui/images/pwa
(until 2026-10-18 they carried the initial of the stock CompanyName, "PHPNuxBill" -> "P"). A deployment may regenerate the
same composition with its own initial or short text, for example:
    python3 dev/pwa-lab/make_icons.py --text "ش" --out ui/ui/images/pwa
Same inputs + same toolchain (Pillow, DejaVu Sans Bold) -> byte-identical files; the report records versions and hashes.
"""
import argparse
import hashlib
import os

from PIL import Image, ImageDraw, ImageFont, __version__ as PIL_VERSION

FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
PRIMARY = (0x34, 0x42, 0xC9, 255)
ON_PRIMARY = (0xFF, 0xFF, 0xFF, 255)
SS = 4  # supersampling factor (anti-aliasing), then LANCZOS down-scale

# name -> (size, full_bleed, glyph height as a fraction of the size)
SPECS = {
    'icon-180.png': (180, True, 0.50),          # apple-touch-icon: the platform applies its own mask (no transparency)
    'icon-192.png': (192, False, 0.50),         # purpose "any": rounded square like the in-app mark
    'icon-512.png': (512, False, 0.50),
    'icon-maskable-512.png': (512, True, 0.40),  # purpose "maskable": glyph well inside the central 80% safe zone
}
# a text of more than one character is scaled by its width: name -> width as a fraction of the size (maskable stays in the
# central 80 % safe zone)
WORD_FRAC = {'icon-180.png': 0.62, 'icon-192.png': 0.64, 'icon-512.png': 0.64, 'icon-maskable-512.png': 0.50}
RADIUS = 0.22  # rounded-square corner radius as a fraction of the size (the in-app mark: 10px / 36px)


def render(initial: str, size: int, full_bleed: bool, glyph_frac: float) -> Image.Image:
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    if full_bleed:
        d.rectangle([0, 0, big - 1, big - 1], fill=PRIMARY)
    else:
        d.rounded_rectangle([0, 0, big - 1, big - 1], radius=int(big * RADIUS), fill=PRIMARY)
    target = big * glyph_frac
    font = ImageFont.truetype(FONT, int(target * 1.4))
    left, top, right, bottom = font.getbbox(initial)
    scale = target / max(1, max(right - left, bottom - top))
    font = ImageFont.truetype(FONT, max(8, int(target * 1.4 * scale)))
    left, top, right, bottom = font.getbbox(initial)
    x = (big - (right - left)) / 2 - left
    y = (big - (bottom - top)) / 2 - top
    d.text((x, y), initial, font=font, fill=ON_PRIMARY)
    return img.resize((size, size), Image.LANCZOS)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--text', '--initial', dest='text', default='ITC')
    ap.add_argument('--out', default='ui/ui/images/pwa')
    a = ap.parse_args()
    os.makedirs(a.out, exist_ok=True)
    print(f'Pillow {PIL_VERSION}; font {FONT}; text {a.text!r}; primary #3442c9; on-primary #ffffff')
    for name, (size, full, frac) in SPECS.items():
        path = os.path.join(a.out, name)
        render(a.text, size, full, frac if len(a.text) == 1 else WORD_FRAC[name]).save(path, format='PNG', optimize=False)
        print(f'{name} {size}x{size} sha256={hashlib.sha256(open(path, "rb").read()).hexdigest()}')


if __name__ == '__main__':
    main()
