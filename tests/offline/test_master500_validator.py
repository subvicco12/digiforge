#!/usr/bin/env python3
"""Offline regression tests for the Master 500 candidate validator."""
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

MODULE_PATH = pathlib.Path(__file__).resolve().parents[2] / "bin" / "validate-master500-v2.py"
spec = importlib.util.spec_from_file_location("master500_validator", MODULE_PATH)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)

class Master500ValidatorTests(unittest.TestCase):
    def fixtures(self):
        source = [["Source DG ID", "Wave", "V1 Family", "V1 Concept", "Engine", "Disposition", "Migration Note", "V2 Successor ID"]]
        v2 = [["V2 ID", "Source DG ID", "Family", "Concept", "Engine", "Physical Product", "Supplier Gate", "Template State", "Origin", "Recommended Stage"]]
        for i in range(1, 501):
            disposition = "KEEP" if i <= 428 else "MERGE" if i <= 465 else "DOWNGRADE"
            successor = f"DG2-{i:03}" if i <= 428 else ""
            source.append([f"DG-{i:03}", "W1", f"Family-{(i - 1) % 24:02}", "Concept", "ENGINE", disposition, "", successor])
            origin = "V1 retained" if i <= 428 else "V2 addition" if i <= 458 else "V2 new family"
            v2.append([f"DG2-{i:03}", f"DG-{i:03}" if i <= 428 else "", f"Family-{(i - 1) % 24:02}", "Concept", "ENGINE", "PP-001", "PRINTIFY_PRIMARY", "TEMPLATE_RESEARCH", origin, "Re-score"])
        return {"Source_Migration_500": source, "Master_500_v2": v2,
                "Family_Rebalance": [["Family", "V1 Count", "V2 Target"]] + [[f"Family-{i:02}", "0", str(21 if i < 20 else 20)] for i in range(24)]}

    def run_check(self, rows):
        with patch.object(validator, "load_rows", return_value=rows):
            return validator.check("unused.xlsx")

    def test_valid_candidate_is_structural_only(self):
        errors, summary = self.run_check(self.fixtures())
        self.assertEqual([], errors)
        self.assertEqual(500, summary["v2_rows"])

    def test_tampered_lineage_fails_closed(self):
        rows = self.fixtures()
        rows["Master_500_v2"][1][1] = "DG-002"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

    def test_duplicate_v2_id_fails_closed(self):
        rows = self.fixtures()
        rows["Master_500_v2"][2][0] = "DG2-001"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

    def test_unexpected_supplier_gate_fails_closed(self):
        rows = self.fixtures()
        rows["Master_500_v2"][1][6] = "UNAPPROVED_PROVIDER"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

    def test_missing_reverse_mapping_fails_closed(self):
        rows = self.fixtures()
        rows["Master_500_v2"][1][1] = ""
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

    def test_nonkeep_source_reference_fails_closed(self):
        rows = self.fixtures()
        rows["Master_500_v2"][429][1] = "DG-429"
        errors, _ = self.run_check(rows)
        self.assertTrue(any("source references" in error or "origin/source" in error for error in errors))

    def test_negative_family_target_fails_closed(self):
        rows = self.fixtures()
        rows["Family_Rebalance"][1][2] = "-1"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

    def test_family_target_mismatch_fails_closed(self):
        rows = self.fixtures()
        rows["Family_Rebalance"][1][2] = "499"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

if __name__ == "__main__":
    unittest.main()
