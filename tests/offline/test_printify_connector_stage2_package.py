"""Exact installed-lineage composition; no WordPress bootstrap or network access."""
import importlib.util
import io
import pathlib
import os
import tempfile
import unittest
import zipfile
import subprocess
import shutil
from contextlib import contextmanager

ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('stage2_package', ROOT / 'bin/package-printify-connector-stage2.py')


class Stage2PackageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.package = importlib.util.module_from_spec(SPEC)
        SPEC.loader.exec_module(cls.package)

    @contextmanager
    def committed_snapshot(self):
        p = self.package
        previous = p.ROOT
        with tempfile.TemporaryDirectory(prefix='df-stage2-source-test-') as d:
            root = pathlib.Path(d)
            paths = list((ROOT / 'includes/Connector/Printify').glob('*.php'))
            paths += list((ROOT / 'companion/digiforge-printify-connector').glob('*.php'))
            paths += [ROOT / p.CLIENT, ROOT / 'includes/Portal/OpportunityDesignPanel.php', ROOT / 'includes/POD/PrintifyConnectorReadContract.php', ROOT / 'docs/printify-connector/STAGE2_PACKAGE_CONTRACT.json', ROOT / 'bin/package-printify-connector-stage2.py']
            for source in paths:
                destination = root / source.relative_to(ROOT)
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, destination)
            subprocess.run(['git', 'init', '-q'], cwd=root, check=True)
            subprocess.run(['git', 'add', '.'], cwd=root, check=True)
            subprocess.run(['git', '-c', 'user.name=Synthetic Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'synthetic source snapshot'], cwd=root, check=True)
            head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
            p.ROOT = root
            try:
                yield root, head
            finally:
                p.ROOT = previous

    def test_source_identity_rejects_wrong_commit_and_dirty_selected_files(self):
        p = self.package
        with self.committed_snapshot() as (root, head):
            snapshot = p.verified_sources(head)
            self.assertEqual(snapshot[p.CLIENT], (root / p.CLIENT).read_bytes())
            with self.assertRaises(ValueError):
                p.verified_sources('a' * 40)
            (root / p.CLIENT).write_bytes(snapshot[p.CLIENT] + b'\n// changed after commit\n')
            with self.assertRaises(ValueError):
                p.verified_sources(head)

    def test_baseline_pin_and_member_inventory(self):
        p = self.package
        self.assertEqual(p.BASELINE_SHA256, '5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac')
        self.assertEqual(p.BASELINE_SOURCE, 'e957ba096751dc34254cdbcbefed13b625f5e507')
        self.assertEqual(p.BASELINE_COUNT, 741)

    def test_reviewed_client_delta_is_additive(self):
        p = self.package
        current = (ROOT / p.CLIENT).read_bytes()
        baseline = current.replace(p.connector_method(current), b'')
        result = p.port_client(baseline, current)
        self.assertEqual(result.replace(p.connector_method(current), b''), baseline)
        self.assertEqual(result.count(b'public function connectorRead('), 1)

    def test_client_rejects_duplicate_or_missing_anchor(self):
        p = self.package
        current = (ROOT / p.CLIENT).read_bytes()
        for raw in [current, b'<?php', current.replace(p.connector_method(current), b'').replace(p.ANCHOR, p.ANCHOR * 2)]:
            with self.assertRaises(ValueError):
                p.port_client(raw, current)

    def test_header_version_roundtrip(self):
        p = self.package
        raw = b' * Version: 1.0.103.17\nconst DIGIFORGE_VERSION = \'1.0.103.17\';\n'
        candidate = p.port_header(raw)
        self.assertEqual(candidate.replace(p.CANDIDATE_VERSION.encode(), b'1.0.103.17'), raw)
        with self.assertRaises(ValueError):
            p.port_header(raw.replace(b'1.0.103.17', b'1.0.103.16'))

    def test_zip_output_is_deterministic(self):
        p = self.package
        with tempfile.TemporaryDirectory() as d:
            a, b = pathlib.Path(d) / 'a.zip', pathlib.Path(d) / 'b.zip'
            p.write_zip(a, {'x/z.php': b'z', 'x/a.php': b'a'})
            p.write_zip(b, {'x/a.php': b'a', 'x/z.php': b'z'})
            self.assertEqual(a.read_bytes(), b.read_bytes())
            with zipfile.ZipFile(a) as z:
                self.assertIsNone(z.testzip())
                self.assertEqual(z.namelist(), ['x/a.php', 'x/z.php'])

    def test_archive_rejects_traversal_symlink_and_duplicates(self):
        p = self.package
        for name, mode, duplicate in [('digiforge/../bad.php', 0o100644, False), ('digiforge/a.php', 0o120777, False), ('digiforge/a.php', 0o100644, True), ('other/a.php', 0o100644, False), ('digiforge/./a.php', 0o100644, False), ('digiforge/a//b.php', 0o100644, False)]:
            buffer = io.BytesIO()
            with zipfile.ZipFile(buffer, 'w') as z:
                info = zipfile.ZipInfo(name)
                info.create_system = 3
                info.external_attr = mode << 16
                z.writestr(info, b'a')
                if duplicate:
                    z.writestr(info, b'b')
            with zipfile.ZipFile(buffer) as z, self.assertRaises(ValueError):
                p.read_archive(z)

    def test_wrong_baseline_digest_fails_before_composition(self):
        p = self.package
        with tempfile.TemporaryDirectory() as d:
            bad = pathlib.Path(d) / 'bad.zip'
            bad.write_bytes(b'not the approved archive')
            with self.assertRaises(ValueError):
                p.build(bad, pathlib.Path(d) / 'out', 'a' * 40)

    @unittest.skipUnless(os.environ.get('DF_STAGE2_BASELINE_ZIP'), 'Exact private baseline is supplied only for package acceptance')
    def test_exact_baseline_preservation_and_independent_readback(self):
        p = self.package
        baseline = pathlib.Path(os.environ['DF_STAGE2_BASELINE_ZIP'])
        with self.committed_snapshot() as (_, head), tempfile.TemporaryDirectory() as d:
            output = pathlib.Path(d)
            manifest = p.build(baseline, output, head)
            self.assertTrue(manifest['source_verified'])
            with zipfile.ZipFile(baseline) as z:
                original = p.read_archive(z)
            with zipfile.ZipFile(output / manifest['artifacts'][0]['filename']) as z:
                candidate = p.read_archive(z)
            changed = {name for name in original if original[name] != candidate[name]}
            self.assertEqual(changed, {'digiforge/digiforge.php', 'digiforge/' + p.CLIENT})
            self.assertEqual(manifest['counts']['RETAIN_IDENTICAL'], 739)
            self.assertTrue(set(original).issubset(candidate))
            self.assertTrue('digiforge/includes/Portal/OpportunityDesignPanel.php' in candidate, 'Existing qualified panel must be included in the recovered assembly')
            self.assertFalse(manifest['installation_authorized'])
            for name, raw in candidate.items():
                self.assertEqual((output / 'extracted/core' / name).read_bytes(), raw)
            self.assertEqual(candidate['digiforge/' + p.CLIENT].replace(p.connector_method((ROOT / p.CLIENT).read_bytes()), b'', 1), original['digiforge/' + p.CLIENT])

    @unittest.skipUnless(os.environ.get('DF_STAGE2_BASELINE_ZIP'), 'Exact private baseline is supplied only for package acceptance')
    def test_complete_packages_are_byte_reproducible(self):
        p = self.package
        baseline = pathlib.Path(os.environ['DF_STAGE2_BASELINE_ZIP'])
        with self.committed_snapshot() as (_, head), tempfile.TemporaryDirectory() as d:
            a, b = pathlib.Path(d) / 'a', pathlib.Path(d) / 'b'
            first, second = p.build(baseline, a, head), p.build(baseline, b, head)
            self.assertEqual(first, second)
            self.assertEqual((a / 'preservation-manifest.json').read_bytes(), (b / 'preservation-manifest.json').read_bytes())
            for artifact in first['artifacts']:
                self.assertEqual((a / artifact['filename']).read_bytes(), (b / artifact['filename']).read_bytes())

    @unittest.skipUnless(os.environ.get('DF_STAGE2_BASELINE_ZIP'), 'Exact private baseline is supplied only for package acceptance')
    def test_extraction_rejects_existing_symlinks_without_writing(self):
        p = self.package
        baseline = pathlib.Path(os.environ['DF_STAGE2_BASELINE_ZIP'])
        with self.committed_snapshot() as (_, head), tempfile.TemporaryDirectory() as d:
            root = pathlib.Path(d)
            for case in ['file', 'directory', 'ancestor', 'dangling']:
                out = root / case / 'output'
                target = root / case / 'sentinel'
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('UNTOUCHED')
                if case == 'file':
                    link = out / 'extracted/core/digiforge/digiforge.php'
                    link.parent.mkdir(parents=True)
                    link.symlink_to(target)
                elif case == 'directory':
                    link = out / 'extracted'
                    out.mkdir(parents=True)
                    external = root / case / 'outside'
                    external.mkdir()
                    link.symlink_to(external, target_is_directory=True)
                elif case == 'ancestor':
                    external = root / case / 'outside'
                    external.mkdir()
                    out.symlink_to(external, target_is_directory=True)
                else:
                    out.mkdir(parents=True)
                    (out / 'dangling').symlink_to(root / 'nonexistent')
                with self.assertRaises(ValueError, msg=case):
                    p.build(baseline, out, head)
                self.assertEqual(target.read_text(), 'UNTOUCHED')
                self.assertFalse((out / 'preservation-manifest.json').exists())

    @unittest.skipUnless(os.environ.get('DF_STAGE2_BASELINE_ZIP'), 'Exact private baseline is supplied only for package acceptance')
    def test_baseline_replacement_cannot_change_verified_composition(self):
        p = self.package
        baseline = pathlib.Path(os.environ['DF_STAGE2_BASELINE_ZIP'])
        with self.committed_snapshot() as (_, head), tempfile.TemporaryDirectory() as d:
            root = pathlib.Path(d)
            copy = root / 'baseline.zip'
            shutil.copyfile(baseline, copy)
            verify = p.verified_sources
            def replace_after_verified_read(sha):
                snapshot = verify(sha)
                copy.write_bytes(b'replaced after checksum')
                return snapshot
            p.verified_sources = replace_after_verified_read
            try:
                manifest = p.build(copy, root / 'out', head)
            finally:
                p.verified_sources = verify
            self.assertEqual(manifest['baseline_sha256'], p.BASELINE_SHA256)
            self.assertEqual(manifest['counts']['RETAIN_IDENTICAL'], 739)


if __name__ == '__main__':
    unittest.main()
