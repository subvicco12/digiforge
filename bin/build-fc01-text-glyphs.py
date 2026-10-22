#!/usr/bin/env python3
"""Offline FC01 glyph build from unmodified, hash-pinned Noto Serif; never fetches fonts."""
import base64
import argparse
import hashlib
import io
import json
from pathlib import Path
import PIL
from PIL import Image, ImageDraw, ImageFont, features

ROOT = Path(__file__).resolve().parents[1] / 'assets/pod-designs/fc01-v2-text-only'
FONT_SHA = 'c8f669ceb2c9c60ccf55198b305e08a997ffca79a38cc7eeb551e643cbe66505'
LICENSE_SHA = '0dab92d0544f7b233403f14b84a663bdbfa746982eda629e7f4f9ffe1b036feb'
FONT = ROOT / 'font/NotoSerif-Regular.ttf'
assert hashlib.sha256(FONT.read_bytes()).hexdigest() == FONT_SHA
assert hashlib.sha256((ROOT / 'font/OFL.txt').read_bytes()).hexdigest() == LICENSE_SHA
assert PIL.__version__ == '13.0.0', 'Use the already pinned offline Pillow runtime'
assert features.version('freetype2') == '2.14.3', 'Glyph rebuild requires the recorded FreeType version'
# Explicit bounded Latin subset, verified against this pinned font's cmap.
# Combining marks/complex shaping are deliberately unsupported, not normalized.
CHARS = ''.join(chr(n) for n in list(range(32, 127)) + [n for n in range(192, 256) if n not in (215, 247)])
result = {'schema': 'fc01-noto-glyphs-v1', 'font_sha256': FONT_SHA,
          'license_sha256': LICENSE_SHA, 'font_version': '2.007',
          'rasterizer': {'pillow': PIL.__version__, 'freetype': features.version('freetype2'),
                         'layout': 'BASIC_INDIVIDUAL_GLYPHS_NO_KERNING_OR_SHAPING'}, 'runs': {}}
for key, size in [('header', 30), ('family_name', 48), ('established', 22)]:
    font = ImageFont.truetype(str(FONT), size, layout_engine=ImageFont.Layout.BASIC)
    assert font.getname() == ('Noto Serif', 'Regular')
    ascent, descent = font.getmetrics()
    glyphs = {}
    for char in CHARS:
        left, top, right, bottom = font.getbbox(char, anchor='ls')
        width, height = right-left, bottom-top
        item = {'advance': round(font.getlength(char)), 'left': left, 'top': top,
                'width': width, 'height': height}
        if width and height:
            image = Image.new('RGBA', (width, height))
            ImageDraw.Draw(image).text((-left, -top), char, font=font, anchor='ls', fill='#304e55')
            out = io.BytesIO()
            image.save(out, format='PNG', optimize=False, compress_level=9)
            item['png'] = base64.b64encode(out.getvalue()).decode('ascii')
        glyphs[char] = item
    result['runs'][key] = {'size': size, 'ascent': ascent, 'descent': descent, 'glyphs': glyphs}
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path, default=ROOT / 'glyphs.json')
target = parser.parse_args().output
target.write_text(json.dumps(result, ensure_ascii=False, separators=(',', ':'))+'\n')
print(hashlib.sha256(target.read_bytes()).hexdigest())
