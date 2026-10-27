import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('ci_mirror', ROOT / 'bin/configure-ci-ubuntu-mirror.py')

class UbuntuMirrorTest(unittest.TestCase):
    def module(self):
        module = importlib.util.module_from_spec(SPEC)
        SPEC.loader.exec_module(module)
        return module

    def test_official_mirror_and_deb822_preserve_trust(self):
        source = 'Types: deb\nURIs: mirror+file:/etc/apt/apt-mirrors.txt\nSuites: noble noble-updates\nComponents: main universe\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n'
        result = self.module().rewrite(source)
        self.assertEqual(source.replace('mirror+file:/etc/apt/apt-mirrors.txt', 'https://archive.ubuntu.com/ubuntu'), result)
        self.assertEqual(result, self.module().rewrite(result))

    def test_azure_and_security_uris(self):
        source = 'deb http://azure.archive.ubuntu.com/ubuntu noble main\nURIs: mirror+file:/etc/apt/apt-security-mirrors.txt\n'
        result = self.module().rewrite(source)
        self.assertIn('https://archive.ubuntu.com/ubuntu noble main', result)
        self.assertIn('https://security.ubuntu.com/ubuntu', result)

    def test_other_sources_and_trust_are_unchanged(self):
        source = 'URIs: https://packages.microsoft.com/ubuntu/24.04/prod\nSigned-By: /etc/apt/keyrings/vendor.gpg\n'
        self.assertEqual(source, self.module().rewrite(source))

    def test_only_ubuntu_sources_are_updated(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'sources.list.d').mkdir()
            target = root / 'sources.list.d/ubuntu.sources'
            target.write_text('URIs: http://azure.archive.ubuntu.com/ubuntu\nSigned-By: KEEP\n')
            vendor = root / 'sources.list.d/vendor.sources'
            vendor.write_text('URIs: http://azure.archive.ubuntu.com/ubuntu\n')
            self.module().configure(root)
            self.assertEqual(target.read_text(), 'URIs: https://archive.ubuntu.com/ubuntu\nSigned-By: KEEP\n')
            self.assertIn('http://azure', vendor.read_text())

    def test_missing_sources_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(RuntimeError):
                self.module().configure(Path(directory))
