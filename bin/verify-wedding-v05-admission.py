#!/usr/bin/env python3
"""Exact recovered v0.5 evidence trial; files are supplied locally, never uploaded."""
import hashlib,json,re,subprocess,sys,tempfile,zipfile
import xml.etree.ElementTree as ET
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
WORKER=ROOT/'includes/ProductFactory/offline/verify_external_document.py'
EXPECTED=[('Letter','0a9fabc469d217827ef50f645324c5bbda34f897d969092d21582034eaf10168','ef8b759106936b6e63d0874d61cbc379c7dd36c35f0806f67adf38bbc6052f7f'),('A4','41170901605d7148ecb61c8ae32e3c6ab1ba9f1095a16dfb3a60831fe274a56c','1420880bba0eeda13334269f9e6634dd54aa13fc312b4cedfe3e2d5ed504ef3e')]
def run(doc,pdf):return subprocess.run([sys.executable,'-B','-I',str(WORKER),str(doc),str(pdf)],capture_output=True,timeout=25)
def main():
 if len(sys.argv)!=5:raise SystemExit('Supply Letter DOCX/PDF, then A4 DOCX/PDF. No historical Product25 identity is assigned.')
 receipts=[]
 for index,(edition,source_hash,export_hash) in enumerate(EXPECTED):
  doc,pdf=map(Path,sys.argv[1+2*index:3+2*index]);original=doc.read_bytes();pdf_bytes=pdf.read_bytes()
  if hashlib.sha256(original).hexdigest()!=source_hash or hashlib.sha256(pdf_bytes).hexdigest()!=export_hash:raise ValueError('Exact source/export identity mismatch')
  with zipfile.ZipFile(doc) as archive:
   text=''.join(ET.fromstring(archive.read('word/document.xml')).itertext())
   fields=re.findall(r'\[\[([A-Z0-9_]+)\]\]',text)
   if len(set(fields))!=52 or len(fields)!=55:raise ValueError('Named field inventory mismatch')
  result=run(doc,pdf)
  if result.returncode or result.stderr:raise ValueError('Exact candidate did not pass bounded verification')
  receipt=json.loads(result.stdout)
  if receipt['page_count']!=9 or receipt['source_sha256']!=source_hash or receipt['export_sha256']!=export_hash or receipt['source_layout_profile']!='INLINE_RGBA8_PNG_STATIC_HEADER_V1' or receipt['artwork_export_parity_verified'] is not False:raise ValueError('Unexpected evidence receipt')
  with tempfile.TemporaryDirectory() as temporary:
   for member in ['word/header1.xml','word/media/image1.png']:
    changed=Path(temporary)/'tampered.docx'
    with zipfile.ZipFile(doc) as src,zipfile.ZipFile(changed,'w') as target:
     for item in src.infolist():
      data=src.read(item)
      if item.filename==member:data=data.replace(b'A CELEBRATION AWAY FROM HOME',b'FORGED HEADER') if member.endswith('.xml') else data[:-1]+bytes([data[-1]^1])
      target.writestr(item,data)
    if run(changed,pdf).returncode==0:raise ValueError('Tampered exact candidate incorrectly admitted')
  if doc.read_bytes()!=original or pdf.read_bytes()!=pdf_bytes:raise ValueError('Original source/export modified')
  receipts.append({'edition':edition,'named_field_count':52,'field_occurrences':55,'receipt':receipt,'tampered_header_rejected':True,'tampered_png_rejected':True,'originals_unchanged':True})
 print(json.dumps({'trial':'EXACT_V05_LOCAL_TECHNICAL_ONLY','verifier_sha256':hashlib.sha256(WORKER.read_bytes()).hexdigest(),'records':receipts,'commercial_approval':False,'authoritative_product_id':None},indent=2))
if __name__=='__main__':main()
