"""Experimental fixed offline worker. Operator review is mandatory, never certification."""
import os
for key in ('OMP_NUM_THREADS','MKL_NUM_THREADS','OPENBLAS_NUM_THREADS','NUMBA_NUM_THREADS'):
    os.environ[key]='1'
os.environ['NUMBA_CACHE_DIR']='/cache'
import sys,json,base64,hashlib,resource,pathlib
from segment import selection_contract
# Only SAM/segment source is hash attested. Installed dependency bytecode is part of the trusted pinned venv.
sys.pycache_prefix=None
VERSION='sam2-small-cf8-experimental-v1'
MODEL_SHA='6d1aa6f30de5c92224f8172114de081d104bbd23dd9dc5c58996f0cad5dc4d38'
VERSIONS={'antlr4-python3-runtime': '4.9.3', 'filelock': '4.2.1', 'fsspec': '2026.7.0', 'hydra-core': '1.3.8', 'iopath': '0.1.10', 'Jinja2': '3.1.6', 'llvmlite': '0.50.0', 'MarkupSafe': '3.0.3', 'mpmath': '1.3.0', 'networkx': '3.6.1', 'numba': '0.68.0', 'numpy': '2.2.6', 'omegaconf': '2.3.1', 'packaging': '26.3', 'pillow': '13.0.0', 'portalocker': '4.4.0', 'PyMatting': '1.1.16', 'PyYAML': '6.0.3', 'scipy': '1.18.1', 'setuptools': '84.1.0', 'sympy': '1.14.0', 'torch': '2.14.1+cpu', 'torchvision': '0.29.1+cpu', 'tqdm': '4.67.1', 'typing_extensions': '4.16.0'}

def decode_request(p):
    if not isinstance(p,dict) or set(p)!={'width','height','rgba','selection'}:
        raise ValueError('Exact experimental request required')
    w,h=p['width'],p['height']
    if type(w) is not int or type(h) is not int or not 8<=w<=10000 or not 8<=h<=10000 or w*h>1048576:
        raise ValueError('Bounded dimensions required')
    s=p['selection']
    if not isinstance(s,dict) or s.get('trimap_width')!=8 or type(s.get('trimap_width')) is not int:
        raise ValueError('Only the tested experimental 8-pixel trimap is supported')
    region,points=selection_contract({k:v for k,v in s.items() if k!='trimap_width'},w,h)
    encoded=p['rgba']
    if not isinstance(encoded,str) or len(encoded)>5592408:raise ValueError('Bounded pixels required')
    raw=base64.b64decode(encoded,validate=True)
    if len(raw)!=w*h*4 or base64.b64encode(raw).decode()!=encoded or any(a!=255 for a in raw[3::4]):
        raise ValueError('Opaque canonical source required')
    return w,h,region,points,raw

def validate_source(root):
    manifest=json.loads(pathlib.Path(__file__).with_name('sam-source-manifest.json').read_text())
    package=pathlib.Path(root)/'sam2'
    files={str(p.relative_to(package)) for p in package.rglob('*') if p.is_file() and (p.suffix in ('.py','.yaml','.so') or (p.suffix=='.pyc' and p.parent.name!='__pycache__'))}
    if files!=set(manifest['files']):raise ValueError('Pinned SAM source required')
    for rel,digest in manifest['files'].items():
        p=package/rel
        if p.is_symlink():
            if str(p.readlink())!=manifest['symlinks'].get(rel) or not p.resolve().is_relative_to(package.resolve()):raise ValueError('SAM source alias changed')
        elif rel in manifest['symlinks']:raise ValueError('SAM source alias missing')
        if hashlib.sha256(p.read_bytes()).hexdigest()!=digest:raise ValueError('SAM source changed')

