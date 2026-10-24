"""Boundary checks require no model, Torch, or invented artistic ground truth."""
import base64,hashlib,importlib.util,json,pathlib,subprocess,sys,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
OFFLINE=ROOT/'includes/POD/offline'
sys.path.insert(0,str(OFFLINE))
import sam_matting as worker

class SamMattingBoundaryTest(unittest.TestCase):
    def test_runtime_versions_match_every_exact_dependency_pin(self):
        pins={}
        for line in (OFFLINE/"requirements-sam-matting.txt").read_text().splitlines():
            if line and not line.startswith(("#","--")):
                name,version=line.split("==");pins[name]=version
        self.assertEqual(pins,worker.VERSIONS)

    def payload(self):
        return dict(width=16,height=16,rgba=base64.b64encode(b'\xff'*1024).decode(),selection=dict(subjects=[dict(x=1,y=1,width=14,height=14)],selected=0,foreground_points=[dict(x=8,y=8)],trimap_width=8))
    def test_exact_opaque_contract_and_unsupported_widths(self):
        self.assertEqual((16,16),worker.decode_request(self.payload())[:2])
        for width in [0,7,24,True,8.0]:
            p=self.payload();p['selection']['trimap_width']=width
            with self.subTest(width=width),self.assertRaises(ValueError):worker.decode_request(p)
        for edit in [dict(model='injected'),dict(width=True),dict(rgba=''),dict(selection={})]:
            p=self.payload();p.update(edit)
            with self.assertRaises(ValueError):worker.decode_request(p)
        p=self.payload();p['rgba']=base64.b64encode(b'\0'*1024).decode()
        with self.assertRaises(ValueError):worker.decode_request(p)
    def test_missing_runtime_and_malformed_input_fail_closed_without_pixel_disclosure(self):
        for raw in ['not-json',json.dumps(self.payload())]:
            p=subprocess.run([sys.executable,str(OFFLINE/'sam_matting.py')],input=raw,capture_output=True,text=True,timeout=20,env={'PATH':'/usr/bin:/bin'})
            self.assertNotEqual(0,p.returncode);self.assertEqual('',p.stdout);self.assertNotIn('Traceback',p.stderr);self.assertNotIn('rgba',p.stderr)
    def test_real_fixture_conversion_and_rights_evidence_are_hash_pinned_not_certification(self):
        root=ROOT/'tests/fixtures/segmentation';m=json.loads((root/'matting-fixtures.json').read_text())
        self.assertFalse(m['commercial_certification']);self.assertEqual('MISSING',m['reviewed_alpha_references'])
        for f in m['fixtures']:
            for file,digest in [(f['file'],f['sha256']),(f['original_file'],f['original_sha256']),(f['rights_evidence'],f['rights_evidence_sha256'])]:self.assertEqual(digest,hashlib.sha256((root/file).read_bytes()).hexdigest())
    def test_fresh_cache_prefix_ignores_unapproved_preexisting_bytecode(self):
        import tempfile,py_compile
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory);approved=root/'fixture.py';evil=root/'evil.py'
            approved.write_text('VALUE="approved"\n');evil.write_text('VALUE="unapproved"\n')
            py_compile.compile(str(evil),cfile=importlib.util.cache_from_source(str(approved)),invalidation_mode=py_compile.PycInvalidationMode.UNCHECKED_HASH)
            code='import sys;sys.path.insert(0,'+repr(directory)+');import fixture;print(fixture.VALUE)'
            result=subprocess.run([sys.executable,'-B','-s','-X','pycache_prefix='+directory+'/fresh','-c',code],capture_output=True,text=True,check=True)
            self.assertEqual('approved\n',result.stdout)
            self.assertIn("'pycache_prefix=/tmp/pycache'",(ROOT/'includes/POD/ExperimentalMattingPipeline.php').read_text())
    def test_sam_loader_rehashes_source_ignores_bytecode_and_denies_unknown_modules(self):
        import tempfile,py_compile,types
        from unittest.mock import patch
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory);package=root/'sam2';package.mkdir();approved=package/'fixture.py';evil=root/'evil.py'
            approved.write_text('VALUE="approved"\n');evil.write_text('VALUE="unapproved"\n')
            py_compile.compile(str(evil),cfile=importlib.util.cache_from_source(str(approved)),invalidation_mode=py_compile.PycInvalidationMode.UNCHECKED_HASH)
            manifest=json.dumps(dict(files={'fixture.py':hashlib.sha256(approved.read_bytes()).hexdigest()},symlinks={}))
            saved=sys.meta_path.copy()
            try:
                with patch.object(pathlib.Path,'read_text',return_value=manifest):worker.install_source_loader(root)
                finder=sys.meta_path[0];spec=finder.find_spec('sam2.fixture',[str(package)]);scope={};exec(spec.loader.get_code('sam2.fixture'),scope);self.assertEqual('approved',scope['VALUE'])
                with self.assertRaises(ImportError):finder.find_spec('sam2.missing',[str(package)])
                approved.write_text('VALUE="changed"\n')
                with self.assertRaises(ImportError):spec.loader.get_code('sam2.fixture')
                sys.modules['sam2.preloaded']=types.ModuleType('sam2.preloaded')
                with patch.object(pathlib.Path,'read_text',return_value=manifest),self.assertRaises(ImportError):worker.install_source_loader(root)
            finally:sys.meta_path[:]=saved;sys.modules.pop('sam2.preloaded',None)
    def test_manifest_does_not_allow_unpinned_code_or_external_aliases(self):
        m=json.loads((OFFLINE/'sam-source-manifest.json').read_text())
        self.assertEqual('2b90b9f5ceec907a1c18123530e92e794ad901a4',m['commit'])
        for path,target in m['symlinks'].items():
            self.assertIn(path,m['files']);self.assertIn(target,m['files']);self.assertNotIn('..',target);self.assertFalse(target.startswith('/'))

if __name__=='__main__':unittest.main()
