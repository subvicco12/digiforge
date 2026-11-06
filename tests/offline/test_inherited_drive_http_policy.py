import importlib.util
import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[2]

class InheritedDrivePolicyTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.temp.name)
        for name in ['includes','modules','automation','admin']:
            shutil.copytree(ROOT/name,self.root/name)
        shutil.copyfile(ROOT/'digiforge.php',self.root/'digiforge.php')
        self.client=self.root/'includes/DigitalFactory/DriveWordPressGoogleHttp.php';self.client.parent.mkdir(exist_ok=True)
        for name in ['DriveWordPressGoogleHttp','DriveWordPressGoogleGate','DriveGoogleWordPress']:
            (self.client.parent/(name+'.php')).write_bytes((ROOT/('tests/fixtures/security/'+name+'.reviewed.php')).read_bytes())
    def tearDown(self):self.temp.cleanup()
    def run_gate(self):
        return subprocess.run(['python3',str(ROOT/'bin/artifact-audit/security.py'),str(self.root)],capture_output=True,text=True)
    def test_reviewed_exact_client_passes_existing_source_rule(self):
        script=(ROOT/'.github/workflows/digiforge-foundation-audit.yml').read_text().split('      - name: External HTTP client scan\n',1)[1].split('\n      - name:',1)[0]
        self.assertIn('python3 bin/artifact-audit/security.py .',script)
        self.assertEqual(0,self.run_gate().returncode)
    def test_new_senders_and_same_filename_elsewhere_deny(self):
        for name in ['modules/Rogue.php','modules/DriveWordPressGoogleHttp.php','includes/DigitalFactory/OtherGoogleSender.php']:
            p=self.root/name;p.write_text('<?php $r=wp_remote_request($url,$args);')
            try:self.assertNotEqual(0,self.run_gate().returncode,name)
            finally:p.unlink()
    def test_gate_tls_or_sender_changes_deny(self):
        original=self.client.read_text()
        for changed in [original.replace('||!$this->gate->allows($binding)',''),original.replace("($args['sslverify']??null)!==true",'false'),original+'\n$response=wp_remote_request($url,$args);\n']:
            self.client.write_text(changed)
            self.assertNotEqual(0,self.run_gate().returncode)
        self.client.write_text(original)

    def test_authoritative_gate_and_production_composition_changes_deny(self):
        for name in ['DriveWordPressGoogleGate.php','DriveGoogleWordPress.php']:
            p=self.client.parent/name;original=p.read_bytes();p.write_bytes(original+b'\n// drift\n')
            try:self.assertNotEqual(0,self.run_gate().returncode)
            finally:p.write_bytes(original)
