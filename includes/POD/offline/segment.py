"""Bounded offline GrabCut worker. No files, network, models or automatic head detection."""
import base64
import json
import os
import sys

for key in ('OPENBLAS_NUM_THREADS', 'OMP_NUM_THREADS', 'MKL_NUM_THREADS'):
    os.environ[key] = '1'

VERSION = 'selected-grabcut-v1'
MAX_PIXELS = 1048576


def integer(value):
    if type(value) is not int:
        raise ValueError('Exact integer required')
    return value


def selection_contract(value, width, height):
    if not isinstance(value, dict) or set(value) != {'subjects', 'selected', 'foreground_points'}:
        raise ValueError('Exact subject selection required')
    subjects = value['subjects']
    if not isinstance(subjects, list) or not 1 <= len(subjects) <= 8:
        raise ValueError('One to eight explicit subject regions required')
    for region in subjects:
        if not isinstance(region, dict) or set(region) != {'x', 'y', 'width', 'height'}:
            raise ValueError('Exact subject rectangle required')
        x,y,w,h = (integer(region[k]) for k in ('x','y','width','height'))
        if x < 1 or y < 1 or w < 3 or h < 3 or x+w >= width or y+h >= height:
            raise ValueError('Subject region needs transparent canvas padding')
    selected = integer(value['selected'])
    if selected < 0 or selected >= len(subjects):
        raise ValueError('Unknown subject selected')
    region = subjects[selected]
    points = value['foreground_points']
    if not isinstance(points, list) or not 1 <= len(points) <= 16:
        raise ValueError('Explicit face-preservation seeds required')
    for point in points:
        if not isinstance(point, dict) or set(point) != {'x', 'y'}:
            raise ValueError('Exact foreground point required')
        x,y = integer(point['x']),integer(point['y'])
        if not region['x'] < x < region['x']+region['width']-1 or not region['y'] < y < region['y']+region['height']-1:
            raise ValueError('Preservation seed must be inside selected subject')
    return region, points


def segment(payload):
    import cv2
    import numpy as np
    if cv2.__version__ != '4.12.0' or np.__version__ != '2.2.6':
        raise ValueError('Approved offline dependency versions unavailable')
    cv2.setNumThreads(1)
    cv2.setRNGSeed(0)
    cv2.ocl.setUseOpenCL(False)
    if not isinstance(payload,dict) or set(payload) != {'rgba','width','height','selection'}:
        raise ValueError('Exact pixel request required')
    width,height=integer(payload['width']),integer(payload['height'])
    if not 8 <= width <= 10000 or not 8 <= height <= 10000 or width*height > MAX_PIXELS:
        raise ValueError('Bounded image required')
    region,points=selection_contract(payload['selection'],width,height)
    if not isinstance(payload['rgba'],str) or len(payload['rgba']) > 5592408:
        raise ValueError('Bounded canonical RGBA required')
    raw=base64.b64decode(payload['rgba'],validate=True)
    if len(raw) != width*height*4 or base64.b64encode(raw).decode() != payload['rgba']:
        raise ValueError('Complete canonical RGBA required')
    pixels=np.frombuffer(raw,dtype=np.uint8).reshape(height,width,4)
    if not np.all(pixels[:,:,3] == 255):
        raise ValueError('Already-transparent input requires reviewed alpha path')
    rgb=np.ascontiguousarray(pixels[:,:,:3])
    labels=np.zeros((height,width),dtype=np.uint8)
    x,y,w,h=(region[k] for k in ('x','y','width','height'))
    labels[y:y+h,x:x+w]=cv2.GC_PR_FGD
    for point in points:
        labels[point['y'],point['x']]=cv2.GC_FGD
    cv2.grabCut(rgb,labels,None,np.zeros((1,65),np.float64),np.zeros((1,65),np.float64),5,cv2.GC_INIT_WITH_MASK)
    foreground=(labels==cv2.GC_FGD)|(labels==cv2.GC_PR_FGD)
    # Retain only components containing explicitly selected foreground seeds.
    count,components=cv2.connectedComponents(foreground.astype(np.uint8),connectivity=8)
    selected_components={int(components[p['y'],p['x']]) for p in points}
    if 0 in selected_components:
        raise ValueError('Selected face seed lost')
    foreground=np.isin(components,list(selected_components))
    total=int(np.count_nonzero(foreground))
    if total <= len(points) or total >= w*h:
        raise ValueError('Nontrivial segmentation unavailable; manual fallback required')
    alpha=(foreground.astype(np.uint8)*255).tobytes()
    clipping=bool(foreground[y,x:x+w].any() or foreground[y+h-1,x:x+w].any() or foreground[y:y+h,x].any() or foreground[y:y+h,x+w-1].any())
    return dict(version=VERSION,backend='opencv-grabcut-4.12.0/numpy-2.2.6',alpha=base64.b64encode(alpha).decode(),foreground_pixels=total,components_retained=len(selected_components),selection_boundary_contact=clipping,semantic_head_certified=False,review_required=True,external_execution_authorized=False)


