"""The narrow source overlay must never become an installer or cumulative release."""
import importlib.util
import copy
import io
import hashlib
import json
from pathlib import Path
import tempfile
import subprocess
import sys
import unittest
import warnings
import zipfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


class FirstPartyOwnerPackageTests(unittest.TestCase):
    def module(self):
        path = ROOT / 'bin/package-first-party-owner.py'
        self.assertTrue(path.is_file(), 'Narrow OWNER package builder/verifier missing')
        spec = importlib.util.spec_from_file_location('owner_package', path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def altered(self, blob, change):
        with zipfile.ZipFile(io.BytesIO(blob)) as z:
            infos = {i.filename: copy.copy(i) for i in z.infolist()}
            files = {i.filename: z.read(i) for i in z.infolist()}
        change(files)
        out = io.BytesIO()
        with zipfile.ZipFile(out, 'w') as z:
            for name, data in files.items():
                entry = infos.get(name)
                if entry is None:
                    entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
                    entry.create_system = 3
                    entry.external_attr = 0o100644 << 16
                z.writestr(entry, data)
        return out.getvalue()

    def test_exact_three_php_payloads_with_no_bootstrap_or_unrelated_modules(self):
        m = self.module()
        blob, manifest = m.build()
        with zipfile.ZipFile(io.BytesIO(blob)) as z:
            self.assertEqual(set(m.PAYLOADS) | {'MANIFEST.json', 'SHA256SUMS', 'INSTALL-REVIEW.md'}, set(z.namelist()))
            self.assertEqual(3, len([n for n in z.namelist() if n.endswith('.php')]))
        self.assertFalse(manifest['deployment_authorized'])
        self.assertFalse(manifest['rollback_tested'])
        self.assertFalse(manifest['mailbox_possession_verified'])
        self.assertEqual(['digital', 'personalized_pod', 'standard_pod', 'jewelry'], manifest['architecture_shops'])
        self.assertEqual(15, len(set(manifest['required_administrative_capabilities'])))
        self.assertEqual('REVIEW_ONLY_DEPLOYMENT_BLOCKED', m.verify(blob)['state'])

    def test_reproducible_source_does_not_read_mutable_checkout(self):
        m = self.module()
        first, manifest = m.build()
        with patch.object(Path, 'read_bytes', side_effect=AssertionError('Mutable checkout read')):
            second, again = m.build()
        self.assertEqual(first, second)
        self.assertEqual(manifest, again)
        self.assertEqual('007da8b9d563567e5811f8ceb43b8732b48a5558', manifest['source_commit'])
        self.assertTrue(all(r['raw_installed_preimage_sha256'] is None for r in manifest['files']))

    def test_extra_cumulative_pod_google_finance_or_traversal_members_reject(self):
        m = self.module()
        blob, _ = m.build()
        for name in ['digiforge/digiforge.php', 'digiforge.php', 'includes/POD/PrintifyCatalogClient.php',
                     'includes/DigitalFactory/DriveGoogleRequest.php', 'includes/Finance/Repository.php',
                     '../wp-config.php', '/absolute.php']:
            with self.subTest(name=name):
                with self.assertRaises(ValueError):
                    m.verify(self.altered(blob, lambda files: files.update({name: b'<?php'})))

    def test_changed_source_manifest_or_fabricated_approval_reject(self):
        m = self.module()
        blob, _ = m.build()
        def change_manifest(files):
            manifest = json.loads(files['MANIFEST.json'])
            manifest['deployment_authorized'] = True
            files['MANIFEST.json'] = json.dumps(manifest).encode()
        for change in [change_manifest,
                       lambda f: f.update({m.PAYLOADS[0]: f[m.PAYLOADS[0]] + b'\n'}),
                       lambda f: f.update({'SHA256SUMS': b'forged'})]:
            with self.assertRaisesRegex(ValueError, 'Payload, manifest, checksums or framing differs'):
                m.verify(self.altered(blob, change))

    def test_missing_payload_rejects_before_content_acceptance(self):
        m = self.module()
        blob, _ = m.build()
        with self.assertRaisesRegex(ValueError, 'Extra, missing, duplicate or cumulative'):
            m.verify(self.altered(blob, lambda files: files.pop(m.PAYLOADS[0])))

    def test_duplicate_and_symlink_payloads_reject(self):
        m = self.module()
        blob, _ = m.build()
        duplicate = io.BytesIO(blob)
        with warnings.catch_warnings(record=True):
            warnings.simplefilter('always')
            with zipfile.ZipFile(duplicate, 'a') as z:
                z.writestr(m.PAYLOADS[0], b'<?php')
        with self.assertRaises(ValueError):
            m.verify(duplicate.getvalue())
        source = io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(blob)) as original, zipfile.ZipFile(source, 'w') as z:
            for i in original.infolist():
                if i.filename == m.PAYLOADS[0]:
                    i.external_attr = 0o120777 << 16
                z.writestr(i, original.read(i))
        with self.assertRaises(ValueError):
            m.verify(source.getvalue())

    def test_missing_git_source_fails_without_falling_back_to_worktree(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as tmp:
            m.ROOT = Path(tmp)
            with self.assertRaises(ValueError):
                m.build()

    def test_new_committed_owner_source_cannot_silently_ship_stale_overlay(self):
        m = self.module()
        files = {p: m._git('cat-file', 'blob', m.SOURCE_COMMIT + ':' + p) for p in m.PAYLOADS + m.DEPENDENCIES}
        with tempfile.TemporaryDirectory() as tmp:
            def git(*args):
                return subprocess.run(['git', '-C', tmp, '-c', 'core.hooksPath=/dev/null',
                                       '-c', 'commit.gpgsign=false', '-c', 'user.name=Synthetic reviewer',
                                       '-c', 'user.email=reviewer@example.invalid', *args],
                                      check=True, capture_output=True, timeout=30).stdout
            git('init', '-q')
            for path, data in files.items():
                target = Path(tmp) / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
            git('add', '.')
            git('commit', '-qm', 'Synthetic baseline')
            m.ROOT = Path(tmp)
            m.SOURCE_COMMIT = git('rev-parse', 'HEAD').decode().strip()
            m.build()
            path = Path(tmp) / m.PAYLOADS[0]
            path.write_bytes(path.read_bytes() + b'\n// changed reviewed source\n')
            git('commit', '-qam', 'Synthetic source drift')
            with self.assertRaisesRegex(ValueError, 'Current committed OWNER dependency drift'):
                m.build()

    def test_committed_candidate_is_exact_reproducible_review_artifact(self):
        m = self.module()
        path = ROOT / 'docs/completion/artifacts/digiforge-owner-007da8b-review.zip'
        blob = path.read_bytes()
        self.assertEqual(m.build()[0], blob)
        self.assertEqual(3, len(m.verify(blob)['files']))
        self.assertEqual(hashlib.sha256(blob).hexdigest() + '  ' + path.name + '\n', path.with_suffix('.zip.sha256').read_text())
        with zipfile.ZipFile(io.BytesIO(blob)) as z:
            self.assertEqual(z.read('MANIFEST.json'), path.with_name('digiforge-owner-007da8b-manifest.json').read_bytes())

    def test_cli_creates_only_archive_never_overwrites_or_extracts(self):
        m = self.module()
        script = str(ROOT / 'bin/package-first-party-owner.py')
        with tempfile.TemporaryDirectory() as tmp:
            output = Path(tmp) / 'owner.zip'
            first = subprocess.run([sys.executable, script, '--output', str(output)], capture_output=True, timeout=30)
            self.assertEqual(0, first.returncode, first.stderr)
            self.assertFalse(json.loads(first.stdout)['deployment_authorized'])
            original = output.read_bytes()
            retry = subprocess.run([sys.executable, script, '--output', str(output)], capture_output=True, timeout=30)
            self.assertNotEqual(0, retry.returncode)
            self.assertEqual(original, output.read_bytes())
            checked = subprocess.run([sys.executable, script, '--verify', str(output)], capture_output=True, timeout=30)
            self.assertEqual(0, checked.returncode, checked.stderr)
            self.assertEqual(['owner.zip'], [p.name for p in Path(tmp).iterdir()])

    def test_empty_truncated_oversized_and_appended_content_reject(self):
        m = self.module()
        blob, _ = m.build()
        for invalid in [b'', blob[:100], blob + b'additional content', b'x' * (m.LIMIT + 1)]:
            with self.assertRaises(ValueError):
                m.verify(invalid)


if __name__ == '__main__':
    unittest.main()
