"""Development packaging boundary tests; no WordPress bootstrap or external calls."""
import copy,hashlib,importlib.util,pathlib,tempfile,unittest,zipfile
BUILDER=pathlib.Path(__file__).resolve().parents[2]/'bin/package-normalized-10317.py'
class NormalizedPackageTests(unittest.TestCase):
 def builder(self):
  self.assertTrue(BUILDER.is_file(),'Normalized committed-object package builder is missing')
  spec=importlib.util.spec_from_file_location('normalized_package',BUILDER);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
 def fixture(self):
  data={'digiforge.php':b'<?php\r\n// exact raw bytes\x00\n'}
  m={'files':[{'path':'digiforge.php','size':len(data['digiforge.php']),'sha256':hashlib.sha256(data['digiforge.php']).hexdigest(),'git_mode':'100644'}]}
  return data,m
 def test_byte_and_metadata_preservation(self):
  b=self.builder();data,m=self.fixture()
  with tempfile.TemporaryDirectory() as d:
   x=pathlib.Path(d)/'a.zip';y=pathlib.Path(d)/'b.zip';b.write_package(data,m,x);b.write_package(data,m,y);self.assertEqual(x.read_bytes(),y.read_bytes())
   with zipfile.ZipFile(x) as z:
    self.assertEqual(z.read('digiforge/digiforge.php'),data['digiforge.php']);i=z.infolist()[0];self.assertEqual(i.external_attr,(0o100644<<16));self.assertEqual(i.date_time,(2026,11,6,0,0,0));self.assertEqual(i.create_system,3);self.assertEqual(i.extra,b'');self.assertEqual(i.comment,b'')
 def deny(self,change):
  b=self.builder();data,m=self.fixture();change(data,m)
  with tempfile.TemporaryDirectory() as d:
   out=pathlib.Path(d)/'invalid.zip'
   with self.assertRaises(ValueError):b.write_package(data,m,out)
   self.assertFalse(out.exists())
 def test_changed_bytes_denied(self):self.deny(lambda d,m:d.update({'digiforge.php':b'changed'}))
 def test_missing_path_denied(self):self.deny(lambda d,m:d.clear())
 def test_extra_path_denied(self):self.deny(lambda d,m:d.update({'unknown.php':b'<?php'}))
 def test_unsafe_path_denied(self):
  def change(d,m):d['../escape.php']=d.pop('digiforge.php');m['files'][0]['path']='../escape.php'
  self.deny(change)
 def test_mode_drift_denied(self):self.deny(lambda d,m:m['files'][0].update(git_mode='100755'))
 def test_duplicate_inventory_denied(self):self.deny(lambda d,m:m['files'].append(copy.deepcopy(m['files'][0])))
 def test_size_drift_denied(self):self.deny(lambda d,m:m['files'][0].update(size=1))
if __name__=='__main__':unittest.main()
