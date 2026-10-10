"""Original-source inventory invariants; no claim of full behavioral acceptance."""
import csv
import json
import pathlib
import re
import unittest
ROOT = pathlib.Path(__file__).resolve().parents[2]
LEDGER = ROOT / 'docs/engineering/original-source'
def rows(name):
    with (LEDGER / name).open() as stream:
        return list(csv.DictReader(stream, delimiter='\t'))
class SourceLedgerInventoryTests(unittest.TestCase):
    def test_all_original_blueprint_anchors_are_retained_exactly(self):
        original = rows('blueprint-acceptance.tsv')
        reconciled = rows('blueprint-reconciliation.tsv')
        locator = 'Derived locator (not original requirement ID)'
        self.assertEqual(706, len(reconciled))
        self.assertEqual(706, len({r[locator] for r in reconciled}))
        for old, new in zip(original, reconciled):
            for key in [locator, 'Original section', 'Original source text']:
                self.assertEqual(old[key], new[key])
        self.assertEqual(36, len({re.match(r'(\d+)\.', r['Original section']).group(1) for r in reconciled if re.match(r'(\d+)\.', r['Original section'])}))
    def test_every_physical_worksheet_row_preserves_values_and_formulas(self):
        workbook = json.loads((LEDGER / 'workbook-original-complete.json').read_text())
        reconciled = rows('workbook-reconciliation.tsv')
        self.assertEqual(6, len(workbook))
        self.assertEqual(1065, len(reconciled))
        for row in reconciled:
            actual = json.loads(row['Complete original row values/formulas'])
            expected = workbook[row['Original worksheet']]['rows'][int(row['Physical row (derived coordinate)']) - 1]
            self.assertEqual(expected, actual)
            self.assertEqual('NOT_OPERATIONAL_AUTHORITY', row['Requirement acceptance'])
        inventory = json.loads((LEDGER / 'source-inventory.json').read_text())
        self.assertEqual(14, sum(len(sheet['formulas']) for sheet in inventory['workbook']['sheets'].values()))
    def test_partial_traces_cannot_be_reported_as_full_acceptance(self):
        data = rows('blueprint-reconciliation.tsv')
        self.assertTrue(any(r['Requirement acceptance'] == 'INCOMPLETE_REQUIREMENT_COVERAGE' for r in data))
        self.assertTrue(any(r['Evidence category (bounded scope)'] == 'FINANCE DEPENDENCY' for r in data))
        for row in data:
            if row['Reconciliation state'] in ['LOCAL_PARTIAL', 'SOFTWARE_PARTIAL', 'RECONCILED_PARTIAL', 'ACCEPTANCE_INCOMPLETE']:
                self.assertEqual('INCOMPLETE_REQUIREMENT_COVERAGE', row['Requirement acceptance'])
            for field in ['Exact implementation reference', 'Exact test references']:
                for reference in filter(None, row[field].split('; ')):
                    path, number, *_ = reference.split(':')
                    self.assertTrue((ROOT / path).is_file(), reference)
                    self.assertLessEqual(int(number), len((ROOT / path).read_text().splitlines()), reference)

    def test_each_master_id_has_an_explicit_uncertified_engine_boundary(self):
        book = json.loads((LEDGER / 'workbook-original-complete.json').read_text())
        matrix = rows('catalog-engine-reconciliation.tsv')
        self.assertEqual(500, len(matrix))
        self.assertEqual(500, len({r['Original V2 ID'] for r in matrix}))
        for source, trace in zip(book['Master_500_v2']['rows'][1:], matrix):
            self.assertEqual(source[0], trace['Original V2 ID'])
            self.assertEqual(source[1] or '', trace['Original source DG ID'])
            self.assertEqual(source[4], trace['Original engine'])
            self.assertEqual(source[9], trace['Original recommended stage'])
            self.assertEqual('NOT_CERTIFIED_OR_PROMOTED', trace['Catalog acceptance'])
        self.assertTrue(any(r['Software boundary'] == 'MISSING_SOFTWARE_ADAPTER' for r in matrix))
