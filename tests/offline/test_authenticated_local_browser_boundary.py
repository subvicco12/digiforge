"""No WordPress, browser installation, credentials or network needed for entry denial."""
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class AuthenticatedLocalBrowserBoundary(unittest.TestCase):
    def test_php_entrypoints_require_explicit_disposable_context(self):
        env = {k: v for k, v in os.environ.items() if not k.startswith('DF_LOCAL_BROWSER_')}
        for name in ('setup.php', 'verify.php', 'cleanup.php', 'guard.php'):
            with self.subTest(name=name):
                result = subprocess.run(['php', str(ROOT / 'tests/browser/authenticated-local' / name)], env=env, capture_output=True, text=True, timeout=10)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('fixture required', (result.stdout + result.stderr).lower().replace('cleanup required', 'fixture required'))

    def test_browser_runner_parses_without_executing(self):
        result = subprocess.run(['node', '--check', str(ROOT / 'tests/browser/authenticated-local-portal.cjs')], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)

if __name__ == '__main__':
    unittest.main()
