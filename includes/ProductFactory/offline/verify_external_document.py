#!/usr/bin/env python3
"""Conservative local DOCX/PDF text-and-body-bounds evidence, never visual approval."""
import hashlib
import json
import math
import re
import posixpath
import struct
import zlib
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


R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
WP = '{http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing}'
A = '{http://schemas.openxmlformats.org/drawingml/2006/main}'
PIC = '{http://schemas.openxmlformats.org/drawingml/2006/picture}'


def passive_png(data):
    """Only non-interlaced 8-bit RGBA, no ancillary/animated/metadata chunks."""
    require(0 < len(data) <= MAX_XML and data[:8] == b'\x89PNG\r\n\x1a\n')
    offset=8; kinds=[]; compressed=b''; width=height=None
    while offset < len(data):
        require(offset+12 <= len(data)); length=struct.unpack('>I',data[offset:offset+4])[0]
        kind=data[offset+4:offset+8]; end=offset+12+length
        require(length <= MAX_XML and end <= len(data))
        payload=data[offset+8:offset+8+length]
        require(struct.unpack('>I',data[end-4:end])[0] == zlib.crc32(kind+payload)&0xffffffff)
        kinds.append(kind)
        if kind == b'IHDR':
            require(len(kinds)==1 and length==13)
            width,height,depth,color,compression,filtering,interlace=struct.unpack('>IIBBBBB',payload)
            require(1 <= width <= 4096 and 1 <= height <= 4096 and width*height <= 4194304)
            require((depth,color,compression,filtering,interlace)==(8,6,0,0,0))
        elif kind == b'IDAT':
            require(width is not None and b'IEND' not in kinds and kinds[-2] in (b'IHDR',b'IDAT'))
            compressed+=payload; require(len(compressed)<=MAX_XML)
        elif kind == b'IEND':
            require(length==0 and b'IDAT' in kinds and end==len(data))
        else: raise ValueError('Unsupported PNG chunk')
        offset=end
    require(kinds and kinds[-1]==b'IEND')
    expected=height*(1+4*width); decoder=zlib.decompressobj()
    pixels=decoder.decompress(compressed,expected+1)
    require(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail and len(pixels)==expected)
    require(all(pixels[row*(1+4*width)]<=4 for row in range(height)))
    return width,height


def inline_picture(drawing, relationships, images, width, height):
    # Match the complete passive tree, with only labels/IDs and bounded dimensions variable.
    require(len(drawing)==1 and drawing[0].tag==WP+'inline' and not drawing.attrib)
    inline=drawing[0]; require(not inline.attrib)
    require([n.tag for n in inline]==[WP+'extent',WP+'docPr',WP+'cNvGraphicFramePr',A+'graphic'])
    extent,props,frame,graphic=inline
    require(set(extent.attrib)=={'cx','cy'} and all(v.isdigit() for v in extent.attrib.values()))
    cx,cy=int(extent.get('cx')),int(extent.get('cy'))
    require(0 < cx/12700 <= width and 0 < cy/12700 <= height)
    require(set(props.attrib)=={'id','name'} and props.get('id').isdigit() and len(props.get('name'))<=160 and len(props)==0)
    require(not frame.attrib and len(frame)==1 and frame[0].tag==A+'graphicFrameLocks' and frame[0].attrib=={'noChangeAspect':'1'} and len(frame[0])==0)
    require(not graphic.attrib and len(graphic)==1 and graphic[0].tag==A+'graphicData')
    gd=graphic[0]; require(gd.attrib=={'uri':'http://schemas.openxmlformats.org/drawingml/2006/picture'} and len(gd)==1 and gd[0].tag==PIC+'pic')
    pic=gd[0]; require(not pic.attrib and [n.tag for n in pic]==[PIC+'nvPicPr',PIC+'blipFill',PIC+'spPr'])
    nv,fill,shape=pic
    require(not nv.attrib and [n.tag for n in nv]==[PIC+'cNvPr',PIC+'cNvPicPr'])
    require(set(nv[0].attrib)=={'id','name'} and nv[0].get('id').isdigit() and len(nv[0].get('name'))<=160 and len(nv[0])==0 and not nv[1].attrib and len(nv[1])==0)
    require(not fill.attrib and [n.tag for n in fill]==[A+'blip',A+'stretch'])
    require(set(fill[0].attrib)=={'{'+R+'}embed'} and len(fill[0])==0)
    rid=fill[0].get('{'+R+'}embed'); matches=[r for r in relationships if r.get('Id')==rid]
    require(len(matches)==1 and matches[0].get('Type')==R+'/image' and matches[0].get('TargetMode') is None)
    target='word/'+matches[0].get('Target',''); require(target in images)
    iw,ih=images[target]; require(abs(cx*ih-cy*iw)<=max(iw,ih))
    require(not fill[1].attrib and len(fill[1])==1 and fill[1][0].tag==A+'fillRect' and not fill[1][0].attrib and len(fill[1][0])==0)
    require(not shape.attrib and [n.tag for n in shape]==[A+'xfrm',A+'prstGeom'])
    transform,geom=shape
    require(not transform.attrib and [n.tag for n in transform]==[A+'off',A+'ext'])
    require(transform[0].attrib=={'x':'0','y':'0'} and transform[1].attrib=={'cx':str(cx),'cy':str(cy)} and all(len(n)==0 for n in transform))
    require(geom.attrib=={'prst':'rect'} and len(geom)==0)
    require(not any(n.text and n.text.strip() for n in drawing.iter()))
    return target


