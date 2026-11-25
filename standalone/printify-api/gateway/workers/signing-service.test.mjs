import test from 'node:test';
import assert from 'node:assert/strict';
import { DetachedSigningService } from './signing-service.mjs';

test('dedicated Ed25519 keys require exact approval and refuse another role',async()=>{
    const keys=await crypto.subtle.generateKey('Ed25519',false,['sign','verify']);
    const digest='d'.repeat(64);
    const now=Math.floor(Date.now()/1000);
    const payload={issuer:'fixture-custodian',audience:'DF_STANDALONE_GET_1',request_digest:'a'.repeat(64),
        id:'b'.repeat(32),issued_at:now,expires_at:now+30,policy_revision:1};
    let authorized=true;
    const signer=new DetachedSigningService({role:'grant',issuer:'fixture-custodian',mode:'offline-fixture',
        keyProvider:async()=>keys.privateKey,verifyIntent:async()=>({authorized,digest})});
    const signed=await signer.sign(payload);
    assert.equal(await crypto.subtle.verify('Ed25519',keys.publicKey,Buffer.from(signed,'hex'),new TextEncoder().encode(digest)),true);
    authorized=false;
    assert.equal(await signer.sign(payload),null);
    authorized=true;
    assert.equal(await signer.sign({...payload,expires_at:now+61}),null);
    assert.equal(await signer.sign({schema:'DF_STANDALONE_GET_REPLY_1'}),null);
});
