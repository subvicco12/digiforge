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
            source.append([f"DG-{i:03}", "W1", "Family", "Concept", "ENGINE", "KEEP", "", f"DG2-{i:03}"])
            v2.append([f"DG2-{i:03}", f"DG-{i:03}", "Family", "Concept", "ENGINE", "PP-001", "PRINTIFY_PRIMARY", "TEMPLATE_RESEARCH", "V1 retained", "Re-score"])
        return {"Source_Migration_500": source, "Master_500_v2": v2,
                "Family_Rebalance": [["Family", "V1 Count", "V2 Target"], ["Family", "500", "500"]]}

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

    def test_family_target_mismatch_fails_closed(self):
        rows = self.fixtures()
        rows["Family_Rebalance"][1][2] = "499"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

if __name__ == "__main__":
    unittest.main()
