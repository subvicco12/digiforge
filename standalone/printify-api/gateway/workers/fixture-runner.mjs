// Disposable single-process test adapter. Never included in Worker deployment.
import { execFile } from 'node:child_process';
import { createPrivateKey, createPublicKey } from 'node:crypto';
import { readFileSync, writeFileSync, renameSync, existsSync } from 'node:fs';
import { ProtectedReadAdmission } from './worker.mjs';
import { PolicyAdmission, RecoveryFloor, seal } from './policy-service.mjs';
import services from './t4-fixture-services.mjs';

import { T4Ingress } from './t4-entry.mjs';
import { OperatorAssertions, signJwt } from './ingress-auth.mjs';

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
const pkcs8=seed=>'302e020100300506032b657004220420'+seed.repeat(32);
const grantPrivate=createPrivateKey({key:Buffer.from(pkcs8('61'),'hex'),format:'der',type:'pkcs8'});
const grantPublic=createPublicKey(grantPrivate).export({format:'der',type:'spki'}).subarray(-32).toString('hex');
const config={MODE:'t4-inert',T4_FIXTURE_SCOPE:JSON.stringify(input.request.scope),
    T4_SCOPE_DIGEST:scope,T4_GRANT_PUBLIC_KEY:grantPublic,T4_REPLY_PRIVATE_PKCS8:pkcs8('62'),POLICY};
const role=(name,extra={})=>({fetch:request=>services.fetch(request,{...config,ROLE:name,...extra})});
const CONTRACTS=role('contracts');
const REPLY_SIGNER=role('reply');
const FIXTURE_CUSTODY=role('custody');
const gateway = new ProtectedReadAdmission({storage},{
    EXECUTION_MODE:'offline-fixture', POLICY, CONTRACTS, REPLY_SIGNER, FIXTURE_CUSTODY,
});
const pair=()=>crypto.subtle.generateKey({name:'RSASSA-PKCS1-v1_5',modulusLength:2048,
    publicExponent:new Uint8Array([1,0,1]),hash:'SHA-256'},false,['sign','verify']);
const [accessKey,operatorKey]=await Promise.all([pair(),pair()]);
const now=Math.floor(Date.now()/1000);
const origin='https://digiforge-t4.example.invalid';
const access={issuer:'https://access.example.invalid',audience:'t4-fixture',identityClaim:'common_name',
    identity:'fixture-hostinger',maxAge:60};
const assertion=await signJwt(accessKey.privateKey,'access-fixture',{iss:access.issuer,aud:[access.audience],
    common_name:access.identity,iat:now,nbf:now,exp:now+30});
const operatorIssuer='urn:fixture:operator';
const operatorAudience='t4-read';
const operatorRecord={...input.request.scope,workspace:input.workspace,revoked:false,
    expires_at:now+60,operations:[input.request.operation]};
const operatorEnv={T4_OPERATOR_ISSUER:operatorIssuer,T4_OPERATOR_AUDIENCE:operatorAudience,
    T4_OPERATOR_KID:'operator-fixture',T4_OPERATOR_RECORD:JSON.stringify(operatorRecord)};
const operatorService=role('operator',operatorEnv);
// The fixture-only issuer enters through the authenticated disposable WP test seam.
const issuer=new OperatorAssertions({issuer:operatorIssuer,audience:operatorAudience,kid:'operator-fixture',
    signingKey:operatorKey.privateKey,current:async expected=>{
        const reply=await operatorService.fetch(new Request('https://operator.invalid/attest',{
            method:'POST',body:JSON.stringify(expected)}));
        return reply.ok ? reply.json() : null;
    }});
const operator=new OperatorAssertions({issuer:operatorIssuer,audience:operatorAudience,
    keySet:{expiresAt:now+60,keys:{'operator-fixture':operatorKey.publicKey}},current:issuer.config.current});
const token=await issuer.issue(input,input.workspace,now);
const ingress=new T4Ingress({mode:'t4-inert',origin,access,
    accessKeys:{expiresAt:now+60,keys:{'access-fixture':accessKey.publicKey}},operator,contracts:CONTRACTS,
    admission:{idFromName:name=>name,get:name=>name===scope ? gateway : null}});
const result=await ingress.fetch(new Request(origin+'/read',{
    method:'POST',headers:{'cf-access-jwt-assertion':assertion},
    body:JSON.stringify({...input,operator_assertion:token}),
}));
if (!result.ok) {
    process.stderr.write('Workers fixture returned '+result.status+' '+await result.text()+'\n');
    process.exit(1);
}
process.stdout.write(await result.text());
