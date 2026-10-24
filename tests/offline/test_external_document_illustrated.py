"""Passive subset only. Synthetic fixtures; real product bytes tested separately offline."""
import hashlib,json,struct,subprocess,sys,tempfile,unittest,zipfile,zlib
from pathlib import Path
from test_external_document_integrity import docx,pdf,WORKER
R='http://schemas.openxmlformats.org/officeDocument/2006/relationships'
DRAW='<w:drawing xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="'+R+'"><wp:inline><wp:extent cx="914400" cy="914400"/><wp:docPr id="1" name="Picture 1"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="art.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdP"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="914400"/></a:xfrm><a:prstGeom prst="rect"/></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>'
def chunk(kind,data):return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
def png():return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',2,2,8,6,0,0,0))+chunk(b'IDAT',zlib.compress((b'\x00'+b'\x00\x80\x40\xff'*2)*2))+chunk(b'IEND',b'')
def illustrated(path,mutate=None):
 docx(path,extra='<w:p><w:r>'+DRAW+'</w:r></w:p>')
 with zipfile.ZipFile(path) as z:parts={n:z.read(n) for n in z.namelist()}
 parts['word/document.xml']=parts['word/document.xml'].replace(b'<w:pgSz',b'<w:headerReference xmlns:r="'+R.encode()+b'" w:type="default" r:id="rIdH"/><w:pgSz').replace(b'w:right="1440"/>',b'w:right="1440" w:header="432"/>')
 parts.update({'word/header1.xml':b'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>OUR CELEBRATION</w:t></w:r></w:p></w:hdr>','word/media/image1.png':png(),'word/_rels/document.xml.rels':('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdP" Type="'+R+'/image" Target="media/image1.png"/><Relationship Id="rIdH" Type="'+R+'/header" Target="header1.xml"/></Relationships>').encode(),'[Content_Types].xml':b'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="png" ContentType="image/png"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>'})
 if mutate:mutate(parts)
 with zipfile.ZipFile(path,'w') as z:
  for n,b in parts.items():z.writestr(n,b)
class IllustratedDocumentTest(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.doc=Path(self.tmp.name)/'a.docx';self.pdf=Path(self.tmp.name)/'a.pdf';illustrated(self.doc);pdf(self.pdf,header='OUR CELEBRATION')
 def run_worker(self):return subprocess.run([sys.executable,'-B',str(WORKER),str(self.doc),str(self.pdf)],capture_output=True,timeout=15)
 def test_passive_png_header_and_literal_hashes(self):
  r=self.run_worker();self.assertEqual(0,r.returncode,r.stdout+r.stderr);a=json.loads(r.stdout);self.assertEqual(hashlib.sha256(self.doc.read_bytes()).hexdigest(),a['source_sha256']);self.assertFalse(a['visual_approved']);self.assertFalse(a['rights_certified'])
 def test_lost_extra_or_reordered_body_text_still_rejects(self):
  for text in ['Exact source','Exact source - punctuation! extra','punctuation! - source Exact']:
   pdf(self.pdf,text=text,header='OUR CELEBRATION');self.assertNotEqual(0,self.run_worker().returncode)
 def test_orphan_image_duplicate_relationship_and_unbound_header_reject(self):
  edits=[lambda p:p.__setitem__('word/media/image2.png',png()),lambda p:p.__setitem__('word/_rels/document.xml.rels',p['word/_rels/document.xml.rels'].replace(b'rIdH',b'rIdP')),lambda p:p.__setitem__('word/document.xml',p['word/document.xml'].replace(b'r:id="rIdH"',b'r:id="missing"')),lambda p:p.__setitem__('word/media/image1.png',b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',4096,4096,8,6,0,0,0))+chunk(b'IDAT',zlib.compress(b'\x00'*100))+chunk(b'IEND',b''))]
  for edit in edits:
   illustrated(self.doc,edit);self.assertNotEqual(0,self.run_worker().returncode)
 def test_compression_bomb_duplicate_member_and_archive_traversal_reject(self):
  import warnings
  illustrated(self.doc)
  with zipfile.ZipFile(self.doc,'a',compression=zipfile.ZIP_DEFLATED) as z:z.writestr('word/bomb.xml',b' '*100000)
  self.assertNotEqual(0,self.run_worker().returncode)
  illustrated(self.doc)
  with warnings.catch_warnings():
   warnings.simplefilter('ignore',UserWarning)
   with zipfile.ZipFile(self.doc,'a') as z:z.writestr('word/header1.xml',b'<invalid/>')
  self.assertNotEqual(0,self.run_worker().returncode)
  illustrated(self.doc,lambda p:p.__setitem__('../payload.exe',b'MZ'))
  self.assertNotEqual(0,self.run_worker().returncode)
 def test_wrong_missing_or_body_position_header_rejects(self):
  for text,y in [('FORGED',None),(None,None),('OUR CELEBRATION',700)]:
   pdf(self.pdf,header=text,header_y=y);self.assertNotEqual(0,self.run_worker().returncode)
 def test_adversarial_png_relationship_header_and_drawing_reject(self):
  changes=[('word/media/image1.png',lambda b:b[:-1]+bytes([b[-1]^1])),('word/media/image1.png',lambda b:b+b'payload'),('word/media/image1.png',lambda b:b'NOT_PNG'),('word/media/image1.png',lambda b:b[:33]+chunk(b'acTL',b'\0'*8)+b[33:]),('word/_rels/document.xml.rels',lambda b:b.replace(b'media/image1.png',b'../../../outside.png')),('word/_rels/document.xml.rels',lambda b:b.replace(b'Target="media',b'TargetMode="External" Target="media')),('[Content_Types].xml',lambda b:b.replace(b'image/png',b'application/octet-stream')),('word/header1.xml',lambda b:b.replace(b'<w:r>',b'<w:r><w:fldSimple w:instr="INCLUDETEXT"/>')),('word/document.xml',lambda b:b.replace(b'wp:inline',b'wp:anchor')),('word/document.xml',lambda b:b.replace(b'r:embed="rIdP"',b'r:link="rIdP"')),('word/document.xml',lambda b:b.replace(b'cx="914400"',b'cx="9144000000"'))]
  for name,change in changes:
   with self.subTest(name=name,change=changes.index((name,change))):
    illustrated(self.doc,lambda p:p.__setitem__(name,change(p[name])));self.assertNotEqual(0,self.run_worker().returncode)
if __name__=='__main__':unittest.main()
