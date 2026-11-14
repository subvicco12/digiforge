"""Synthetic production-resolution compilation, never supplier certification."""
import base64
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import unittest
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
PATH = ROOT / 'includes/POD/offline/fc01_artifact.py'
spec = importlib.util.spec_from_file_location('fc01_artifact', PATH)
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)
FIXTURES = ROOT / 'tests/offline/fixtures/fc01-production'


class FC01ProductionArtifactTests(unittest.TestCase):
    def packet(self):
        return json.loads((FIXTURES / 'synthetic-request.json').read_text())

    def sign(self, packet):
        packet.pop('request_sha256', None)
        packet['request_sha256'] = worker.digest(worker.canonical(packet))
        return packet

    def payload(self, packet, fields):
        packet['personalization'] = fields
        packet['input_sha256'] = worker.digest(worker.encoded(fields))
        packet['text_runs'] = {'header': 'OUR FAMILY', 'family_name': fields['family_name']}
        if fields.get('established', '').strip():
            packet['text_runs']['established'] = 'ESTABLISHED '+fields['established']
        return self.sign(packet)

    def test_actual_fresh_font_png_dimensions_dpi_transparency_and_determinism(self):
        before = {p: worker.digest(p.read_bytes()) for p in (ROOT / 'assets/pod-designs/fc01-v2-text-only').rglob('*') if p.is_file()}
        p = self.packet()
        a = worker.compile_artifact(p)
        self.assertEqual(a, worker.compile_artifact(p))
        png = base64.b64decode(a['png_base64'])
        self.assertEqual(a['png_sha256'], hashlib.sha256(png).hexdigest())
        image = Image.open(io.BytesIO(png))
        self.assertEqual((1800, 1400), image.size)
        self.assertAlmostEqual(300, image.info['dpi'][0], places=1)
        self.assertEqual(0, image.getpixel((0, 0))[3])
        self.assertIsNotNone(image.getbbox())
        self.assertFalse(a['production_authorized'])
        self.assertEqual('IDENTICAL_PNG_BYTES', a['preview_final_parity'])
        self.assertEqual(before, {p: worker.digest(p.read_bytes()) for p in before})
        for box in a['ink_bounds'].values():
            self.assertTrue(worker.inside(box, p['geometry_qa']['safe_zone']))

    def test_committed_synthetic_sample_is_exactly_reproducible(self):
        result = worker.compile_artifact(self.packet())
        png = base64.b64decode(result.pop('png_base64'))
        self.assertEqual((FIXTURES / 'synthetic-preview.png').read_bytes(), png)
        self.assertEqual((FIXTURES / 'synthetic-final.png').read_bytes(), png)
        self.assertEqual(json.loads((FIXTURES / 'receipt.json').read_text()), result)

    def test_optional_absent_blank_and_exact_unicode_payload(self):
        for fields in [{'family_name': '  Éva & Noël  '}, {'family_name': 'Éva', 'established': ' \t\n'}, {'family_name': 'Éva', 'established': '　'}, {'family_name': 'Éva', 'established': '\u2028\u2029'}]:
            packet = self.payload(self.packet(), fields)
            output = worker.compile_artifact(packet)
            self.assertNotIn('established', output['ink_bounds'])
            self.assertEqual(fields, packet['personalization'])
        packet = self.payload(self.packet(), {'family_name': 'Éva', 'established': ' 2016 '})
        self.assertIn('established', worker.compile_artifact(packet)['ink_bounds'])

    def test_long_names_unsupported_glyphs_controls_and_49_points_fail_closed(self):
        for text in ['W'*48, 'i'*49, '😀', 'e\u0301', '李', '', '  ', 'A\nB']:
            with self.subTest(text=text), self.assertRaises(ValueError):
                worker.compile_artifact(self.payload(self.packet(), {'family_name': text}))
        self.assertIn('family_name', worker.compile_artifact(self.payload(self.packet(), {'family_name': 'i'*48}))['ink_bounds'])

    def test_scope_expiry_missing_geometry_changed_identity_and_claims_reject(self):
        for field, value in [('shop_key', 'digital'), ('production_authorized', True), ('export_policy', 'AUTO_FIT'), ('schema', 'v0')]:
            packet = self.packet()
            packet[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                worker.compile_artifact(self.sign(packet))
        for modify in [lambda p: p['review'].update(expires_at=1), lambda p: p['identity'].update(font_sha256='0'*64), lambda p: p['area'].update(width_px=25000), lambda p: p['area'].update(dpi=0), lambda p: p['geometry_qa'].update(bleed_state='UNKNOWN'), lambda p: p['area'].update(decoration_method='embroidery')]:
            packet = self.packet()
            modify(packet)
            with self.assertRaises(ValueError):
                worker.compile_artifact(self.sign(packet))
        packet = self.packet()
        packet['area']['width_px'] += 1  # No re-sign: any source change invalidates exact request.
        with self.assertRaises(ValueError):
            worker.compile_artifact(packet)

    def test_seam_collision_and_physical_overflow_fail_without_auto_shrink(self):
        packet = self.packet()
        seam = {'x': 0, 'y': 0, 'width': 1800, 'height': 1400}
        packet['area']['qa_geometry']['seam_exclusions'] = [seam]
        packet['geometry_qa']['seam_exclusions'] = [seam]
        with self.assertRaises(ValueError):
            worker.compile_artifact(self.sign(packet))
        packet = self.packet()
        packet['regions']['family_name']['width'] = 1  # Caller cannot replace pinned layout.
        with self.assertRaises(ValueError):
            worker.compile_artifact(self.sign(packet))

    def test_bounded_cli_real_process_and_duplicate_keys_reject(self):
        result = subprocess.run([sys.executable, '-B', str(PATH)], input=json.dumps(self.packet()).encode(), capture_output=True, timeout=30)
        self.assertEqual(0, result.returncode, result.stdout)
        self.assertEqual(worker.compile_artifact(self.packet()), json.loads(result.stdout))
        for raw in [b'{"schema":"one","schema":"two"}', b'x'*131073, b'{not-json}']:
            result = subprocess.run([sys.executable, '-B', str(PATH)], input=raw, capture_output=True, timeout=30)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual({'error': 'fc01_offline_artifact_blocked'}, json.loads(result.stdout))
            self.assertNotIn('family_name', result.stderr.decode())
