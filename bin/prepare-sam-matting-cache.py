"""Explicit deployment preparation, not request processing. Never downloads weights."""
import pathlib,sys,os,subprocess,json,hashlib,importlib.metadata

def main():
    if len(sys.argv)!=2:raise ValueError('One new cache directory required')
    requirements=pathlib.Path(__file__).resolve().parents[1]/'includes/POD/offline/requirements-sam-matting.txt'
    for line in requirements.read_text().splitlines():
        if not line or line.startswith('#') or line.startswith('--'):continue
        package,version=line.split('==')
        if importlib.metadata.version(package)!=version:raise ValueError('Pinned preparation runtime required')
    root=pathlib.Path(sys.argv[1]).resolve()
    if root.exists():raise ValueError('Refusing existing cache; prepare a fresh version')
    root.mkdir(mode=0o700,parents=True)
    environment={'PATH':'/usr/bin:/bin','NUMBA_CACHE_DIR':str(root),'NUMBA_NUM_THREADS':'1','OMP_NUM_THREADS':'1','OPENBLAS_NUM_THREADS':'1','MKL_NUM_THREADS':'1','PYTHONNOUSERSITE':'1'}
    subprocess.run([sys.executable,'-B','-s','-c','import pymatting'],env=environment,check=True,timeout=120)
    files={str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(root.rglob('*')) if p.is_file()}
    if not files or len(files)>128 or sum(p.stat().st_size for p in root.rglob('*') if p.is_file())>16777216:raise ValueError('Cache exceeds fixed bounds')
    (root/'manifest.json').write_text(json.dumps({'schema':1,'files':files},sort_keys=False,separators=(',',':'))+'\n')
    print('Prepared hashed cache; configure protected local runtime only after review.')

if __name__=='__main__':main()
