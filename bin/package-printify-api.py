"""Compose an API-only candidate from the exact qualified installed-lineage .17 artifact."""
import argparse
import hashlib
import json
import pathlib
import stat
import subprocess
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
BASE_SHA = '5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac'
BASE_SOURCE = 'e957ba096751dc34254cdbcbefed13b625f5e507'
ADDITIONS = ['includes/POD/' + x + '.php' for x in ['PrintifyApiRequest','PrintifyApiReadEvidence','PrintifyApiReadWorkflow','PrintifyOrderEvidence','PrintifyWebhookEvidence']]
HEADER_PRE = b"'Accept'=>'application/json'"
HEADER_POST = HEADER_PRE + b",'User-Agent'=>'DigiForge/PrintifyApi'"
READ_METHOD = b' public function executeApiRead(string $planHash,string $authorizationHash):array|WP_Error{return (new PrintifyApiReadWorkflow())->execute($planHash,$authorizationHash,$this->sender);}\n'
ORDER_ANCHOR = b"   $endpoint='/shops/'.$shop.'/orders.json';$external='';"
ORDER_CHECK = b"   try{PrintifyOrderEvidence::validate($body);}catch(\\InvalidArgumentException){return self::e('order_contract','Order does not satisfy the supported existing-product contract.');}\n"

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def once(raw, before, after):
    if raw.count(before) != 1:
        raise ValueError('Exact unique patch preimage required')
    result = raw.replace(before, after, 1)
    if result.replace(after, before, 1) != raw:
        raise ValueError('Non-reversible overlay')
    return result

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    raw = args.base.read_bytes()
    if sha(raw) != BASE_SHA:
        raise ValueError('Exact qualified .17 package required')
    original = {}
    with zipfile.ZipFile(args.base) as archive:
        if archive.testzip() or len(archive.infolist()) != 741:
            raise ValueError('Base inventory/integrity mismatch')
        for info in archive.infolist():
            path = pathlib.PurePosixPath(info.filename)
            if path.parts[0] != 'digiforge' or '..' in path.parts or path.is_absolute() or '\\' in info.filename or stat.S_IFMT(info.external_attr >> 16) != stat.S_IFREG:
                raise ValueError('Unsafe base member')
            rel = str(path.relative_to('digiforge'))
            if rel in original:
                raise ValueError('Duplicate member')
            original[rel] = archive.read(info)
    head = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    changed = ADDITIONS + ['digiforge.php','includes/POD/PrintifyControlledTransport.php','includes/POD/PrintifyMutationRequest.php','includes/POD/PrintifyProductPipeline.php','bin/package-printify-api.py']
    for rel in changed:
        if subprocess.check_output(['git','show',head+':'+rel],cwd=ROOT) != (ROOT/rel).read_bytes():
            raise ValueError('Uncommitted packaging input: '+rel)
    payload = dict(original)
    for rel in ADDITIONS:
        if rel in payload:
            raise ValueError('Feature collision')
        payload[rel] = (ROOT/rel).read_bytes()
    rel = 'digiforge.php'
    if original[rel].count(b'1.0.103.17') != 2:
        raise ValueError('Version lineage mismatch')
    payload[rel] = original[rel].replace(b'1.0.103.17',b'1.0.103.19')
    if payload[rel] != (ROOT/rel).read_bytes():
        raise ValueError('Unexpected plugin bootstrap change')
    rel = 'includes/POD/PrintifyProductPipeline.php'
    payload[rel] = once(original[rel],HEADER_PRE,HEADER_POST)
    if payload[rel] != (ROOT/rel).read_bytes():
        raise ValueError('Product pipeline has unrelated changes')
    rel = 'includes/POD/PrintifyMutationRequest.php'
    source_pre = subprocess.check_output(['git','show',BASE_SOURCE+':'+rel],cwd=ROOT)
    if once(source_pre,ORDER_ANCHOR,ORDER_CHECK+ORDER_ANCHOR) != (ROOT/rel).read_bytes():
        raise ValueError('Unreviewed order validation delta')
    payload[rel] = once(original[rel],ORDER_ANCHOR,ORDER_CHECK+ORDER_ANCHOR)
    rel = 'includes/POD/PrintifyControlledTransport.php'
    def transport(raw):
        raw = once(raw,HEADER_PRE,HEADER_POST)
        raw = once(raw,b"'redirection'=>0,'sslverify'=>true]",b"'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048576]")
        return once(raw,b' public function execute(array $permit',READ_METHOD+b' public function execute(array $permit')
    source_pre = subprocess.check_output(['git','show',BASE_SOURCE+':'+rel],cwd=ROOT)
    if transport(source_pre) != (ROOT/rel).read_bytes():
        raise ValueError('Unreviewed transport delta')
    payload[rel] = transport(original[rel])
    # Installed dispatch claims and connection-scope checks are retained byte-for-byte by reversible edits.
    args.output.mkdir(parents=True,exist_ok=True)
    target = args.output/'digiforge-1.0.103.19-printify-api-update.zip'
    records=[]
    with zipfile.ZipFile(target,'w') as archive:
        for rel,raw in sorted(payload.items()):
            info=zipfile.ZipInfo('digiforge/'+rel,(2026,11,8,0,0,0))
            info.create_system=3;info.external_attr=(stat.S_IFREG|0o644)<<16;info.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(info,raw,compresslevel=9)
            records.append({'path':info.filename,'sha256':sha(raw),'size':len(raw),'baseline_sha256':sha(original[rel]) if rel in original else None,'action':'ADD' if rel not in original else 'RETAIN_IDENTICAL' if original[rel]==raw else 'MODIFY_REVIEWED','source_byte_identical':(ROOT/rel).is_file() and (ROOT/rel).read_bytes()==raw})
    counts={a:sum(r['action']==a for r in records) for a in ['ADD','MODIFY_REVIEWED','RETAIN_IDENTICAL']}
    if counts != {'ADD':5,'MODIFY_REVIEWED':4,'RETAIN_IDENTICAL':737} or not original.keys()<=payload.keys():
        raise ValueError('Preservation inventory mismatch')
    with zipfile.ZipFile(target) as archive:
        if archive.testzip() is not None or any(sha(archive.read(r['path']))!=r['sha256'] for r in records):
            raise ValueError('Packaged bytes mismatch')
    manifest={'version':'1.0.103.19','source_sha':head,'artifact':target.name,'sha256':sha(target.read_bytes()),'size':target.stat().st_size,'base_sha256':BASE_SHA,'base_source_sha':BASE_SOURCE,'composition_is_exact_checkout':False,'schema_migration':'NONE','installation_authorized':False,'mission_b_1259_included':False,'counts':dict(counts,DELETE=0),'files':records}
    (args.output/'preservation-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    print(json.dumps({k:v for k,v in manifest.items() if k!='files'},indent=2))

if __name__=='__main__':
    main()
