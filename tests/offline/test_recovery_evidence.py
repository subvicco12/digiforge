#!/usr/bin/env python3
"""Unit tests for read-only recovery evidence predicates."""
import importlib.util
import pathlib
import unittest

path = pathlib.Path(__file__).resolve().parents[2] / "bin" / "check-recovery-evidence.py"
spec = importlib.util.spec_from_file_location("recovery_evidence", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class RecoveryEvidenceTests(unittest.TestCase):
    def valid(self):
        return {
            "environment": "digiforgestaging.converentis.com",
            "stop_all": True, "externally_locked": True,
            "automation_enabled": False, "external_actions_performed": False,
            "runtime_schema_current": True, "backup_currently_retrievable": True,
            "backup_checksum_verified": True, "installed_package_identity_verified": True,
            "installed_commit_sha": "a" * 64, "drill_fresh": True,
            "drill_bound_to_current_artifacts": True, "drill_passed": True,
        }

    def test_complete_evidence_only_passes_predicates(self):
        self.assertEqual("EVIDENCE_PREDICATES_PASS", module.assess(self.valid())["status"])

    def test_missing_or_stale_evidence_fails_closed(self):
        for key in self.valid():
            with self.subTest(key=key):
                record = self.valid()
                record.pop(key)
                self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

    def test_unlocked_environment_fails_closed(self):
        record = self.valid()
        record["externally_locked"] = False
        self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

if __name__ == "__main__":
    unittest.main()
