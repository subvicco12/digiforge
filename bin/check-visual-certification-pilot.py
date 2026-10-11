#!/usr/bin/env python3
"""Read-only pilot CSV validation. Claims are never authenticated review or certification."""
import argparse,csv,datetime,hashlib,importlib.util,io,json,pathlib,re,sys

ROOT=pathlib.Path(__file__).resolve().parents[1]
PACK=ROOT/'docs/engineering/visual-certification-pilot-v1'
HEADERS='case_id,subject_type,pose_or_condition,background,critical_features,source_image_path,source_sha256,source_url_or_owner,license_or_permission_id,consent_id_if_applicable,reference_matte_path,reference_matte_sha256,reference_reviewer_id,reference_review_date,operator_id,qa_decision,reviewer_1_id,reviewer_1_decision,reviewer_2_id,reviewer_2_decision,supplier_template_evidence_id,evidence_status'.split(',')
SUBJECTS=['dog']*3+['cat']*3+['human']*3+['dog+dog','human+human','human+pet']
IDS=[f'{p}-{i:02d}' for p in ['DOG','CAT','HUM','MULTI'] for i in range(1,4)]
SPEC=importlib.util.spec_from_file_location('cutout_qa_manifest',ROOT/'bin/check-cutout-qa-fixtures.py')
QA=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(QA)

def bounded(path,limit=65536):
 if path.is_symlink() or not path.is_file() or not 0<path.stat().st_size<=limit:raise ValueError('Bounded regular manifest required')
 with path.open('rb') as stream:body=stream.read(limit+1)
 if len(body)>limit:raise ValueError('Manifest size limit exceeded')
 return body

def rows(body):
 if not isinstance(body,bytes) or len(body)>65536:raise ValueError('Bounded UTF-8 CSV required')
 try:result=list(csv.reader(io.StringIO(body.decode('utf-8'),newline=''),strict=True))
 except (ValueError,UnicodeError,csv.Error) as error:raise ValueError('Strict CSV required') from error
 if len(result)!=13 or result[0]!=HEADERS or any(len(r)!=len(HEADERS) or any(len(v)>2048 or '\x00' in v for v in r) for r in result[1:]):raise ValueError('Exact 12-case pilot schema required')
 return result[1:]

