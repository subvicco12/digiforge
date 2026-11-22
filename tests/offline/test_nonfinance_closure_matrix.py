"""Coverage/provenance checks; does not certify implementation or owner approval."""
import csv
import importlib.util
import json
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'docs/engineering/original-source'
spec = importlib.util.spec_from_file_location('closure', ROOT / 'bin/build-nonfinance-closure-matrix.py')
closure = importlib.util.module_from_spec(spec)
spec.loader.exec_module(closure)
class NonfinanceClosureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rows, cls.inventory = closure.build()
    def test_checked_in_outputs_match_reproducible_build(self):
        with (SOURCE / 'nonfinance-closure-matrix.tsv').open(newline='') as stream:
            self.assertEqual(self.rows, list(csv.DictReader(stream, delimiter='\t')))
        self.assertEqual(self.inventory, json.loads((SOURCE / 'transformation-contract-inventory.json').read_text()))
    def test_all_anchors_and_physical_rows_preserve_exact_source_strings(self):
        anchors = closure.read_tsv('blueprint-acceptance.tsv')
        ledger = closure.read_tsv('workbook-reconciliation.tsv')
        self.assertEqual(1771, len(self.rows))
        self.assertEqual(1771, len({(r['source_kind'], r['source_locator']) for r in self.rows}))
        for old, new in zip(anchors, self.rows[:706]):
            self.assertEqual(old[closure.LOCATOR], new['source_locator'])
            self.assertEqual(old['Original source text'].encode(), new['original_text_or_row_json'].encode())
        for old, new in zip(ledger, self.rows[706:]):
            self.assertEqual(old['Complete original row values/formulas'].encode(), new['original_text_or_row_json'].encode())
        self.assertEqual(14, sum(len(v['formulas']) for v in self.inventory['source_inventory']['workbook']['sheets'].values()))
    def test_transformation_counts_and_original_identities_have_no_positive_certification(self):
        expected = {'PET_PORTRAIT': (79,83), 'WATERCOLOR': (38,43), 'LINE_ART': (12,8), 'VINTAGE_PORTRAIT': (0,0), 'AI_TRANSFORM': (11,15)}
        for engine, counts in expected.items():
            item = self.inventory['engines'][engine]
            self.assertEqual(counts, tuple(item['counts'][s] for s in ('Master_500_v2','Source_Migration_500')))
            self.assertFalse(item['sufficient_algorithm_or_style_contract_found'])
            self.assertEqual(0, item['approved_source_result_fixture_pairs_found'])
            for sheet, entries in item['rows'].items():
                for entry in entries:
                    row = next(r for r in self.rows if r['source_locator'] == entry['source_locator'])
                    self.assertEqual('BLOCKED_MISSING_TRANSFORMATION_CONTRACT', row['status'])
                    self.assertEqual(entry['original_values'], json.loads(row['original_text_or_row_json']))
                    if sheet == 'Source_Migration_500':
                        self.assertEqual(entry['original_values'][5], entry['migration_disposition'])
    def test_broad_associations_never_become_complete_acceptance(self):
        verified = [r for r in self.rows if r['status'] == 'VERIFIED_LOCAL_BOUNDARY']
        self.assertEqual({'word/document.xml/table14/r7/c1/p1','word/document.xml/table14/r7/c2/p1','word/document.xml/p233','word/document.xml/p234','word/document.xml/p229','word/document.xml/p236'}, {r['source_locator'] for r in verified})
        self.assertEqual('INCOMPLETE', self.inventory['full_product_acceptance'])
        self.assertFalse(self.inventory['source_document_authorization'])
        self.assertEqual(4, self.inventory['counts']['FINANCE_DEPENDENCY'])
        self.assertEqual(424, self.inventory['counts']['IMPLEMENTED_NOT_FULLY_VERIFIED'])
        for locator, method in [('word/document.xml/p233', 'testDigitalDesignReleaseToListingPreservesReviewedShopAndSandbox'), ('word/document.xml/p234', 'testListingAndReceivedOrderCannotCrossShopOwnership'), ('word/document.xml/p229', 'testDigitalDesignReleaseToListingPreservesReviewedShopAndSandbox'), ('word/document.xml/p236', 'testQueueDiagnosticFailureCannotClaimAvailableRecovery')]:
            row = next(r for r in verified if r['source_locator'] == locator)
            self.assertIn(method, row['exact_test_references'])
            self.assertNotEqual('No requirement-specific full acceptance established by associated references.', row['bounded_proof'])
        for row in self.rows:
            self.assertNotIn(row['status'], ('PASS','COMPLETE'))
            closure.validate_references(row['related_implementation_references'])
            closure.validate_references(row['exact_test_references'])
    def test_invalid_reference_or_missing_test_method_is_rejected(self):
        for ref in ('missing.php:1','includes/POD/DeterministicPersonalizationRenderer.php:999999','tests/wordpress/RenderArtifactCertificationTest.php:140::testInventedAcceptance'):
            with self.assertRaises((ValueError, FileNotFoundError)):
                closure.validate_references(ref)
if __name__ == '__main__':
    unittest.main()
