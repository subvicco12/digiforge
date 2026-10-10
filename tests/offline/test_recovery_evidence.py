#!/usr/bin/env python3
"""Unit tests for read-only recovery evidence predicates."""
import importlib.util
import pathlib
import json
import os
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

    def test_nonfinite_json_constant_cli_fails_closed(self):
        for constant in ("NaN", "Infinity", "-Infinity"):
            with self.subTest(constant=constant), tempfile.TemporaryDirectory() as directory:
                evidence = pathlib.Path(directory) / "nonfinite.json"
                payload = json.dumps(self.valid()).replace('"stop_all": true', '"extra": ' + constant + ', "stop_all": true')
                evidence.write_text(payload, encoding="utf-8")
                run = subprocess.run([sys.executable, str(path), str(evidence)],
                                     capture_output=True, text=True, check=False)
                self.assertNotEqual(0, run.returncode)
                self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_unexpected_evidence_field_fails_closed(self):
        evidence = self.valid()
        evidence["unverified_override"] = True
        result = module.assess(evidence)
        self.assertEqual("REVIEW_REQUIRED", result["status"])
        self.assertIn("unexpected recovery evidence fields", result["errors"])

    def test_fdopen_failure_closes_descriptor(self):
        import inspect
        source = inspect.getsource(module.main)
        self.assertIn("except BaseException:", source)
        self.assertIn("os.close(descriptor)", source)

    def test_close_on_exec_flag_used_when_available(self):
        import inspect
        source = inspect.getsource(module.main)
        self.assertIn('if hasattr(os, "O_CLOEXEC"):', source)
        self.assertIn("flags |= os.O_CLOEXEC", source)

    def test_safe_open_flags_are_required(self):
        import inspect
        source = inspect.getsource(module.main)
        self.assertIn('hasattr(os, "O_NOFOLLOW")', source)
        self.assertIn('hasattr(os, "O_NONBLOCK")', source)
        self.assertNotIn('getattr(os, "O_NOFOLLOW", 0)', source)

    def test_fifo_evidence_cli_fails_closed_without_blocking(self):
        if not hasattr(os, "mkfifo"):
            self.skipTest("FIFO not supported")
        with tempfile.TemporaryDirectory() as directory:
            fifo = pathlib.Path(directory) / "evidence.fifo"
            os.mkfifo(fifo)
            run = subprocess.run([sys.executable, str(path), str(fifo)],
                                 capture_output=True, text=True, check=False, timeout=5)
            self.assertNotEqual(0, run.returncode)
            self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_symlink_evidence_cli_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            target = pathlib.Path(directory) / "evidence.json"
            target.write_text(json.dumps(self.valid()), encoding="utf-8")
            link = pathlib.Path(directory) / "linked.json"
            link.symlink_to(target)
            run = subprocess.run([sys.executable, str(path), str(link)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_invalid_utf8_evidence_cli_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = pathlib.Path(directory) / "invalid-utf8.json"
            evidence.write_bytes(b"\\xff")
            run = subprocess.run([sys.executable, str(path), str(evidence)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_oversized_evidence_cli_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = pathlib.Path(directory) / "oversized.json"
            evidence.write_text(" " * (1024 * 1024 + 1), encoding="utf-8")
            run = subprocess.run([sys.executable, str(path), str(evidence)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_duplicate_safety_key_cli_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = pathlib.Path(directory) / "duplicate.json"
            payload = json.dumps(self.valid())
            payload = payload.replace('"stop_all": true', '"stop_all": false, "stop_all": true')
            evidence.write_text(payload, encoding="utf-8")
            run = subprocess.run([sys.executable, str(path), str(evidence)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            result = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", result["status"])
            self.assertTrue(result["errors"])

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

    def test_all_digest_fields_reject_wrong_type_or_case(self):
        fields = ("certified_package_sha256", "installed_package_sha256",
                  "drill_package_sha256", "backup_sha256", "drill_backup_sha256")
        for key in fields:
            for bad in (123, None, "A" * 64, "a" * 63):
                with self.subTest(key=key, value=bad):
                    record = self.valid()
                    record[key] = bad
                    self.assertEqual("REVIEW_REQUIRED", module.assess(record)["status"])

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
