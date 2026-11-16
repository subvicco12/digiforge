"""Bounded packaged-runtime acceptance. Assumes this task's isolated DB/server only.
Run preparatory mode, reset only its private photo_http DB, run generated fixture,
restart PHP with router.php, then run generated journey and finalize this script.
"""
from pathlib import Path
import zipfile,hashlib,subprocess,json,sys
root=Path('/workspace/lane-a-photo-http-20261116');repo=Path('/workspace/lane-a-product-factory-1266');h=repo/'tests/browser/photo-http-acceptance';private=root/'private';out=root/'receipt-packaged-candidate';out.mkdir(exist_ok=True)
package=Path('/workspace/lane-a-continuation-20261116/package-a/digiforge.zip');expected='d61dc11e8132c9aad2cadf1fb19b4b20412c5444ea6bf1bcfc761f692438f027';head='784458955d8882e11429980355a94f338a67ae4d'
assert hashlib.sha256(package.read_bytes()).hexdigest()==expected
z=zipfile.ZipFile(package);assert z.testzip() is None;assert len(z.infolist())==879
checks={}
for n in z.namelist():
 if n.endswith('/'):continue
 assert n.startswith('digiforge/') and '..' not in Path(n).parts
 rel=n.removeprefix('digiforge/')
 if rel.startswith(('includes/','assets/')) or rel=='digiforge.php':
  data=z.read(n);tracked=subprocess.check_output(['git','show',head+':'+rel],cwd=repo);assert data==tracked,rel;checks[rel]=hashlib.sha256(data).hexdigest()
if '--finalize' in sys.argv:
 r=json.loads((out/'receipt.json').read_text());r['package']={'sha256':expected,'members':879,'crc':'PASS','exact_source_head':head,'runtime_files_compared_to_git':len(checks),'critical_runtime_sha256':{k:v for k,v in checks.items() if k in ['digiforge.php','includes/POD/CustomerPhotoSelection.php','includes/POD/CustomerEditorWorkflow.php','includes/POD/CustomerEditorRepository.php','assets/customer-editor.js']}}
 r['executed_runtime_identity']=json.loads((out/'runtime-identity.json').read_text());r['safety_after']=json.loads((out/'safety-after.json').read_text());hosts=r.pop('external_attempts');assert all(h in ['127.0.0.1','localhost','::1'] for h in hosts);r['http_transport']={'actual_loopback':True,'blocked_loopback_probes':len(hosts),'nonloopback_attempts':0,'actual_provider_requests':0};r['preview_bytes_equal']=(out/'buyer-preview.png').read_bytes()==(out/'operator-unreviewed-artifact.png').read_bytes();assert r['preview_bytes_equal'];r['limits']='Packaged SINGLE_PHOTO synthetic one-slot local HTTP only; historical collage/browser receipts remain separate. No complete Etsy buyer identity, provider binding, export or production acceptance.';(out/'receipt.json').write_text(json.dumps(r,indent=2)+'\n');print('Packaged acceptance finalized',len(r['cases']),'HTTP cases',len(checks),'source-matched runtime files');sys.exit()
plugins=root/'site/wp-content/plugins';z.extractall(plugins);runtime=plugins/'digiforge';assert not runtime.is_symlink()
mu=root/'site/wp-content/mu-plugins/harness.php';s=mu.read_text();s=s.replace("require '/workspace/lane-a-product-factory-1266/digiforge.php'", "require '/workspace/lane-a-photo-http-20261116/site/wp-content/plugins/digiforge/digiforge.php'");mu.write_text(s)
s=(h/'fixture.php').read_text().replace("/receipt/safety-settings.json","/receipt-packaged-candidate/safety-settings.json")
identity="""$identity=[];foreach(['DigiForge\\\\POD\\\\CustomerPhotoSelection','DigiForge\\\\POD\\\\CustomerEditorWorkflow','DigiForge\\\\POD\\\\CustomerEditorRepository','DigiForge\\\\POD\\\\DeterministicPersonalizationRenderer']as $class){$file=(new ReflectionClass($class))->getFileName();if(!str_starts_with($file,'/workspace/lane-a-photo-http-20261116/site/wp-content/plugins/digiforge/'))throw new RuntimeException('Non-packaged runtime loaded');$identity[$class]=['file'=>str_replace('/workspace/lane-a-photo-http-20261116/site/wp-content/plugins/digiforge/','',$file),'sha256'=>hash_file('sha256',$file)];}file_put_contents('/workspace/lane-a-photo-http-20261116/receipt-packaged-candidate/runtime-identity.json',json_encode($identity,JSON_PRETTY_PRINT));
"""
s=s.replace('echo "Synthetic fixture',identity+'echo "Synthetic fixture');(private/'packaged-fixture.php').write_text(s)
s=(h/'journey.py').read_text().replace("out=ROOT/'receipt'", "out=ROOT/'receipt-packaged-candidate'");(private/'packaged-journey.py').write_text(s)
(out/'package-integrity.json').write_text(json.dumps({'package_sha256':expected,'head':head,'crc':'PASS','members':879,'runtime_files_match_git_head':len(checks)},indent=2));print('Prepared packaged runtime',len(checks),'files verified')
