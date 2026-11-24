import json,pathlib,subprocess,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
class Gate2UninstallTests(unittest.TestCase):
 def probe(self,mode):return json.loads(subprocess.check_output(['php',str(ROOT/'tests/fixtures/gate2-uninstall.php'),mode],text=True))
 def test_default_retains_all_data(self):r=self.probe('default');self.assertEqual([],r['queries']);self.assertEqual([],r['deleted'])
 def test_multisite_does_not_clean_up(self):r=self.probe('multisite');self.assertEqual([],r['queries']);self.assertEqual([],r['deleted'])
 def test_explicit_isolated_optin_targets_only_plugin_tables(self):
  r=self.probe('optin');self.assertGreater(len(r['queries']),80)
  for q in r['queries']:self.assertTrue(q.startswith('DROP TABLE IF EXISTS `isolated_test_digiforge_'),q)
  self.assertIn('digiforge_db_schema_version',r['deleted']);self.assertNotIn('wp_users',r['deleted'])
if __name__=='__main__':unittest.main()
