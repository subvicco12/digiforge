import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]

class PortalInvitationBrowserTest(unittest.TestCase):
    @unittest.skipUnless(shutil.which('node'), 'Node unavailable in this local PHP fixture; hosted CI runs the browser-state test')
    def test_private_fragment_survives_login_and_is_removed_after_acceptance_handoff(self):
        run = subprocess.run(['node', 'tests/offline/portal-invitation-browser.js'], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(0, run.returncode, run.stderr)
        self.assertIn('PASS: guest login/acceptance', run.stdout)
