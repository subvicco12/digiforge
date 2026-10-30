"""The narrow source overlay must never become an installer or cumulative release."""
import importlib.util
import copy
import io
import hashlib
import json
import os
from pathlib import Path
import tempfile
import subprocess
import sys
import types
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

    def capture(self, module, root):
        for name in module.DEPENDENCIES + module.PAYLOADS[1:]:
            target = root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(module._source(name, module.SOURCE_COMMIT if name in module.PAYLOADS else module.DEPENDENCY_REFERENCE_COMMIT))

    def test_private_capture_never_becomes_production_or_rollback_approval(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.capture(m, root)
            before = {str(p.relative_to(root)): p.read_bytes() for p in root.rglob('*') if p.is_file()}
            result = m.inspect_capture(root)
            self.assertEqual('LOCAL_FILES_PRESENT_DEPENDENCIES_MATCH', result['capture_status'])
            for key in ['production_source_verified', 'deployment_authorized', 'rollback_tested', 'mailbox_possession_verified']:
                self.assertFalse(result[key])
            self.assertEqual(17, len(result['capture_files']))
            self.assertEqual('ABSENT_IN_LOCAL_CAPTURE', result['capture_files'][0]['capture_state'])
            self.assertEqual(before, {str(p.relative_to(root)): p.read_bytes() for p in root.rglob('*') if p.is_file()})
            self.assertEqual(3, len(m.PAYLOADS))

    def test_missing_preimage_and_dependency_drift_block_scope(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.capture(m, root)
            (root / m.PAYLOADS[1]).unlink()
            (root / m.DEPENDENCIES[0]).write_bytes(b'<?php /* synthetic altered dependency */')
            result = m.inspect_capture(root)
            self.assertEqual('MISSING_OR_DRIFTED', result['capture_status'])
            self.assertIn('MISSING_PREIMAGE', str(result))
            self.assertIn('DEPENDENCY_DRIFT', str(result))
            self.assertFalse(result['deployment_authorized'])

    def test_extra_previously_unknown_policy_is_not_silently_overwritten(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.capture(m, root)
            (root / m.PAYLOADS[0]).write_bytes(b'<?php /* unrelated synthetic policy */')
            result = m.inspect_capture(root)
            self.assertEqual('MISSING_OR_DRIFTED', result['capture_status'])
            self.assertEqual('UNEXPECTED_EXISTING_POLICY', result['capture_files'][0]['scope_check'])

    def test_capture_symlink_parents_and_nonregular_files_reject(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.capture(m, root)
            target = root / m.PAYLOADS[1]
            target.unlink()
            target.symlink_to('/etc/passwd')
            with self.assertRaises(ValueError):
                m.inspect_capture(root)

    def test_capture_cli_never_returns_deployment_success_or_extracts(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run([sys.executable, str(ROOT / 'bin/package-first-party-owner.py'), '--inspect-capture', directory], capture_output=True, text=True)
            self.assertEqual(2, result.returncode)
            report = json.loads(result.stdout)
            self.assertFalse(report['deployment_authorized'])
            self.assertEqual([], list(Path(directory).iterdir()))

    def test_capture_root_parent_links_fifo_hardlinks_and_oversize_reject(self):
        m = self.module()
        for kind in ['root-link', 'parent-link', 'fifo', 'hardlink', 'oversize']:
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as directory:
                root = Path(directory) / 'capture'
                root.mkdir()
                self.capture(m, root)
                target = root / m.PAYLOADS[1]
                selected = root
                if kind == 'root-link':
                    selected = Path(directory) / 'link'
                    selected.symlink_to(root, target_is_directory=True)
                elif kind == 'parent-link':
                    (root / 'includes').rename(root / 'held-includes')
                    (root / 'includes').symlink_to(root / 'held-includes', target_is_directory=True)
                elif kind == 'fifo':
                    target.unlink()
                    os.mkfifo(target)
                elif kind == 'hardlink':
                    os.link(target, root / 'another-link')
                else:
                    target.write_bytes(b'x' * (m.LIMIT + 1))
                with self.assertRaises(ValueError):
                    m.inspect_capture(selected)

    def test_capture_changed_during_read_rejects_without_exporting_content(self):
        m = self.module()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.capture(m, root)
            target = root / m.PAYLOADS[1]
            original_read = os.read
            changed = False
            def read(fd, count):
                nonlocal changed
                chunk = original_read(fd, count)
                if chunk and not changed:
                    changed = True
                    with target.open('ab') as output:
                        output.write(b'changed synthetic capture')
                return chunk
            opened = m._capture_directory(root)
            try:
                with patch.object(m.os, 'read', side_effect=read):
                    with self.assertRaisesRegex(ValueError, 'Capture changed'):
                        m._capture_file(opened, m.PAYLOADS[1])
            finally:
                os.close(opened)

    def test_capture_cli_emits_metadata_only_and_ignores_protected_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            m = self.module()
            root = Path(directory)
            self.capture(m, root)
            marker = 'CAPTURE_CONTENT_MUST_NOT_BE_EXPORTED'
            (root / m.PAYLOADS[1]).write_text('<?php /* ' + marker + ' */')
            (root / 'wp-config.php').write_text(marker)
            result = subprocess.run([sys.executable, str(ROOT / 'bin/package-first-party-owner.py'), '--inspect-capture', directory], capture_output=True, text=True)
            self.assertEqual(2, result.returncode)
            self.assertNotIn(marker, result.stdout + result.stderr)
            report = json.loads(result.stdout)
            self.assertEqual(17, len(report['capture_files']))
            self.assertNotIn('wp-config.php', result.stdout)
            self.assertFalse(report['production_source_verified'])

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
        references={row['path'] for row in manifest['existing_runtime_dependency_references_not_payload']}
        self.assertIn('includes/Portal/ShopWorkflowNavigation.php', references)
        self.assertIsNone(manifest['source_reference_audit'])
        self.assertEqual('COMPLETE_EXACT_CANDIDATE_AUDIT_REQUIRED',manifest['source_reference_audit_state'])
        self.assertEqual('REVIEW_ONLY_DEPLOYMENT_BLOCKED', m.verify(blob)['state'])

    def test_reference_only_policy_labels_do_not_replace_owner_payload_identity(self):
        m = self.module()
        blob, manifest = m.build()
        self.assertEqual('aa8b687501450f0e243c4aa9854e9089a44a76b5', manifest['source_commit'])
        self.assertEqual('27a6d7bd180732f6de1c148abac8ecaf68694f62', manifest['dependency_reference_commit'])
        with zipfile.ZipFile(io.BytesIO(blob)) as archive:
            for path in m.PAYLOADS:
                self.assertEqual(m._source(path, m.SOURCE_COMMIT), archive.read(path))
            self.assertNotIn('includes/Portal/Portal.php', archive.namelist())
            self.assertNotIn('includes/Finance/Repository.php', archive.namelist())
        old = m.DEPENDENCY_REFERENCE_COMMIT
        m.DEPENDENCY_REFERENCE_COMMIT = m.SOURCE_COMMIT
        try:
            with self.assertRaisesRegex(ValueError, 'Current committed OWNER dependency drift'):
                m.build()
        finally:
            m.DEPENDENCY_REFERENCE_COMMIT = old

    def test_reproducible_source_does_not_read_mutable_checkout(self):
        m = self.module()
        first, manifest = m.build()
        with patch.object(Path, 'read_bytes', side_effect=AssertionError('Mutable checkout read')):
            second, again = m.build()
        self.assertEqual(first, second)
        self.assertEqual(manifest, again)
        self.assertEqual('aa8b687501450f0e243c4aa9854e9089a44a76b5', manifest['source_commit'])
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
        files = {p: m._git('cat-file', 'blob', (m.SOURCE_COMMIT if p in m.PAYLOADS else m.DEPENDENCY_REFERENCE_COMMIT) + ':' + p) for p in m.PAYLOADS + m.DEPENDENCIES}
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
            m.DEPENDENCY_REFERENCE_COMMIT = m.SOURCE_COMMIT
            m.build()
            path = Path(tmp) / m.PAYLOADS[0]
            path.write_bytes(path.read_bytes() + b'\n// changed reviewed source\n')
            git('commit', '-qam', 'Synthetic source drift')
            with self.assertRaisesRegex(ValueError, 'Current committed OWNER dependency drift'):
                m.build()

    def historical_module(self):
        # Preserve the certified archive and verifier at their original source
        # boundary. It must not be relabelled as this candidate after a repin.
        current=self.module()
        baseline='9c8d3a71c1c36fb6b501a2f39b42655a28208875'
        source=current._git('show',baseline+':bin/package-first-party-owner.py')
        historical=types.ModuleType('historical_owner_package')
        historical.__file__=str(ROOT/'bin/package-first-party-owner.py')
        exec(compile(source,historical.__file__,'exec'),historical.__dict__)
        git=historical._git
        historical._git=lambda *args:git(*(baseline if arg=='HEAD' else arg for arg in args))
        return historical

    def test_historical_archive_keeps_its_exact_original_source_verifier(self):
        m = self.historical_module()
        path = ROOT / 'docs/completion/artifacts/digiforge-owner-007da8b-review.zip'
        blob = path.read_bytes()
        self.assertEqual(m.build()[0], blob)
        self.assertEqual(3, len(m.verify(blob)['files']))
        self.assertEqual(hashlib.sha256(blob).hexdigest() + '  ' + path.name + '\n', path.with_suffix('.zip.sha256').read_text())
        with zipfile.ZipFile(io.BytesIO(blob)) as z:
            self.assertEqual(z.read('MANIFEST.json'), path.with_name('digiforge-owner-007da8b-manifest.json').read_bytes())

    def test_current_and_historical_review_archives_cannot_substitute_for_each_other(self):
        current=self.module();historical=self.historical_module()
        old=(ROOT/'docs/completion/artifacts/digiforge-owner-007da8b-review.zip').read_bytes()
        with self.assertRaises(ValueError):current.verify(old)
        with self.assertRaises(ValueError):historical.verify(current.build()[0])

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
