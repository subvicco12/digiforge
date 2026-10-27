"""Real stdlib-generated DOCX/PDF pairs; no commercial fixtures."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
WORKER = ROOT / 'includes/ProductFactory/offline/verify_external_document.py'


def docx(path, text='Exact source - punctuation!', width=12240, height=15840, extra='', members=None, footer=False):
    xml = f'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{text}</w:t></w:r></w:p>{extra}<w:sectPr><w:pgSz w:w="{width}" w:h="{height}"/><w:pgMar w:top="1440" w:bottom="1440" w:left="1440" w:right="1440"/></w:sectPr></w:body></w:document>'
    if footer:
        xml=xml.replace('<w:pgSz','<w:footerReference xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" w:type="default" r:id="rIdFooter"/><w:pgSz').replace('w:right="1440"/>','w:right="1440" w:footer="432"/>')
        members={**(members or {}),'word/footer1.xml':'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>REVIEW / </w:t></w:r><w:fldSimple w:instr="PAGE"/></w:p></w:ftr>','word/_rels/document.xml.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>'}
    with zipfile.ZipFile(path, 'w') as z:
        z.writestr('word/document.xml', xml)
        for key, value in (members or {}).items():
            z.writestr(key, value)


def pdf(path, text='Exact source - punctuation!', width=612, height=792, x=72, y=None, footer=None, footer_y=22, header=None, header_y=None):
    y = height-90 if y is None else y
    text = text.replace('\\', '\\\\').replace('(', '\\(').replace(')', '\\)')
    stream = f'BT /F1 12 Tf {x} {y} Td ({text}) Tj ET'.encode('ascii')
    if footer is not None:
        stream += f' BT /F1 10 Tf 72 {footer_y} Td ({footer}) Tj ET'.encode('ascii')
    if header is not None:
        stream += f' BT /F1 10 Tf 72 {height-30 if header_y is None else header_y} Td ({header}) Tj ET'.encode('ascii')
    objects = [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>', f'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {width} {height}] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>'.encode(), b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', b'<< /Length '+str(len(stream)).encode()+b' >>\nstream\n'+stream+b'\nendstream']
    result = b'%PDF-1.4\n'; offsets = [0]
    for i, body in enumerate(objects, 1):
        offsets.append(len(result)); result += str(i).encode()+b' 0 obj\n'+body+b'\nendobj\n'
    start = len(result)
    result += b'xref\n0 6\n0000000000 65535 f \n'+b''.join(f'{o:010d} 00000 n \n'.encode() for o in offsets[1:])
    result += f'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{start}\n%%EOF\n'.encode()
    path.write_bytes(result)


class ExternalDocumentIntegrityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.doc = Path(self.temp.name)/'source.docx'; self.out = Path(self.temp.name)/'export.pdf'
        docx(self.doc); pdf(self.out)

    def run_worker(self):
        return subprocess.run([sys.executable, '-B', str(WORKER), str(self.doc), str(self.out)], capture_output=True, timeout=15)

    def accepted(self):
        result = self.run_worker(); self.assertEqual(0, result.returncode, result.stdout+result.stderr)
        return json.loads(result.stdout)

    def rejected(self):
        result = self.run_worker(); self.assertNotEqual(0, result.returncode)
        self.assertEqual({'error': 'external_document_integrity_blocked'}, json.loads(result.stdout))

    def test_real_letter_and_a4_exact_hashes(self):
        for width, height in [(12240,15840),(11906,16838)]:
            with self.subTest(width=width):
                docx(self.doc,width=width,height=height); pdf(self.out,width=width/20,height=height/20)
                r=self.accepted(); self.assertEqual('digiforge-external-docx-pdf-v1',r['profile'])
                self.assertEqual(hashlib.sha256(self.doc.read_bytes()).hexdigest(),r['source_sha256'])
                self.assertEqual(hashlib.sha256(self.out.read_bytes()).hexdigest(),r['export_sha256'])
                self.assertEqual(1,r['page_count']); self.assertFalse(r['production_authorized'])

    def test_missing_extra_reordered_and_punctuation_text_reject(self):
        for text in ['Exact source','Exact source - punctuation! extra','punctuation! - source Exact','Exact source punctuation!','Exact source – punctuation!']:
            with self.subTest(text=text):
                if not text.isascii():
                    docx(self.doc,text=text)
                else: pdf(self.out,text=text)
                self.rejected()

    def test_overflow_and_wrong_page_geometry_reject(self):
        pdf(self.out,x=10);self.rejected()
        pdf(self.out,width=595,height=842);self.rejected()

    def test_truncated_pdf_reject(self):
        self.out.write_bytes(self.out.read_bytes()[:100]);self.rejected()

    def test_active_external_entities_headers_and_traversal_reject(self):
        for members in [{'../escape':'bad'},{'word/vbaProject.bin':'bad'},{'[Content_Types].xml':'<Types><Override ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>'},{'word/header1.xml':'<header>Unbound header</header>'},{'word/_rels/document.xml.rels':'<Relationships><Relationship TargetMode="External" Target="https://example.test"/></Relationships>'}]:
            with self.subTest(members=list(members)):
                docx(self.doc,members=members);self.rejected()
        docx(self.doc,extra='<w:altChunk/>');self.rejected()
        with zipfile.ZipFile(self.doc,'w') as z:z.writestr('word/document.xml','<!DOCTYPE x [<!ENTITY x "value">]><x>&x;</x>')
        self.rejected()

    def test_exact_source_bound_footer_and_page_field(self):
        docx(self.doc,footer=True);pdf(self.out,footer='REVIEW / 1');self.accepted()

    def test_forged_missing_misplaced_and_outside_footer_reject(self):
        docx(self.doc,footer=True)
        for content,y in [('FORGED / 1',22),('REVIEW / 2',22),('REVIEW / 1',100),('REVIEW / 1',4),(None,22)]:
            with self.subTest(content=content,y=y):
                pdf(self.out,footer=content,footer_y=y);self.rejected()

    def test_non_page_nested_and_wrong_footer_relationship_reject(self):
        docx(self.doc,footer=True)
        with zipfile.ZipFile(self.doc) as z: original={n:z.read(n) for n in z.namelist()}
        for key,old,new in [('word/footer1.xml',b'w:instr="PAGE"',b'w:instr="DATE"'),('word/footer1.xml',b'<w:fldSimple w:instr="PAGE"/>',b'<w:fldSimple w:instr="PAGE"><w:r><w:t>1</w:t></w:r></w:fldSimple>'),('word/_rels/document.xml.rels',b'Target="footer1.xml"',b'Target="../footer1.xml"')]:
            with self.subTest(key=key,new=new):
                with zipfile.ZipFile(self.doc,'w') as z:
                    for name,data in original.items():z.writestr(name,data.replace(old,new) if name==key else data)
                pdf(self.out,footer='REVIEW / 1');self.rejected()

    def test_unknown_binary_and_invalid_thumbnail_and_parent_symlink_reject(self):
        for members in [{'word/payload.exe':b'MZ executable'},{'word/unrecognized.bin':b'payload'},{'docProps/thumbnail.jpeg':b'not jpeg'}]:
            with self.subTest(members=list(members)):
                docx(self.doc,members=members);self.rejected()
        docx(self.doc)
        real = Path(self.temp.name)/'real';real.mkdir()
        self.doc.rename(real/'source.docx')
        alias=Path(self.temp.name)/'alias';alias.symlink_to(real,target_is_directory=True)
        self.doc=alias/'source.docx';self.rejected()

    def test_utf16_entity_declaration_cannot_bypass_xml_policy(self):
        markup = '<!DOCTYPE x [<!ENTITY buyer "Exact source - punctuation!">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&buyer;</w:t></w:r></w:p><w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:bottom="1440" w:left="1440" w:right="1440"/></w:sectPr></w:body></w:document>'
        with zipfile.ZipFile(self.doc,'w') as z:z.writestr('word/document.xml',markup.encode('utf-16'))
        self.rejected()

    def test_whitespace_only_policy_and_table_order(self):
        docx(self.doc,text='Exact   source - punctuation!');self.accepted()
        docx(self.doc,extra='<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Table end.</w:t></w:r></w:p></w:tc></w:tr></w:tbl>')
        pdf(self.out,text='Exact source - punctuation! Table end.');self.accepted()

if __name__ == '__main__': unittest.main()
