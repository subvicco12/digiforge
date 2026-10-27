import copy,csv,hashlib,importlib.util,io,json,pathlib,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
PACK=ROOT/'docs/engineering/visual-certification-pilot-v1'
SPEC=importlib.util.spec_from_file_location('pilot',ROOT/'bin/check-visual-certification-pilot.py')

class PilotTests(unittest.TestCase):
 def module(self):
  self.assertTrue(pathlib.Path(SPEC.origin).is_file(),'Pilot manifest boundary is missing')
  m=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(m);return m
 def csv(self,change):
  rows=list(csv.reader(io.StringIO((PACK/'pilot_fixture_manifest.csv').read_text())));change(rows)
  out=io.StringIO(newline='');csv.writer(out).writerows(rows);return out.getvalue().encode()
 def test_original_pack_keeps_all_twelve_cases_missing(self):
  m=self.module();report=m.verify_pack(PACK)
  self.assertEqual(report,json.loads((ROOT/'docs/engineering/visual-certification-pilot-report.json').read_text()));self.assertEqual(12,report['case_count']);self.assertEqual({'dog':3,'cat':3,'human':3,'multi_subject':3},report['subject_counts'])
  self.assertEqual(12,report['missing_cases']);self.assertEqual(0,report['verified_reference_mattes']);self.assertFalse(report['certified']);self.assertEqual('PROPOSED_NOT_APPROVED',report['criteria_state'])
  self.assertTrue(all(c['evidence_status']=='MISSING' and c['metrics'] is None and not c['ready_for_intake'] for c in report['cases']))
 def test_ids_headers_scope_and_fabricated_status_fail_closed(self):
  m=self.module()
  changes=[lambda r:r[0].append('certified'),lambda r:r.__setitem__(2,copy.deepcopy(r[1])),lambda r:r[1].__setitem__(1,'human'),lambda r:r[1].__setitem__(-1,'VERIFIED'),lambda r:r.pop()]
  for change in changes:
   with self.assertRaises(ValueError):m.verify_manifest(self.csv(change),PACK,'personalized_pod')
  with self.assertRaises(ValueError):m.verify_manifest((PACK/'pilot_fixture_manifest.csv').read_bytes(),PACK,'standard_pod')
 def test_unverified_label_without_assets_stays_missing(self):
  m=self.module()
  def change(r):r[1][-1]='SUPPLIED_UNVERIFIED'
  report=m.verify_manifest(self.csv(change),PACK,'personalized_pod')
  self.assertEqual('MISSING',report['cases'][0]['evidence_status']);self.assertEqual(12,report['missing_cases'])
 def test_claimed_review_cannot_authorize_existing_real_assets(self):
  m=self.module();root=ROOT/'tests/fixtures/segmentation';source=root/'chelsea-srgb.png';digest=hashlib.sha256(source.read_bytes()).hexdigest()
  def change(r):
   h=r[0];v=dict(zip(h,r[4]));v.update(source_image_path=source.name,source_sha256=digest,source_url_or_owner='Existing CC0 regression fixture; not pilot licensing',license_or_permission_id='SYNTHETIC_CLAIM',consent_id_if_applicable='SYNTHETIC_CLAIM',reference_matte_path=source.name,reference_matte_sha256=digest,reference_reviewer_id='2',reference_review_date='2026-01-01T00:00:00Z',operator_id='1',qa_decision='APPROVE',reviewer_1_id='2',reviewer_1_decision='APPROVE',evidence_status='SUPPLIED_UNVERIFIED');r[4]=[v[k] for k in h]
  report=m.verify_manifest(self.csv(change),root,'personalized_pod');case=report['cases'][3]
  self.assertTrue(case['source_bytes_verified']);self.assertTrue(case['reference_bytes_verified']);self.assertFalse(case['ready_for_intake']);self.assertIsNone(case['metrics']);self.assertEqual(0,report['verified_reference_mattes']);self.assertIn('AUTHENTICATED_REFERENCE_REVIEW',case['blockers'])
 def test_wrong_hash_path_unsafe_image_self_review_and_absolute_date(self):
  m=self.module()
  def partial(r):
   h=r[0];r[1][h.index('source_image_path')]='asset.png';r[1][h.index('source_sha256')]=hashlib.sha256(b'not png').hexdigest()
  with tempfile.TemporaryDirectory() as tmp:
   root=pathlib.Path(tmp);(root/'asset.png').write_bytes(b'not png')
   with self.assertRaises(ValueError):m.verify_manifest(self.csv(partial),root,'personalized_pod')
   (root/'asset.png').write_bytes((ROOT/'tests/fixtures/segmentation/chelsea-srgb.png').read_bytes())
   for field,value in [('source_image_path','../asset.png'),('source_sha256','0'*64),('reference_review_date','2026-01-01 00:00:00'),('reference_review_date','2999-01-01T00:00:00Z')]:
    def change(r,f=field,v=value):partial(r);r[1][r[0].index('source_sha256')]=hashlib.sha256((root/'asset.png').read_bytes()).hexdigest();r[1][r[0].index('reference_reviewer_id')]='2';r[1][r[0].index(f)]=v
    with self.assertRaises(ValueError):m.verify_manifest(self.csv(change),root,'personalized_pod')
   def same(r):r[1][r[0].index('operator_id')]='2';r[1][r[0].index('reviewer_1_id')]='2'
   with self.assertRaises(ValueError):m.verify_manifest(self.csv(same),root,'personalized_pod')
 def test_pack_member_tamper_is_rejected(self):
  m=self.module()
  with tempfile.TemporaryDirectory() as tmp:
   root=pathlib.Path(tmp)
   for p in PACK.iterdir():(root/p.name).write_bytes(p.read_bytes())
   (root/'qa_acceptance_criteria.md').write_text('Approved thresholds fabricated')
   with self.assertRaises(ValueError):m.verify_pack(root)

if __name__=='__main__':unittest.main()
