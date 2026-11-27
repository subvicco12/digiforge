import test from 'node:test';
import assert from 'node:assert/strict';
import { LocalSqliteStorage } from './support.mjs';
import { b64, canonical, hash } from '../protocol.mjs';
import { CustodyBridge } from '../custody-bridge/bridge.mjs';
import { CustodyCaller } from '../custody-bridge/caller.mjs';

const at=1700000000, operation_id='4'.repeat(64);
const pair=await crypto.subtle.generateKey('Ed25519',true,['sign','verify']);
const scope={shop:'107',account_reference:'fixture-account',credential_reference:'fixture-custody-read',
  source_sha:'b'.repeat(40),source_revision:2,authorization_revision:4,
  signer_key:b64(await crypto.subtle.exportKey('raw',pair.publicKey)),start:at,end:at+60};
const operation='ORDER_READ', method='GET', endpoint='/v1/shops/107/orders/order-1.json', required_scope='orders.read';
const typed_request_sha256=await hash(canonical({operation,method,endpoint,required_scope,provider_shop_id:'107',body:[]}));
const wire=JSON.stringify({schema:'DF_PRINTIFY_CUSTODY_READ_REQUEST_1',evidence_class:'SIGNED_CUSTODY_FIXTURE',
  operation_id,provider:'printify',shop:scope.shop,account_reference:scope.account_reference,
  credential_reference:scope.credential_reference,source_sha:scope.source_sha,source_revision:scope.source_revision,
  authorization_revision:scope.authorization_revision,operation,method,endpoint,required_scope,typed_request_sha256,
  timeout_ms:2000,max_response_bytes:262144});
const approval='a'.repeat(64), digest=await hash(wire);
const config={...scope,approval,request_sha256:digest,operation_id};
const instance='stage-a-custody-'+operation_id;
const payload={schema:'DF_STAGE_A_CUSTODY_READ_1',approval,wire};
const makeBridge=()=>new CustodyBridge(new LocalSqliteStorage(),scope,()=>at,
  bytes=>crypto.subtle.sign('Ed25519',pair.privateKey,bytes));
function makeCaller(service,storage=new LocalSqliteStorage(),clock=()=>at,config_=config) {
  Object.defineProperty(storage.sql,'databaseSize',{value:4096});
  return {storage,caller:new CustodyCaller(storage,config_,clock,service)};
}

test('exact private route, wrong approval/instance/wire denied, replay fenced after caller restart',async()=>{
  const bridge=makeBridge();
  let calls=0;
  const service={exchange:async wire=>{++calls;return bridge.exchange(wire);}};
  const {caller,storage}=makeCaller(service);
  for (const [candidate,id] of [[{...payload,approval:'b'.repeat(64)},instance],[payload,'wrong'],
    [{...payload,wire:wire.replace('"107"','"108"')},instance]]) {
    assert.equal((await caller.invoke(candidate,id)).state,'DENIED');
  }
  assert.equal(calls,0);
  const batch=await Promise.all([caller.invoke(payload,instance),caller.invoke(payload,instance)]);
  assert.equal(batch.filter(x=>x.record).length,1);
  assert.equal(JSON.parse(batch.find(x=>x.record).record).request_sha256,digest);
  assert.equal((await makeCaller(service,storage).caller.invoke(payload,instance)).state,'DENIED');
  assert.equal(calls,1);
});
test('forged response or lost acknowledgement stays unknown and cannot replay',async()=>{
  for (const fault of ['forged','lost']) {
    const bridge=makeBridge();
    let calls=0;
    const service={exchange:async wire=>{
      ++calls;
      const receipt=await bridge.exchange(wire);
      if (fault==='lost') throw new Error('lost');
      return {...receipt,signature:b64(new Uint8Array(64))};
    }};
    const {caller,storage}=makeCaller(service);
    assert.equal((await caller.invoke(payload,instance)).state,'UNKNOWN');
    assert.equal((await makeCaller(service,storage).caller.invoke(payload,instance)).state,'UNKNOWN');
    assert.equal(calls,1);
  }
});
test('expiry, revocation, and timeout close the route without retry',async()=>{
  const bridge=makeBridge();
  let calls=0;
  const service={exchange:wire=>{++calls;return bridge.exchange(wire);}};
  assert.equal((await makeCaller(service,undefined,()=>at+60).caller.invoke(payload,instance)).state,'DENIED');
  assert.equal(calls,0);
  bridge.revoke();
  assert.equal((await makeCaller(service).caller.invoke(payload,instance)).state,'DENIED');
  assert.equal(calls,1);
  const stalled={exchange:()=>{++calls;return new Promise(()=>{});}};
  const {caller,storage}=makeCaller(stalled);
  assert.equal((await caller.invoke(payload,instance)).state,'UNKNOWN');
  assert.equal((await makeCaller(stalled,storage).caller.invoke(payload,instance)).state,'UNKNOWN');
  assert.equal(calls,2);
});
