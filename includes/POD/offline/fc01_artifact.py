#!/usr/bin/env python3
"""Bounded offline PNG compiler. A request/hash is NOT an authorization or supplier certificate."""
import base64
import hashlib
import io
import json
import math
from pathlib import Path
import resource
import sys
import time
import PIL
from PIL import Image, ImageDraw, ImageFont, features

ASSETS = Path(__file__).resolve().parents[3] / 'assets/pod-designs/fc01-v2-text-only'
FONT_SHA = 'c8f669ceb2c9c60ccf55198b305e08a997ffca79a38cc7eeb551e643cbe66505'
LICENSE_SHA = '0dab92d0544f7b233403f14b84a663bdbfa746982eda629e7f4f9ffe1b036feb'
CHARS = set(chr(n) for n in list(range(32, 127)) + [n for n in range(192, 256) if n not in (215, 247)])


def encoded(value, sort_keys=False):
    # Match WordPress JSON_UNESCAPED_UNICODE without LINE_TERMINATORS.
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), sort_keys=sort_keys).replace('\u2028', '\\u2028').replace('\u2029', '\\u2029').encode('utf-8')


def canonical(value):
    return encoded(value, sort_keys=True)


def digest(value):
    return hashlib.sha256(value).hexdigest()


def require(condition):
    if not condition:
        raise ValueError('Offline artifact blocked')


def integer(value, low, high):
    require(type(value) is int and low <= value <= high)
    return value


def inside(a, b):
    return a['x'] >= b['x'] and a['y'] >= b['y'] and a['x']+a['width'] <= b['x']+b['width'] and a['y']+a['height'] <= b['y']+b['height']


