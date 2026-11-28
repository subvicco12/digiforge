#!/usr/bin/env python3
"""Offline, bounded fixture/reference evidence verification; never certifies art or consent."""
import datetime,hashlib,json,pathlib,re,sys,subprocess

def exact(value,keys):
 if not isinstance(value,dict) or set(value)!=set(keys):raise ValueError('Exact evidence schema required')

def asset(root,name,digest):
 if not isinstance(name,str) or not re.fullmatch(r'[A-Za-z0-9_-]+\.[A-Za-z0-9]+',name):raise ValueError('Local basename required')
 if not isinstance(digest,str) or not re.fullmatch(r'[a-f0-9]{64}',digest):raise ValueError('SHA-256 required')
 path=root/name
 if path.is_symlink() or not path.is_file() or not 0<path.stat().st_size<=16777216:raise ValueError('Bounded regular evidence file required')
 body=path.read_bytes()
 if hashlib.sha256(body).hexdigest()!=digest:raise ValueError('Evidence hash mismatch')
 return body

def png_geometry(body):
 script="require 'includes/POD/RasterArtworkCodec.php'; $i=\\DigiForge\\POD\\RasterArtworkCodec::decode(stream_get_contents(STDIN)); echo json_encode([$i['width_px'],$i['height_px']]);"
 result=subprocess.run(['php','-d','memory_limit=128M','-r',script],input=body,capture_output=True,timeout=10,cwd=pathlib.Path(__file__).resolve().parents[1])
 if result.returncode or len(result.stdout)>128:raise ValueError('Fully decoded bounded canonical PNG required')
 return json.loads(result.stdout)

def verify(data,root):
 exact(data,['schema','qa_version','certified','fixtures'])
 if data['schema']!=1 or data['qa_version']!='cutout-pixel-qa-v1' or data['certified'] is not False or not isinstance(data['fixtures'],list) or not 1<=len(data['fixtures'])<=64:raise ValueError('Uncertified bounded fixture inventory required')
 seen=set();available=0;reviewed=0;missing=[]
 for row in data['fixtures']:
  exact(row,['id','species','source','rights','consent_status','reference','supplier_certification'])
  if not isinstance(row['id'],str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,80}',row['id']) or row['id'] in seen or row['species'] not in ['human','cat','dog','multi_subject']:raise ValueError('Unique typed subject required')
  seen.add(row['id'])
  if row['consent_status']!='NOT_ESTABLISHED_FOR_CUSTOMER_USE' or row['supplier_certification']!='MISSING':raise ValueError('No operational certification permitted by offline manifest')
  src=row['source'];rights=row['rights'];ref=row['reference']
  exact(src,['status','file','sha256']);exact(rights,['status','file','sha256','license','scope'])
  if rights['scope']!='OFFLINE_SOFTWARE_TEST_ONLY':raise ValueError('Offline rights scope required')
  if src['status']=='AVAILABLE':
   if rights['status']!='AVAILABLE' or not isinstance(rights['license'],str) or not 1<=len(rights['license'])<=1000:raise ValueError('License evidence required')
   geometry=png_geometry(asset(root,src['file'],src['sha256']));asset(root,rights['file'],rights['sha256']);available+=1
  elif src['status']!='MISSING' or src['file'] is not None or src['sha256'] is not None or rights['status']!='MISSING' or any(rights[k] is not None for k in ['file','sha256','license']):raise ValueError('Missing assets must be empty')
  exact(ref,['status','file','sha256','reviewer','review_utc','review_evidence_file','review_evidence_sha256','rights_file','rights_sha256','details'])
  if ref['status']=='MISSING':
   if ref['details']!=[] or any(ref[k] is not None for k in ref if k not in ['status','details']):raise ValueError('Missing reference cannot carry fabricated review')
   missing.append(row['id'])
  elif ref['status']=='SUPPLIED_UNVERIFIED':
   if src['status']!='AVAILABLE' or not isinstance(ref['reviewer'],str) or not 1<=len(ref['reviewer'])<=100 or not isinstance(ref['review_utc'],str):raise ValueError('Reference reviewer and source required')
   stamp=datetime.datetime.fromisoformat(ref['review_utc'].replace('Z','+00:00'))
   if stamp.utcoffset() is None or stamp>datetime.datetime.now(datetime.timezone.utc):raise ValueError('Absolute nonfuture reference review required')
   if png_geometry(asset(root,ref['file'],ref['sha256']))!=geometry:raise ValueError('Reference/source geometry mismatch')
   evidence=json.loads(asset(root,ref['review_evidence_file'],ref['review_evidence_sha256']))
   exact(evidence,['source_sha256','reference_sha256','reviewer','review_utc','details','state'])
   if evidence!={'source_sha256':src['sha256'],'reference_sha256':ref['sha256'],'reviewer':ref['reviewer'],'review_utc':ref['review_utc'],'details':ref['details'],'state':'SUPPLIED_UNVERIFIED'}:raise ValueError('Claimed review evidence mismatch')
   asset(root,ref['rights_file'],ref['rights_sha256'])
   if not isinstance(ref['details'],list) or not ref['details'] or len(ref['details'])>32:raise ValueError('Reviewed detail regions required')
   for region in ref['details']:
    exact(region,['kind','x','y','width','height'])
    if region['kind'] not in ['hair','ears','fur','whiskers','subject','background'] or any(type(region[k]) is not int or not 0<=region[k]<=10000 for k in ['x','y','width','height']) or region['width']==0 or region['height']==0:raise ValueError('Bounded typed detail region required')
    if region['x']+region['width']>geometry[0] or region['y']+region['height']>geometry[1]:raise ValueError('Detail region outside source geometry')
   missing.append(row['id']) # Supplied documents do not establish authorized reviewer identity.
  else:raise ValueError('Explicit reference state required')
 return {'available_sources':available,'reviewed_references':reviewed,'missing_reference_ids':missing,'certified':False}

if __name__=='__main__':
 try:
  root=pathlib.Path(__file__).resolve().parents[1]/'tests/fixtures/segmentation'
  print(json.dumps(verify(json.loads((root/'visual-qa-manifest.json').read_text()),root),sort_keys=True))
 except (ValueError,OSError,TypeError,KeyError) as error:
  print('Fixture evidence rejected: '+str(error),file=sys.stderr);sys.exit(1)
