"""Isolated behavioral dependency checks; PHP stubs never bootstrap WordPress."""
import os
import subprocess
import unittest
from pathlib import Path

class ActionSchedulerReadinessTest(unittest.TestCase):
    def test_dependency_scenarios(self):
        root = Path(__file__).resolve().parents[2]
        prefix = os.environ.get('DIGIFORGE_PHP_COMMAND', 'php').split()
        for scenario in ('available-current', 'absent-header', 'late-registered', 'active-unsupported', 'unknown-active', 'absent', 'available', 'uninitialized', 'incompatible', 'future', 'throws', 'query-failure', 'malformed', 'nonarray', 'missing-query-api'):
            with self.subTest(scenario=scenario):
                result = subprocess.run(prefix + [str(Path(os.environ.get('DIGIFORGE_PHP_ROOT', str(root))) / 'tests/fixtures/action-scheduler-readiness.php'), scenario], text=True, capture_output=True)
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_upstream_393_query_contract(self):
        root = Path(__file__).resolve().parents[2]
        prefix = os.environ.get('DIGIFORGE_PHP_COMMAND', 'php').split()
        for scenario in ('associative','positional','empty','exception','missing-methods','wrong-hook','bad-args'):
            with self.subTest(scenario=scenario):
                result = subprocess.run(prefix + [str(Path(os.environ.get('DIGIFORGE_PHP_ROOT', str(root))) / 'tests/fixtures/action-scheduler-393-query.php'), scenario], text=True, capture_output=True)
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)

if __name__ == '__main__':
    unittest.main()
