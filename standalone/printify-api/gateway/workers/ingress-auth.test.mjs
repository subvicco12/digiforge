import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyJwt, signJwt, OperatorAssertions } from './ingress-auth.mjs';

const pair = () => crypto.subtle.generateKey({name:'RSASSA-PKCS1-v1_5',modulusLength:2048,
    publicExponent:new Uint8Array([1,0,1]),hash:'SHA-256'},false,['sign','verify']);
const now=2000;
test('Access verification pins audience, issuer, service identity, time and current keys',async()=>{
    const first=await pair(), next=await pair();
    const claims={iss:'https://fixture.invalid',aud:['fixture'],common_name:'svc',iat:now-1,nbf:now-1,exp:now+30};
    const config={issuer:claims.iss,audience:'fixture',identityClaim:'common_name',identity:'svc',maxAge:60};
    const keys={expiresAt:now+60,keys:{old:first.publicKey,new:next.publicKey}};
    const original=await signJwt(first.privateKey,'old',claims);
    assert.equal((await verifyJwt(original,config,keys,now)).common_name,'svc');
    for(const modified of [{aud:['wrong']},{iss:'https://other.invalid'},{common_name:'other'},{exp:now},{nbf:now+1}]) {
        assert.equal(await verifyJwt(await signJwt(first.privateKey,'old',{...claims,...modified}),config,keys,now),null);
    }
    assert.equal(await verifyJwt(original,config,{...keys,keys:{new:next.publicKey}},now),null);
    assert.equal(await verifyJwt(original,config,{...keys,expiresAt:now},now),null);
    assert.equal(await verifyJwt(original,config,null,now),null);
    assert.ok(await verifyJwt(await signJwt(next.privateKey,'new',claims),config,keys,now));
    assert.equal(await verifyJwt(await signJwt(next.privateKey,'old',claims),config,keys,now),null);
});

test('operator assertion enforces exact intent and current independently verified revisions',async()=>{
    const keys=await pair();
    const envelope={request:{scope:{site:'wp-fixture',actor:7,session:'a'.repeat(64),revision:'b'.repeat(64),
        account:'acct',shop:'107',integration:'test'},operation:'SHOPS_READ'},grant:{request_digest:'c'.repeat(64),id:'d'.repeat(32)}};
    const workspace='e'.repeat(64);
    let active=true;
    const auth=new OperatorAssertions({issuer:'urn:fixture:operator',audience:'get-fixture',kid:'test',
        signingKey:keys.privateKey,keySet:{expiresAt:now+60,keys:{test:keys.publicKey}},
        current:async expected=> active && expected.workspace===workspace ? expected : null});
    const token=await auth.issue(envelope,workspace,now);
    assert.ok(token);
    assert.ok(await auth.verify(token,envelope,workspace,now));
    assert.equal(await auth.verify(token,envelope,'f'.repeat(64),now),null);
    assert.equal(await auth.verify(token,envelope,workspace,now+30),null);
    const crossSite=structuredClone(envelope);
    crossSite.request.scope.site='wp-other';
    assert.equal(await auth.verify(token,crossSite,workspace,now),null);
    active=false;
    assert.equal(await auth.verify(token,envelope,workspace,now),null);
    assert.equal(await auth.issue(envelope,workspace,now),null);
});

test('T4 route denies alternate origins, missing operator, and injected internal carrier',async()=>{
    const {T4Ingress,default:worker}=await import('./t4-entry.mjs');
    const current=Math.floor(Date.now()/1000);
    const key=await pair();
    const origin='https://digiforge-t4.example.invalid';
    const access={issuer:'https://access.example.invalid',audience:'fixture',identityClaim:'common_name',identity:'svc',maxAge:60};
    const jwt=await signJwt(key.privateKey,'test',{iss:access.issuer,aud:[access.audience],common_name:'svc',
        iat:current,nbf:current,exp:current+30});
    const digest='a'.repeat(64);
    let handoffs=0;
    let active=false;
    const app=new T4Ingress({mode:'t4-inert',origin,access,accessKeys:{expiresAt:current+60,keys:{test:key.publicKey}},
        operator:{verify:async()=>active ? {request_digest:digest} : null},
        contracts:{fetch:async()=>Response.json({verified:true,request_digest:digest,scope_digest:digest,path:'/fixed'})},
        admission:{idFromName:name=>name,get:()=>({fetch:async req=>{
            const envelope=await req.json();
            assert.equal(req.headers.get('cf-access-jwt-assertion'),null);
            assert.equal(envelope.operator_assertion,undefined);
            ++handoffs;
            return Response.json({});
        }})},
    });
    const req=(url,headers={})=>new Request(url,{method:'POST',
        headers:{'cf-access-jwt-assertion':jwt,...headers},body:JSON.stringify({
            request:{path:'/fixed'},grant:{id:'c'.repeat(32)},signature:'fixture',operator_assertion:'fixture',
        })});
    assert.equal((await worker.fetch(req(origin+'/read'),{T4_MODE:'held'})).status,404);
    assert.equal((await app.fetch(req(origin+'/read'))).status,403);
    active=true;
    assert.equal((await app.fetch(req('https://alternate.example.invalid/read'))).status,403);
    assert.equal((await app.fetch(req(origin+'/read',{'x-df-ingress-scope':digest}))).status,403);
    assert.equal((await app.fetch(req(origin+'/read?extra=true'))).status,403);
    assert.equal((await app.fetch(req(origin+'/read',{'cf-access-jwt-assertion':''}))).status,403);
    assert.equal(handoffs,0);
    assert.equal((await app.fetch(req(origin+'/read'))).status,200);
    assert.equal(handoffs,1);
});
