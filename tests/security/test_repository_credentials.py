"""Real offline scanner acceptance; synthetic canary is never a usable credential."""
import json
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


if __name__ == '__main__':
    unittest.main()
