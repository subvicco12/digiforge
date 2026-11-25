// Disposable single-process test adapter. Never included in Worker deployment.
import { execFile } from 'node:child_process';
import { readFileSync, writeFileSync, renameSync, existsSync } from 'node:fs';
import { HostingerReadIngress, ProtectedReadAdmission } from './worker.mjs';
import { PolicyAdmission, RecoveryFloor, seal } from './policy-service.mjs';
import { ReadCustodyService } from './custody-service.mjs';
import { DetachedSigningService } from './signing-service.mjs';

const [php,script,plugin,journal] = process.argv.slice(2);
const input = JSON.parse(readFileSync(0,'utf8'));
const invoke = (port,data) => new Promise((resolve,reject) => {
    const child = execFile(php,[script,plugin,journal,port],{
        encoding:'utf8',timeout:4000,maxBuffer:1048576,
    },(error,stdout,stderr) => {
        if (error) {
            reject(new Error('Fixture port failed: ' + port + ' ' + error.code + ' ' + stderr));
            return;
        }
        try { resolve(JSON.parse(stdout)); } catch (failure) { reject(failure); }
    });
    child.stdin.on('error',reject);
    child.stdin.end(JSON.stringify(data));
});
const proof = await invoke('verify',input);
const scope = proof.scope_digest;
const consumed = JSON.parse(readFileSync(journal,'utf8'));
const write = (path,value) => {
    writeFileSync(path+'.next',JSON.stringify(value),{mode:0o600});
    renameSync(path+'.next',path);
};
const storage = {transaction: async fn => fn({
    get: async () => ({epoch:'fixture',sequence:consumed.length,revision:1,scope_digest:scope,
        count:consumed.length,consumed}),
    put: async (_key,value) => write(journal,value.consumed),
})};
const service = fn => ({fetch:async request => {
    const path = new URL(request.url).pathname;
    const data = await request.json();
    return path === '/ready' ? Response.json({ready:true,protocol:data.protocol}) : fn(path,data);
}});
const policyFile=journal+'.policy';
const floorFile=journal+'.anchor';
const genesis={scope_digest:scope,epoch:'fixture',sequence:0,revision:1,stop_all:false,external_lock:false,
    release_hold:false,revoked:[],admissions:[]};
if (!existsSync(policyFile) && !existsSync(floorFile) && consumed.length===0) {
    write(policyFile,{state:genesis});
    write(floorFile,{scope_digest:scope,epoch:'fixture',sequence:0,digest:await seal(genesis)});
}
const durable=path=>{
    const get=async()=>JSON.parse(readFileSync(path,'utf8'));
    return {get,transaction:fn=>fn({get,put:async(_key,value)=>write(path,value)})};
};
const POLICY=new PolicyAdmission({storage:durable(policyFile)},{MODE:'offline-fixture',
    WITNESS:new RecoveryFloor({storage:durable(floorFile)},{MODE:'offline-fixture'})});
const CONTRACTS = service(async (path,data) => Response.json(await invoke(path.slice(1),data)));
// RFC 8410 PKCS#8 envelope over the existing deterministic fixture seed.
const key=await crypto.subtle.importKey('pkcs8',Buffer.from('302e020100300506032b657004220420'+'62'.repeat(32),'hex'),
    'Ed25519',false,['sign']);
const REPLY_SIGNER=new DetachedSigningService({role:'reply',mode:'offline-fixture',keyProvider:async()=>key,
    verifyIntent:async (_role,receipt)=>receipt.request_digest===input.grant.request_digest
        && receipt.grant_id===input.grant.id ? {authorized:true,...await invoke('digest',receipt)} : null,
});
const FIXTURE_CUSTODY=new ReadCustodyService({MODE:'offline-fixture',POLICY,CONTRACTS,
    CREDENTIAL_PROVIDER:{resolve:async scope=>scope.account==='fixture-account' && scope.shop==='107'
        && scope.integration==='fixture-read' && scope.custody==='custody-ref:fixture'
        ? {synthetic:true,value:'fixture-access'} : null},
    FIXTURE_UPSTREAM:{fetch:async request=>{
        if (request.method!=='GET' || request.redirect!=='manual'
            || request.url!=='https://api.printify.com'+input.request.path
            || request.headers.get('authorization')!=='Bearer fixture-access') {
            return Response.json({}, {status:403});
        }
        const fixture=await invoke('get',{request:input.request});
        return Response.json(fixture.body,{status:fixture.status});
    }},
});
const gateway = new ProtectedReadAdmission({storage},{
    EXECUTION_MODE:'offline-fixture', POLICY, CONTRACTS, REPLY_SIGNER, FIXTURE_CUSTODY,
});
const ingress = new HostingerReadIngress({
    EXECUTION_MODE:'offline-fixture',
    INGRESS_AUTH:service(async (_path,{credential,envelope}) => {
        if (credential !== 'Bearer synthetic-hostinger-instance') {
            return Response.json({authenticated:false});
        }
        const binding = await invoke('verify',envelope);
        return Response.json({...envelope.request.scope,operation:envelope.request.operation,
            authenticated:binding.verified,scope_digest:binding.scope_digest,
            request_digest:binding.request_digest,grant_id:envelope.grant.id});
    }),
    ADMISSION:{idFromName:name=>name,get:name=>name === scope ? gateway : null},
});
const result = await ingress.fetch(new Request('https://gateway.invalid/read',{
    method:'POST',headers:{authorization:'Bearer synthetic-hostinger-instance'},body:JSON.stringify(input),
}));
if (!result.ok) {
    process.stderr.write('Workers fixture returned '+result.status+' '+await result.text()+'\n');
    process.exit(1);
}
process.stdout.write(await result.text());
