"""Real offline scanner acceptance; synthetic canary is never a usable credential."""
import json
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'bin/check-repository-credentials.py'


class RepositoryCredentialAcceptanceTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(SCRIPT.is_file(), 'Repository credential gate must exist')
        self.scanner = os.environ['DIGIFORGE_TEST_GITLEAKS']
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'repo'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Offline synthetic scanner fixture')
        self.git('config', 'user.email', 'scanner@example.invalid')

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.root), *args], check=True,
                              capture_output=True, text=True).stdout.strip()

    def commit(self, text):
        (self.root / 'fixture.txt').write_text(text)
        self.git('add', 'fixture.txt')
        self.git('commit', '-qm', 'Offline scanner fixture')

    def scan(self):
        report = Path(self.temp.name) / 'evidence.json'
        result = subprocess.run(['python3', str(SCRIPT), '--scanner', self.scanner,
                                 '--repository', str(self.root), '--report', str(report)],
                                capture_output=True, text=True, timeout=60)
        self.assertTrue(report.is_file())
        return result, json.loads(report.read_text())

    def test_clean_complete_history_binds_exact_commit_without_claiming_other_refs(self):
        self.commit('This is a harmless deterministic offline fixture.\n')
        result, evidence = self.scan()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('PASS', evidence['status'])
        self.assertEqual(self.git('rev-parse', 'HEAD'), evidence['commit_sha'])
        self.assertEqual(1, evidence['reachable_commits'])
        self.assertEqual(0, evidence['finding_count'])
        self.assertFalse(evidence['other_refs_certified'])

    def test_removed_synthetic_credential_is_rejected_from_history_without_echoing_it(self):
        canary = 'ghp_' + 'a1B2c3D4' * 4 + 'E5f6'
        self.commit('token = "' + canary + '"\n')
        self.commit('The canary has been removed from the current tree.\n')
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('FINDINGS_REVIEW_REQUIRED', evidence['status'])
        self.assertGreater(evidence['finding_count'], 0)
        self.assertEqual(2, evidence['reachable_commits'])
        self.assertNotIn(canary, result.stdout + result.stderr + json.dumps(evidence))
        self.assertNotIn('Secret', evidence)

    def test_uncommitted_tracked_changes_cannot_reuse_a_clean_head_certificate(self):
        self.commit('Clean committed fixture.\n')
        (self.root / 'fixture.txt').write_text('Uncommitted changed evidence.\n')
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('TRACKED_TREE_CHANGED', evidence['status'])

    def test_unavailable_scanner_never_reports_zero_findings(self):
        self.commit('Clean fixture.\n')
        self.scanner = str(Path(self.temp.name) / 'missing-scanner')
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('SCAN_UNAVAILABLE', evidence['status'])
        self.assertIsNone(evidence['finding_count'])

    def test_repository_configuration_and_inline_allow_cannot_suppress_findings(self):
        canary = 'ghp_' + 'a1B2c3D4' * 4 + 'E5f6'
        self.commit('token = "' + canary + '" # gitleaks:allow\n')
        (self.root / '.gitleaks.toml').write_text('[extend]\nuseDefault = true\n[[allowlists]]\nregexes = [".*"]\n')
        (self.root / '.gitleaksignore').write_text(self.git('rev-parse', 'HEAD') + ':fixture.txt:github-pat:1\n')
        self.git('add', '.gitleaks.toml', '.gitleaksignore')
        self.git('commit', '-qm', 'Synthetic suppression attempt')
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('FINDINGS_REVIEW_REQUIRED', evidence['status'])
        self.assertNotIn(canary, result.stdout + result.stderr + json.dumps(evidence))

    def manifest_fixture(self, extra=None):
        self.commit('Nonsecret Git object fixture.\n')
        start=self.git('rev-parse','HEAD')
        blob=self.git('rev-parse',start+':fixture.txt')
        # The reviewed historical manifest schema, not a generic token allowlist.
        manifest={'schema':'digiforge-printify-shared-dependency-v1',
                  'refs':{'api_1253_start':start,'api_branch_base_main':start},
                  'api_lane_files':[{'path':'fixture.txt','api_start_blob':blob}]}
        if extra:manifest.update(extra)
        p=self.root/'docs/engineering/printify-shared-dependency-manifest-v1.json'
        p.parent.mkdir(parents=True);p.write_text(json.dumps(manifest,indent=2)+'\n')
        self.git('add','.');self.git('commit','-qm','Git object metadata fixture')
        return p,manifest

    def test_git_metadata_requires_real_commit_and_exact_path_blob(self):
        self.manifest_fixture()
        result,evidence=self.scan()
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertGreater(evidence.get('verified_git_identifier_count',0),0)
        self.assertEqual(evidence['raw_finding_count'],evidence['verified_git_identifier_count'])
        self.assertEqual(0,evidence['finding_count'])

    def test_metadata_schema_cannot_hide_a_new_credential(self):
        canary='ghp_'+'a1B2c3D4'*4+'E5f6'
        self.manifest_fixture({'api_key':canary})
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertGreater(evidence['finding_count'],0)
        self.assertNotIn(canary,result.stdout+result.stderr+json.dumps(evidence))

    def test_wrong_blob_at_same_metadata_key_is_not_classified(self):
        p,m=self.manifest_fixture()
        m['api_lane_files'][0]['api_start_blob']=m['refs']['api_1253_start']
        p.write_text(json.dumps(m,indent=2)+'\n');self.git('add','.');self.git('commit','-qm','Wrong object identity fixture')
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertGreater(evidence['finding_count'],0)

    def test_known_git_key_outside_reviewed_structural_location_remains_actionable(self):
        p, m = self.manifest_fixture()
        m['unreviewed'] = {'api_start_blob': m['api_lane_files'][0]['api_start_blob']}
        p.write_text(json.dumps(m, indent=2) + '\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'Wrong structural location fixture')
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertGreater(evidence['finding_count'], 0)

    def test_duplicate_json_members_remain_actionable(self):
        p, m = self.manifest_fixture()
        raw = json.dumps(m, indent=2)
        raw = raw.replace('  "schema":', '  "schema": "duplicate",\n  "schema":', 1)
        p.write_text(raw + '\n')
        self.git('add', '.')
        self.git('commit', '--amend', '-qm', 'Duplicate member fixture')
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertGreater(evidence['finding_count'], 0)

    def test_shallow_history_cannot_be_certified(self):
        self.commit('Clean initial fixture.\n')
        self.commit('Clean second fixture.\n')
        shallow = Path(self.temp.name) / 'shallow'
        subprocess.run(['git', 'clone', '-q', '--depth=1', self.root.as_uri(), str(shallow)],
                       check=True, capture_output=True)
        self.root = shallow
        result, evidence = self.scan()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('HISTORY_INCOMPLETE', evidence['status'])
        self.assertIsNone(evidence['finding_count'])

    def reviewed_plan_fixture(self):
        path='docs/superpowers/plans/2026-11-13-native-reservation-continuation.md'
        raw=subprocess.run(['git','-C',str(SCRIPT.parent.parent),'show',
                            'cc60045d165f53c10f716d6c646edbb1b4d09ab3:'+path],
                           check=True,capture_output=True).stdout
        target=self.root/path;target.parent.mkdir(parents=True);target.write_bytes(raw)
        self.git('add','.');self.git('commit','-qm','Exact noncredential historical plan fixture')

    def reviewed_source_digest_fixture(self, baseline_available=True, changed=False):
        path='tests/offline/test_printify_dependencies.py'
        project=SCRIPT.parent.parent
        raw=subprocess.check_output(['git','-C',str(project),'show',
                                     'd8733ba6167a968575010a8ee53e936752e344b8:'+path])
        if changed:
            raw+=b'\n# A changed context must require review again.\n'
        if baseline_available:
            common=subprocess.check_output(['git','-C',str(project),'rev-parse','--git-common-dir'],text=True).strip()
            objects=(project/common).resolve()/'objects'
            # Read-only real baseline objects, not candidate ancestors or invented Git identities.
            alternate=self.root/'.git/objects/info/alternates'
            alternate.parent.mkdir(parents=True,exist_ok=True);alternate.write_text(str(objects)+'\n')
        target=self.root/path;target.parent.mkdir(parents=True);target.write_bytes(raw)
        self.git('add','.');self.git('commit','-qm','Exact public source digest assertion fixture')

    def test_exact_historical_source_digest_is_independently_resolved(self):
        self.reviewed_source_digest_fixture()
        result,evidence=self.scan()
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual('PASS',evidence['status'])
        self.assertEqual(1,evidence['verified_source_digest_reference_count'])
        self.assertEqual(1,evidence['raw_finding_count'])
        self.assertEqual(0,evidence['finding_count'])

    def test_source_digest_without_resolvable_baseline_remains_actionable(self):
        self.reviewed_source_digest_fixture(baseline_available=False)
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertGreater(evidence['finding_count'],0)

    def test_changed_digest_assertion_context_remains_actionable(self):
        self.reviewed_source_digest_fixture(changed=True)
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertGreater(evidence['finding_count'],0)

    def test_source_digest_classification_does_not_hide_another_credential(self):
        self.reviewed_source_digest_fixture()
        canary='ghp_'+'a1B2c3D4'*4+'E5f6'
        self.commit('token = "'+canary+'"\n')
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertEqual(1,evidence['verified_source_digest_reference_count'])
        self.assertGreater(evidence['finding_count'],0)
        self.assertNotIn(canary,result.stdout+result.stderr+json.dumps(evidence))

    def test_source_digest_does_not_hide_generic_key_in_same_file_history(self):
        self.reviewed_source_digest_fixture()
        canary='b7C9dE2fG5hJ8kM3'*3
        target=self.root/'tests/offline/test_printify_dependencies.py'
        target.write_bytes(target.read_bytes()+('\napi_key = "'+canary+'"\n').encode())
        self.git('add','.');self.git('commit','-qm','Synthetic generic credential at reviewed path')
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertEqual('FINDINGS_REVIEW_REQUIRED',evidence['status'])
        self.assertEqual(1,evidence['verified_source_digest_reference_count'])
        self.assertGreater(evidence['rules_with_findings'].get('generic-api-key',0),0)
        self.assertNotIn(canary,result.stdout+result.stderr+json.dumps(evidence))

    def test_exact_reviewed_plan_prose_is_separately_verified(self):
        self.reviewed_plan_fixture()
        result,evidence=self.scan()
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual(1,evidence.get('verified_plan_state_phrase_count',0))
        self.assertEqual(0,evidence['finding_count'])
        self.assertEqual(0,evidence['verified_git_identifier_count'])

    def test_reviewed_prose_does_not_hide_an_additional_history_credential(self):
        self.reviewed_plan_fixture()
        canary='ghp_'+'a1B2c3D4'*4+'E5f6'
        self.commit('token = "'+canary+'"\n')
        result,evidence=self.scan()
        self.assertNotEqual(0,result.returncode)
        self.assertEqual(1,evidence.get('verified_plan_state_phrase_count',0))
        self.assertGreater(evidence['finding_count'],0)
        self.assertNotIn(canary,result.stdout+result.stderr+json.dumps(evidence))


class ReviewedPlanPhraseVerifierTest(unittest.TestCase):
    def setUp(self):
        spec=importlib.util.spec_from_file_location('credential_gate',SCRIPT)
        self.gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.gate)
        self.repo=SCRIPT.parent.parent
        self.finding={'File':'docs/superpowers/plans/2026-11-13-native-reservation-continuation.md',
                      'RuleID':'generic-api-key','Commit':'cc60045d165f53c10f716d6c646edbb1b4d09ab3',
                      'StartLine':8,'EndLine':8,'Secret':'REDACTED','Match':'key '+'denial, REDACTED '}
    def git(self,*args):
        return subprocess.run(['git','-C',str(self.repo),*args],check=True,
                              capture_output=True,text=True).stdout.strip()
    def raw(self,*args):
        return subprocess.run(['git','-C',str(self.repo),*args],check=True,
                              capture_output=True).stdout
    def verify(self,finding=None,git=None,raw=None):
        self.assertTrue(hasattr(self.gate,'verified_plan_state_phrase'),
                        'Exact historical noncredential verifier must exist')
        return self.gate.verified_plan_state_phrase(finding or self.finding,git or self.git,raw or self.raw)
    def test_exact_historical_prose_requires_actual_raw_blob(self):
        self.assertTrue(self.verify())
    def test_rule_path_line_match_and_commit_changes_remain_actionable(self):
        for key,value in [('File','other.md'),('RuleID','github-pat'),('StartLine',9),
                          ('EndLine',9),('StartLine',8.0),('Secret','unredacted-value'),
                          ('Match','different redacted match'),('Commit','f'*40)]:
            changed=dict(self.finding);changed[key]=value
            with self.subTest(field=key):self.assertFalse(self.verify(changed))
    def test_changed_blob_or_appended_credential_cannot_reuse_review(self):
        def wrong(*args):
            return 'f'*40 if args[0]=='rev-parse' else self.git(*args)
        self.assertFalse(self.verify(git=wrong))
        raw=self.raw('show',self.finding['Commit']+':'+self.finding['File'])
        self.assertFalse(self.verify(raw=lambda *args:raw+b'\n'+b'api_'+b'key = '+b'synthetic_'+b'changed_content\n'))
        self.assertFalse(self.verify(raw=lambda *args:raw.replace(b'non-revival',b'changed-content')))
    def test_lookup_failure_is_not_a_clean_classification(self):
        def unavailable(*args):raise OSError('offline lookup failure')
        self.assertFalse(self.verify(raw=unavailable))


