"""Offline reconciliation never certifies products or installation evidence."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'bin/prepare-owner-pod-acceptance.py'


class OwnerPodAcceptanceReviewTests(unittest.TestCase):
    def module(self):
        self.assertTrue(SCRIPT.is_file(), 'Acceptance reconciliation tool missing')
        spec = importlib.util.spec_from_file_location('owner_pod_review', SCRIPT)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        return mod

    def test_reuses_exact_twenty_waves_and_all_twenty_two_unresolved_criteria(self):
        m = self.module()
        result = m.prepare()
        self.assertEqual(20, len(result['waves']))
        self.assertEqual(500, sum(len(w['products']) for w in result['waves']))
        self.assertEqual(22, len(result['unresolved_nonfinance']))
        self.assertEqual(22, len({r['id'] for r in result['unresolved_nonfinance']}))
        self.assertTrue(all(r['status'] == 'UNVERIFIED' for r in result['unresolved_nonfinance']))
        self.assertFalse(result['deploy_authorized'])
        self.assertFalse(result['publish_authorized'])
        self.assertEqual(result['receipt_sha256'], m.receipt_digest(result))
        altered = copy.deepcopy(result)
        altered['unresolved_nonfinance'][0]['status'] = 'IMPLEMENTED'
        self.assertNotEqual(result['receipt_sha256'], m.receipt_digest(altered))

    def test_selected_product_is_exact_source_identity_and_supplier_gaps_stay_unverified(self):
        result = self.module().prepare()
        product = result['selected_product']
        self.assertEqual('DG2-438', product['source']['v2_id'])
        self.assertEqual('NAME_MONOGRAM', product['source']['engine'])
        self.assertEqual('REVIEW_REQUIRED', product['source']['launch_status'])
        self.assertEqual(18, product['wave'])
        self.assertEqual('ATTRIBUTED_HISTORICAL_UI_REPORT', product['supplier']['identity_evidence'])
        self.assertIsNone(product['supplier']['blueprint_id'])
        self.assertIsNone(product['supplier']['provider_id'])
        self.assertFalse(product['source_to_supplier_binding_verified'])
        self.assertTrue(all(v == 'UNVERIFIED' for v in product['production_requirements'].values()))
        self.assertFalse(product['production_authorized'])

    def test_unknown_product_or_tampered_source_does_not_rebind_evidence(self):
        m = self.module()
        with self.assertRaises(ValueError):
            m.prepare(product_id='DG2-276')
        with self.assertRaises(ValueError):
            m.prepare(source_bytes=b'not the pinned workbook extraction')


if __name__ == '__main__':
    unittest.main()
