#!/usr/bin/env python3
"""Build a deterministic create-only feature overlay. Never installs or contacts a site."""
import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import zipfile

BASE='78ea96d94e1faa50eced02eb42c9f590e36a5439'
PAYLOAD=(
 'includes/POD/ProductIntelligence.php',
 'includes/POD/ProductIntelligenceRepository.php',
 'includes/POD/PrintifyResearchMapper.php',
 'includes/POD/PrintifyResearchReader.php',
 'includes/POD/PrintifyResearchGateway.php',
 'includes/POD/PrintifyResearchBatch.php',
 'includes/POD/PrintifyResearchDependencies.php',
 'includes/REST/PrintifyResearchController.php',
 'includes/REST/PrintifyCatalogShopAdmission.php',
 'includes/Portal/ProductIntelligencePanel.php',
 'includes/Portal/PrintifyResearchControls.php',
 'includes/Portal/PrintifyProductIntelligenceFeature.php',
 'assets/printify-product-intelligence.css',
)
GATES=[
 'INSTALLED_SHARED_INTERFACES_UNVERIFIED',
 'MISSING_OWNER_POLICY_REQUIRES_SECURITY_OWNER_RECONCILIATION',
 'INSTALLED_GLOBAL_REST_ADMIN_MAPPING_UNVERIFIED',
 'INSTALLED_PORTAL_EXTENSION_POINT_UNVERIFIED',
 'SCHEMA_23_24_25_AND_PROVIDER_1_UNVERIFIED',
 'TARGET_PATH_ABSENCE_PREFLIGHT_REQUIRED',
 'VERIFIED_RECOVERY_CHECKPOINT_AND_INSTALLATION_AUTHORIZATION_REQUIRED',
]

def encoded(value):
    return (json.dumps(value,sort_keys=True,indent=2)+'\n').encode()

def build_bundle(commit,files,inventory,instructions):
    if not re.fullmatch('[a-f0-9]{40}',commit):raise ValueError('Exact immutable source SHA required')
    if set(files)!=set(PAYLOAD):raise ValueError('Only the exact feature allowlist can be packaged')
    if inventory.get('complete') is not True:raise ValueError('Original complete inventory evidence required')
    if inventory.get('coverage')=='includes-only':
        if inventory.get('total_items')!=len(inventory.get('items',[])):raise ValueError('Incomplete inventory')
        existing=['includes/'+x['path'] for x in inventory['items']]
    elif inventory.get('coverage')=='includes-only-retained-summary':
        if inventory.get('enumeration_retained') is not False or inventory.get('total_items')!=492:raise ValueError('Invalid retained inventory summary')
        existing=inventory.get('known_existing_paths',[])
    else:raise ValueError('Inventory coverage must be explicit')
    if set(existing)&set(PAYLOAD):raise ValueError('Known existing destination may not be overwritten')
    for path in existing:
        if not isinstance(path,str) or path.startswith('/') or '..' in PurePosixPath(path).parts or '\\' in path:raise ValueError('Unsafe inventory path')
    rows=[]
    for path in sorted(PAYLOAD):
        data=files[path]
        if not isinstance(data,bytes) or not data or len(data)>16777216:raise ValueError('Bounded nonempty file bytes required')
        rows.append({'path':path,'sha256':hashlib.sha256(data).hexdigest(),'size_bytes':len(data),'action':'CREATE_IF_ABSENT','overwrite_allowed':False,'absence_preflight_required':True,'no_symlink_or_path_escape':True})
    manifest={'schema':'digiforge-printify-feature-overlay-v1','source_commit':commit,'certified_implementation_base':BASE,'artifact_type':'FEATURE_ONLY_OVERLAY_NOT_WORDPRESS_PLUGIN_ZIP','installation_authorized':False,'automatic_activation':False,'schema_migrations':False,'external_execution_authorized':False,'files':rows,'installation_gates':GATES,'known_missing_shared_dependencies':['Security/FirstPartyOwnerPolicy.php'],'shared_component_policy':'REUSE_INSTALLED_ONLY; no shared source or replacement files included','entry_points':{'register':'DigiForge\\Portal\\PrintifyProductIntelligenceFeature::register()','render':'DigiForge\\Portal\\PrintifyProductIntelligenceFeature::render($baseUrl)'},'entry_point_status':'Proposed explicit calls only; actual installed extension point must be reviewed before wiring'}
    preservation={'schema':'digiforge-printify-feature-preservation-v1','default_existing_path_action':'PRESERVE','allow_deletion':False,'allow_overwrite':False,'installed_source_equivalence_asserted':False,'coverage_limit':'Prior includes-only file metadata; not a whole-plugin checksum inventory. Assets/root/module absence is unverified. Every destination requires fresh preflight.','reused_inventory':inventory,'protected_surfaces':['digiforge.php','uninstall.php','includes/Core/Plugin.php','includes/Portal/Portal.php','includes/POD/PrintifyCatalogClient.php','includes/POD/PrintifyCatalogReadRepository.php','includes/POD/PrintifyCatalogReadContract.php','includes/POD/PrintifyCatalogReadPolicy.php','includes/Security/','includes/Database/','includes/Integrations/','assets/portal.css','all other installed files, configuration, data and hooks']}
    entries={'feature-manifest.json':encoded(manifest),'preservation-manifest.json':encoded(preservation),'INSTALLATION-GATES.md':instructions.encode()}
    entries.update({'payload/'+p:files[p] for p in PAYLOAD})
    out=io.BytesIO()
    with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name in sorted(entries):
            info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,entries[name])
    return out.getvalue()

def git_bytes(repo,commit,path):
    return subprocess.run(['git','-C',str(repo),'show',commit+':'+path],capture_output=True,check=True).stdout

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repository',type=Path,default=Path('.'))
    parser.add_argument('--ref',required=True)
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    if not re.fullmatch('[a-f0-9]{40}',args.ref):parser.error('Use an exact 40-hex commit')
    subprocess.run(['git','-C',str(args.repository),'merge-base','--is-ancestor',BASE,args.ref],check=True)
    files={p:git_bytes(args.repository,args.ref,p) for p in PAYLOAD}
    modes=subprocess.run(['git','-C',str(args.repository),'ls-tree','-r',args.ref,'--',*PAYLOAD],capture_output=True,text=True,check=True).stdout.splitlines()
    if len(modes)!=len(PAYLOAD) or any(line.split()[0] not in ('100644','100755') for line in modes):raise ValueError('Regular committed source files required')
    inventory=json.loads(git_bytes(args.repository,args.ref,'docs/engineering/printify-feature/installed-inventory-summary.json'))
    instructions=git_bytes(args.repository,args.ref,'docs/engineering/printify-feature/README.md').decode()
    data=build_bundle(args.ref,files,inventory,instructions)
    args.output.parent.mkdir(parents=True,exist_ok=True);args.output.write_bytes(data)
    args.output.with_suffix(args.output.suffix+'.sha256').write_text(hashlib.sha256(data).hexdigest()+'  '+args.output.name+'\n')
    print(json.dumps({'source_commit':args.ref,'feature_files':len(files),'sha256':hashlib.sha256(data).hexdigest(),'installation_authorized':False}))

if __name__=='__main__':main()
