"""Additive, pinned .23 API completion overlay; never replace inherited shared files."""
import argparse, hashlib, io, json, pathlib, stat, subprocess, zipfile
ROOT=pathlib.Path(__file__).resolve().parent.parent
BASE_SOURCE='d6566caa84089aa31117f2f960ae85da723beb7c'
BASE_SHA='cab74d7281b8d8c78f210566532c54737bbffb4e4707b39d50b6d3ffa6e76518'
MODIFIED=['digiforge.php']+['includes/POD/'+p+'.php' for p in ['PrintifyControlledTransport','PrintifyCatalogReadRepository']]
ADDED=['includes/POD/'+p+'.php' for p in ['PrintifyAcceptanceContract','PrintifyAcceptanceBridge','PrintifyAcceptanceAbilities']]
def sha(b):return hashlib.sha256(b).hexdigest()
def source(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=ROOT)
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--base',type=pathlib.Path,required=True);parser.add_argument('--output',type=pathlib.Path,required=True);args=parser.parse_args();raw=args.base.read_bytes()
 if len(raw)!=3252710 or sha(raw)!=BASE_SHA:raise ValueError('Exact qualified .22 base required')
 original={}
 # Parsing and hashing consume the same verified immutable bytes.
 with zipfile.ZipFile(io.BytesIO(raw)) as archive:
  if len(archive.infolist())!=760 or archive.testzip():raise ValueError('Base integrity/inventory mismatch')
  if sum(i.file_size for i in archive.infolist())>134217728:raise ValueError('Unbounded input')
  for info in archive.infolist():
   p=pathlib.PurePosixPath(info.filename)
   if len(p.parts)<2 or p.parts[0]!='digiforge' or p.is_absolute() or '..' in p.parts or chr(92) in info.filename or stat.S_IFMT(info.external_attr>>16)!=stat.S_IFREG:raise ValueError('Unsafe member')
   rel=str(p.relative_to('digiforge'))
   if rel in original:raise ValueError('Duplicate member')
   original[rel]=archive.read(info)
 head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();payload=dict(original)
 for p in MODIFIED+ADDED+['bin/package-printify-api-final.py']:
  if source(head,p)!=(ROOT/p).read_bytes():raise ValueError('Uncommitted composition input '+p)
 for p in MODIFIED:
  if original[p]!=source(BASE_SOURCE,p):raise ValueError('Unverified installed preimage '+p)
  payload[p]=source(head,p)
 for p in ADDED:
  if p in original:raise ValueError('Addition collision')
  payload[p]=source(head,p)
 args.output.mkdir(parents=True,exist_ok=True);target=args.output/'digiforge-1.0.103.23-printify-api-update.zip';records=[]
 with zipfile.ZipFile(target,'w') as archive:
  for p,b in sorted(payload.items()):
   info=zipfile.ZipInfo('digiforge/'+p,(2026,11,8,0,0,0));info.create_system=3;info.external_attr=(stat.S_IFREG|0o644)<<16;info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,b,compresslevel=9)
   records.append({'path':info.filename,'size':len(b),'sha256':sha(b),'baseline_sha256':sha(original[p]) if p in original else None,'action':'ADD' if p not in original else 'RETAIN_IDENTICAL' if original[p]==b else 'MODIFY_REVIEWED','source_byte_identical':(ROOT/p).is_file() and (ROOT/p).read_bytes()==b})
 with zipfile.ZipFile(target) as archive:
  if archive.testzip() or len(archive.infolist())!=763 or any(sha(archive.read(r['path']))!=r['sha256'] for r in records):raise ValueError('Exact member verification failed')
 counts={k:sum(r['action']==k for r in records) for k in ['ADD','MODIFY_REVIEWED','RETAIN_IDENTICAL']}
 if counts!={'ADD':3,'MODIFY_REVIEWED':3,'RETAIN_IDENTICAL':757} or not original.keys()<=payload.keys():raise ValueError('Preservation mismatch')
 manifest={'version':'1.0.103.23','source_sha':head,'artifact':target.name,'size':target.stat().st_size,'sha256':sha(target.read_bytes()),'base_source_sha':BASE_SOURCE,'base_sha256':BASE_SHA,'counts':dict(counts,DELETE=0),'composition_is_exact_checkout':False,'schema_migration':'NONE','installation_authorized':False,'live_provider_acceptance':False,'files':records}
 (args.output/'preservation-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n');print(json.dumps({k:v for k,v in manifest.items() if k!='files'},indent=2))
if __name__=='__main__':main()
