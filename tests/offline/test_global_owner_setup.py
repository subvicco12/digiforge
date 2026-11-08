"""Synthetic Ed25519 fixtures only: planner neither writes nor provisions anything."""
import base64
import json
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'bin/plan-global-owner-setup.php'
SITE = 'https://fixture.invalid/'
SHOPS = ['digital', 'personalized_pod', 'standard_pod', 'jewelry']

class GlobalOwnerSetupTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name)
        self.expiry = int(time.time()) + 600
        self.caps = json.loads(subprocess.check_output(['php', '-r',
            'require $argv[1];echo json_encode(DigiForge\\Core\\Capabilities::ALL);',
            str(ROOT / 'includes/Core/Capabilities.php')]))
        self.fixture([self.record()])

    def record(self, **changes):
        record = dict(actor_id=1, shop='personalized_pod', caps=self.caps,
                      expires_at=self.expiry, source_approval_hash='a'*64, authority='OWNER')
        return dict(record, **changes)

    def fixture(self, records):
        # Private signing key lives only in this synthetic PHP fixture process.
        script = '''$p=sodium_crypto_sign_keypair();$s=sodium_crypto_sign_secretkey($p);
$m=json_encode(['version'=>1,'authorities'=>json_decode(stream_get_contents(STDIN),true)],JSON_UNESCAPED_SLASHES);
file_put_contents($argv[1].'/public.txt',base64_encode(sodium_crypto_sign_publickey($p)));
file_put_contents($argv[1].'/authority.json',json_encode(['manifest'=>$m,'signature'=>base64_encode(sodium_crypto_sign_detached($m,$s))]));
sodium_memzero($s);sodium_memzero($p);'''
        subprocess.run(['php', '-r', script, str(self.path)], input=json.dumps(records), text=True, check=True)

    def run_plan(self, *, envelope=True, **changes):
        args = dict(site=SITE, approval='a'*64, expires=str(self.expiry),
                    **{'trust-key-file': str(self.path/'public.txt')})
        if envelope:
            args['authority-file'] = str(self.path/'authority.json')
        args.update(changes)
        before = {p.name: p.read_bytes() for p in self.path.iterdir() if p.is_file()}
        run = subprocess.run(['php', str(SCRIPT)] + ['--'+k+'='+v for k,v in args.items()], capture_output=True, text=True)
        after = {p.name: p.read_bytes() for p in self.path.iterdir() if p.is_file()}
        self.assertEqual(before, after, 'planner changed fixture files')
        return run

    def reject(self, **kwargs):
        run = self.run_plan(**kwargs)
        self.assertNotEqual(0, run.returncode)
        self.assertEqual('', run.stdout)
        return run

    def test_legacy_shop2_replaced_with_unsigned_exact_global_draft(self):
        delegate = self.record(actor_id=7, authority='DELEGATE', caps=[self.caps[0]], custom_review='retained')
        self.fixture([self.record(), delegate])
        run = self.run_plan()
        self.assertEqual(0, run.returncode, run.stderr)
        result = json.loads(run.stdout)
        self.assertEqual('UNSIGNED_NOT_PROVISIONED', result['status'])
        self.assertEqual('REQUIRES_SIGNED_GLOBAL_REPLACEMENT', result['classification'])
        self.assertNotIn('signature', result)
        records = json.loads(result['manifest'])['authorities']
        self.assertEqual(SHOPS, [r['shop'] for r in records[:4]])
        for record in records[:4]:
            self.assertEqual(1, record['actor_id'])
            self.assertEqual('GLOBAL_PRIMARY_OWNER', record['owner_scope'])
            self.assertEqual('OWNER', record['authority'])
            self.assertEqual(self.caps, record['caps'])
            self.assertEqual(SITE, record['site'])
            self.assertEqual('subvicco12@gmail.com', record['subject_email'])
            self.assertEqual(self.expiry, record['expires_at'])
            self.assertEqual('a'*64, record['source_approval_hash'])
        self.assertEqual(1, len({r['global_authority_id'] for r in records[:4]}))
        self.assertEqual(delegate, records[4])
        self.assertFalse(result['root_rotation_supported'])
        plan = result['host_compatibility_plan']
        self.assertEqual('GLOBAL_PRIMARY_OWNER', plan['required_reviewed_constants']['DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SCOPE'])
        self.assertIn('untouched', plan['configuration'])
        self.assertIn('regrant', plan['grant_history'])
        self.assertEqual(run.stdout, self.run_plan().stdout)

    def test_current_global_authority_is_verified_and_exact_site_bound(self):
        first = self.run_plan(envelope=False)
        self.assertEqual(0, first.returncode, first.stderr)
        owners = json.loads(json.loads(first.stdout)['manifest'])['authorities']
        self.fixture(owners)
        result = self.run_plan()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('CURRENT_GLOBAL_AUTHORITY', json.loads(result.stdout)['classification'])
        self.reject(site='https://other.invalid/')
        owners[1]['caps'] = list(reversed(owners[1]['caps']))
        self.fixture(owners)
        self.reject()

    def test_conflicting_owners_expiry_and_invalid_signature_fail_closed(self):
        for records in [[self.record(actor_id=9)], [self.record(expires_at=int(time.time())-1)],
                        [self.record(authority='UNKNOWN')]]:
            with self.subTest(records=records):
                self.fixture(records)
                self.reject()
        self.fixture([self.record()])
        path = self.path/'authority.json'
        envelope = json.loads(path.read_text())
        envelope['signature'] = base64.b64encode(bytes(64)).decode()
        path.write_text(json.dumps(envelope))
        self.reject()

    def test_bounded_non_symlink_envelope_and_public_key(self):
        for content in ['garbage', base64.b64encode(bytes(31)).decode(), 'A'*257]:
            (self.path/'public.txt').write_text(content)
            self.reject()
        self.fixture([self.record()])
        link = self.path/'linked.json'
        link.symlink_to(self.path/'authority.json')
        self.reject(**{'authority-file':str(link)})
        (self.path/'authority.json').write_text(' '*131073)
        self.reject()
        self.reject(**{'trust-key-file':str(self.path)})

    def test_invalid_sites_approvals_expiries_and_output_switch(self):
        for site in ['http://fixture.invalid/', SITE+'?x=1', SITE+'#x', 'https://u@fixture.invalid/',
                     'https://fixture.invalid', 'https://fixture.invalid/\n']:
            with self.subTest(site=site):
                self.reject(site=site)
        for changes in [dict(approval='A'*64), dict(expires=str(int(time.time())-1)),
                        dict(expires=str(int(time.time())+31*86400)), dict(expires='no'),
                        dict(output=str(self.path/'must-not-exist'))]:
            self.reject(**changes)
        self.assertFalse((self.path/'must-not-exist').exists())

    def test_preserving_delegates_must_fit_existing_host_limits(self):
        # 100 records fit the verified old envelope, but replacing one OWNER
        # with four must not silently emit an un-installable 103-record request.
        self.fixture([self.record()] + [self.record(actor_id=i+2, authority='DELEGATE',
            caps=[self.caps[0]]) for i in range(99)])
        run = self.reject()
        self.assertIn('host authority limits', run.stderr)
