"""Execute the real audit step against isolated source copies; never send HTTP."""
import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]


def scan_script():
    workflow = (ROOT / '.github/workflows/digiforge-foundation-audit.yml').read_text()
    section = workflow.split('      - name: External HTTP client scan\n', 1)[1]
    body = section.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0]
    return '\n'.join(line[10:] for line in body.splitlines() if line.startswith('          '))


class GoogleHttpBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='google-http-scan-')
        self.root = pathlib.Path(self.temp.name)
        for folder in ('includes', 'modules', 'automation', 'admin'):
            shutil.copytree(ROOT / folder, self.root / folder)
        shutil.copyfile(ROOT / 'digiforge.php', self.root / 'digiforge.php')
        self.source = self.root / 'includes/DigitalFactory/DriveWordPressGoogleHttp.php'

    def tearDown(self):
        self.temp.cleanup()

    def scan(self):
        return subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', scan_script()], cwd=self.root,
                              capture_output=True, text=True)

    def test_exact_guarded_sender_is_admitted(self):
        result = self.scan()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_other_sender_and_same_filename_elsewhere_still_fail(self):
        for path in ('modules/Rogue.php', 'modules/DriveWordPressGoogleHttp.php', 'includes/DigitalFactory/DriveWordPressGoogleHttpXphp'):
            target = self.root / path
            target.write_text('<?php\n        $response=wp_remote_request($url,$args);\n')
            try:
                self.assertNotEqual(0, self.scan().returncode)
            finally:
                target.unlink()

    def test_missing_gate_tls_and_duplicate_sender_fail(self):
        original = self.source.read_text()
        variants = [original.replace('||!$this->gate->allows($binding)', ''),
                    original.replace("($args['sslverify']??null)!==true", "false"),
                    original + '\n$response=wp_remote_request($url,$args);\n']
        for value in variants:
            with self.subTest(value=value[-100:]):
                self.source.write_text(value)
                self.assertNotEqual(0, self.scan().returncode)
                self.source.write_text(original)


if __name__ == '__main__':
    unittest.main()
