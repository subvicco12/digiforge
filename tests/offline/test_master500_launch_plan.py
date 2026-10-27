import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class Master500LaunchPlanTest(unittest.TestCase):
    def load(self):
        path = ROOT / 'bin/prepare-master500-launch.py'
        self.assertTrue(path.is_file(), 'Offline launch-preparation workflow missing')
        spec = importlib.util.spec_from_file_location('launch_plan', path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_real_source_has_exact_five_hundred_identities_and_twenty_review_waves(self):
        m = self.load()
        result = m.prepare()
        self.assertEqual(500, result['product_count'])
        self.assertEqual(20, len(result['waves']))
        products = [p for wave in result['waves'] for p in wave['products']]
        self.assertEqual(500, len({p['v2_id'] for p in products}))
        self.assertEqual(72, sum(not p['source_dg_id'] for p in products))
        self.assertEqual(result, m.prepare())
        self.assertFalse(result['external_execution_authorized'])
        self.assertEqual(['digital', 'personalized_pod'], result['operational_shop_scopes'])
        for p in products:
            self.assertEqual('personalized_pod', p['shop'])
            self.assertEqual('REVIEW_REQUIRED', p['launch_status'])
            self.assertFalse(p['publish_authorized'])
            self.assertEqual(8, len(p['required_evidence']))

    def test_changed_source_bytes_cannot_be_rebound_to_original_workbook(self):
        m = self.load()
        raw = m.SOURCE.read_bytes()
        for changed in [raw + b'\n', raw.replace(b'DG2-001', b'DG2-002', 1), raw.replace(b'personalized', b'other') + b'\x00']:
            with self.assertRaises(ValueError):
                m.prepare(changed)

    def test_source_provenance_and_content_digest_cover_every_wave(self):
        m = self.load()
        plan = m.prepare()
        self.assertEqual(64, len(plan['plan_sha256']))
        self.assertEqual('56639c9122985ac17b46685bb8b91e5d29f387f16fff715b3488e9b4c2380f6d', plan['source_workbook_sha256'])
        plan['waves'][0]['products'][0]['concept'] = 'changed'
        self.assertNotEqual(plan['plan_sha256'], m.plan_digest(plan))


if __name__ == '__main__':
    unittest.main()
