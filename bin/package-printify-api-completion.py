"""Digest-pinned, reversible API overlay on the qualified installed-lineage .19 ZIP."""
import argparse, hashlib, json, pathlib, stat, subprocess, tempfile, zipfile
ROOT=pathlib.Path(__file__).resolve().parent.parent
BASE_SOURCE='cbead09e7e3884b7f5413be367cc33dbcd7f5794'
BASE_SHA='03d3e609f7bf15d51655995e6a849a5f1cc6d5b92a563f0fd0d44b13c81bdccc'
# Explicit runtime boundary. All other installed members must retain their exact bytes.
MODIFIED=['digiforge.php','includes/Integrations/ConnectionTester.php']+['includes/POD/'+n+'.php' for n in ['PrintifyApiReadEvidence','PrintifyApiReadWorkflow','PrintifyApiRequest','PrintifyCatalogClient','PrintifyCatalogReadRepository','PrintifyControlledTransport','PrintifyOrderEvidence','PrintifyProductPipeline','PrintifyProductRequest','PrintifyProductResponse','PrintifyReconciliationLookup','PrintifyReconciliationRepository','PrintifyReconciliationWorkflow','ScopedProviderConnection']]+['includes/PortalAccess/RestPolicy.php','includes/PortalAccess/RestRoutes.php','includes/Security/ShopAccessRestGuard.php']
ADDED=['includes/POD/'+n+'.php' for n in ['PrintifyAccountQuota','PrintifyApiOutcome','PrintifyLifecycleEvidence','PrintifyLifecycleRequest','PrintifyLifecycleWorkflow','PrintifyOperationClaim','PrintifyWebhookInbox','PrintifyWebhookRequest']]+['includes/REST/PrintifyWebhookController.php']
def sha(b): return hashlib.sha256(b).hexdigest()
def source(ref,p): return subprocess.check_output(['git','show',ref+':'+p],cwd=ROOT)
def once(b,pre,post):
 if b.count(pre)!=1: raise ValueError('Unique installed patch preimage required')
 out=b.replace(pre,post,1)
 if out.count(post)!=1 or out.replace(post,pre,1)!=b: raise ValueError('Irreversible patch')
 return out