def install_source_loader(root):
    """Compile only hash-verified SAM source; never read its preexisting bytecode."""
    import importlib.abc,importlib.machinery
    package=(pathlib.Path(root)/'sam2').resolve()
    manifest=json.loads(pathlib.Path(__file__).with_name('sam-source-manifest.json').read_text())
    if any(name=='sam2' or name.startswith('sam2.') for name in sys.modules):raise ImportError('Preloaded SAM modules are not trusted')
    approved_dirs={package}
    for relative in manifest['files']:
        approved_dirs.update(p for p in (package/relative).parents if p.is_relative_to(package))
    class VerifiedLoader(importlib.machinery.SourceFileLoader):
        def get_code(self,fullname):
            path=pathlib.Path(self.path)
            if not path.resolve().is_relative_to(package):raise ImportError('SAM source escaped contract')
            relative=str(path.relative_to(package))
            source=self.get_data(self.path)
            if relative not in manifest['files'] or hashlib.sha256(source).hexdigest()!=manifest['files'][relative]:raise ImportError('SAM source changed before import')
            return self.source_to_code(source,self.path)
    class VerifiedFinder(importlib.abc.MetaPathFinder):
        def find_spec(self,fullname,path=None,target=None):
            if fullname!='sam2' and not fullname.startswith('sam2.'):return None
            spec=importlib.machinery.PathFinder.find_spec(fullname,path)
            if spec is None:raise ImportError('SAM module absent from source contract')
            if spec.loader is None:
                if not spec.submodule_search_locations or any(pathlib.Path(p).resolve() not in approved_dirs for p in spec.submodule_search_locations):raise ImportError('SAM namespace escaped contract')
            elif not isinstance(spec.loader,importlib.machinery.SourceFileLoader) or not spec.origin or not spec.origin.endswith('.py'):
                raise ImportError('SAM requires verified source, not bytecode or native modules')
            else:spec.loader=VerifiedLoader(fullname,spec.origin)
            return spec
    sys.meta_path.insert(0,VerifiedFinder())

