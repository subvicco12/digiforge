#!/usr/bin/env python3
"""Rebuild draft-only artwork library from supplied bundles; never contacts a supplier."""
import argparse
import csv
import hashlib
import io
import json
from pathlib import Path
import struct
import xml.etree.ElementTree as ET
import zipfile
import zlib


def sha(data):
    return hashlib.sha256(data).hexdigest()


def png(rgba, width, height):
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
    rows = b''.join(b'\0' + rgba[y * width * 4:(y + 1) * width * 4] for y in range(height))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(rows, 9)) + chunk(b'IEND', b''))


def build_families(output):
    """Versioned draft composition contracts. Does not approve a template or supplier."""
    fields = {
        'NM01':['initial','name'], 'NM02':['name','detail'], 'NM03':['initials','names'], 'NM04':['name','year'],
        'FC01':['family_name','established'], 'FC02':['coordinates','place'], 'FC03':['name_one','name_two','date'], 'FC04':['family_name','detail'],
        'PT01':['name','detail'], 'PT02':['name','detail'], 'PT03':['name','photo'], 'PT04':['name','photo'],
        'MR01':['name','dates'], 'MR02':['name','detail'], 'MR03':['name','photo'], 'MR04':['artwork','name'],
        'OC01':['name','milestone'], 'OC02':['name','date'], 'OC03':['name','year'], 'OC04':['family_name','year']}
    families = {}
    rows = csv.DictReader(io.StringIO((output/'sources/20_Personalized_Template_Previews/preview_manifest.csv').read_text()))
    for row in rows:
        ident = row['concept_id']
        schema = []
        for index, key in enumerate(fields[ident]):
            image = key in ('photo','artwork')
            schema.append({'key':key,'type':'image_reference' if image else ('date' if key=='date' else 'text'),
                           'required':index==0 or image or ident=='FC03',
                           'max_length':512 if image else (1 if key=='initial' else 4 if key=='initials' else 48)})
        families[ident] = {'id':ident,'version':1,'name':row['concept'],'component':row['component'].removesuffix('.svg'),
                          'engine':{'PT03':'PHOTO_TEXT','PT04':'PET_PORTRAIT','MR03':'MEMORIAL','MR04':'HANDWRITING'}.get(ident,'NAME_MONOGRAM'),
                          'fields':schema,'status':'TRANSFORMATION_REQUIRED' if ident=='PT04' else 'DRAFT_RECIPE_AVAILABLE'}
    for path in sorted((output/'sources/FACE_REPEAT_Design_Family').glob('FR*.svg')):
        root = ET.fromstring(path.read_bytes())
        positions = []
        for node in root.iter():
            if node.attrib.get("transform", "").startswith("translate(") and node.tag.endswith('}g'):
                transform = node.attrib['transform']
                x, y = transform.removeprefix('translate(').removesuffix(')').split()
                positions.append([float(x),float(y)])
        ident = path.name[:4]
        families[ident] = {'id':ident,'version':1,'name':path.stem[5:],
                          'engine':'FACE_REPEAT' if ident in ('FR04','FR05','FR06') else 'PET_FACE_REPEAT',
                          'fields':[],'component':None,'status':'DRAFT_RECIPE_AVAILABLE','positions':positions,
                          'canvas':[560,560],'compatible_engines':['FACE_REPEAT','PET_FACE_REPEAT','AOP_PATTERN'],'prototype_source':str(path.relative_to(output))}
        if ident=='FR06':
            families[ident]['required_cutout_style']='CS06'
        if ident in ('FR03','FR07'):
            families[ident]['decoration'] = ident.lower()+'_decoration'
    (output/'families.json').write_text(json.dumps(families,sort_keys=True,indent=2)+'\n')


