#!/usr/bin/env python3
"""Offline Drive research converter. No network, credentials or application writes."""
import argparse
import csv
import hashlib
import json
import posixpath
from pathlib import Path
import re
import zipfile
import xml.etree.ElementTree as ET

NS = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
MAX_BYTES = 16 * 1024 * 1024


def rows_hash(rows):
    raw = json.dumps(rows, ensure_ascii=False, separators=(',', ':')).replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')
    return hashlib.sha256(raw.encode()).hexdigest()


def records(matrix, row_numbers=None):
    if not matrix:
        raise ValueError('Missing header')
    headers = matrix[0]
    indexes = [i for i, name in enumerate(headers) if name not in (None, '')]
    names = [str(headers[i]) for i in indexes]
    if len(set(names)) != len(names) or len(names) > 64:
        raise ValueError('Duplicate or excessive headers')
    result = []
    for offset, cells in enumerate(matrix[1:], 1):
        row = {name: (str(cells[i]) if i < len(cells) and cells[i] not in ('', None) else None) for i, name in zip(indexes, names)}
        if any(v is not None for v in row.values()):
            row['_source_row'] = str(row_numbers[offset] if row_numbers else offset + 1)
            result.append(row)
    if len(result) > 50000:
        raise ValueError('Row ceiling exceeded')
    return result


def read_csv(path):
    with path.open(encoding='utf-8-sig', newline='') as stream:
        return records(list(csv.reader(stream)))


def read_xlsx(path, sheet):
    with zipfile.ZipFile(path) as archive:
        if sum(i.file_size for i in archive.infolist()) > 64 * 1024 * 1024:
            raise ValueError('Expanded XLSX exceeds 64 MiB')
        shared = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            for item in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('s:si', NS):
                shared.append(''.join(item.itertext()))
        relationships = {r.attrib['Id']:r.attrib['Target'] for r in ET.fromstring(archive.read('xl/_rels/workbook.xml.rels'))}
        sheets = ET.fromstring(archive.read('xl/workbook.xml')).findall('s:sheets/s:sheet', NS)
        match = next((s for s in sheets if s.attrib['name'] == sheet), None)
        if match is None:
            raise ValueError('Missing worksheet: ' + sheet)
        target = relationships[match.attrib['{' + REL + '}id']]
        member = posixpath.normpath(target.lstrip('/') if target.startswith('/') else 'xl/' + target)
        if not member.startswith('xl/'):
            raise ValueError('Invalid worksheet path')
        root = ET.fromstring(archive.read(member))
        matrix, row_numbers = [], []
        for row in root.findall('s:sheetData/s:row', NS):
            cells = []
            for cell in row.findall('s:c', NS):
                if cell.find('s:f', NS) is not None:
                    raise ValueError('Formula cells require a separately retained values-only source')
                col = re.match(r'([A-Z]+)', cell.attrib.get('r', chr(65 + len(cells)))).group(1)
                index = 0
                for letter in col:
                    index = index * 26 + ord(letter) - 64
                if index > 64:
                    raise ValueError('Column ceiling exceeded')
                while len(cells) < index:
                    cells.append(None)
                value = cell.find('s:v', NS)
                text = value.text if value is not None else None
                if cell.attrib.get('t') == 's' and text is not None:
                    text = shared[int(text)]
                elif cell.attrib.get('t') == 'inlineStr':
                    inline = cell.find('s:is', NS)
                    text = ''.join(inline.itertext()) if inline is not None else None
                cells[index - 1] = text
            matrix.append(cells)
            row_numbers.append(int(row.attrib.get('r', len(matrix))))
        return records(matrix, row_numbers)


def convert(manifest):
    sources, files = [], {}
    for source in manifest['sources']:
        path = Path(source['path'])
        if path.stat().st_size > MAX_BYTES:
            raise ValueError('Source exceeds 16 MiB')
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if source.get('sha256', digest) != digest:
            raise ValueError('Source hash mismatch')
        if source['id'] in files:
            raise ValueError('Duplicate source ID')
        files[source['id']] = path
        sources.append({k: source[k] for k in ('id', 'name', 'modified_at') } | {'sha256': digest})
    sections = {}
    for name, binding in manifest['sections'].items():
        path = files[binding['source_id']]
        rows = read_xlsx(path, binding['sheet']) if path.suffix.lower() == '.xlsx' else read_csv(path)
        sections[name] = binding | {'rows': rows, 'rows_sha256': rows_hash(rows)}
    packet = {'schema_version': 1, 'shop_key': 'personalized_pod', 'business_key': 'digicraftifygoods', 'catalog_key': 'digicraftifygoods-master-500-v2', 'sources': sources, 'sections': sections, 'production_authority': False}
    if len(json.dumps(packet).encode()) > MAX_BYTES:
        raise ValueError('Snapshot exceeds 16 MiB')
    return packet


