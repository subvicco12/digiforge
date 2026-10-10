#!/usr/bin/env python3
"""Offline regression tests for the Master 500 candidate validator."""
import importlib.util
import pathlib
import json
import subprocess
import sys
import tempfile
import unittest
import zipfile
import warnings
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

    def test_duplicate_workbook_sheet_names_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "duplicate-sheets.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                   '<sheets><sheet name="Source_Migration_500" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/>'
                   '<sheet name="Source_Migration_500"/></sheets></workbook>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
                archive.writestr("xl/worksheets/sheet1.xml", '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>')
            run = subprocess.run([sys.executable, str(MODULE_PATH), str(workbook)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_duplicate_workbook_relationship_ids_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "duplicate-relations.xlsx"
            relationships = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                             '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/>'
                             '<Relationship Id="rId1" Target="worksheets/sheet2.xml"/>'
                             '</Relationships>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>')
                archive.writestr("xl/_rels/workbook.xml.rels", relationships)
            with self.assertRaisesRegex(ValueError, "duplicate relationship IDs"):
                validator.load_rows(workbook)

    def test_missing_worksheet_relationship_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "missing-relation.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="missing"/></sheets></workbook>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", "<Relationships/>")
            with self.assertRaisesRegex((ValueError, KeyError), "relationship"):
                validator.load_rows(workbook)

    def test_external_worksheet_relationship_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "external-sheet.xlsx"
            workbook_xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                            '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels_xml = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                        '<Relationship Id="rId1" Target="worksheets/sheet1.xml" TargetMode="External"/>'
                        '</Relationships>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", workbook_xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels_xml)
            with self.assertRaisesRegex(ValueError, "external worksheet relationship"):
                validator.load_rows(workbook)

    def test_duplicate_archive_path_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "duplicate.xlsx"
            with warnings.catch_warnings():
                warnings.simplefilter("ignore", UserWarning)
                with zipfile.ZipFile(workbook, "w") as archive:
                    archive.writestr("xl/workbook.xml", "first")
                    archive.writestr("xl/workbook.xml", "second")
            run = subprocess.run([sys.executable, str(MODULE_PATH), str(workbook)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            self.assertEqual("REVIEW_REQUIRED", json.loads(run.stdout)["status"])

    def test_oversized_archive_entry_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "oversized.xlsx"
            with zipfile.ZipFile(workbook, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                archive.writestr("xl/workbook.xml", b"x" * (25 * 1024 * 1024 + 1))
            run = subprocess.run([sys.executable, str(MODULE_PATH), str(workbook)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            report = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", report["status"])
            self.assertTrue(report["errors"])

    def test_missing_workbook_cli_fails_closed_as_json(self):
        with tempfile.TemporaryDirectory() as directory:
            missing = pathlib.Path(directory) / "missing.xlsx"
            run = subprocess.run([sys.executable, str(MODULE_PATH), str(missing)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            report = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", report["status"])
            self.assertIsNone(report["sha256"])
            self.assertTrue(report["errors"])

    def test_corrupt_workbook_cli_fails_closed_as_json(self):
        with tempfile.TemporaryDirectory() as directory:
            corrupt = pathlib.Path(directory) / "corrupt.xlsx"
            corrupt.write_bytes(b"not an XLSX archive")
            run = subprocess.run([sys.executable, str(MODULE_PATH), str(corrupt)],
                                 capture_output=True, text=True, check=False)
            self.assertNotEqual(0, run.returncode)
            report = json.loads(run.stdout)
            self.assertEqual("REVIEW_REQUIRED", report["status"])
            self.assertTrue(report["sha256"])
            self.assertTrue(report["errors"])

    def test_valid_candidate_is_structural_only(self):
        errors, summary = self.run_check(self.fixtures())
        self.assertEqual([], errors)
        self.assertEqual(500, summary["v2_rows"])

    def test_tampered_lineage_fails_closed(self):
        rows = self.fixtures()
        rows["Master_500_v2"][1][1] = "DG-002"
        errors, _ = self.run_check(rows)
        self.assertTrue(errors)

    def test_blank_source_and_candidate_identifiers_fail_closed(self):
        for sheet in ("Source_Migration_500", "Master_500_v2"):
            with self.subTest(sheet=sheet):
                rows = self.fixtures()
                rows[sheet][1][0] = ""
                errors, _ = self.run_check(rows)
                self.assertTrue(any("blank identifier" in error for error in errors))

    def test_duplicate_source_id_fails_closed(self):
        rows = self.fixtures()
        rows["Source_Migration_500"][2][0] = rows["Source_Migration_500"][1][0]
        errors, _ = self.run_check(rows)
        self.assertTrue(any("duplicate identifier" in error for error in errors))

    def test_nonkeep_direct_successor_fails_closed(self):
        rows = self.fixtures()
        rows["Source_Migration_500"][429][7] = "DG2-429"
        errors, _ = self.run_check(rows)
        self.assertTrue(any("non-KEEP direct successor" in error for error in errors))

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

    def test_required_source_descriptive_fields_fail_closed(self):
        for column in (1, 2, 3, 4):
            with self.subTest(column=column):
                rows = self.fixtures()
                rows["Source_Migration_500"][1][column] = ""
                errors, _ = self.run_check(rows)
                self.assertTrue(any("required wave/family/concept/engine" in error for error in errors))

    def test_required_v2_descriptive_fields_fail_closed(self):
        for column in (2, 3, 4):
            with self.subTest(column=column):
                rows = self.fixtures()
                rows["Master_500_v2"][1][column] = ""
                errors, _ = self.run_check(rows)
                self.assertTrue(any("required family/concept/engine" in error for error in errors))

    def test_blank_family_row_fails_closed(self):
        rows = self.fixtures()
        rows["Family_Rebalance"].append(["", "", ""])
        errors, _ = self.run_check(rows)
        self.assertTrue(any("blank family" in error for error in errors))

    def test_noninteger_family_target_fails_closed(self):
        rows = self.fixtures()
        rows["Family_Rebalance"][1][2] = "not-a-number"
        errors, _ = self.run_check(rows)
        self.assertTrue(any("noninteger target" in error for error in errors))

    def test_duplicate_family_target_fails_closed(self):
        rows = self.fixtures()
        rows["Family_Rebalance"][2][0] = rows["Family_Rebalance"][1][0]
        errors, _ = self.run_check(rows)
        self.assertTrue(any("duplicate family" in error for error in errors))

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