def source(data):
    footer_parts = {}
    relationships = None
    header_parts = {}; images = {}; content_types = None
    with tempfile.SpooledTemporaryFile(max_size=MAX_FILE) as f:
        f.write(data); f.seek(0)
        with zipfile.ZipFile(f) as archive:
            infos = archive.infolist(); require(0 < len(infos) <= 512)
            names = [i.filename for i in infos]; require(len(names) == len(set(names)))
            require(sum(i.file_size for i in infos) <= 32 * 1024 * 1024)
            for i in infos:
                name = i.filename; parts = PurePosixPath(name).parts
                require(not name.startswith('/') and ':' not in name and '\\' not in name and '..' not in parts and str(PurePosixPath(name))==name and not i.flag_bits & 1)
                require(i.file_size <= 65536 or i.file_size <= 200*max(i.compress_size,1))
                require(i.file_size <= MAX_XML and (i.external_attr >> 16) & 0o170000 != 0o120000)
                lower = name.lower()
                require(lower.endswith(('.xml', '.rels')) or name == 'docProps/thumbnail.jpeg' or re.fullmatch(r'word/media/image[1-9][0-9]{0,2}\.png',name))
                if name.endswith('.png'):
                    require(len(images)<16); images[name]=passive_png(archive.read(i))
                if name == 'docProps/thumbnail.jpeg':
                    thumbnail = archive.read(i)
                    # Only the byte-pinned passive python-docx default thumbnail is admitted.
                    require(digest(thumbnail) == '96367138dc44ce09bf2c8f0f8e49348a1478d2c5c0af69bbc2bbc38b63cdcead')
                require(not any(x in lower for x in ('vbaproject', 'activex', 'embeddings/', 'footnotes', 'endnotes', 'comments')))
                if lower.endswith(('.xml', '.rels')):
                    tree = xml(archive.read(i))
                    require(not any(n.get('TargetMode', '').lower() == 'external' for n in tree.iter()))
                    require(not any('macroenabled' in value.lower() or 'vbaproject' in value.lower() for n in tree.iter() for value in n.attrib.values()))
                    if lower.startswith('word/header'):
                        require(name == 'word/header1.xml'); header_parts[name]=tree
                    if name == '[Content_Types].xml': content_types=tree
                    if lower.endswith('.rels'):
                        relns='{http://schemas.openxmlformats.org/package/2006/relationships}'
                        require(tree.tag==relns+'Relationships' and not tree.attrib)
                        require(all(n.tag==relns+'Relationship' and len(n)==0 and {'Id','Type','Target'} <= set(n.attrib) <= {'Id','Type','Target','TargetMode'} for n in tree))
                        ids=[n.get('Id') for n in tree]; require(len(ids)==len(set(ids)))
                        for rel in tree:
                            target=rel.get('Target','')
                            require(target and not any(x in target for x in ('\\',':','%','?','#')) and not target.startswith('/'))
                            base='' if name=='_rels/.rels' else str(PurePosixPath(name).parent.parent)
                            resolved=posixpath.normpath(posixpath.join(base,target))
                            require(not resolved.startswith('../') and resolved not in ('.','..') and resolved in names)

                        require(not any(n.get('Type','').rsplit('/',1)[-1].lower() in ('oleobject','package','afchunk','attachedtemplate','hyperlink','vbaproject','control','externallink') for n in tree))
                    if lower.startswith('word/footer'):
                        require(lower == 'word/footer1.xml'); footer_parts[name] = tree
                    if name == 'word/_rels/document.xml.rels': relationships = tree
            root = xml(archive.read('word/document.xml'))
    require(root.tag == W+'document')
    body = root.find(W+'body'); require(body is not None)
    forbidden = ['altChunk','pict','object','sym','fldSimple','fldChar','instrText','del','ins','moveFrom','moveTo','sdt','numPr','txbxContent','lastRenderedPageBreak']
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
    require(section.find(W+'titlePg') is None and section.find(W+'pgNumType') is None)
    header_refs=section.findall(W+'headerReference'); require(len(header_refs)<=1)
    header=None
    if header_refs:
        require(len(header_parts)==1 and relationships is not None and content_types is not None)
        ref=header_refs[0]; require(ref.get(W+'type')=='default')
        matches=[r for r in relationships if r.get('Id')==ref.get('{'+R+'}id')]
        require(len(matches)==1 and matches[0].get('Type')==R+'/header' and matches[0].get('Target')=='header1.xml')
        hdr=header_parts['word/header1.xml']; require(hdr.tag==W+'hdr' and len(hdr)==1 and hdr[0].tag==W+'p')
        tags={'hdr','p','pPr','pStyle','jc','r','rPr','rFonts','color','sz','t'}
        require(all(n.tag in {W+t for t in tags} for n in hdr.iter()))
        require(not any(n.tag==W+'t' and len(n) for n in hdr.iter()))
        text=''.join(n.text or '' for n in hdr.iter(W+'t')); require(0 < len(text)<=512 and normalized(text))
        distance=number(margin,'header'); require(0 < distance < top)
        header=(text,distance,top)
        declarations=[n for n in content_types if n.get('PartName')=='/word/header1.xml']
        require(len(declarations)==1 and declarations[0].get('ContentType')=='application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml')
    else: require(not header_parts)
    if header or images:
        ct='{http://schemas.openxmlformats.org/package/2006/content-types}'
        require(content_types is not None and content_types.tag==ct+'Types' and not content_types.attrib)
        require(all(n.tag in (ct+'Default',ct+'Override') and len(n)==0 for n in content_types))
        extensions=[n.get('Extension','').lower() for n in content_types if n.tag==ct+'Default']
        overrides=[n.get('PartName') for n in content_types if n.tag==ct+'Override']
        require(len(extensions)==len(set(extensions)) and len(overrides)==len(set(overrides)))
    drawings=list(body.iter(W+'drawing')); require(len(drawings)<=16)
    referenced=set()
    if images or drawings:
        require(images and drawings and relationships is not None and content_types is not None)
        png_types=[n for n in content_types if n.get('Extension','').lower()=='png']
        require(len(png_types)==1 and png_types[0].get('ContentType')=='image/png')
        require(all(n.get('ContentType')=='image/png' for n in content_types if n.get('PartName','').endswith('.png')))
        for drawing in drawings: referenced.add(inline_picture(drawing,relationships,images,width-left-right,height-top-bottom))
        require(referenced==set(images))
        require(all('word/'+r.get('Target','') in images for r in relationships if r.get('Type')==R+'/image'))
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
    return text, (width,height,left,top,width-right,height-bottom), footer, header, bool(images or header)