class ReviewedSourceChecksumVerifierTest(unittest.TestCase):
    def setUp(self):
        import hashlib
        spec=importlib.util.spec_from_file_location('source_checksum_gate',SCRIPT)
        self.gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.gate);self.repo=SCRIPT.parent.parent
        self.path='tests/offline/test_printify_dependencies.py';self.commit='d8733ba6167a968575010a8ee53e936752e344b8'
        self.content=self.raw('show',self.commit+':'+self.path)
        line=self.content.decode().splitlines()[92]
        qualified=self.raw('show','7d624512a0fab416278b7d8f88208c9463224b6f:includes/Security/ShopAccessRepository.php')
        checksum=hashlib.sha256(qualified).hexdigest();self.assertIn(checksum,line)
        masked=line.replace(checksum,'REDACTED')[34:70]
        self.assertEqual('f39d40f87f1c979483dfa43e545d6e87909a3c44a6df9fe7fc2680eeca7b91e3',hashlib.sha256(masked.encode()).hexdigest())
        self.finding={'File':self.path,'RuleID':'generic-api-key','Commit':self.commit,'StartLine':93,'EndLine':93,'StartColumn':36,'EndColumn':127,'Secret':'REDACTED','Match':masked}
    def git(self,*args):
        return subprocess.run(['git','-C',str(self.repo),*args],check=True,capture_output=True,text=True).stdout.strip()
    def raw(self,*args):
        return subprocess.run(['git','-C',str(self.repo),*args],check=True,capture_output=True).stdout
    def verify(self,finding=None,git=None,raw=None):
        self.assertTrue(hasattr(self.gate,'verified_source_digest_reference'),'Exact independent qualified-source checksum verifier required')
        return self.gate.verified_source_digest_reference(finding or self.finding,git or self.git,raw or self.raw)
    def test_exact_public_checksum_requires_real_historical_blob_and_qualified_source(self):
        self.assertTrue(self.verify())
    def test_wrong_path_rule_span_redaction_or_commit_remains_actionable(self):
        for key,value in [('File','tests/offline/other.py'),('RuleID','github-pat'),('StartLine',94),('EndLine',94),('StartLine',93.0),('StartColumn',35),('EndColumn',128),('StartColumn',True),('Secret','unredacted-value'),('Match','different masked match'),('Commit','f'*40)]:
            changed=dict(self.finding);changed[key]=value
            with self.subTest(field=key):self.assertFalse(self.verify(changed))
    def test_unreviewed_blob_or_content_cannot_reuse_the_checksum_classification(self):
        def wrong(*args):return 'f'*40 if args[0]=='rev-parse' else self.git(*args)
        self.assertFalse(self.verify(git=wrong))
        self.assertFalse(self.verify(raw=lambda *args:self.content+b'\n# synthetic changed content\n'))
        self.assertFalse(self.verify(raw=lambda *args:self.content.replace(b'bases={',b'other={',1)))
    def test_missing_or_changed_independent_qualified_source_remains_actionable(self):
        def unavailable(*args):
            if args[0]=='cat-file' and args[1]=='-t' and args[2]=='7d624512a0fab416278b7d8f88208c9463224b6f':raise OSError('missing qualified source')
            return self.git(*args)
        self.assertFalse(self.verify(git=unavailable))
        def changed(*args):
            value=self.raw(*args)
            return value if value==self.content else value+b'\n# synthetic altered qualified source\n'
        self.assertFalse(self.verify(raw=changed))
    def test_unavailable_git_or_invalid_blob_bytes_never_count_as_verified(self):
        def unavailable(*args):raise OSError('offline lookup unavailable')
        self.assertFalse(self.verify(git=unavailable));self.assertFalse(self.verify(raw=unavailable));self.assertFalse(self.verify(raw=lambda *args:'not bytes'))


if __name__ == '__main__':
    unittest.main()
