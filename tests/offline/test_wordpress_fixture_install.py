"""Execute the installer offline against simulated release downloads."""
import io
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

INSTALLER = Path(__file__).resolve().parents[2] / 'bin/install-wp-tests.sh'


class WordPressFixtureInstallTests(unittest.TestCase):
    def install(self, version):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binary = root / 'bin'
            binary.mkdir()
            with tarfile.open(root / 'core.tar.gz', 'w:gz') as archive:
                data = ("<?php $wp_version = '" + version + "';").encode()
                member = tarfile.TarInfo('wordpress/wp-includes/version.php')
                member.size = len(data)
                archive.addfile(member, io.BytesIO(data))
            (binary / 'curl').write_text('''#!/usr/bin/env python3
import os, pathlib, sys
args = sys.argv[1:]
url = next(arg for arg in args if arg.startswith('https://'))
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(url + '\\n')
if '--output' in args:
    pathlib.Path(args[args.index('--output') + 1]).write_text("<?php // fixture config\\n")
else:
    sys.stdout.buffer.write(pathlib.Path(os.environ['FIXTURE_ARCHIVE']).read_bytes())
''')
            (binary / 'svn').write_text('''#!/usr/bin/env python3
import os, pathlib, sys
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(next(arg for arg in sys.argv if arg.startswith('https://')) + '\\n')
pathlib.Path(sys.argv[-1]).mkdir(parents=True)
''')
            for executable in binary.iterdir():
                executable.chmod(0o755)
            env = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ['PATH'],
                       WP_TESTS_DIR=str(root / 'tests'), WP_CORE_DIR=str(root / 'core'),
                       FIXTURE_LOG=str(root / 'requests'), FIXTURE_ARCHIVE=str(root / 'core.tar.gz'))
            result = subprocess.run(['bash', str(INSTALLER)], env=env,
                                    capture_output=True, text=True, timeout=10)
            return result, (root / 'requests').read_text().splitlines()

    def test_all_fixture_downloads_match_installed_release(self):
        for version in ('7.1', '7.1.3'):
            with self.subTest(version=version):
                result, requests = self.install(version)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(['https://wordpress.org/latest.tar.gz'] + [
                    'https://develop.svn.wordpress.org/tags/' + version + suffix
                    for suffix in ('/tests/phpunit/includes/', '/tests/phpunit/data/',
                                   '/wp-tests-config-sample.php')], requests)

    def test_unreleased_core_fails_before_fixture_downloads(self):
        result, requests = self.install('7.2-alpha')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Unsupported WordPress core version', result.stderr)
        self.assertEqual(['https://wordpress.org/latest.tar.gz'], requests)