def main():
 a=argparse.ArgumentParser();a.add_argument('--base',type=pathlib.Path,required=True);a.add_argument('--output',type=pathlib.Path,required=True);args=a.parse_args()
 raw=args.base.read_bytes()
 if sha(raw)!=BASE_SHA or len(raw)!=3212028: raise ValueError('Exact .19 base required')
 original={}
 with zipfile.ZipFile(args.base) as z:
  if z.testzip() or len(z.infolist())!=746: raise ValueError('Base integrity/inventory')
  for i in z.infolist():
   p=pathlib.PurePosixPath(i.filename)
   if p.is_absolute() or len(p.parts)<2 or p.parts[0]!='digiforge' or '..' in p.parts or chr(92) in i.filename or stat.S_IFMT(i.external_attr>>16)!=stat.S_IFREG: raise ValueError('Unsafe member')
   rel=str(p.relative_to('digiforge'))
   if rel in original: raise ValueError('Duplicate member')
   original[rel]=z.read(i)
 head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
 for p in MODIFIED+ADDED+['bin/package-printify-api-completion.py']:
  if source(head,p)!=(ROOT/p).read_bytes(): raise ValueError('Uncommitted input: '+p)
 payload=dict(original);special=['includes/Integrations/ConnectionTester.php','includes/POD/PrintifyCatalogClient.php']
 # Apply normal source deltas to installed bytes, then reverse every delta and compare.
 normal=[p for p in MODIFIED if p not in special]
 with tempfile.TemporaryDirectory(prefix='api20-compose-') as tmp:
  root=pathlib.Path(tmp)
  for p in normal:
   f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(original[p])
  patch=subprocess.check_output(['git','diff','--binary',BASE_SOURCE,head,'--',*normal],cwd=ROOT);patchfile=root/'overlay.patch';patchfile.write_bytes(patch)
  for argv in [['--check'],[],['--reverse','--check'],['--reverse']]:
   subprocess.run(['git','apply',*argv,str(patchfile)],cwd=root,check=True)
   if argv==[]:
    for p in normal: payload[p]=(root/p).read_bytes()
  if any((root/p).read_bytes()!=original[p] for p in normal): raise ValueError('Installed reverse preservation failed')
 # Installed connection tester has stronger runtime guards and a different signature.
 p=special[0];old=source(BASE_SOURCE,p);new=source(head,p)
 pre=b"    private function testPrintify(int $integrationId): array|\\WP_Error {\n"
 added=new.split(pre,1)[1].splitlines(keepends=True)[0]
 method=new[new.index(b'    public function testPrintifyGoverned('):new.index(b'    private function testEtsy(')]
 expected=once(old,pre,pre+added);expected=once(expected,b'    private function testEtsy(',method+b'    private function testEtsy(')
 if expected!=new: raise ValueError('Unreviewed connection tester source delta')
 installed_pre=pre.replace(b'int $integrationId)',b'int $integrationId,array $scope)')
 payload[p]=once(original[p],installed_pre,installed_pre+added);payload[p]=once(payload[p],b'    private function testEtsy(',method+b'    private function testEtsy(')
 # Catalog installed lineage preserves stronger scope/size checks. Apply exact minimal blocks.
 p=special[1];old=source(BASE_SOURCE,p);new=source(head,p)
 pre=b"        try { $response = ($this->transport)('https://api.printify.com'.$path, $args); }\n        catch (\\Throwable) { return new WP_Error('catalog_read_transport', 'Catalog GET failed; the request remains consumed.', ['status'=>502]); }\n"
 start=new.index(b'        $quotaScope=');end=new.index(b'        if (is_wp_error($response)) return new WP_Error',start);post=new[start:end]
 marker=b'    private function getWithEvidence(string $token, string $url): array|WP_Error\n    {\n';addition=new.split(marker,1)[1].splitlines(keepends=True)[0]
 expected=once(old,pre,post);expected=once(expected,marker,marker+addition)
 if expected!=new: raise ValueError('Unreviewed catalog source delta')
 payload[p]=once(original[p],pre,post);payload[p]=once(payload[p],marker,marker+addition)
 for p in ADDED:
  if p in original: raise ValueError('Addition collision')
  payload[p]=source(head,p)
 if not original.keys()<=payload.keys(): raise ValueError('Deletion')
 args.output.mkdir(parents=True,exist_ok=True);target=args.output/'digiforge-1.0.103.20-printify-api-update.zip';records=[]
 with zipfile.ZipFile(target,'w') as z:
  for p,b in sorted(payload.items()):
   i=zipfile.ZipInfo('digiforge/'+p,(2026,11,8,0,0,0));i.create_system=3;i.external_attr=(stat.S_IFREG|0o644)<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,b,compresslevel=9)
   records.append({'path':i.filename,'size':len(b),'sha256':sha(b),'baseline_sha256':sha(original[p]) if p in original else None,'action':'ADD' if p not in original else 'RETAIN_IDENTICAL' if original[p]==b else 'MODIFY_REVIEWED','source_byte_identical':(ROOT/p).is_file() and (ROOT/p).read_bytes()==b})
 with zipfile.ZipFile(target) as z:
  if z.testzip() or len(z.infolist())!=len(records) or any(sha(z.read(r['path']))!=r['sha256'] for r in records): raise ValueError('ZIP verification failed')
 counts={k:sum(r['action']==k for r in records) for k in ['ADD','RETAIN_IDENTICAL','MODIFY_REVIEWED']}
 if counts['ADD']!=9 or counts['MODIFY_REVIEWED']!=19: raise ValueError('Unexpected patch inventory')
 manifest={'version':'1.0.103.20','source_sha':head,'artifact':target.name,'size':target.stat().st_size,'sha256':sha(target.read_bytes()),'base_source_sha':BASE_SOURCE,'base_sha256':BASE_SHA,'counts':dict(counts,DELETE=0),'composition_is_exact_checkout':False,'schema_migration':'NONE','installation_authorized':False,'live_provider_acceptance':False,'mission_b_1259_included':False,'files':records}
 (args.output/'preservation-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n');print(json.dumps({k:v for k,v in manifest.items() if k!='files'},indent=2))
if __name__=='__main__': main()