def inspect(docx_path, pdf_path):
    paths = [Path(docx_path),Path(pdf_path)]
    for path in paths:
        require(not any(parent.is_symlink() for parent in path.absolute().parents))
        require(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= MAX_FILE)
    docx, pdf = [p.read_bytes() for p in paths]
    require(pdf.startswith(b'%PDF-') and pdf.rstrip().endswith(b'%%EOF'))
    text, geometry, footer, header, passive_layout = source(docx)
    # Reject evident unsupported PDF features. Raw-token screening cannot certify
    # absence of compressed/escaped active objects: this is NOT PDF safety certification.
    require(not any(token in pdf for token in (b'/JavaScript',b'/JS',b'/Launch',b'/EmbeddedFile',b'/AcroForm',b'/Encrypt',b'/OCProperties')))
    with tempfile.TemporaryDirectory(prefix='digiforge-document-') as directory:
        pdf_file = Path(directory)/'export.pdf'; output = Path(directory)/'text.xhtml'
        pdf_file.write_bytes(pdf)
        options=['-bbox','-raw'] if passive_layout else ['-bbox-layout']
        result = subprocess.run(['/usr/bin/pdftotext',*options,'-enc','UTF-8',str(pdf_file),str(output)],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,timeout=10,env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'})
        require(result.returncode == 0 and result.stderr == b'' and output.is_file() and output.stat().st_size <= MAX_XML)
        # Poppler emits an XHTML DOCTYPE; reject entities, then remove only its fixed declaration.
        parsed = output.read_bytes()
        declaration = b'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
        parsed = parsed.replace(declaration,b'',1)
        tree = xml(parsed)
    pages = [n for n in tree.iter() if n.tag.rsplit('}',1)[-1]=='page']; require(0 < len(pages) <= 200)
    words=[]; width,height,left,top,right,bottom=geometry
    for page_number, page in enumerate(pages, 1):
        footer_words = []; header_words=[]
        require(abs(float(page.get('width'))-width) <= .1 and abs(float(page.get('height'))-height) <= .1)
        for word in page.iter():
            if word.tag.rsplit('}',1)[-1] != 'word': continue
            box=[float(word.get(k)) for k in ('xMin','yMin','xMax','yMax')]
            require(all(math.isfinite(v) for v in box))
            require(left-.25 <= box[0] < box[2] <= right+.25)
            value = ''.join(word.itertext())
            if top-.25 <= box[1] < box[3] <= bottom+.25:
                words.append(value)
            elif header is not None and header[1]-.25 <= box[1] < box[3] < header[2]-.25:
                header_words.append(value)
            else:
                require(footer is not None)
                prefix, baseline, footer_top = footer
                # Footer ink must stay below body and near its declared bottom offset.
                require(footer_top+.25 < box[1] < box[3] <= baseline+4 and box[1] >= baseline-16)
                footer_words.append(value)
        if header is not None: require(normalized(' '.join(header_words)) == normalized(header[0]))
        if footer is not None:
            require(normalized(' '.join(footer_words)) == normalized(footer[0]+str(page_number)))
        else:
            require(not footer_words)
    require(normalized(' '.join(words)) == text)
    # Re-read original paths to reject changes during parsing, rather than trust caller hashes.
    require(paths[0].read_bytes() == docx and paths[1].read_bytes() == pdf)
    receipt = {'profile':PROFILE,'source_sha256':digest(docx),'export_sha256':digest(pdf),'normalized_text_sha256':digest(text.encode('utf-8')),'page_count':len(pages),'text_policy':'UNICODE_WHITESPACE_COLLAPSE_ONLY_LITERAL_GLYPHS','technical_scope':'SOURCE_TEXT_AND_DECLARED_BODY_BOUNDS_ONLY','visual_approved':False,'rights_certified':False,'production_authorized':False}
    if passive_layout:
        receipt.update({'source_layout_profile':'INLINE_RGBA8_PNG_STATIC_HEADER_V1','artwork_export_parity_verified':False})
    return receipt


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
