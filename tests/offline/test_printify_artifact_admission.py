import json
import os
import subprocess
import sys
import importlib.util
import pathlib
import tempfile
import unittest
import zipfile

SCRIPT = pathlib.Path(__file__).resolve().parents[2] / 'bin/artifact-audit/verify.py'

class ArtifactAdmissionTest(unittest.TestCase):
    def module(self):
        spec = importlib.util.spec_from_file_location('artifact_verify', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_wrong_digest_prevents_output(self):
        with tempfile.TemporaryDirectory() as folder:
            root = pathlib.Path(folder)
            source = root / 'bad.zip'
            source.write_bytes(b'untrusted')
            with self.assertRaises(ValueError):
                self.module().verify(source, root / 'absent-manifest.json', root / 'out')
            self.assertFalse((root / 'out').exists())

    def test_unsafe_paths_deny(self):
        for name in ('../escape', '/absolute', 'digiforge/../../escape', 'digiforge/../escape', 'other/plugin.php', 'digiforge\\evil.php'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.module().safe_name(name)

    def test_environment_review_is_not_optional(self):
        m = self.module()
        with self.assertRaises(ValueError):
            m.reviewers({}, 'operator')
        for config in ({'type': 'required_reviewers', 'prevent_self_review': False, 'reviewers': [{'type': 'User', 'reviewer': {'login': 'auditor'}}]},
                       {'type': 'required_reviewers', 'prevent_self_review': True, 'reviewers': [{'type': 'User', 'reviewer': {'login': 'operator'}}]}):
            with self.assertRaises(ValueError):
                m.reviewers({'protection_rules': [config]}, 'operator')
        self.assertEqual(['auditor'], m.reviewers({'can_admins_bypass':False,'protection_rules': [{'type':'required_reviewers','prevent_self_review':True,'reviewers':[{'type':'User','reviewer':{'login':'auditor'}}]}]}, 'operator'))

    def test_team_and_admin_bypass_deny(self):
        m = self.module()
        rule = {'type':'required_reviewers','prevent_self_review':True,'reviewers':[{'type':'User','reviewer':{'login':'auditor'}},{'type':'Team','reviewer':{'slug':'authors'}}]}
        with self.assertRaises(ValueError):
            m.reviewers({'can_admins_bypass':False,'protection_rules':[rule]}, 'operator')
        rule['reviewers'] = rule['reviewers'][:1]
        with self.assertRaises(ValueError):
            m.reviewers({'can_admins_bypass':True,'protection_rules':[rule]}, 'operator')

    def test_approval_must_come_from_configured_independent_user(self):
        m = self.module()
        config = {'can_admins_bypass':False,'protection_rules':[{'type':'required_reviewers','prevent_self_review':True,'reviewers':[{'type':'User','reviewer':{'login':'auditor'}}]}]}
        receipt = {'state':'approved','user':{'login':'stranger'},'environments':[{'name':'intake'}]}
        with self.assertRaises(ValueError):
            m.approved(config, 'operator', 'author', [receipt], 'intake')
        receipt['user']['login'] = 'auditor'
        self.assertEqual(['auditor'],m.approved(config,'operator','author',[receipt],'intake'))

    def test_owner_attestation_must_bind_all_identities(self):
        m=self.module()
        att={'artifact_sha256':m.DIGEST,'audit_source_sha':'a'*40,'intake_run_id':'12','intake_artifact_id':'34','accept_reduced_independence':True,'approve_controls':True,'drive_client_sha256':'b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458'}
        self.assertTrue(m.owner_attestation(att,'a'*40,'12','34'))
        for key,value in [('approve_controls',False),('accept_reduced_independence',False),('audit_source_sha','b'*40),('intake_artifact_id','99'),('artifact_sha256','0'*64)]:
            altered=dict(att);altered[key]=value
            with self.assertRaises(ValueError):m.owner_attestation(altered,'a'*40,'12','34')

    def test_owner_control_authenticates_comment_and_dispatch_without_network(self):
        m=self.module()
        body={'artifact_sha256':m.DIGEST,'audit_source_sha':'a'*40,'intake_run_id':'12','intake_artifact_id':'34','accept_reduced_independence':True,'approve_controls':True,'drive_client_sha256':'b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458'}
        comment={'user':{'login':'subvicco12','type':'User'},'issue_url':'https://api.github.com/repos/subvicco12/digiforge/issues/1253','html_url':'https://example.invalid/attestation','body':json.dumps(body)}
        with tempfile.TemporaryDirectory() as folder:
            fake=pathlib.Path(folder)/'gh'
            fake.write_text('#!'+sys.executable+'\nimport os\nprint(os.environ["TEST_COMMENT"])\n')
            fake.chmod(0o700)
            env={'PATH':folder+os.pathsep+os.defpath,'GITHUB_RUN_ATTEMPT':'1','GITHUB_ACTOR':'subvicco12','GITHUB_EVENT_NAME':'workflow_dispatch','GITHUB_SHA':'a'*40,'TEST_COMMENT':json.dumps(comment)}
            cmd=[sys.executable,str(SCRIPT.with_name('control.py')),'owner-attestation','56','12','34']
            self.assertEqual(0,subprocess.run(cmd,env=env,capture_output=True).returncode)
            for key,value in [('GITHUB_ACTOR','other'),('GITHUB_EVENT_NAME','push'),('GITHUB_RUN_ATTEMPT','2'),('GITHUB_SHA','b'*40)]:
                bad=dict(env);bad[key]=value
                self.assertNotEqual(0,subprocess.run(cmd,env=bad,capture_output=True).returncode,key)
            for modified in [dict(comment,user={'login':'other','type':'User'}),dict(comment,user={'login':'subvicco12','type':'Bot'}),dict(comment,issue_url='https://api.github.com/repos/subvicco12/digiforge/issues/1242')]:
                bad=dict(env);bad['TEST_COMMENT']=json.dumps(modified)
                self.assertNotEqual(0,subprocess.run(cmd,env=bad,capture_output=True).returncode)