def infer(payload):
    w,h,region,points,raw=decode_request(payload)
    validate_source(os.environ['DF_SAM_SOURCE'])
    checkpoint=pathlib.Path(os.environ['DF_SAM_MODEL'])
    with checkpoint.open('rb') as f:
        if hashlib.file_digest(f,'sha256').hexdigest()!=MODEL_SHA:raise ValueError('Pinned weights required')
    import importlib.metadata
    for package,version in VERSIONS.items():
        if importlib.metadata.version(package)!=version:raise ValueError('Pinned dependency versions required')
    import numpy as np
    import torch
    from scipy.ndimage import label,distance_transform_edt
    from pymatting import estimate_alpha_cf,estimate_foreground_ml,ichol
    sys.path.insert(0,os.environ['DF_SAM_SOURCE'])
    install_source_loader(os.environ['DF_SAM_SOURCE'])
    from sam2.build_sam import build_sam2
    from sam2.sam2_image_predictor import SAM2ImagePredictor
    torch.set_num_threads(1);torch.set_num_interop_threads(1);torch.manual_seed(0);torch.use_deterministic_algorithms(True)
    rgb=np.frombuffer(raw,np.uint8).reshape(h,w,4)[:,:,:3].copy()
    model=build_sam2('configs/sam2.1/sam2.1_hiera_s.yaml',str(checkpoint),device='cpu',apply_postprocessing=False)
    predictor=SAM2ImagePredictor(model)
    x,y,rw,rh=(region[k] for k in ('x','y','width','height'))
    coords=np.array([[p['x'],p['y']] for p in points],np.float32)
    with torch.inference_mode():
        predictor.set_image(rgb)
        masks,scores,_=predictor.predict(point_coords=coords,point_labels=np.ones(len(points),np.int32),box=np.array([x,y,x+rw,y+rh],np.float32),multimask_output=True)
    candidates=[i for i,m in enumerate(masks) if all(m[p['y'],p['x']] for p in points)]
    if not candidates:raise ValueError('Critical preservation seed lost')
    best=max(candidates,key=lambda i:float(scores[i]))
    components,_=label(masks[best],structure=np.ones((3,3),int))
    selected={int(components[p['y'],p['x']]) for p in points}
    if 0 in selected:raise ValueError('Critical seed lost')
    hard=np.isin(components,list(selected))
    # Subject rectangle is a safety boundary, never silently clips predicted pixels.
    allowed=np.zeros((h,w),bool);allowed[y+1:y+rh-1,x+1:x+rw-1]=True
    if np.any(hard&~allowed):raise ValueError('Selected subject clips or escapes its region')
    trimap=np.full((h,w),0.5)
    trimap[distance_transform_edt(hard)>8]=1
    trimap[distance_transform_edt(~hard)>8]=0
    for p in points:trimap[p['y'],p['x']]=1
    if not np.any(trimap==0) or not np.any(trimap==1):raise ValueError('Nontrivial reviewed regions required')
    image=rgb.astype(np.float64)/255
    alpha=estimate_alpha_cf(image,trimap,preconditioner=lambda matrix:ichol(matrix,max_nnz=2000000),cg_kwargs={'maxiter':200})
    if not np.isfinite(alpha).all() or np.any(alpha<0) or np.any(alpha>1) or np.any(alpha[trimap==0]!=0) or np.any(alpha[trimap==1]!=1):raise ValueError('Unsafe solver output')
    a=np.rint(alpha*255).astype(np.uint8)
    visible=a>0
    if visible.sum()<2 or np.any(visible&~allowed):raise ValueError('Matted foreground clips or contaminates outside selected region')
    final_components,_=label(visible,structure=np.ones((3,3),int))
    seeded={int(final_components[p['y'],p['x']]) for p in points}
    if 0 in seeded or any(a[p['y'],p['x']]!=255 for p in points):raise ValueError('Critical detail seed lost')
    detached=~np.isin(final_components,list(seeded|{0}))
    removed=int(detached.sum())
    if removed:
        # Bounded numerical specks only. Never remove a substantial unselected subject/detail.
        ids=np.unique(final_components[detached])
        if removed>128 or int(a[detached].max())>16 or any(int((final_components==c).sum())>16 for c in ids):raise ValueError('Unreviewable detached fragments')
        a[detached]=0;alpha[detached]=0;visible=a>0
    foreground=estimate_foreground_ml(image,alpha)
    if not np.isfinite(foreground).all():raise ValueError('Unsafe foreground output')
    rgba=np.full((h,w,4),255,np.uint8);rgba[:,:,:3]=np.clip(np.rint(foreground*255),0,255).astype(np.uint8)
    rgba[~visible,:3]=0
    return dict(version=VERSION,model_sha256=MODEL_SHA,rgba=base64.b64encode(rgba.tobytes()).decode(),alpha=base64.b64encode(a.tobytes()).decode(),foreground_pixels=int(visible.sum()),removed_speck_pixels=removed,soft_alpha_pixels=int(((a>0)&(a<255)).sum()),mask_pixel_sha256=hashlib.sha256(a.tobytes()).hexdigest(),foreground_pixel_sha256=hashlib.sha256(rgba.tobytes()).hexdigest(),trimap_sha256=hashlib.sha256(trimap.tobytes()).hexdigest(),selection_boundary_contact=False,semantic_head_certified=False,review_required=True,external_execution_authorized=False)

def main():
    resource.setrlimit(resource.RLIMIT_CPU,(12,12));resource.setrlimit(resource.RLIMIT_AS,(2147483648,2147483648));resource.setrlimit(resource.RLIMIT_CORE,(0,0));resource.setrlimit(resource.RLIMIT_FSIZE,(8*1024**2,8*1024**2))
    raw=sys.stdin.buffer.read(6004097)
    if len(raw)>6004096:raise ValueError('Request exceeds bound')
    result=infer(json.loads(raw))
    sys.stdout.write(json.dumps(result,sort_keys=True,separators=(',',':')))

if __name__=='__main__':
    try:main()
    except Exception:
        sys.stderr.write('Experimental inference unavailable or unsafe; separately reviewed alpha fallback required.\n');sys.exit(1)