def main():
    import cairosvg
    from PIL import Image
    p = argparse.ArgumentParser()
    p.add_argument('--bundles', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    args = p.parse_args()
    prefixes = ['DigiForge_20_Design_QA_Handoff', 'DigiForge_FACE_REPEAT_Cutout_Styling',
                'DigiForge_FACE_REPEAT_Design_Family', 'DigiForge_POD_Reusable_Vector_Components',
                'DigiForge_20_Personalized_Template_Previews']
    registry = {'version': 1, 'approval': 'DRAFT_ONLY', 'supplier_certified': False,
                'commercial_certified': False, 'archives': [], 'components': {}, 'decorations': {}}
    for prefix in prefixes:
        matches = list(args.bundles.rglob(prefix + '*.zip'))
        if len(matches) != 1:
            raise ValueError('Exactly one authoritative bundle required: ' + prefix)
        archive = matches[0]
        pack = prefix.removeprefix('DigiForge_')
        folder = args.output / 'sources' / pack
        folder.mkdir(parents=True, exist_ok=True)
        files = {}
        with zipfile.ZipFile(archive) as z:
            if len(z.infolist()) > 256:
                raise ValueError('Asset work bound')
            for info in z.infolist():
                name = Path(info.filename)
                if info.is_dir():
                    continue
                if name.is_absolute() or len(name.parts) != 1 or '..' in name.parts or info.file_size > 20000000:
                    raise ValueError('Unsafe archive member')
                data = z.read(info)
                files[info.filename] = data
                (folder / name).write_bytes(data)
        manifest = files.get('SHA256SUMS.csv')
        if manifest:
            for row in csv.DictReader(io.StringIO(manifest.decode())):
                data = files[row.get('file') or row['filename']]
                if sha(data) != row['sha256'] or len(data) != int(row['bytes']):
                    raise ValueError('Original manifest mismatch')
        if 'preview_manifest.csv' in files:
            rows = list(csv.DictReader(io.StringIO(files['preview_manifest.csv'].decode())))
            if len(rows) != 20 or any(sha(files[r['png']]) != r['png_sha256'] for r in rows):
                raise ValueError('Preview inventory mismatch')
        registry['archives'].append({'filename': archive.name, 'sha256': sha(archive.read_bytes()),
                                    'files': {name: sha(data) for name, data in sorted(files.items())}})
        if 'Reusable_Vector' not in prefix:
            continue
        attributes = {'xmlns', 'viewBox', 'role', 'aria-label', 'fill', 'stroke', 'stroke-width',
                      'stroke-linecap', 'stroke-linejoin', 'transform', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'width', 'height'}
        for name, data in files.items():
            if not name.endswith('.svg'):
                continue
            if b'<!' in data or b'<?' in data:
                raise ValueError('SVG declarations unsupported')
            root = ET.fromstring(data)
            if root.get('viewBox') != '0 0 1000 1000':
                raise ValueError('Component source canvas mismatch')
            for node in root.iter():
                if node.tag not in {'{http://www.w3.org/2000/svg}' + tag for tag in ['svg', 'g', 'path', 'circle', 'ellipse', 'rect']}:
                    raise ValueError('Unsupported component construct')
                if set(node.attrib) - attributes or any('url(' in v or '://' in v for v in node.attrib.values()):
                    raise ValueError('Unsafe SVG attribute')
            image = Image.open(io.BytesIO(cairosvg.svg2png(bytestring=data, output_width=1000, output_height=1000))).convert('RGBA')
            derived = png(image.tobytes(), 1000, 1000)
            target = args.output / 'components' / name.replace('.svg', '.png')
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(derived)
            registry['components'][Path(name).stem] = {'source': str((folder / name).relative_to(args.output)),
                'source_sha256': sha(data), 'raster': str(target.relative_to(args.output)), 'raster_sha256': sha(derived),
                'width_px': 1000, 'height_px': 1000, 'conversion': 'CairoSVG-' + cairosvg.__version__ + '/RGBA8-no-metadata-v1',
                'approval': 'CANDIDATE_NOT_APPROVED'}
    if len(registry['components']) != 18:
        raise ValueError('Component inventory mismatch')
    # Preserve only actual top-level hearts/ribbons, never placeholder faces or NAME text.
    for ident, tag in [('FR03','path'),('FR07','rect')]:
        source = next((args.output/'sources/FACE_REPEAT_Design_Family').glob(ident+'*.svg'))
        original = source.read_bytes()
        root = ET.fromstring(original)
        derived_root = ET.Element('{http://www.w3.org/2000/svg}svg', {'viewBox':'0 0 560 560'})
        for node in list(root):
            if node.tag == '{http://www.w3.org/2000/svg}'+tag and (ident!='FR07' or 'x' in node.attrib):
                if any('url(' in value or '://' in value for value in node.attrib.values()):
                    raise ValueError('Unsafe decoration')
                derived_root.append(node)
        if len(derived_root)!=16:
            raise ValueError('Exact sixteen source decorations required')
        rendered = ET.tostring(derived_root)
        image = Image.open(io.BytesIO(cairosvg.svg2png(bytestring=rendered,output_width=560,output_height=560))).convert('RGBA')
        derived = png(image.tobytes(),560,560)
        key = ident.lower()+'_decoration'
        target = args.output/'components'/(key+'.png')
        target.write_bytes(derived)
        registry['decorations'][key] = {'source':str(source.relative_to(args.output)),'source_sha256':sha(original),
            'raster':str(target.relative_to(args.output)), 'raster_sha256':sha(derived), 'width_px':560,'height_px':560,
            'conversion_input_sha256':sha(rendered),'conversion':'CairoSVG-'+cairosvg.__version__+'/top-level-'+tag+'-without-placeholders-v1',
            'approval':'CANDIDATE_NOT_APPROVED'}
    build_families(args.output)
    (args.output / 'registry.json').write_text(json.dumps(registry, sort_keys=True, indent=2) + '\n')
    print('Five source bundles verified; 18 bounded component rasters built; certification remains false.')


if __name__ == '__main__':
    main()
