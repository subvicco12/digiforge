#!/usr/bin/env python3
"""Canonical committed-source archive plus one explicitly generated native build identity.
Offline engineering artifact only. Never installs, migrates or activates anything.
"""
import argparse,hashlib,json,pathlib,subprocess,zipfile,io,datetime

def digest(data):return hashlib.sha256(data).hexdigest()
def main():
 parser=argparse.ArgumentParser();parser.add_argument('output',type=pathlib.Path);args=parser.parse_args()
 repo=pathlib.Path(__file__).resolve().parent.parent
 source=subprocess.check_output(['git','rev-parse','HEAD^{commit}'],cwd=repo,text=True).strip()
 archive=subprocess.check_output(['git','archive','--format=zip','--prefix=digiforge/',source],cwd=repo)
 upstream=zipfile.ZipFile(io.BytesIO(archive));assert upstream.testzip() is None
 native='includes/Connector/Printify/';paths=sorted(n[len('digiforge/'):] for n in upstream.namelist() if not n.endswith('/') and (n.startswith('digiforge/'+native) or n=='digiforge/includes/POD/PrintifyConnectorReadContract.php'))
 assert native+'native-build.json' not in paths
 files={p:digest(upstream.read('digiforge/'+p)) for p in paths}
 spec='6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434'
 assert files[native+'native-core-port.v1.json']==spec
 stamp={'schema':'DF_NATIVE_BUILD_1','source_sha':source,'spec_sha256':spec,'files':files}
 stamp_bytes=(json.dumps(stamp,separators=(',',':'))+'\n').encode()
 ts=int(subprocess.check_output(['git','show','-s','--format=%ct',source],cwd=repo,text=True));dt=datetime.datetime.fromtimestamp(ts,datetime.timezone.utc);date=(max(1980,dt.year),dt.month,dt.day,dt.hour,dt.minute,dt.second//2*2)
 args.output.mkdir(parents=True,exist_ok=True);target=args.output/'digiforge-native-ports-offline.zip'
 with zipfile.ZipFile(target,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as out:
  for entry in upstream.infolist():out.writestr(entry,upstream.read(entry.filename))
  info=zipfile.ZipInfo('digiforge/'+native+'native-build.json',date);info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;out.writestr(info,stamp_bytes)
 with zipfile.ZipFile(target)as out:
  assert out.testzip() is None
  assert set(out.namelist())==set(upstream.namelist())|{'digiforge/'+native+'native-build.json'}
  for n in upstream.namelist():assert out.read(n)==upstream.read(n)
  inventory={n:{'sha256':digest(out.read(n)),'bytes':len(out.read(n))}for n in out.namelist()if not n.endswith('/')}
 manifest={'schema':'DF_NATIVE_OFFLINE_ARTIFACT_1','source_sha':source,'canonical_git_archive_sha256':digest(archive),'artifact':target.name,'artifact_sha256':digest(target.read_bytes()),'spec_sha256':spec,'transformation':'All git archive members unchanged; add build-owned native-build.json with committed native file hashes. Archive bytes intentionally differ.','files':inventory,'external_execution_authorized':False,'operational_acceptance':'BLOCKED_NATIVE_JOURNAL_CHECKPOINT_AND_IDENTITY_JOINS'}
 (args.output/'source-to-artifact.json').write_text(json.dumps(manifest,indent=2)+'\n')
 (args.output/(target.name+'.sha256')).write_text(manifest['artifact_sha256']+'  '+target.name+'\n')
 print(json.dumps({k:manifest[k]for k in ['source_sha','artifact','artifact_sha256','operational_acceptance']}))
if __name__=='__main__':main()
