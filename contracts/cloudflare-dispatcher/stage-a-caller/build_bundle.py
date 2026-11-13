#!/usr/bin/env python3
"""Deterministic committed-source caller bundle. Never deploys or uses credentials."""
import argparse, hashlib, json, posixpath, re, subprocess, zipfile
from pathlib import Path
PREFIX = 'contracts/cloudflare-dispatcher/'
APPROVED = 'b0d29d9f7b362c420b5c56143315ebd1e0cde92d7f00fe4bdff3f46a9836ca1e'
def digest(b): return hashlib.sha256(b).hexdigest()
def build(root, output):
    sha = subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
    paths = subprocess.check_output(['git','ls-tree','-r','--name-only',sha,PREFIX],cwd=root,text=True).splitlines()
    source = {}
    for p in paths:
        b = subprocess.check_output(['git','show',sha+':'+p],cwd=root)
        if (root/p).read_bytes().replace(b'\r\n', b'\n') != b.replace(b'\r\n', b'\n'): raise ValueError('UNCOMMITTED_SOURCE:'+p)
        source[p] = b
    config = json.loads(source[PREFIX+'stage-a-caller/wrangler.template.json'])
    runtime = config['rules'][0]['globs']
    if config['workers_dev'] or config['preview_urls'] or config['routes'] or config['vars']['DF_CALLER_MODE']!='DISABLED': raise ValueError('ACTIVE_CONFIGURATION')
    if config['services'] != [{'binding':'DF_STAGE_A','service':'digiforge-dispatcher-qualification'}]: raise ValueError('WRONG_SERVICE')
    target = ['protocol.mjs','sqlite-state.mjs']+['stage-a/'+n+'.mjs' for n in ['entrypoint','factory','egress','state','suite']]
    target_hashes = {p:digest(source[PREFIX+p]) for p in sorted(target)}
    if digest(json.dumps(target_hashes,sort_keys=True,separators=(',',':')).encode()) != APPROVED: raise ValueError('FROZEN_TARGET_CHANGED')
    members = {'source/'+p:b for p,b in source.items()}
    for p in runtime:
        b = source[PREFIX+p]; text = b.decode()
        if re.search(r'\b(?:eval|require|Function)\s*\(|\bimport\s*\(',text) or 'api.printify.com' in text or 'cloudflare:sockets' in text: raise ValueError('UNSAFE_RUNTIME:'+p)
        for spec in re.findall(r'(?:from\s*|import\s*)[\'\"]([^\'\"]+)[\'\"]',text):
            if spec=='cloudflare:workers' and p=='stage-a-caller/entrypoint.mjs': continue
            if not spec.startswith('.') or posixpath.normpath(posixpath.join(posixpath.dirname(p),spec)) not in runtime: raise ValueError('IMPORT_ESCAPE:'+p)
        members['caller-deployment/'+p]=b
    members['caller-deployment/wrangler.json']=(json.dumps(config,indent=2)+'\n').encode()
    manifest={'source_sha':sha,'type':'OFFLINE_STAGE_A_CALLER_NOT_WORDPRESS_INSTALLER','approved_target_build':APPROVED,'hosted':'NOT_EXECUTED','recovery':'RECOVERY_BLOCKED','member_sha256':{p:digest(b) for p,b in sorted(members.items())}}
    members['caller-source-manifest.json']=(json.dumps(manifest,sort_keys=True,indent=2)+'\n').encode()
    output.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(output,'w',compression=zipfile.ZIP_STORED) as z:
        for p,b in sorted(members.items()):
            i=zipfile.ZipInfo(p,(1980,1,1,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;z.writestr(i,b)
    with zipfile.ZipFile(output) as z:
        assert z.testzip() is None
        for p,h in manifest['member_sha256'].items(): assert digest(z.read(p))==h
    print(json.dumps({'source_sha':sha,'size':output.stat().st_size,'sha256':digest(output.read_bytes()),'approved_target_build':APPROVED},sort_keys=True))
if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('--output',type=Path,required=True);args=a.parse_args();build(Path(__file__).resolve().parents[3],args.output.resolve())
