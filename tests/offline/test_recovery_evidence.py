#!/usr/bin/env python3
"""Unit tests for read-only recovery evidence predicates."""
import importlib.util
import pathlib
import json
import subprocess
import sys
import tempfile
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
            "installed_commit_sha": "a" * 40, "drill_fresh": True,
            "drill_bound_to_current_artifacts": True, "drill_passed": True,
            "certified_package_sha256": "b" * 64, "installed_package_sha256": "b" * 64,
            "drill_package_sha256": "b" * 64, "backup_sha256": "c" * 64,
            "drill_backup_sha256": "c" * 64,
        }

    def test_missing_evidence_cli_fails_closed_as_json(self):
        with tempfile.TemporaryDirectory() as directory:
            missing = pathlib.Path(directory) / "missing.json"
            run = subprocess.run([sys.executable, str(path), str(missing)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            result = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", result["status"])
            self.assertTrue(result["read_only"])
            self.assertFalse(result["checker_performed_external_actions"])

    def test_malformed_evidence_cli_fails_closed_as_json(self):
        with tempfile.TemporaryDirectory() as directory:
            malformed = pathlib.Path(directory) / "malformed.json"
            malformed.write_text("{not-json", encoding="utf-8")
            run = subprocess.run([sys.executable, str(path), str(malformed)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            result = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", result["status"])
            self.assertTrue(result["errors"])

    def test_complete_evidence_cli_passes_predicates_only(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = pathlib.Path(directory) / "evidence.json"
            evidence.write_text(json.dumps(self.valid()), encoding="utf-8")
            run = subprocess.run([sys.executable, str(path), str(evidence)],
                                 capture_output=True, text=True, check=False)
            self.assertEqual(0, run.returncode)
            result = json.loads(run.stdout)
            self.assertEqual("EVIDENCE_PREDICATES_PASS", result["status"])
            self.assertTrue(result["read_only"])
            self.assertFalse(result["checker_performed_external_actions"])

    def test_unlocked_evidence_cli_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = pathlib.Path(directory) / "unlocked.json"
            record = self.valid()
            record["externally_locked"] = False
            evidence.write_text(json.dumps(record), encoding="utf-8")
            run = subprocess.run([sys.executable, str(path), str(evidence)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            result = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", result["status"])
            self.assertTrue(any("external safety lock" in error for error in result["errors"]))

    def test_complete_evidence_only_passes_predicates(self):
        self.assertEqual("EVIDENCE_PREDICATES_PASS", module.assess(self.valid())["status"])

    def test_missing_or_stale_evidence_fails_closed(self):
        for key in self.valid():
            with self.subTest(key=key):
                record = self.valid()
                record.pop(key)
                self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

    def test_artifact_digest_mismatch_fails_closed(self):
        for key in ("installed_package_sha256", "drill_package_sha256", "drill_backup_sha256"):
            with self.subTest(key=key):
                record = self.valid()
                record[key] = "d" * 64
                self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

    def test_commit_sha_requires_40_hex_digits(self):
        for bad in ("a" * 64, "a" * 39, "z" * 40):
            with self.subTest(bad=bad):
                record = self.valid()
                record["installed_commit_sha"] = bad
                self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

    def test_checker_output_does_not_claim_source_actions(self):
        result = module.assess(self.valid())
        self.assertFalse(result["checker_performed_external_actions"])
        self.assertNotIn("external_actions_performed", result)

    def test_non_object_evidence_fails_closed(self):
        for value in (None, [], "text", 42):
            with self.subTest(value=value):
                self.assertEqual("REVIEW_REQUIRED", module.assess(value)["status"])

    def test_non_string_digest_fails_closed(self):
        record = self.valid()
        record["backup_sha256"] = 123
        self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

    def test_boolean_safety_flags_reject_truthy_and_numeric_values(self):
        expected = {"stop_all": True, "externally_locked": True,
                    "automation_enabled": False, "external_actions_performed": False,
                    "runtime_schema_current": True, "backup_currently_retrievable": True,
                    "backup_checksum_verified": True, "installed_package_identity_verified": True,
                    "drill_fresh": True, "drill_bound_to_current_artifacts": True,
                    "drill_passed": True}
        for key, required in expected.items():
            for value in ("true" if required else "false", 1 if required else 0):
                with self.subTest(key=key, value=value):
                    record = self.valid()
                    record[key] = value
                    self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

    def test_unlocked_environment_fails_closed(self):
        record = self.valid()
        record["externally_locked"] = False
        self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

if __name__ == "__main__":
    unittest.main()