def verify_manifest(body,assets,shop):
 if shop!='personalized_pod':raise ValueError('Existing personalized POD shop required')
 supplied=rows(body);baseline=rows(bounded(PACK/'pilot_fixture_manifest.csv'));cases=[];groups={k:0 for k in ['dog','cat','human','multi_subject']}
 for index,values in enumerate(supplied):
  row=dict(zip(HEADERS,values));base=baseline[index]
  if row['case_id']!=IDS[index] or row['subject_type']!=SUBJECTS[index] or values[:5]!=base[:5]:raise ValueError('Original case identity and planned condition required')
  if row['evidence_status'] not in ['MISSING','SUPPLIED_UNVERIFIED']:raise ValueError('CSV claims cannot certify evidence')
  kind='multi_subject' if '+' in row['subject_type'] else row['subject_type'];groups[kind]+=1
  for key in ['qa_decision','reviewer_1_decision','reviewer_2_decision']:
   if row[key] not in ['', 'APPROVE','REJECT','REVISE','BLOCKED']:raise ValueError('Explicit review outcome required')
  identities=[row[k] for k in ['reference_reviewer_id','operator_id','reviewer_1_id','reviewer_2_id']]
  if any(v and not re.fullmatch(r'[1-9][0-9]{0,9}',v) for v in identities):raise ValueError('Positive account-ID claims required')
  reference,operator,first,second=identities
  if operator and operator in [reference,first,second] or first and first==second:raise ValueError('Self-review and duplicated reviewers rejected')
  for number,identity in [('1',first),('2',second)]:
   if row['reviewer_'+number+'_decision'] and not identity:raise ValueError('Decision identity required')
  if row['reference_review_date']:
   if not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})',row['reference_review_date']):raise ValueError('Absolute review timestamp required')
   stamp=datetime.datetime.fromisoformat(row['reference_review_date'].replace('Z','+00:00'))
   if not reference or stamp.utcoffset() is None or stamp>datetime.datetime.now(datetime.timezone.utc):raise ValueError('Nonfuture reviewer-bound timestamp required')
  geometry={};verified={}
  for prefix,path_key,hash_key in [('source','source_image_path','source_sha256'),('reference','reference_matte_path','reference_matte_sha256')]:
   name,digest=row[path_key],row[hash_key]
   if bool(name)!=bool(digest):raise ValueError('Image path/hash pair required')
   verified[prefix]=bool(name)
   if name:geometry[prefix]=QA.png_geometry(QA.asset(assets,name,digest))
  if len(geometry)==2 and geometry['source']!=geometry['reference']:raise ValueError('Source/reference canvas mismatch')
  missing=[k for k in HEADERS[5:21] if not row[k]]
  blockers=['BOUND_RIGHTS_AND_CONSENT_DOCUMENTS','AUTHENTICATED_REFERENCE_REVIEW','AUTHENTICATED_CUTOUT_AND_DECISION','CANONICAL_PRODUCT_PREVIEW','APPROVED_ARTISTIC_CRITERIA','VERIFIED_SUPPLIER_AND_PHYSICAL_PRINT']
  cases.append({'case_id':row['case_id'],'subject_type':kind,'evidence_status':'SUPPLIED_UNVERIFIED' if row['evidence_status']=='SUPPLIED_UNVERIFIED' and all(verified.values()) else 'MISSING','source_bytes_verified':verified['source'],'reference_bytes_verified':verified['reference'],'reference_status':'SUPPLIED_UNVERIFIED' if verified['reference'] else 'MISSING','review_claims_authenticated':False,'ready_for_intake':False,'metrics':None,'missing_manifest_fields':missing,'second_review_policy':'REQUIRES_APPLICABLE_APPROVED_POLICY','blockers':blockers,'certified':False})
 return {'schema':1,'shop_key':shop,'manifest_sha256':hashlib.sha256(body).hexdigest(),'case_count':12,'subject_counts':groups,'missing_cases':sum(c['evidence_status']=='MISSING' for c in cases),'verified_source_files':sum(c['source_bytes_verified'] for c in cases),'verified_reference_files':sum(c['reference_bytes_verified'] for c in cases),'verified_reference_mattes':0,'criteria_state':'PROPOSED_NOT_APPROVED','cases':cases,'certified':False,'supplier_certified':False,'external_execution_authorized':False,'read_only':True}

def verify_pack(root):
 integrity=bounded(root/'pack_integrity.json');data=json.loads(integrity)
 QA.exact(data,['pack','case_count','evidence_status','files'])
 names=['README_START_HERE.md','codex_handoff.md','pilot_fixture_manifest.csv','qa_acceptance_criteria.md','reference_matte_instructions.md','reviewer_checklist.md']
 if data['pack']!='DigiForge FACE_REPEAT Visual Certification Pilot v1.0' or data['case_count']!=12 or data['evidence_status']!='ALL MISSING' or set(data['files'])!=set(names):raise ValueError('Original uncertified pilot pack required')
 for name,digest in data['files'].items():
  body=bounded(root/name)
  if not re.fullmatch(r'[a-f0-9]{64}',digest) or hashlib.sha256(body).hexdigest()!=digest:raise ValueError('Pilot member hash mismatch')
 report=verify_manifest(bounded(root/'pilot_fixture_manifest.csv'),root,'personalized_pod');report['pack_integrity_sha256']=hashlib.sha256(integrity).hexdigest()
 return report

if __name__=='__main__':
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--manifest',type=pathlib.Path);parser.add_argument('--assets',type=pathlib.Path);parser.add_argument('--shop-key',default='personalized_pod');args=parser.parse_args()
 try:
  baseline=verify_pack(PACK)
  if args.manifest is None:
   if args.assets is not None or args.shop_key!='personalized_pod':raise ValueError('Assets require an explicit private manifest')
   report=baseline
  else:
   if args.assets is None:raise ValueError('Private asset root required')
   report=verify_manifest(bounded(args.manifest),args.assets,args.shop_key);report['template_pack_integrity_sha256']=baseline['pack_integrity_sha256']
  print(json.dumps(report,sort_keys=True,indent=2))
 except (ValueError,OSError,TypeError,KeyError,OverflowError) as error:
  print('Pilot evidence rejected: '+str(error),file=sys.stderr);sys.exit(1)
