"""The obsolete helper must stop before creating any private output."""
from pathlib import Path
import subprocess
import tempfile
import time
import unittest
ROOT = Path(__file__).resolve().parents[2]

class OwnerSetupTest(unittest.TestCase):
    def test_guard_exits_78_before_creating_directory_keys_or_code(self):
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / 'private-authority'
            run = subprocess.run(['php', str(ROOT / 'bin/prepare-portal-owner-setup.php'),
                '--site=https://fixture.invalid/', '--output=' + str(target),
                '--approval=' + 'a'*64, '--expires=' + str(int(time.time())+600)], capture_output=True, text=True)
            self.assertEqual(78, run.returncode)
            self.assertEqual('', run.stdout)
            self.assertIn('BLOCKED', run.stderr)
            self.assertFalse(target.exists())
            self.assertEqual([], list(Path(tmp).iterdir()))

    def test_guard_precedes_arguments_and_preserves_existing_files(self):
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp)
            for name in ['authority.json', 'host-config.php', 'owner-enrollment-code.txt']:
                (target / name).write_text('synthetic sentinel')
            run = subprocess.run(['php', str(ROOT / 'bin/prepare-portal-owner-setup.php'),
                '--output='+tmp, '--expires=invalid'], capture_output=True)
            self.assertEqual(78, run.returncode)
            self.assertEqual(b'', run.stdout)
            self.assertEqual(3, len(list(target.iterdir())))
            for path in target.iterdir():
                self.assertEqual('synthetic sentinel', path.read_text())
