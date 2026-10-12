"""Prevent fabricated completion denominators and broken evidence provenance."""
import copy
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('final_matrix', ROOT / 'bin/check-final-acceptance-matrix.py')
matrix = importlib.util.module_from_spec(spec)
spec.loader.exec_module(matrix)


class FinalAcceptanceMatrixTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rows = matrix.read_rows(matrix.SOURCE / 'distinct-nonfinance-acceptance.tsv')
        cls.anchors = {row['Derived locator (not original requirement ID)']
                       for row in matrix.read_rows(matrix.SOURCE / 'blueprint-acceptance.tsv')}

    def test_source_record_count_cannot_become_requirement_denominator(self):
        report = matrix.report(self.rows, self.anchors, [], 1771)
        self.assertIsNone(report['mandatory_original_spec_denominator'])
        self.assertIsNone(report['mandatory_original_spec_completion_percent'])
        self.assertEqual('UNVERIFIED', report['mandatory_original_spec_status'])
        self.assertEqual('INCOMPLETE', report['full_product_acceptance'])
        self.assertLess(report['matrix_referenced_blueprint_anchors'], len(self.anchors))

    def test_unknown_source_locator_and_duplicate_identity_rejected(self):
        rows = copy.deepcopy(self.rows)
        rows[0]['source_locators'] = 'word/document.xml/nonexistent'
        with self.assertRaisesRegex(ValueError, 'Unknown source locator'):
            matrix.validate(rows, self.anchors)
        with self.assertRaisesRegex(ValueError, 'Duplicate'):
            matrix.validate(self.rows + [self.rows[0]], self.anchors)

    def test_implemented_claim_requires_actual_named_behavioral_evidence(self):
        rows = copy.deepcopy(self.rows)
        rows[0]['behavioral_evidence'] = rows[0]['behavioral_evidence'].split('::')[0] + '::testInventedPass'
        with self.assertRaisesRegex(ValueError, 'Invalid candidate reference'):
            matrix.validate(rows, self.anchors)

    def test_main_does_not_inherit_unmerged_candidate_evidence(self):
        # Model the boundary with real Git objects instead of depending on
        # whether hosted CI fetched the historical pinned-main commit.
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            subprocess.run(['git', 'init', '-q', str(root)], check=True, capture_output=True)
            evidence = root / 'Evidence.php'
            evidence.write_text('<?php\nfunction testBaseline() {}\n')
            subprocess.run(['git', 'add', 'Evidence.php'], cwd=root, check=True, capture_output=True)
            subprocess.run(['git', '-c', 'user.name=Acceptance Fixture',
                            '-c', 'user.email=fixture@example.invalid',
                            '-c', 'core.hooksPath=/dev/null', 'commit', '--no-gpg-sign',
                            '-qm', 'Baseline evidence'], cwd=root, check=True, capture_output=True)
            baseline = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
            evidence.write_text('<?php\nfunction testBaseline() {}\nfunction testCandidateOnly() {}\n')
            baseline_ref = 'Evidence.php:2::testBaseline'
            candidate_ref = 'Evidence.php:3::testCandidateOnly'
            self.assertTrue(matrix.reference_exists(root, baseline_ref, baseline))
            self.assertTrue(matrix.reference_exists(root, candidate_ref))
            self.assertFalse(matrix.reference_exists(root, candidate_ref, baseline))

    def test_bounded_qa_implementation_does_not_certify_original_spec_or_semantic_truth(self):
        row = next(row for row in self.rows if row['derived_requirement_id'] == 'NF-digital-qa-coverage')
        self.assertEqual('IMPLEMENTED', row['candidate_status'])
        self.assertIn('EXTERNAL', row['remaining_statuses'])
        self.assertIn('testEverySupportedLocalFormatPersistsActualQaProofWithoutSemanticAuthority', row['behavioral_evidence'])
        self.assertIn('testSemanticApprovalGateRequiresEveryNamedCheckRatherThanSevenArbitraryRows', row['behavioral_evidence'])
        report = matrix.report(self.rows, self.anchors, [], 1771)
        self.assertIsNone(report['mandatory_original_spec_completion_percent'])
        self.assertEqual('INCOMPLETE', report['full_product_acceptance'])
        linked = {locator for row in self.rows for locator in row['source_locators'].split('; ')}
        self.assertTrue({f'word/document.xml/p{number}' for number in range(226, 242)} <= linked)

    def test_unknown_obligation_without_proof_does_not_break_or_inflate_main_reference_report(self):
        rows = copy.deepcopy(self.rows)
        rows[0]['candidate_status'] = 'UNVERIFIED'
        rows[0]['behavioral_evidence'] = ''
        report = matrix.report(rows, self.anchors, [], 1771)
        self.assertLess(report['bounded_candidate_implemented'], report['bounded_local_denominator'])
        if report['main_git_evidence_status'] == 'AVAILABLE':
            self.assertLess(report['main_local_behavioral_references_present'], report['bounded_local_denominator'])

    def test_python_behavioral_identity_is_validated_and_invented_method_rejected(self):
        self.assertTrue(matrix.reference_exists(ROOT,
            'tests/offline/test_final_release_identity.py:13::test_dirty_version_pins_cannot_mislabel_committed_archive'))
        self.assertFalse(matrix.reference_exists(ROOT,
            'tests/offline/test_final_release_identity.py:13::testInventedReleasePass'))

    def test_missing_pinned_main_object_is_unavailable_not_absent_implementation(self):
        unavailable = '0000000000000000000000000000000000000000'
        report = matrix.report(self.rows, self.anchors, [], 1771, main=unavailable)
        self.assertEqual('UNAVAILABLE', report['main_git_evidence_status'])
        self.assertIsNone(report['main_local_implementation_references_present'])
        self.assertIsNone(report['main_local_behavioral_references_present'])
        with self.assertRaisesRegex(ValueError, 'Git evidence unavailable'):
            matrix.reference_exists(ROOT, self.rows[0]['implementation'], unavailable)


if __name__ == '__main__':
    unittest.main()
