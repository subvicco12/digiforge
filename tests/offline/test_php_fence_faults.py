"""Real local PHP worker/process probes, not FPM/Hostinger power-loss certification."""
import hashlib
import json
import os
from pathlib import Path
import select
import shutil
import signal
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('DIGIFORGE_TEST_PHP') or shutil.which('php')
PROBE = Path(__file__).with_name('php_fence_fault_probe.php')

class PhpFenceFaults(unittest.TestCase):
    def setUp(self):
        self.assertTrue(PHP, 'PHP required; missing executable is not a qualification skip')
        self.tmp = tempfile.TemporaryDirectory(prefix='df-host-fault-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.private = self.root / 'private'
        self.private.mkdir(mode=0o700)
        self.original = b'DFPF1\n' + b'a' * 64 + b'\n'
        self.write('events', self.original)
        self.write('dispatch.lock', b'DF_DISPATCH_GATE_V1\n')
        st = (self.private / 'dispatch.lock').stat()
        identity = hashlib.sha256(f'{st.st_dev}:{st.st_ino}'.encode()).hexdigest()
        self.write('dispatch.identity', f'DFG1:{identity}\n'.encode())
        self.old = self.run_probe('snapshot')['result']

    def write(self, name, data):
        path = self.private / name
        path.write_bytes(data)
        path.chmod(0o600)

    def run_probe(self, operation, fault=None, denied=False):
        env = os.environ.copy()
        env['DF_FAULT_CASE'] = fault or ''
        r = subprocess.run([PHP, str(PROBE), str(self.private), operation],
                           env=env, text=True, capture_output=True, timeout=8)
        self.assertEqual(r.returncode, 42 if denied else 0, r.stderr + r.stdout)
        data = json.loads(r.stdout)
        self.assertEqual(data['status'], 'DENIED' if denied else 'OK')
        return data

    def crash_probe(self, mode, operation='invalidate'):
        env = os.environ.copy()
        env['DF_FAULT_CASE'] = mode
        r = subprocess.run([PHP, str(PROBE), str(self.private), operation],
                           env=env, text=True, capture_output=True, timeout=8)
        self.assertEqual(r.returncode, -signal.SIGKILL, r.stderr)
        return r

    def test_killed_partial_append_poison_survives_new_worker(self):
        self.crash_probe('kill_partial')
        result = self.run_probe('snapshot', denied=True)
        self.assertEqual(result['error'], 'ACCEPTANCE_FENCE_UNAVAILABLE')
        self.assertNotEqual((self.private/'events').read_bytes(), self.original)

    def test_process_killed_after_fsync_changes_generation(self):
        r = self.crash_probe('kill_after_fsync')
        self.assertIn('SYNCED', r.stdout)
        self.assertNotEqual(self.old, self.run_probe('snapshot')['result'])

    def test_failed_fsync_never_reports_invalidation_success(self):
        r = self.run_probe('invalidate', 'failed_fsync', denied=True)
        self.assertFalse(r['retry_permitted'])
        self.assertNotEqual(self.old, self.run_probe('snapshot')['result'])

    def test_append_survives_database_only_restore(self):
        db = sqlite3.connect(self.root/'generation.sqlite')
        self.addCleanup(db.close)
        db.execute('CREATE TABLE generation(digest TEXT)')
        db.execute('INSERT INTO generation VALUES(?)', (self.old,))
        db.commit()
        self.crash_probe('kill_after_fsync')
        self.assertNotEqual(db.execute('SELECT digest FROM generation').fetchone()[0],
                            self.run_probe('snapshot')['result'])

    def test_database_only_advance_does_not_match_old_filesystem(self):
        db = sqlite3.connect(self.root/'generation.sqlite')
        self.addCleanup(db.close)
        db.execute('CREATE TABLE generation(digest TEXT)')
        db.execute('INSERT INTO generation VALUES(?)', ('f'*64,))
        db.commit()
        self.assertNotEqual(db.execute('SELECT digest FROM generation').fetchone()[0],
                            self.run_probe('snapshot')['result'])

    def test_all_root_restore_is_explicit_host_fail_stop_counterexample(self):
        self.run_probe('invalidate', 'failed_fsync', denied=True)
        self.write('events', self.original)  # Simulated loss/restore of independent root too.
        self.assertEqual(self.old, self.run_probe('snapshot')['result'],
                         'All-root rewind revives old digest: HOST_FAIL_STOP_REQUIRED, not recovery PASS')

    def test_replacement_before_rename_retains_old_complete_file(self):
        self.crash_probe('replace_before', 'replace')
        self.assertEqual(self.old, self.run_probe('snapshot')['result'])
        # No publication occurred. Temp existence is NOT a durable stop acknowledgement.

    def test_interrupted_replacement_publishing_partial_file_denies(self):
        self.crash_probe('replace_partial', 'replace')
        self.run_probe('snapshot', denied=True)

    def test_complete_replacement_changes_digest_not_host_durability(self):
        self.crash_probe('replace_after', 'replace')
        self.assertNotEqual(self.old, self.run_probe('snapshot')['result'])

    def test_replaced_lock_inode_cannot_fake_drain(self):
        self.write('lock.pending', b'DF_DISPATCH_GATE_V1\n')
        os.replace(self.private/'lock.pending', self.private/'dispatch.lock')
        self.run_probe('drain', denied=True)

    def test_shared_workers_block_drain_until_killed_without_delaying_intent(self):
        workers = []
        try:
            for _ in range(2):
                p = subprocess.Popen([PHP, str(PROBE), str(self.private), 'hold'],
                                     stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                     stderr=subprocess.PIPE, text=True)
                workers.append(p)
                self.assertTrue(select.select([p.stdout], [], [], 5)[0], 'Worker readiness timeout')
                self.assertEqual(p.stdout.readline(), 'LEASED\n')
            self.assertNotEqual(self.old, self.run_probe('invalidate')['result'])
            self.assertEqual(self.run_probe('drain', denied=True)['error'],
                             'ACCEPTANCE_FENCE_DRAIN_BUSY')
            workers[0].kill(); workers[0].wait(timeout=5)
            self.run_probe('drain', denied=True)
            workers[1].kill(); workers[1].wait(timeout=5)
            self.assertTrue(self.run_probe('drain')['result'])
            # OS locks disappear on crash. This is NOT durable UNKNOWN reconciliation;
            # persisted independent authority lease tests cover that separate obligation.
        finally:
            for p in workers:
                if p.poll() is None: p.kill()
                p.communicate(timeout=5)

if __name__ == '__main__':
    unittest.main()
