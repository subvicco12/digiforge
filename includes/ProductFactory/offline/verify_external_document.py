#!/usr/bin/env python3
"""Conservative local DOCX/PDF text-and-body-bounds evidence, never visual approval."""
import hashlib
import json
import math
from pathlib import Path, PurePosixPath
import resource
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile

PROFILE = 'digiforge-external-docx-pdf-v1'
W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
MAX_FILE = 16 * 1024 * 1024
MAX_XML = 8 * 1024 * 1024


def require(value):
    if not value:
        raise ValueError('Unsupported or incomplete document evidence')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def normalized(text):
    # Only Unicode whitespace is collapsed. Punctuation, hyphens, case and glyphs remain literal.
    return ' '.join(text.split())


def xml(data):
    data.decode('utf-8-sig')  # UTF-8 only: UTF-16 must not bypass declaration checks.
    require(b'\x00' not in data and len(data) <= MAX_XML and b'<!DOCTYPE' not in data.upper() and b'<!ENTITY' not in data.upper())
    root = ET.fromstring(data)
    require(sum(1 for _ in root.iter()) <= 100000)
    return root


def source(data):
    footer_parts = {}
    relationships = None
    with tempfile.SpooledTemporaryFile(max_size=MAX_FILE) as f:
        f.write(data); f.seek(0)
        with zipfile.ZipFile(f) as archive:
            infos = archive.infolist(); require(0 < len(infos) <= 512)
            names = [i.filename for i in infos]; require(len(names) == len(set(names)))
            require(sum(i.file_size for i in infos) <= 32 * 1024 * 1024)
            for i in infos:
                name = i.filename; parts = PurePosixPath(name).parts
                require(not name.startswith('/') and '\\' not in name and '..' not in parts and not i.flag_bits & 1)
                require(i.file_size <= MAX_XML and (i.external_attr >> 16) & 0o170000 != 0o120000)
                lower = name.lower()
                require(lower.endswith(('.xml', '.rels')) or name == 'docProps/thumbnail.jpeg')
                if name == 'docProps/thumbnail.jpeg':
                    thumbnail = archive.read(i)
                    # Only the byte-pinned passive python-docx default thumbnail is admitted.
                    require(digest(thumbnail) == '96367138dc44ce09bf2c8f0f8e49348a1478d2c5c0af69bbc2bbc38b63cdcead')
                require(not any(x in lower for x in ('vbaproject', 'activex', 'embeddings/', 'header', 'footnotes', 'endnotes', 'comments')))
                if lower.endswith(('.xml', '.rels')):
                    tree = xml(archive.read(i))
                    require(not any(n.get('TargetMode', '').lower() == 'external' for n in tree.iter()))
                    require(not any('macroenabled' in value.lower() or 'vbaproject' in value.lower() for n in tree.iter() for value in n.attrib.values()))
                    if lower.startswith('word/footer'):
                        require(lower == 'word/footer1.xml'); footer_parts[name] = tree
                    if name == 'word/_rels/document.xml.rels': relationships = tree
            root = xml(archive.read('word/document.xml'))
    require(root.tag == W+'document')
    body = root.find(W+'body'); require(body is not None)
    forbidden = ['altChunk','drawing','pict','object','sym','fldSimple','fldChar','instrText','del','ins','moveFrom','moveTo','sdt','numPr','txbxContent','lastRenderedPageBreak']
    require(not any(n.tag == W+tag for n in body.iter() for tag in forbidden))
    # Reject hidden runs and explicitly unsupported text directions rather than omit rendered text.
    require(not any(n.tag in (W+'vanish', W+'webHidden', W+'textDirection', W+'bidi') for n in body.iter()))
    sections = list(body.iter(W+'sectPr')); require(len(sections) == 1 and list(body)[-1] is sections[0])
    section = sections[0]; size = section.find(W+'pgSz'); margin = section.find(W+'pgMar')
    require(size is not None and margin is not None)
    def number(node, key):
        value = node.get(W+key); require(value is not None and value.isdigit()); return int(value)/20
    width, height = number(size,'w'), number(size,'h')
    left, right, top, bottom = [number(margin,k) for k in ('left','right','top','bottom')]
    require(72 <= width <= 1440 and 72 <= height <= 1440 and 0 < left+right < width and 0 < top+bottom < height)
    require(not margin.get(W+'gutter') or margin.get(W+'gutter') == '0')
    require(section.find(W+'headerReference') is None and section.find(W+'titlePg') is None and section.find(W+'pgNumType') is None)
    footer_refs = section.findall(W+'footerReference'); require(len(footer_refs) <= 1)
    footer = None
    if footer_refs:
        require(len(footer_parts) == 1 and relationships is not None)
        ref = footer_refs[0]; require(ref.get(W+'type') == 'default')
        rid = ref.get('{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id')
        matches = [r for r in relationships if r.get('Id') == rid]
        require(len(matches) == 1 and matches[0].get('Target') == 'footer1.xml' and matches[0].get('Type') == 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer')
        footer_root = footer_parts['word/footer1.xml']; require(footer_root.tag == W+'ftr')
        allowed = {'ftr','p','pPr','pStyle','jc','r','rPr','sz','t','fldSimple'}
        require(all(n.tag in {W+t for t in allowed} for n in footer_root.iter()))
        fields = list(footer_root.iter(W+'fldSimple'))
        require(len(fields) == 1 and fields[0].get(W+'instr','').strip() == 'PAGE' and len(fields[0]) == 0)
        require(len(list(footer_root.iter(W+'p'))) == 1)
        # PAGE must be terminal; no hidden cached field text or arbitrary field expression.
        paragraph = footer_root.find(W+'p'); require(list(paragraph)[-1] is fields[0])
        prefix = ''.join(n.text or '' for n in footer_root.iter(W+'t'))
        require(normalized(prefix) and 'http:' not in prefix.lower() and 'https:' not in prefix.lower())
        distance = number(margin,'footer'); require(0 < distance < bottom)
        footer = (prefix, height-distance, height-bottom)
    else:
        require(not footer_parts)
    paragraphs = []
    for p in body.iter(W+'p'):
        fragments = []
        for n in p.iter():
            if n.tag == W+'t': fragments.append(n.text or '')
            elif n.tag in (W+'tab', W+'br', W+'cr'): fragments.append(' ')
            elif n.tag in (W+'noBreakHyphen', W+'softHyphen'): raise ValueError('Unsupported discretionary glyph')
        paragraphs.append(''.join(fragments))
    text = normalized('\n'.join(paragraphs)); require(text and len(text) <= 1000000)
    return text, (width,height,left,top,width-right,height-bottom), footer


def inspect(docx_path, pdf_path):
    paths = [Path(docx_path),Path(pdf_path)]
    for path in paths:
        require(not any(parent.is_symlink() for parent in path.absolute().parents))
        require(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= MAX_FILE)
    docx, pdf = [p.read_bytes() for p in paths]
    require(pdf.startswith(b'%PDF-') and pdf.rstrip().endswith(b'%%EOF'))
    text, geometry, footer = source(docx)
    # Reject evident unsupported PDF features. Raw-token screening cannot certify
    # absence of compressed/escaped active objects: this is NOT PDF safety certification.
    require(not any(token in pdf for token in (b'/JavaScript',b'/JS',b'/Launch',b'/EmbeddedFile',b'/AcroForm',b'/Encrypt',b'/OCProperties')))
    with tempfile.TemporaryDirectory(prefix='digiforge-document-') as directory:
        pdf_file = Path(directory)/'export.pdf'; output = Path(directory)/'text.xhtml'
        pdf_file.write_bytes(pdf)
        result = subprocess.run(['/usr/bin/pdftotext','-bbox-layout','-enc','UTF-8',str(pdf_file),str(output)],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,timeout=10,env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'})
        require(result.returncode == 0 and result.stderr == b'' and output.is_file() and output.stat().st_size <= MAX_XML)
        # Poppler emits an XHTML DOCTYPE; reject entities, then remove only its fixed declaration.
        parsed = output.read_bytes()
        declaration = b'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
        parsed = parsed.replace(declaration,b'',1)
        tree = xml(parsed)
    pages = [n for n in tree.iter() if n.tag.rsplit('}',1)[-1]=='page']; require(0 < len(pages) <= 200)
    words=[]; width,height,left,top,right,bottom=geometry
    for page_number, page in enumerate(pages, 1):
        footer_words = []
        require(abs(float(page.get('width'))-width) <= .1 and abs(float(page.get('height'))-height) <= .1)
        for word in page.iter():
            if word.tag.rsplit('}',1)[-1] != 'word': continue
            box=[float(word.get(k)) for k in ('xMin','yMin','xMax','yMax')]
            require(all(math.isfinite(v) for v in box))
            require(left-.25 <= box[0] < box[2] <= right+.25)
            value = ''.join(word.itertext())
            if top-.25 <= box[1] < box[3] <= bottom+.25:
                words.append(value)
            else:
                require(footer is not None)
                prefix, baseline, footer_top = footer
                # Footer ink must stay below body and near its declared bottom offset.
                require(footer_top+.25 < box[1] < box[3] <= baseline+4 and box[1] >= baseline-16)
                footer_words.append(value)
        if footer is not None:
            require(normalized(' '.join(footer_words)) == normalized(footer[0]+str(page_number)))
        else:
            require(not footer_words)
    require(normalized(' '.join(words)) == text)
    # Re-read original paths to reject changes during parsing, rather than trust caller hashes.
    require(paths[0].read_bytes() == docx and paths[1].read_bytes() == pdf)
    return {'profile':PROFILE,'source_sha256':digest(docx),'export_sha256':digest(pdf),'normalized_text_sha256':digest(text.encode('utf-8')),'page_count':len(pages),'text_policy':'UNICODE_WHITESPACE_COLLAPSE_ONLY_LITERAL_GLYPHS','technical_scope':'SOURCE_TEXT_AND_DECLARED_BODY_BOUNDS_ONLY','visual_approved':False,'rights_certified':False,'production_authorized':False}


def main():
    try:
        resource.setrlimit(resource.RLIMIT_AS,(256*1024*1024,256*1024*1024))
        resource.setrlimit(resource.RLIMIT_CPU,(15,15))
        resource.setrlimit(resource.RLIMIT_FSIZE,(MAX_XML,MAX_XML))
        require(len(sys.argv)==3)
        print(json.dumps(inspect(sys.argv[1],sys.argv[2]),sort_keys=True,separators=(',',':')))
        return 0
    except Exception:
        print('{"error":"external_document_integrity_blocked"}')
        return 1

if __name__ == '__main__': sys.exit(main())
