"""Real local font rendering and license-integrity checks; no provider certification."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
ASSETS = ROOT / 'assets/pod-designs/fc01-v2-text-only'


class FC01NotoGlyphTests(unittest.TestCase):
    def test_pinned_font_license_and_actual_glyph_rebuild(self):
        provenance = json.loads((ASSETS / 'font/provenance.json').read_text())
        self.assertEqual('2.007', provenance['version'])
        self.assertEqual('SIL-OFL-1.1', provenance['license'])
        for file, key in [('NotoSerif-Regular.ttf', 'font_sha256'), ('OFL.txt', 'license_sha256')]:
            self.assertEqual(provenance[key], hashlib.sha256((ASSETS / 'font' / file).read_bytes()).hexdigest())
        self.assertIn('SIL OPEN FONT LICENSE Version 1.1', (ASSETS / 'font/OFL.txt').read_text())
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'glyphs.json'
            result = subprocess.run([sys.executable, str(ROOT / 'bin/build-fc01-text-glyphs.py'), '--output', str(output)], capture_output=True, text=True, timeout=30)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual((ASSETS / 'glyphs.json').read_bytes(), output.read_bytes())

    def test_supported_glyphs_have_real_ink_and_no_fallback(self):
        atlas = json.loads((ASSETS / 'glyphs.json').read_text())
        for run in atlas['runs'].values():
            self.assertNotIn('😀', run['glyphs'])
            self.assertNotIn('\u0301', run['glyphs'])
            self.assertGreater(run['glyphs']['W']['advance'], run['glyphs']['i']['advance'])
            self.assertGreater(run['glyphs']['é']['height'], 0)
            self.assertIn('png', run['glyphs']['é'])
            self.assertEqual(0, run['glyphs'][' ']['height'])
