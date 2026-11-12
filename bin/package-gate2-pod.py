"""Explicit preservation-qualified Gate2 build from committed objects, never worktree bytes."""
import argparse,hashlib,json,re,subprocess,zipfile
from pathlib import Path,PurePosixPath
BASE='fb3b805ca7eb7d91b8e87deab6ba408f6e38d3e3'
INVENTORY='docs/gate2/PAYLOAD.json'

def validate_delta(manifest,baseline):
 if manifest.get('baseline_source_sha')!=BASE:raise ValueError('Certified baseline required')
 old={x['path']:x['sha256'] for x in baseline['files']};rows=manifest['files'];paths=[x['path'] for x in rows]
 if len(paths)!=len(set(paths)) or not old.keys()<=set(paths):raise ValueError('No baseline removals or duplicate paths permitted')
 for x in rows:
  expected=old.get(x['path']);changed=x['sha256']!=expected
  if x['baseline_sha256']!=expected or not x.get('reason') or x['change']!=('ADD' if expected is None else 'MODIFY_REVIEWED' if changed else 'RETAIN_IDENTICAL'):raise ValueError('Explicit exact-path preservation decision required')

def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--output',type=Path,required=True);a=p.parse_args();root=Path(__file__).resolve().parent.parent
 def git(*s):return subprocess.check_output(['git',*s],cwd=root)
 sha=git('rev-parse','HEAD').decode().strip();script=Path(__file__).relative_to(root).as_posix()
 if git('show',sha+':'+script)!=Path(__file__).read_bytes():raise ValueError('Committed builder required')
 baseline=json.loads(git('show',BASE+':docs/normalization/QUALIFIED_PAYLOAD.json'));m=json.loads(git('show',sha+':'+INVENTORY));validate_delta(m,baseline)
 entries={}
 for x in git('ls-tree','-r','-z',sha).split(b'\0'):
  if x:
   meta,name=x.split(b'\t',1);mode,kind,oid=meta.split();entries[name.decode()]=(mode.decode(),kind.decode(),oid.decode())
 data={};inventory=[]
 for x in m['files']:
  n=x['path'];path=PurePosixPath(n)
  if path.is_absolute() or '..' in path.parts or '\\' in n or str(path)!=n or n.split('/')[0] in ['bin','docs','tests','.github','vendor']:raise ValueError('Unsafe runtime path')
  mode,kind,oid=entries.get(n,('','',''))
  if mode!='100644' or kind!='blob':raise ValueError('Exact regular0644 Git mode required: '+n)
  raw=git('cat-file','blob',oid)
  if len(raw)!=x['size'] or hashlib.sha256(raw).hexdigest()!=x['sha256']:raise ValueError('Committed runtime hash mismatch: '+n)
  data[n]=raw;inventory.append({**x,'source_git_blob':oid})
 roots={'includes','assets','modules','admin','automation'}
 if any(n.split('/')[0] in roots and n not in data for n in entries):raise ValueError('Uninventoried runtime source')
 a.output.mkdir(parents=True,exist_ok=True);target=a.output/'digiforge.zip'
 # Stored ZIP avoids platform-dependent DEFLATE/zlib bytes. No runtime byte transforms.
 with zipfile.ZipFile(target,'w',zipfile.ZIP_STORED) as z:
  for n,raw in sorted(data.items()):
   i=zipfile.ZipInfo('digiforge/'+n,(2026,11,12,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_STORED;z.writestr(i,raw)
 with zipfile.ZipFile(target) as z:
  if z.testzip() is not None:raise ValueError('ZIP CRC failure')
 digest=hashlib.sha256(target.read_bytes()).hexdigest();version=re.search(rb"const DIGIFORGE_VERSION = '([^']+)';",data['digiforge.php']).group(1).decode()
 receipt={'schema':'digiforge-release-manifest-v1','commit_sha':sha,'baseline_source_sha':BASE,'plugin_version':version,'database_schema_version':'25','legacy_bootstrap_db_marker':'23','artifact':'digiforge.zip','sha256':digest,'bytes':target.stat().st_size,'file_count':len(inventory),'compression':'ZIP_STORED','external_actions_performed':False,'production_activation_authorized':False,'files':inventory,'excluded_paths':sorted(set(entries)-set(data))}
 (a.output/'source-to-artifact.json').write_text(json.dumps(receipt,indent=2)+'\n');(a.output/'digiforge.release-manifest.json').write_text(json.dumps({k:v for k,v in receipt.items() if k not in ['files','excluded_paths']},indent=2)+'\n');(a.output/'digiforge.zip.sha256').write_text(digest+'  digiforge.zip\n');print(json.dumps({k:v for k,v in receipt.items() if k not in ['files','excluded_paths']},indent=2))
if __name__=='__main__':main()
