import importlib.util,json,pathlib,tempfile,unittest,copy
ROOT=pathlib.Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location('qa_manifest',ROOT/'bin/check-cutout-qa-fixtures.py')
class ManifestTests(unittest.TestCase):
 def test_missing_reference_is_explicit_and_tampering_is_rejected(self):
  self.assertTrue(SPEC.origin and pathlib.Path(SPEC.origin).is_file(),'Manifest verifier missing')
  m=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(m)
  data=json.loads((ROOT/'tests/fixtures/segmentation/visual-qa-manifest.json').read_text())
  report=m.verify(data,ROOT/'tests/fixtures/segmentation')
  self.assertEqual(0,report['reviewed_references']);self.assertEqual(2,report['available_sources']);self.assertFalse(report['certified'])
  bad=copy.deepcopy(data);bad['fixtures'][0]['source']['sha256']='0'*64
  with self.assertRaises(ValueError):m.verify(bad,ROOT/'tests/fixtures/segmentation')
  bad=copy.deepcopy(data);bad['fixtures'][0]['reference']['status']='REVIEWED'
  with self.assertRaises(ValueError):m.verify(bad,ROOT/'tests/fixtures/segmentation')
 def test_paths_symlinks_and_fake_reviews_rejected(self):
  self.assertTrue(SPEC.origin and pathlib.Path(SPEC.origin).is_file(),'Manifest verifier missing')
  m=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(m)
  data=json.loads((ROOT/'tests/fixtures/segmentation/visual-qa-manifest.json').read_text())
  bad=copy.deepcopy(data);bad['fixtures'][0]['source']['file']='../manifest.json'
  with self.assertRaises(ValueError):m.verify(bad,ROOT/'tests/fixtures/segmentation')
  bad=copy.deepcopy(data);bad['fixtures'][0]['reference']['reviewer']='invented reviewer'
  with self.assertRaises(ValueError):m.verify(bad,ROOT/'tests/fixtures/segmentation')
 def test_supplied_reference_is_decoded_but_never_becomes_verified_review(self):
  m=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(m)
  data=json.loads((ROOT/'tests/fixtures/segmentation/visual-qa-manifest.json').read_text())
  with tempfile.TemporaryDirectory() as temp:
   root=pathlib.Path(temp)
   import shutil,hashlib
   for f in ['astronaut-srgb.png','chelsea-srgb.png','upstream-license-source.txt']:shutil.copyfile(ROOT/'tests/fixtures/segmentation'/f,root/f)
   ref=data['fixtures'][0]['reference'];src=data['fixtures'][0]['source'];rights=data['fixtures'][0]['rights']
   ref.update(status='SUPPLIED_UNVERIFIED',file=src['file'],sha256=src['sha256'],reviewer='SYNTHETIC TEST CLAIM',review_utc='2026-01-01T00:00:00Z',review_evidence_file='review.json',rights_file=rights['file'],rights_sha256=rights['sha256'],details=[dict(kind='hair',x=0,y=0,width=10,height=10)])
   evidence=dict(source_sha256=src['sha256'],reference_sha256=ref['sha256'],reviewer=ref['reviewer'],review_utc=ref['review_utc'],details=ref['details'],state=ref['status'])
   body=json.dumps(evidence).encode();(root/'review.json').write_bytes(body);ref['review_evidence_sha256']=hashlib.sha256(body).hexdigest()
   self.assertEqual(0,m.verify(data,root)['reviewed_references'])
   ref['status']='REVIEWED'
   with self.assertRaises(ValueError):m.verify(data,root)
   ref['status']='SUPPLIED_UNVERIFIED';ref['file']=rights['file'];ref['sha256']=rights['sha256']
   with self.assertRaises(ValueError):m.verify(data,root)
if __name__=='__main__':unittest.main()
