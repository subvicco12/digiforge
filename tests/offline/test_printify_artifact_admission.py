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