def normalize_photo(payload):
    import hashlib
    import io
    import warnings
    import PIL
    from PIL import Image, ImageOps
    if PIL.__version__ != '13.0.0' or set(payload) != {'kind','image_base64'} or payload['kind'] != 'normalize_photo':
        raise ValueError('Exact offline normalization contract required')
    encoded=payload['image_base64']
    if not isinstance(encoded,str) or len(encoded)>6000000:
        raise ValueError('Bounded photo bytes required')
    raw=base64.b64decode(encoded,validate=True)
    if len(raw)<1 or len(raw)>4500000 or base64.b64encode(raw).decode()!=encoded:
        raise ValueError('Canonical photo bytes required')
    Image.MAX_IMAGE_PIXELS=MAX_PIXELS
    with warnings.catch_warnings():
        warnings.simplefilter('error')
        with Image.open(io.BytesIO(raw), formats=['PNG','JPEG']) as image:
            if image.width<8 or image.height<8 or image.width*image.height>MAX_PIXELS or getattr(image,'n_frames',1)!=1 or image.mode not in ('RGB','RGBA','L','LA'):
                raise ValueError('Unsupported image geometry, animation or color mode')
            if image.info.get('icc_profile'):
                raise ValueError('Color-managed photo normalization requires a reviewed adapter')
            orientation=image.getexif().get(274,1)
            if type(orientation) is not int or orientation not in range(1,9):
                raise ValueError('Unknown orientation cannot be guessed')
            image.load()
            pixels=ImageOps.exif_transpose(image).convert('RGBA')
            rgba=pixels.tobytes()
            return dict(normalizer_version='pillow-13.0.0/exif-transpose-v1',input_sha256=hashlib.sha256(raw).hexdigest(),input_bytes=len(raw),input_format=image.format,orientation_applied=orientation,width_px=pixels.width,height_px=pixels.height,normalized_pixel_sha256=hashlib.sha256(rgba).hexdigest(),metadata_stripped=True,rgba=base64.b64encode(rgba).decode())


def main():
    import resource
    resource.setrlimit(resource.RLIMIT_CPU,(12,12))
    resource.setrlimit(resource.RLIMIT_AS,(2147483648,2147483648))
    resource.setrlimit(resource.RLIMIT_FSIZE,(0,0))
    resource.setrlimit(resource.RLIMIT_CORE,(0,0))
    raw=sys.stdin.buffer.read(6004097)
    if len(raw)>6004096:
        raise ValueError('Request exceeds work bound')
    payload=json.loads(raw)
    result=normalize_photo(payload) if isinstance(payload,dict) and payload.get('kind')=='normalize_photo' else segment(payload)
    sys.stdout.write(json.dumps(result,sort_keys=True,separators=(',',':')))

if __name__ == '__main__':
    try:
        main()
    except Exception:
        # No customer pixels/paths/options or traceback in logs.
        sys.stderr.write('Offline segmentation unavailable or invalid; reviewed alpha fallback required.\n')
        sys.exit(1)
