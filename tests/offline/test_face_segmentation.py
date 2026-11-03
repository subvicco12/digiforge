import base64
import hashlib
import json
import pathlib
import subprocess
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKER = ROOT / 'includes/POD/offline/segment.py'

class OfflineFaceSegmentationTest(unittest.TestCase):
    def run_worker(self, rgba, width, height, selection):
        payload = dict(rgba=base64.b64encode(rgba).decode(), width=width, height=height, selection=selection)
        result = subprocess.run([sys.executable, str(WORKER)], input=json.dumps(payload), capture_output=True, text=True, timeout=20)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_real_pixels_segment_selected_subject_and_reproduce_without_weights(self):
        w = h = 64
        pixels = bytearray(b'\xf0\xf0\xf0\xff' * (w*h))
        for y in range(18, 45):
            for x in range(18, 45):
                if (x-31)**2 + (y-31)**2 < 13**2:
                    pixels[(y*w+x)*4:(y*w+x)*4+4] = b'\x80\x30\x10\xff'
        selection = dict(subjects=[dict(x=8, y=8, width=48, height=48)], selected=0, foreground_points=[dict(x=31,y=31)])
        first = self.run_worker(bytes(pixels),w,h,selection)
        self.assertEqual(first,self.run_worker(bytes(pixels),w,h,selection))
        alpha = base64.b64decode(first['alpha'])
        self.assertEqual(255,alpha[31*w+31])
        self.assertEqual(0,alpha[0])
        self.assertEqual(0,alpha[10*w+10])
        self.assertGreater(first['foreground_pixels'],100)
        self.assertFalse(first['semantic_head_certified'])

    def test_rights_cleared_real_human_and_cat_photos_exercise_actual_segmentation(self):
        from PIL import Image
        manifest = json.loads((ROOT/'tests/fixtures/segmentation/manifest.json').read_text())
        for fixture in manifest['fixtures']:
            with self.subTest(fixture=fixture['id']):
                path=ROOT/'tests/fixtures/segmentation'/fixture['file']
                self.assertEqual(fixture['sha256'],hashlib.sha256(path.read_bytes()).hexdigest())
                rights=ROOT/'tests/fixtures/segmentation'/fixture['rights_evidence']
                self.assertEqual(fixture['rights_evidence_sha256'],hashlib.sha256(rights.read_bytes()).hexdigest())
                image=Image.open(path).convert('RGBA')
                result=self.run_worker(image.tobytes(),*image.size,fixture['selection'])
                alpha=base64.b64decode(result['alpha'])
                self.assertGreater(result['foreground_pixels'],100)
                self.assertLess(result['foreground_pixels'],image.width*image.height)
                self.assertEqual(0,alpha[0])
                for p in fixture['selection']['foreground_points']:
                    self.assertEqual(255,alpha[p['y']*image.width+p['x']])
                self.assertFalse(result['semantic_head_certified'])

    def test_multiple_subjects_select_only_requested_component(self):
        w,h=96,64
        pixels=bytearray(b'\xf0\xf0\xf0\xff'*(w*h))
        for cx,color in [(24,b'\x80\x30\x10\xff'),(72,b'\x10\x30\x80\xff')]:
            for y in range(20,44):
                for x in range(cx-10,cx+10):
                    pixels[(y*w+x)*4:(y*w+x)*4+4]=color
        regions=[dict(x=8,y=8,width=32,height=48),dict(x=56,y=8,width=32,height=48)]
        for selected,cx,other in [(0,24,72),(1,72,24)]:
            result=self.run_worker(bytes(pixels),w,h,dict(subjects=regions,selected=selected,foreground_points=[dict(x=cx,y=31)]))
            alpha=base64.b64decode(result['alpha'])
            self.assertEqual(255,alpha[31*w+cx])
            self.assertEqual(0,alpha[31*w+other])
            self.assertFalse(result['selection_boundary_contact'])

    def test_jpeg_orientation_is_applied_and_metadata_stripped(self):
        from PIL import Image, ImageOps
        import io
        image=Image.new('RGB',(24,16),(120,30,10))
        exif=Image.Exif();exif[274]=6;exif[270]='Private source metadata'
        buffer=io.BytesIO();image.save(buffer,format='JPEG',exif=exif)
        raw=buffer.getvalue()
        payload=dict(kind='normalize_photo',image_base64=base64.b64encode(raw).decode())
        result=subprocess.run([sys.executable,str(WORKER)],input=json.dumps(payload),capture_output=True,text=True,timeout=20)
        self.assertEqual(0,result.returncode,result.stderr)
        output=json.loads(result.stdout)
        self.assertEqual('pillow-13.0.0/exif-transpose-v1',output['normalizer_version'])
        self.assertEqual((16,24),(output['width_px'],output['height_px']))
        self.assertEqual(6,output['orientation_applied'])
        self.assertTrue(output['metadata_stripped'])
        self.assertEqual(hashlib.sha256(raw).hexdigest(),output['input_sha256'])
        expected=ImageOps.exif_transpose(Image.open(io.BytesIO(raw))).convert('RGBA').tobytes()
        self.assertEqual(expected,base64.b64decode(output['rgba']))
        self.assertNotIn('Private source metadata',result.stdout)

    def test_normalization_rejects_animation_profiles_unknown_orientation_and_nonimages(self):
        from PIL import Image
        import io
        cases=[b'not an image']
        image=Image.new('RGB',(16,16),(120,30,10))
        for kind in ['icc','orientation','animation','unsupported']:
            buffer=io.BytesIO()
            if kind=='icc':image.save(buffer,format='PNG',icc_profile=b'unreviewed profile')
            elif kind=='orientation':
                exif=Image.Exif();exif[274]=9;image.save(buffer,format='JPEG',exif=exif)
            elif kind=='animation':image.save(buffer,format='PNG',save_all=True,append_images=[Image.new('RGB',(16,16),(10,10,10))])
            else:image.save(buffer,format='GIF')
            cases.append(buffer.getvalue())
        for raw in cases:
            with self.subTest(input_sha=hashlib.sha256(raw).hexdigest()):
                result=subprocess.run([sys.executable,str(WORKER)],input=json.dumps(dict(kind='normalize_photo',image_base64=base64.b64encode(raw).decode())),capture_output=True,text=True,timeout=20)
                self.assertNotEqual(0,result.returncode)
                self.assertEqual('',result.stdout)
                self.assertNotIn('Traceback',result.stderr)

    def test_invalid_selection_and_unsupported_alpha_fail_closed(self):
        payload=dict(width=8,height=8,rgba=base64.b64encode(b'\xff'*256).decode(),selection=dict(subjects=[dict(x=0,y=0,width=8,height=8)],selected=0,foreground_points=[]))
        result=subprocess.run([sys.executable,str(WORKER)],input=json.dumps(payload),capture_output=True,text=True,timeout=20)
        self.assertNotEqual(0,result.returncode)
        self.assertEqual('',result.stdout)

    def test_fresh_normalization_rejects_retired_pillow_runtime(self):
        import importlib.util
        import PIL
        from PIL import Image, ImageOps  # Load the actual compiled runtime before simulating a retired version.
        from unittest.mock import patch
        self.assertEqual('13.0.0', PIL.__version__)
        spec=importlib.util.spec_from_file_location('synthetic_normalizer_security_check',WORKER)
        worker=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(worker)
        with patch.object(PIL,'__version__','12.3.0'):
            with self.assertRaisesRegex(ValueError,'Exact offline normalization contract required'):
                worker.normalize_photo(dict(kind='normalize_photo',image_base64='unused'))

if __name__ == '__main__':
    unittest.main()
