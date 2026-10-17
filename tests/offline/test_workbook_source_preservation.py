"""Clause-level preserved workbook data, not formula evaluation or market certification.

The immutable index digest is the existing committed index at audited 6c70cc8.
No original workbook is reopened, inventory rebuilt, formula evaluated or network
request made. Every relevant indexed cell and projection is compared.
"""
import csv
import hashlib
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'docs/engineering/original-source'
INDEX_SHA256 = '7073f818865ba5f019e125f615d9b9fab72bc3099a76f8fbbaf8f264b366d45c'


def read_tsv(name):
    with (SOURCE / name).open(newline='') as stream:
        return list(csv.DictReader(stream, delimiter='\t'))


def coordinate(row, column):
    letters = ''
    while column:
        column, remainder = divmod(column - 1, 26)
        letters = chr(65 + remainder) + letters
    return letters + str(row)


class WorkbookSourcePreservationTest(unittest.TestCase):
    def setUp(self):
        data = (SOURCE / 'workbook-original-complete.json').read_bytes()
        self.assertEqual(INDEX_SHA256, hashlib.sha256(data).hexdigest(),
                         'The authoritative committed source index changed; do not silently reset its evidence digest.')
        self.book = json.loads(data)
        self.reconciled = read_tsv('workbook-reconciliation.tsv')
        self.original = read_tsv('nonfinance-closure-matrix.tsv')
        self.trace = read_tsv('full-source-requirement-trace.tsv')

    def assert_every_indexed_cell(self, sheet):
        expected = self.book[sheet]['rows']
        projections = [
            {int(r['Physical row (derived coordinate)']): json.loads(r['Complete original row values/formulas'])
             for r in self.reconciled if r['Original worksheet'] == sheet},
            {int(r['source_locator'].split('!row')[1]): json.loads(r['original_text_or_row_json'])
             for r in self.original if r['source_locator'].startswith(sheet + '!row')},
            {int(r['source_locator'].split('!row')[1]): json.loads(r['original_text_or_row_json'])
             for r in self.trace if r['source_locator'].startswith(sheet + '!row')},
        ]
        # Derive coordinates from the authoritative index, not an arbitrary
        # minimum count that would allow omitted cells or rows to pass.
        positions = set(range(1, len(expected) + 1))
        for projection in projections:
            self.assertEqual(positions, set(projection), sheet)
            for number, cells in enumerate(expected, 1):
                actual = projection[number]
                self.assertEqual(len(cells), len(actual), (sheet, number))
                for column, (reference, preserved) in enumerate(zip(cells, actual), 1):
                    with self.subTest(sheet=sheet, cell=coordinate(number, column)):
                        self.assertEqual(type(reference), type(preserved))
                        self.assertEqual(reference, preserved)
        # Actual JSON round-trip retains nulls, exact strings and numeric types.
        self.assertEqual(expected, json.loads(json.dumps(expected, ensure_ascii=False, allow_nan=False)))

    def test_every_ai_planning_cell_formula_cache_and_zero_budget_state_roundtrips_without_evaluation(self):
        sheet = 'AI_Launch_Model'
        self.assert_every_indexed_cell(sheet)
        rows = self.book[sheet]['rows']
        expressions = {coordinate(row, col): value
                       for row, cells in enumerate(rows, 1)
                       for col, value in enumerate(cells, 1)
                       if isinstance(value, str) and value.startswith('=')}
        preserved = self.book[sheet]['formulas']
        addresses = [item['cell'] for item in preserved]
        self.assertEqual(len(addresses), len(set(addresses)))
        self.assertEqual(set(expressions), set(addresses))
        for item in preserved:
            with self.subTest(formula=item['cell']):
                self.assertEqual({'cell', 'formula', 'cached'}, set(item))
                self.assertRegex(item['cell'], r'^[A-Z]+[1-9][0-9]*$')
                self.assertEqual(expressions[item['cell']], item['formula'])
                self.assertEqual(item, json.loads(json.dumps(item, ensure_ascii=False, allow_nan=False)))
        # These are preserved authored expressions/caches. Do not calculate
        # budget arithmetic or convert a planning cache into provider authority.
        self.assertEqual(0, rows[9][1])
        zero_state = [item for item in preserved if '"SET BUDGET"' in item['formula']]
        self.assertTrue(zero_state)
        for item in zero_state:
            self.assertEqual('SET BUDGET', item['cached'])
        for row in self.trace:
            if row['source_locator'].startswith(sheet + '!row'):
                self.assertEqual('REQUIREMENTS_ONLY_NOT_EXECUTION_AUTHORIZATION', row['authority'])
        for row in self.reconciled:
            if row['Original worksheet'] == sheet:
                self.assertEqual('NOT_OPERATIONAL_AUTHORITY', row['Requirement acceptance'])

    def test_every_dated_research_url_decision_note_and_catalog_migration_identity_roundtrips_as_source_only(self):
        for sheet in ['Research_Evidence', 'Source_Migration_500', 'Master_500_v2']:
            self.assert_every_indexed_cell(sheet)
        research = self.book['Research_Evidence']['rows']
        self.assertEqual(['Source', 'Date/Period', 'Evidence used', 'Planning implication', 'URL'], research[0][:5])
        # Comparison above covers every date/period, URL, source and decision
        # note, including internal and absent values; none is fetched/refreshed.
        for filename, sheet in [('Source_Migration_500.tsv', 'Source_Migration_500'),
                                ('Master_500_v2.tsv', 'Master_500_v2')]:
            source_rows = self.book[sheet]['rows']
            exported = read_tsv(filename)
            by_row = {int(row['Original Excel row']): row for row in exported}
            self.assertEqual(len(exported), len(by_row))
            self.assertEqual(set(range(2, len(source_rows) + 1)), set(by_row))
            headers = [value for value in source_rows[0] if value is not None]
            for number, cells in enumerate(source_rows[1:], 2):
                for column, header in enumerate(headers):
                    with self.subTest(sheet=sheet, row=number, header=header):
                        self.assertEqual('' if cells[column] is None else str(cells[column]), by_row[number][header])
        for row in self.original:
            if row['source_locator'].startswith('Research_Evidence!row'):
                self.assertEqual('NOT_CERTIFIED_OR_PROMOTED_NOT_OPERATIONAL_AUTHORITY', row['authority'])
        for row in self.trace:
            if row['source_locator'].startswith('Research_Evidence!row'):
                self.assertEqual('REQUIREMENTS_ONLY_NOT_EXECUTION_AUTHORIZATION', row['authority'])


if __name__ == '__main__':
    unittest.main()