def compile_artifact(packet):
    require(PIL.__version__ == '13.0.0' and features.version('freetype2') == '2.14.3')
    require(digest((ASSETS / 'font/NotoSerif-Regular.ttf').read_bytes()) == FONT_SHA)
    require(digest((ASSETS / 'font/OFL.txt').read_bytes()) == LICENSE_SHA)
    require(packet['schema'] == 'fc01-production-artifact-request-v1' and packet['shop_key'] == 'personalized_pod')
    require(packet['environment'] in ('sandbox', 'test', 'production'))
    require(packet['acceptance_state'] == 'UNVERIFIED')
    for flag in ('supplier_certified', 'rights_certified', 'production_authorized', 'external_execution_authorized'):
        require(packet[flag] is False)
    require(packet['export_policy'] == 'TRANSPARENT_TEXT_UNIFORM_CONCEPT_SCALE_NO_AUTO_FIT')
    unsigned = {k: v for k, v in packet.items() if k != 'request_sha256'}
    require(digest(canonical(unsigned)) == packet['request_sha256'])
    require(packet['review']['effective_at'] <= time.time() < packet['review']['expires_at'])
    template = packet['template']
    require(template['provider'] == 'printify')
    for key in ('blueprint_id', 'provider_id', 'variant_id', 'version'):
        integer(template[key], 1, 2147483647)
    require(packet['source_sha256']['compiler'] == digest(Path(__file__).read_bytes()))
    require(packet['source_sha256']['request_builder'] == digest((Path(__file__).resolve().parents[1] / 'FC01ProductionArtifact.php').read_bytes()))
    require(packet['source_sha256']['font_provenance'] == digest((ASSETS / 'font/provenance.json').read_bytes()))
    identity = packet['identity']
    spec = (ASSETS / 'spec.json').read_bytes()
    require(identity['font_sha256'] == FONT_SHA and identity['license_sha256'] == LICENSE_SHA)
    require(identity['spec_sha256'] == digest(spec) and identity['family_id'] == 'FC01' and identity['version'] == 2 and identity['design_variant'] == 'text-only-noto-serif-v1' and identity['shop_key'] == 'personalized_pod')
    require(packet['regions'] == json.loads(spec)['regions'])
    payload = packet['personalization']
    require(set(payload) <= {'family_name', 'established'} and 'family_name' in payload)
    for field, value in payload.items():
        require(type(value) is str and len(value) <= 48)
        if value.strip():
            require(all(c in CHARS for c in value))
        else:
            require(field == 'established')
    # Preserve presence, whitespace and exact Unicode bytes in the payload digest.
    require(digest(encoded({k: payload[k] for k in ('family_name', 'established') if k in payload})) == packet['input_sha256'])
    runs = {'header': 'OUR FAMILY', 'family_name': payload['family_name']}
    if payload.get('established', '').strip():
        runs['established'] = 'ESTABLISHED '+payload['established']
    require(packet['text_runs'] == runs)
    area = packet['area']
    w, h = integer(area['width_px'], 1, 10000), integer(area['height_px'], 1, 10000)
    bindings = area['_catalog_bindings']
    selected = [b for b in bindings if b['variant_id'] == template['variant_id']]
    require(len(selected) == 1)
    for binding in bindings:
        require(binding['provider'] == 'printify' and binding['environment'] == packet['environment'] and binding['blueprint_id'] == template['blueprint_id'] and binding['provider_id'] == template['provider_id'])
        expected = {'position': area['position'], 'decoration_method': area['decoration_method'], 'width': w, 'height': h}
        require(binding['placeholder_sha256'] == digest(json.dumps(expected, ensure_ascii=False, separators=(',', ':')).encode()))
    require(w*h <= 25000000 and area['decoration_method'] in ('dtg', 'dtf', 'sublimation'))
    integer(area['dpi'], 72, 1200)
    qa = packet['geometry_qa']
    require(qa['state'] == 'VERIFIED_LOCAL_GEOMETRY' and qa['bleed_state'] == 'VERIFIED_LOCAL_BLEED_GEOMETRY' and qa['dpi'] == area['dpi'])
    safe = qa['safe_zone']
    for box in [safe]+qa['seam_exclusions']:
        for k in ('x', 'y'):
            integer(box[k], 0, 10000)
        for k in ('width', 'height'):
            integer(box[k], 1, 10000)
        require(inside(box, {'x': 0, 'y': 0, 'width': w, 'height': h}))
    require(qa['safe_zone'] == area['qa_geometry']['safe_zone'] and qa['seam_exclusions'] == area['qa_geometry']['seam_exclusions'])
    bleed = qa['bleed']
    require(bleed == area['qa_geometry']['bleed'])
    trim, margins = bleed['trim_box'], bleed['margins_px']
    for key in ('left', 'top', 'right', 'bottom'):
        integer(margins[key], 0, 10000)
    require(trim['x'] == margins['left'] and trim['y'] == margins['top'] and trim['x']+trim['width']+margins['right'] == w and trim['y']+trim['height']+margins['bottom'] == h and inside(safe, trim))
    # Uniformly place the retained 900x700 text layout inside the supplied safe area.
    # Font sizes are fixed by geometry; long inputs are rejected, never auto-shrunk.
    scale = min(safe['width']/900, safe['height']/700)
    require(scale >= 1)
    ox = safe['x']+(safe['width']-900*scale)/2
    oy = safe['y']+(safe['height']-700*scale)/2
    image = Image.new('RGBA', (w, h))
    draw = ImageDraw.Draw(image)
    bounds = {}
    for key, text in runs.items():
        require(all(char in CHARS for char in text))
        src = packet['regions'][key]
        box = {'x': math.ceil(ox+src['x']*scale), 'y': math.ceil(oy+src['y']*scale), 'width': math.floor(src['width']*scale), 'height': math.floor(src['height']*scale)}
        require(inside(box, safe))
        size = round({'header': 30, 'family_name': 48, 'established': 22}[key]*scale)
        font = ImageFont.truetype(str(ASSETS / 'font/NotoSerif-Regular.ttf'), size, layout_engine=ImageFont.Layout.BASIC)
        ascent, descent = font.getmetrics()
        advance = sum(round(font.getlength(c)) for c in text)
        require(advance <= box['width'] and ascent+descent <= box['height'])
        x = box['x']+(box['width']-advance)//2
        baseline = box['y']+(box['height']-ascent-descent)//2+ascent
        ink = []
        for char in text:
            l, t, r, b = font.getbbox(char, anchor='ls')
            if r > l and b > t:
                glyph = {'x': x+l, 'y': baseline+t, 'width': r-l, 'height': b-t}
                require(inside(glyph, box) and inside(glyph, safe))
                for seam in qa['seam_exclusions']:
                    require(not (glyph['x'] < seam['x']+seam['width'] and seam['x'] < glyph['x']+glyph['width'] and glyph['y'] < seam['y']+seam['height'] and seam['y'] < glyph['y']+glyph['height']))
                ink.append((glyph['x'], glyph['y'], glyph['x']+glyph['width'], glyph['y']+glyph['height']))
                draw.text((x, baseline), char, font=font, anchor='ls', fill='#304e55')
            x += round(font.getlength(char))
        require(bool(ink))
        bounds[key] = {'x': min(b[0] for b in ink), 'y': min(b[1] for b in ink), 'width': max(b[2] for b in ink)-min(b[0] for b in ink), 'height': max(b[3] for b in ink)-min(b[1] for b in ink)}
    output = io.BytesIO()
    image.save(output, format='PNG', dpi=(area['dpi'], area['dpi']), optimize=False, compress_level=9)
    png = output.getvalue()
    require(len(png) <= 8388608)
    return {'schema': 'fc01-production-artifact-receipt-v1', 'request_sha256': packet['request_sha256'], 'png_sha256': digest(png), 'png_base64': base64.b64encode(png).decode('ascii'), 'width_px': w, 'height_px': h, 'dpi': area['dpi'], 'ink_bounds': bounds, 'font_sha256': FONT_SHA, 'license_sha256': LICENSE_SHA, 'preview_final_parity': 'IDENTICAL_PNG_BYTES', 'pillow': PIL.__version__, 'freetype': features.version('freetype2'), 'production_authorized': False, 'supplier_certified': False, 'rights_certified': False, 'external_execution_authorized': False, 'publishing_allowed': False, 'acceptance_state': 'UNVERIFIED'}


def main():
    # Linux isolated worker: no network libraries, no caller-selected paths or fonts.
    resource.setrlimit(resource.RLIMIT_AS, (768*1024*1024, 768*1024*1024))
    resource.setrlimit(resource.RLIMIT_CPU, (20, 20))
    try:
        raw = sys.stdin.buffer.read(131073)
        require(len(raw) <= 131072)
        def unique(pairs):
            result = {}
            for key, value in pairs:
                require(key not in result)
                result[key] = value
            return result
        packet = json.loads(raw, object_pairs_hook=unique)
        print(json.dumps(compile_artifact(packet), separators=(',', ':')))
    except Exception:
        print('{"error":"fc01_offline_artifact_blocked"}')
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
