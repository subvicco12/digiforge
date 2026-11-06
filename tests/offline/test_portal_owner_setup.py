"""Synthetic private setup output; never provisions a host or sends email."""
import json
from pathlib import Path
import subprocess
import tempfile
import time
import unittest
ROOT = Path(__file__).resolve().parents[2]
class OwnerSetupTest(unittest.TestCase):
    def test_private_signed_bundle_and_refusal_to_overwrite(self):
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / 'private-authority'
            cmd = ['php', str(ROOT / 'bin/prepare-portal-owner-setup.php'), '--site=https://fixture.invalid/', '--output=' + str(target), '--approval=' + 'a'*64, '--expires=' + str(int(time.time())+600)]
            run = subprocess.run(cmd, capture_output=True, text=True)
            self.assertEqual(0, run.returncode, run.stderr)
            self.assertEqual(0o700, target.stat().st_mode & 0o777)
            for name in ['authority.json', 'host-config.php', 'owner-enrollment-code.txt']:
                self.assertEqual(0o600, (target / name).stat().st_mode & 0o777)
            envelope = json.loads((target / 'authority.json').read_text())
            manifest = json.loads(envelope['manifest'])
            self.assertEqual(1, manifest['authorities'][0]['actor_id'])
            self.assertEqual('personalized_pod', manifest['authorities'][0]['shop'])
            code = (target / 'owner-enrollment-code.txt').read_text().strip()
            self.assertEqual(64, len(code))
            self.assertNotIn(code, run.stdout + run.stderr + (target / 'host-config.php').read_text())
            verify = "$e=json_decode(file_get_contents($argv[1]),true);require $argv[2];if(!sodium_crypto_sign_verify_detached(base64_decode($e['signature']),$e['manifest'],base64_decode(DIGIFORGE_SHOP_ACCESS_TRUST_KEY)))exit(1);if(!hash_equals(DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256,hash('sha256',trim(file_get_contents($argv[3])))))exit(2);"
            result = subprocess.run(['php', '-r', verify, str(target/'authority.json'), str(target/'host-config.php'), str(target/'owner-enrollment-code.txt')], capture_output=True)
            self.assertEqual(0, result.returncode)
            prior = (target/'authority.json').read_bytes()
            self.assertNotEqual(0, subprocess.run(cmd, capture_output=True).returncode)
            self.assertEqual(prior, (target/'authority.json').read_bytes())
    def test_unbounded_expiry_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp)/'unused'
            run = subprocess.run(['php', str(ROOT/'bin/prepare-portal-owner-setup.php'), '--site=https://fixture.invalid/', '--output='+str(path), '--approval='+'a'*64, '--expires='+str(int(time.time())+86400*31)], capture_output=True)
            self.assertNotEqual(0, run.returncode)
            self.assertFalse(path.exists())