def validate_packet(packet):
    """Verify a bounded WordPress research export before local conversion."""
    allowed={'concepts','lineage','matches','candidates','blueprints','shortlist','providers','variants','print_areas','size_guides','shipping','costs','gaps','coverage','batches','source_lineage'}
    if packet.get('schema_version') != 1 or packet.get('shop_key') != 'personalized_pod' or packet.get('business_key') != 'digicraftifygoods' or packet.get('catalog_key') != 'digicraftifygoods-master-500-v2':
        raise ValueError('Exact Shop 2 research packet required')
    if len(json.dumps(packet).encode()) > MAX_BYTES or len(packet.get('sources',[])) > 200:
        raise ValueError('Packet exceeds bounds')
    sources={source['id']: source for source in packet['sources']}
    count=0
    for name,section in packet['sections'].items():
        rows=section['rows'];count+=len(rows)
        if name not in allowed or section['source_id'] not in sources or count > 50000 or rows_hash(rows) != section['rows_sha256']:
            raise ValueError('Section provenance or integrity mismatch')
        for row in rows:
            if row.get('_source_id',section['source_id']) not in sources:
                raise ValueError('Row source unavailable')
    packet.update(production_authority=False,finance_authority=False,external_execution_authorized=False,approval_status='UNREVIEWED')
    return packet


def export_xlsx(packet, path):
    # All cells are literal inline strings, including formula-looking research text.
    from xml.sax.saxutils import escape
    sheets = list(packet['sections'].items())
    if any(not re.fullmatch(r'[a-z_]{1,31}', name) for name,_ in sheets):
        raise ValueError('Unsafe export section name')
    if packet.get('sources'):
        sheets.append(('_sources',{'rows':packet['sources']}))
        sheets.append(('_bindings',{'rows':[{'section':name,'source_id':section['source_id'],'sheet':section['sheet'],'rows_sha256':section['rows_sha256']} for name,section in packet['sections'].items()]}))
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        overrides = ''.join(f'<Override PartName="/xl/worksheets/sheet{i}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' for i in range(1,len(sheets)+1))
        z.writestr('[Content_Types].xml', f'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>{overrides}</Types>')
        z.writestr('_rels/.rels', f'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="{REL}/officeDocument" Target="xl/workbook.xml"/></Relationships>')
        refs = ''.join(f'<sheet name="{escape(name)}" sheetId="{i}" r:id="rId{i}"/>' for i,(name,_) in enumerate(sheets,1))
        z.writestr('xl/workbook.xml', f'<workbook xmlns="{NS["s"]}" xmlns:r="{REL}"><sheets>{refs}</sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'+''.join(f'<Relationship Id="rId{i}" Type="{REL}/worksheet" Target="worksheets/sheet{i}.xml"/>' for i in range(1,len(sheets)+1))+'</Relationships>')
        for i,(_,section) in enumerate(sheets,1):
            rows=section['rows'];headers=list(dict.fromkeys(k for row in rows for k in row))
            values=[headers]+[[row.get(k) for k in headers] for row in rows]
            xml=''.join('<row>'+''.join('<c t="inlineStr"><is><t xml:space="preserve">'+escape('' if v is None else str(v))+'</t></is></c>' for v in row)+'</row>' for row in values)
            z.writestr(f'xl/worksheets/sheet{i}.xml', f'<worksheet xmlns="{NS["s"]}"><sheetData>{xml}</sheetData></worksheet>')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest',type=Path)
    parser.add_argument('--output',required=True,type=Path)
    parser.add_argument('--packet',action='store_true',help='Input is an exported research JSON packet, not a source manifest')
    parser.add_argument('--xlsx',type=Path,help='Optional local literal-cell XLSX export')
    args=parser.parse_args()
    input_data=json.loads(args.manifest.read_text())
    packet=validate_packet(input_data) if args.packet else convert(input_data)
    args.output.write_text(json.dumps(packet,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
    if args.xlsx: export_xlsx(packet,args.xlsx)
    print(json.dumps({'sections':{k:len(v['rows']) for k,v in packet['sections'].items()},'production_authority':False}))

if __name__=='__main__': main()
