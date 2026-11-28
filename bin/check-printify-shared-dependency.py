#!/usr/bin/env python3
"""Read pinned local Git objects and run a no-authority compatibility probe. Never fetch/import/install."""
import argparse
import hashlib
import json
import re
from pathlib import Path
import shutil
import subprocess
import tempfile


def git_bytes(repo, ref, path):
    if not re.fullmatch(r'[a-f0-9]{40}', ref):
        raise ValueError('Exact immutable Git commit required')
    if not path.startswith('includes/') or '..' in Path(path).parts:
        raise ValueError('Only exact source paths are permitted')
    result=subprocess.run(['git','-C',str(repo),'show',ref+':'+path],capture_output=True,check=True)
    return result.stdout


def probe(repo, manifest, profile, php, api_root):
    if manifest['approval_status'] not in ('PENDING_SHARED_OWNER','OWNER_AUTHORIZED_NARROW_INTEGRATION') or manifest['policy']['external_execution_authorized'] is not False:
        raise ValueError('Diagnostic manifest cannot confer authority')
    with tempfile.TemporaryDirectory(prefix='df-printify-dependency-') as tmp:
        root=Path(tmp)
        for entry in manifest['runtime_files']:
            ref=manifest['refs'].get('shared_core',manifest['refs']['shared_1242']) if entry['consumption']=='owner-approved-shared-core' else manifest['refs']['main']
            data=git_bytes(repo,ref,entry['path'])
            if hashlib.sha256(data).hexdigest()!=entry['selected_sha256']:
                raise ValueError('Pinned source mismatch: '+entry['path'])
            path=root/entry['path'];path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
        if profile=='cumulative-admission':
            for entry in manifest['conditional_integration_surfaces']:
                # Definitions only: never load/bootstrap Plugin or invoke provisioning routes.
                if entry['path'] in ('includes/Core/Plugin.php','includes/REST/ShopAccessController.php'):
                    continue
                data=git_bytes(repo,manifest['refs']['shared_1242'],entry['path'])
                if hashlib.sha256(data).hexdigest()!=entry['shared_sha256']:
                    raise ValueError('Pinned admission source mismatch')
                path=root/entry['path'];path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
        for relative in ['includes/POD/PrintifyResearchDependencies.php','includes/POD/PrintifyResearchReader.php','includes/POD/PrintifyResearchGateway.php']:
            path=root/relative;path.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(api_root/relative,path)
        harness=root/'probe.php'
        harness.write_text('''<?php
class WP_Error { public function __construct(private string $code='',private string $message='',private array $data=[]){} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
class WP_REST_Request {public function __construct(private string $method,private string $route){} public function get_route(){return $this->route;} public function get_method(){return $this->method;} public function get_param($key){return null;}}
function get_current_user_id(){return 0;} function current_user_can($cap){return false;} function is_wp_error($v){return $v instanceof WP_Error;}
function wp_remote_get(...$args){throw new RuntimeException('HTTP forbidden in dependency probe');}
function wp_remote_request(...$args){throw new RuntimeException('HTTP forbidden in dependency probe');}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
spl_autoload_register(function($name){if(str_starts_with($name,'DigiForge\\\\')){$path=__DIR__.'/includes/'.str_replace('\\\\','/',substr($name,10)).'.php';if(is_file($path))require_once $path;}});
$report=DigiForge\\POD\\PrintifyResearchDependencies::inspect();
$denied=(new DigiForge\\POD\\PrintifyResearchGateway())->collect(str_repeat('a',64),0,'diagnostic-only');
if(!$denied instanceof WP_Error)throw new RuntimeException('Unauthenticated request was not denied');
echo json_encode(['inspection'=>$report,'unauthenticated_denial'=>$denied->get_error_code(),'production_writes'=>0,'grants_issued'=>0,'exceptions_issued'=>0,'http_calls'=>0],JSON_THROW_ON_ERROR);
''')
        run=subprocess.run([php,str(harness)],capture_output=True,text=True,check=True)
        return json.loads(run.stdout)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repository',type=Path,default=Path('.'))
    parser.add_argument('--manifest',type=Path,default=Path(__file__).resolve().parents[1]/'docs/engineering/printify-shared-dependency-manifest-v1.json')
    parser.add_argument('--profile',choices=['narrow-core','cumulative-admission'],required=True)
    parser.add_argument('--php',default='php')
    args=parser.parse_args()
    result=probe(args.repository,json.loads(args.manifest.read_text()),args.profile,args.php,Path(__file__).resolve().parents[1])
    print(json.dumps(result,indent=2))

if __name__=='__main__':main()
