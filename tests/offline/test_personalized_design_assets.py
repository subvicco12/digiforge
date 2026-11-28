"""Supplied prototype integrity only; never certifies licensed/customer/supplier artwork."""
import csv
import hashlib
import json
import io
from pathlib import Path
import unittest


class PersonalizedDesignAssetsTest(unittest.TestCase):
    root = Path(__file__).resolve().parents[2] / 'assets/pod-designs/v1'

    def test_all_original_bundle_files_and_derived_components_are_pinned(self):
        registry = json.loads((self.root / 'registry.json').read_text())
        self.assertEqual(5, len(registry['archives']))
        self.assertFalse(registry['supplier_certified'])
        self.assertFalse(registry['commercial_certified'])
        for archive in registry['archives']:
            pack = archive['filename'].removeprefix('DigiForge_').removesuffix('_v1.zip')
            for filename, expected in archive['files'].items():
                self.assertEqual(expected, hashlib.sha256((self.root / 'sources' / pack / filename).read_bytes()).hexdigest())
        self.assertEqual(18, len(registry['components']))
        self.assertEqual(2, len(registry['decorations']))
        for component in list(registry['components'].values()) + list(registry['decorations'].values()):
            for path, digest in [('source', 'source_sha256'), ('raster', 'raster_sha256')]:
                self.assertEqual(component[digest], hashlib.sha256((self.root / component[path]).read_bytes()).hexdigest())

    def test_all_twenty_concepts_and_eight_repeat_layouts_keep_original_ids(self):
        families = json.loads((self.root / 'families.json').read_text())
        rows = list(csv.DictReader(io.StringIO((self.root / 'sources/20_Personalized_Template_Previews/preview_manifest.csv').read_text())))
        self.assertEqual(28, len(families))
        self.assertEqual(20, len(rows))
        for row in rows:
            family = families[row['concept_id']]
            self.assertEqual(row['component'].removesuffix('.svg'), family['component'])
            self.assertTrue(family['fields'])
            self.assertEqual('CONCEPT_ONLY', row['approval'])
        for number in range(1, 9):
            self.assertEqual(16, len(families[f'FR{number:02}']['positions']))
        self.assertEqual('TRANSFORMATION_REQUIRED', families['PT04']['status'])


if __name__ == '__main__':
    unittest.main()
