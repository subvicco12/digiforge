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

    def write_workbook(self, path, rows, compression=zipfile.ZIP_STORED):
        from xml.etree import ElementTree as ET
        namespace = validator.NS['m']
        relation_namespace = validator.NS['r']
        workbook = ET.Element('{%s}workbook' % namespace)
        sheets = ET.SubElement(workbook, '{%s}sheets' % namespace)
        relationships = ET.Element(validator.REL + 'Relationships')
        with zipfile.ZipFile(path, 'w', compression=compression) as archive:
            for n, (name, data) in enumerate(rows.items(), 1):
                ET.SubElement(sheets, '{%s}sheet' % namespace, name=name,
                              attrib={'{%s}id' % relation_namespace: 'rId%d' % n})
                ET.SubElement(relationships, validator.REL + 'Relationship',
                              Id='rId%d' % n, Target='worksheets/sheet%d.xml' % n)
                sheet = ET.Element('{%s}worksheet' % namespace)
                sheet_data = ET.SubElement(sheet, '{%s}sheetData' % namespace)
                for r, values in enumerate(data, 1):
                    row = ET.SubElement(sheet_data, '{%s}row' % namespace, r=str(r))
                    for c, value in enumerate(values):
                        cell = ET.SubElement(row, '{%s}c' % namespace, r=chr(65+c)+str(r), t='inlineStr')
                        inline = ET.SubElement(cell, '{%s}is' % namespace)
                        ET.SubElement(inline, '{%s}t' % namespace).text = value
                archive.writestr('xl/worksheets/sheet%d.xml' % n, ET.tostring(sheet))
            archive.writestr('xl/workbook.xml', ET.tostring(workbook))
            archive.writestr('xl/_rels/workbook.xml.rels', ET.tostring(relationships))

    def test_unknown_recommended_stage_fails_with_exact_location(self):
        rows = self.fixtures()
        rows['Master_500_v2'][1][9] = 'AUTO_PUBLISH'
        errors, summary = self.run_check(rows)
        self.assertTrue(errors)
        issue = next(x for x in summary['exceptions'] if x['column'] == 'Recommended Stage')
        self.assertEqual('Master_500_v2', issue['sheet'])
        self.assertEqual(2, issue['row'])
        self.assertEqual('DG2-001', issue['identifier'])
        self.assertEqual('ERROR', issue['severity'])

    def test_valid_xlsx_receipt_has_canonical_digest_and_no_authority(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'candidate.xlsx'
            self.write_workbook(path, self.fixtures())
            run = subprocess.run([sys.executable, str(MODULE_PATH), str(path)], capture_output=True, text=True)
            self.assertEqual(0, run.returncode, run.stdout)
            report = json.loads(run.stdout)
            self.assertEqual('STRUCTURAL_PASS_ONLY', report['status'])
            self.assertRegex(report['canonical_rows_sha256'], r'^[a-f0-9]{64}$')
            self.assertFalse(report['production_authority'])
            self.assertFalse(report['promotion_authorized'])
            self.assertEqual([], report['exceptions'])

    def test_canonical_digest_ignores_zip_packaging_but_detects_content_change(self):
        with tempfile.TemporaryDirectory() as directory:
            first = pathlib.Path(directory) / 'stored.xlsx'
            second = pathlib.Path(directory) / 'compressed.xlsx'
            self.write_workbook(first, self.fixtures())
            self.write_workbook(second, self.fixtures(), zipfile.ZIP_DEFLATED)
            _, a = validator.check(first)
            _, b = validator.check(second)
            self.assertEqual(a['canonical_rows_sha256'], b['canonical_rows_sha256'])
            self.assertNotEqual(first.read_bytes(), second.read_bytes())
            changed = self.fixtures()
            changed['Source_Migration_500'][429][6] = 'A changed exclusion decision'
            self.write_workbook(second, changed)
            _, c = validator.check(second)
            self.assertNotEqual(a['canonical_rows_sha256'], c['canonical_rows_sha256'])

    def test_formula_cached_identity_is_not_accepted_as_literal_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'formula.xlsx'
            self.write_workbook(path, self.fixtures())
            with zipfile.ZipFile(path) as archive:
                files = {name: archive.read(name) for name in archive.namelist()}
            files['xl/worksheets/sheet1.xml'] = files['xl/worksheets/sheet1.xml'].replace(b'<ns0:is>', b'<ns0:f>1+1</ns0:f><ns0:is>', 1)
            with zipfile.ZipFile(path, 'w') as archive:
                for name, data in files.items(): archive.writestr(name, data)
            with self.assertRaisesRegex(ValueError, 'formula'):
                validator.load_rows(path)

    def test_blank_required_field_has_complete_review_location(self):
        rows = self.fixtures()
        rows['Master_500_v2'][1][3] = ''
        errors, summary = self.run_check(rows)
        self.assertTrue(errors)
        issue = next(x for x in summary['exceptions'] if x['column'] == 'Concept')
        self.assertEqual(('Master_500_v2', 2, 'DG2-001'),
                         (issue['sheet'], issue['row'], issue['identifier']))

    def test_review_exception_uses_physical_excel_row_for_sparse_sheet(self):
        from xml.etree import ElementTree as ET
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'sparse.xlsx'
            rows = self.fixtures()
            rows['Master_500_v2'][1][9] = 'AUTO_PUBLISH'
            self.write_workbook(path, rows)
            with zipfile.ZipFile(path) as archive:
                files = {name: archive.read(name) for name in archive.namelist()}
            root = ET.fromstring(files['xl/worksheets/sheet2.xml'])
            for row in root.findall('.//m:row', validator.NS)[1:]:
                new_number = int(row.attrib['r']) + 10
                row.attrib['r'] = str(new_number)
                for cell in row.findall('m:c', validator.NS):
                    cell.attrib['r'] = ''.join(x for x in cell.attrib['r'] if x.isalpha()) + str(new_number)
            files['xl/worksheets/sheet2.xml'] = ET.tostring(root)
            with zipfile.ZipFile(path, 'w') as archive:
                for name, data in files.items(): archive.writestr(name, data)
            _, summary = validator.check(path)
            issue = next(x for x in summary['exceptions'] if x['column'] == 'Recommended Stage')
            self.assertEqual(12, issue['row'])

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

    def test_worksheet_parent_traversal_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "parent-traversal.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/../worksheets/sheet1.xml"/>'
                    '</Relationships>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
            with self.assertRaisesRegex(ValueError, "parent traversal"):
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

    def test_duplicate_required_worksheet_targets_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "shared-target.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/>'
                   '<sheet name="Master_500_v2" r:id="rId2"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/>'
                    '<Relationship Id="rId2" Target="worksheets/sheet1.xml"/></Relationships>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
                archive.writestr("xl/worksheets/sheet1.xml", '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>')
            with self.assertRaisesRegex(ValueError, "share an archive target"):
                validator.load_rows(workbook)

    def test_out_of_bounds_coordinates_fail_closed(self):
        for row_id, cell_ref, error in [("1048577", "A1048577", "row exceeds Excel"),
                                        ("1", "XFE1", "Excel column bounds"),
                                        ("1", "ZZZZZZ1", "Excel column bounds")]:
            with self.subTest(cell_ref=cell_ref), tempfile.TemporaryDirectory() as directory:
                workbook = pathlib.Path(directory) / "invalid-coordinate.xlsx"
                xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                       'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                       '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
                rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                        '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
                sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                         f'<sheetData><row r="{row_id}"><c r="{cell_ref}"><v>x</v></c></row></sheetData></worksheet>')
                with zipfile.ZipFile(workbook, "w") as archive:
                    archive.writestr("xl/workbook.xml", xml)
                    archive.writestr("xl/_rels/workbook.xml.rels", rels)
                    archive.writestr("xl/worksheets/sheet1.xml", sheet)
                with self.assertRaisesRegex(ValueError, error):
                    validator.load_rows(workbook)

    def test_oversized_coordinate_digits_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "oversized-coordinate.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            for row_id, cell_ref, expected in [("9" * 5000, "A1", "row exceeds Excel"),
                                               ("1", "A" + "9" * 5000, "cell reference malformed")]:
                sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                         f'<sheetData><row r="{row_id}"><c r="{cell_ref}"><v>x</v></c></row></sheetData></worksheet>')
                with zipfile.ZipFile(workbook, "w") as archive:
                    archive.writestr("xl/workbook.xml", xml)
                    archive.writestr("xl/_rels/workbook.xml.rels", rels)
                    archive.writestr("xl/worksheets/sheet1.xml", sheet)
                with self.assertRaisesRegex(ValueError, expected):
                    validator.load_rows(workbook)

    def test_out_of_order_cell_columns_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "out-of-order-cells.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                     '<sheetData><row r="1"><c r="B1"><v>b</v></c>'
                     '<c r="A1"><v>a</v></c></row></sheetData></worksheet>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
                archive.writestr("xl/worksheets/sheet1.xml", sheet)
            with self.assertRaisesRegex(ValueError, "strictly increasing"):
                validator.load_rows(workbook)

    def test_out_of_order_row_numbers_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "out-of-order.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                     '<sheetData><row r="2"><c r="A2"><v>two</v></c></row>'
                     '<row r="1"><c r="A1"><v>one</v></c></row></sheetData></worksheet>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
                archive.writestr("xl/worksheets/sheet1.xml", sheet)
            with self.assertRaisesRegex(ValueError, "strictly increasing"):
                validator.load_rows(workbook)

    def test_duplicate_numeric_row_aliases_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "row-alias.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                     '<sheetData><row r="1"><c r="A1"><v>one</v></c></row>'
                     '<row r="01"><c r="B01"><v>two</v></c></row></sheetData></worksheet>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
                archive.writestr("xl/worksheets/sheet1.xml", sheet)
            with self.assertRaisesRegex(ValueError, "row number malformed"):
                validator.load_rows(workbook)

    def test_duplicate_row_numbers_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "duplicate-rows.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                     '<sheetData><row r="1"><c r="A1"><v>one</v></c></row>'
                     '<row r="1"><c r="B1"><v>two</v></c></row></sheetData></worksheet>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
                archive.writestr("xl/worksheets/sheet1.xml", sheet)
            with self.assertRaisesRegex(ValueError, "duplicate row numbers"):
                validator.load_rows(workbook)

    def test_malformed_cell_reference_fails_closed(self):
        for cell_ref in ("A1junk", "A2", "A0"):
            with self.subTest(cell_ref=cell_ref), tempfile.TemporaryDirectory() as directory:
                workbook = pathlib.Path(directory) / "malformed-cell.xlsx"
                xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                       'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                       '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
                rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                        '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
                sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                         '<sheetData><row r="1"><c r="' + cell_ref + '"><v>one</v></c>'
                         '</row></sheetData></worksheet>')
                with zipfile.ZipFile(workbook, "w") as archive:
                    archive.writestr("xl/workbook.xml", xml)
                    archive.writestr("xl/_rels/workbook.xml.rels", rels)
                    archive.writestr("xl/worksheets/sheet1.xml", sheet)
                with self.assertRaisesRegex(ValueError, "cell reference malformed"):
                    validator.load_rows(workbook)

    def test_duplicate_cell_column_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook = pathlib.Path(directory) / "duplicate-cell.xlsx"
            xml = ('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                   '<sheets><sheet name="Source_Migration_500" r:id="rId1"/></sheets></workbook>')
            rels = ('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            sheet = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                     '<sheetData><row r="1"><c r="A1"><v>one</v></c><c r="A1"><v>two</v></c>'
                     '</row></sheetData></worksheet>')
            with zipfile.ZipFile(workbook, "w") as archive:
                archive.writestr("xl/workbook.xml", xml)
                archive.writestr("xl/_rels/workbook.xml.rels", rels)
                archive.writestr("xl/worksheets/sheet1.xml", sheet)
            with self.assertRaisesRegex(ValueError, "duplicate cell column"):
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
