import copy,importlib.util,pathlib,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
class Gate2InventoryTests(unittest.TestCase):
 def module(self):
  p=ROOT/'bin/package-gate2-pod.py';self.assertTrue(p.exists(),'Gate2 committed-object builder missing');s=importlib.util.spec_from_file_location('g2',p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
 def pins(self):return {'baseline_source_sha':'fb3b805ca7eb7d91b8e87deab6ba408f6e38d3e3','files':[{'path':'kept.php','baseline_sha256':'a'*64,'sha256':'a'*64,'change':'RETAIN_IDENTICAL','reason':'Preserved'}]}, {'files':[{'path':'kept.php','sha256':'a'*64}]}
 def test_preserved_baseline_is_accepted(self):m,b=self.pins();self.module().validate_delta(m,b)
 def test_baseline_deletion_is_denied(self):m,b=self.pins();m['files']=[];self.assertRaises(ValueError,self.module().validate_delta,m,b)
 def test_undeclared_change_is_denied(self):m,b=self.pins();m['files'][0]['sha256']='b'*64;self.assertRaises(ValueError,self.module().validate_delta,m,b)
 def test_unpinned_baseline_is_denied(self):m,b=self.pins();m['baseline_source_sha']='0'*40;self.assertRaises(ValueError,self.module().validate_delta,m,b)
if __name__=='__main__':unittest.main()
