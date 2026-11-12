#!/usr/bin/env python3
"""Materialize the complete pinned .17 test suite and reviewed fixture overlays. Never installs or networks."""
import argparse
import hashlib
import json
import pathlib
import re
import subprocess

ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='e957ba096751dc34254cdbcbefed13b625f5e507'

def blob(ref,path):
    return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT)

def methods(raw):
    return set(re.findall(rb'function\s+(test[A-Za-z0-9_]+)\s*\(',raw))

def prepare(output):
    if output.exists():
        raise ValueError('Use a new empty private harness directory')
    for ancestor in [output.absolute(),*output.absolute().parents]:
        if ancestor.is_symlink():
            raise ValueError('Harness path cannot traverse a symlink')
    names=subprocess.check_output(['git','ls-tree','-r','--name-only',BASE,'--','tests'],cwd=ROOT,text=True).splitlines()
    original={name:blob(BASE,name) for name in names}
    head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    overlays={}
    for path in sorted((ROOT/'tests/package-acceptance/wordpress').glob('*.php')):
        source=path.relative_to(ROOT).as_posix()
        raw=path.read_bytes()
        if raw!=blob(head,source):
            raise ValueError('Acceptance overlay must match the named commit')
        target='tests/wordpress/'+path.name
        if target not in original or not methods(original[target])<=methods(raw):
            raise ValueError('An original test method was removed or an overlay is unaccounted')
        overlays[target]={'source':source,'baseline_sha256':hashlib.sha256(original[target]).hexdigest(),'sha256':hashlib.sha256(raw).hexdigest()}
        original[target]=raw
    connector='tests/wordpress/PrintifyConnectorIntegrationTest.php'
    original[connector]=blob(head,connector)
    original['phpunit.wordpress.xml.dist']=blob(BASE,'phpunit.wordpress.xml.dist')
    for name,raw in original.items():
        target=output/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(raw)
    manifest={'base_source_sha':BASE,'fixture_source_sha':head,'source_identity_verified':True,'omitted_test_files':[],'omitted_original_test_methods':[],'overlays':overlays,'files':[{'path':name,'sha256':hashlib.sha256(raw).hexdigest()}for name,raw in sorted(original.items())]}
    (output/'fixture-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    return manifest

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--output',type=pathlib.Path,required=True);args=parser.parse_args()
    result=prepare(args.output);print(json.dumps({k:v for k,v in result.items() if k!='files'},indent=2))
