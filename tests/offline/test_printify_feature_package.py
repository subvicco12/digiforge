import importlib.util
import io
import json
import hashlib
from pathlib import Path
import unittest
import zipfile

ROOT=Path(__file__).resolve().parents[2]
SCRIPT=ROOT/'bin/package-printify-feature.py'

class PrintifyFeaturePackageTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(SCRIPT.is_file(), 'Feature-only packager must exist')
        spec=importlib.util.spec_from_file_location('printify_feature_package',SCRIPT)
        self.m=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.m)
        self.files={p:b'feature fixture\n' for p in self.m.PAYLOAD}
        self.inventory={'complete':True,'coverage':'includes-only','total_items':1,'items':[{'path':'Core/Plugin.php','size_bytes':5722}]}
        self.sha='a'*40
    def bundle(self, files=None, inventory=None):
        return self.m.build_bundle(self.sha,self.files if files is None else files,self.inventory if inventory is None else inventory,'Installation remains gated.')
    def test_only_create_only_feature_files_are_shipped(self):
        with zipfile.ZipFile(io.BytesIO(self.bundle())) as z:
            manifest=json.loads(z.read('feature-manifest.json'))
            self.assertEqual(13,len(manifest['files']))
            self.assertFalse(manifest['installation_authorized'])
            self.assertFalse(manifest['automatic_activation'])
            self.assertFalse(manifest['schema_migrations'])
            self.assertTrue(all(f['action']=='CREATE_IF_ABSENT' and not f['overwrite_allowed'] for f in manifest['files']))
            for f in manifest['files']:
                self.assertEqual(f['sha256'],hashlib.sha256(z.read('payload/'+f['path'])).hexdigest())
            self.assertNotIn('payload/digiforge.php',z.namelist())
            self.assertNotIn('payload/includes/Portal/Portal.php',z.namelist())
            preservation=json.loads(z.read('preservation-manifest.json'))
            self.assertEqual('PRESERVE',preservation['default_existing_path_action'])
            self.assertFalse(preservation['allow_deletion'])
            self.assertFalse(preservation['installed_source_equivalence_asserted'])
    def test_shared_or_extra_file_injection_is_rejected(self):
        for path in ['digiforge.php','includes/Portal/Portal.php','../escape.php','includes/POD/PrintifyCatalogReadContract.php']:
            bad=dict(self.files);bad[path]=b'not permitted'
            with self.assertRaises(ValueError):self.bundle(files=bad)
    def test_known_existing_destination_and_incomplete_inventory_fail_closed(self):
        existing=dict(self.inventory);existing['items']=[{'path':self.m.PAYLOAD[0].removeprefix('includes/'),'size_bytes':1}]
        with self.assertRaises(ValueError):self.bundle(inventory=existing)
        for bad in [dict(self.inventory,complete=False),dict(self.inventory,total_items=2)]:
            with self.assertRaises(ValueError):self.bundle(inventory=bad)
    def test_missing_payload_and_mutable_source_ref_are_rejected(self):
        bad=dict(self.files);bad.pop(next(iter(bad)))
        with self.assertRaises(ValueError):self.bundle(files=bad)
        with self.assertRaises(ValueError):self.m.build_bundle('main',self.files,self.inventory,'docs')
    def test_bundle_is_reproducible_and_retains_unknown_schema_gates(self):
        self.assertEqual(self.bundle(),self.bundle())
        with zipfile.ZipFile(io.BytesIO(self.bundle())) as z:
            m=json.loads(z.read('feature-manifest.json'))
            self.assertIn('INSTALLED_SHARED_INTERFACES_UNVERIFIED',m['installation_gates'])
            self.assertIn('SCHEMA_23_24_25_AND_PROVIDER_1_UNVERIFIED',m['installation_gates'])
            self.assertIn('TARGET_PATH_ABSENCE_PREFLIGHT_REQUIRED',m['installation_gates'])
            self.assertIn('Security/FirstPartyOwnerPolicy.php',m['known_missing_shared_dependencies'])

if __name__=='__main__':unittest.main()
