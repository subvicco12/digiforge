"""Offline reconciliation never certifies products or installation evidence."""
import copy
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
import json

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
        self.assertEqual('personalized_pod', product['supplier']['shop_key'])
        self.assertEqual('production', product['supplier']['environment'])
        self.assertEqual('28930593', product['supplier']['printify_store_id'])
        self.assertEqual('6ad647d24f4a65e4300211e5', product['supplier']['product_id'])
        self.assertEqual('8a2666c5-d3db-4b44-bf6a-4c5b4213815e', product['supplier']['source_listing_id'])
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

    def test_foreign_ambiguous_or_changed_supplier_receipt_cannot_rebind_shortlist(self):
        m = self.module()
        target = ROOT / 'tests/fixtures/printify/source-six-group-review-20261020.json'
        original = json.loads(target.read_text(encoding='utf-8'))
        variants = []
        for key, value in [('shop_key', 'digital'), ('environment', 'staging'),
                           ('printify_store_id', 'foreign'), ('source_listing_id', 'foreign')]:
            changed = copy.deepcopy(original)
            changed['source_snapshot'][key] = value
            variants.append(changed)
        changed = copy.deepcopy(original)
        sweatshirt = next(p for p in changed['source_snapshot']['products'] if p['label'] == 'sweatshirt')
        sweatshirt['product_id'] = 'foreign-product'
        variants.append(changed)
        changed = copy.deepcopy(original)
        changed['source_snapshot']['products'].insert(0, copy.deepcopy(sweatshirt))
        variants.append(changed)
        changed = copy.deepcopy(original)
        changed['report_receipts'][0]['body'] += ' changed observation'
        variants.append(changed)
        read_bytes, read_text = Path.read_bytes, Path.read_text
        for index, changed in enumerate(variants):
            raw = json.dumps(changed).encode()
            with self.subTest(index=index), \
                 patch.object(Path, 'read_bytes', lambda p: raw if p == target else read_bytes(p)), \
                 patch.object(Path, 'read_text', lambda p, *a, **k: raw.decode() if p == target else read_text(p, *a, **k)):
                with self.assertRaises(ValueError):
                    m.prepare()


if __name__ == '__main__':
    unittest.main()
