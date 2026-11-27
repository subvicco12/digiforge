import test from 'node:test';
import assert from 'node:assert/strict';
import { LocalSqliteStorage } from './support.mjs';
import { b64, canonical, hash } from '../protocol.mjs';
import { CustodyBridge } from '../custody-bridge/bridge.mjs';

const pair=await crypto.subtle.generateKey('Ed25519',true,['sign','verify']);
const pin=b64(await crypto.subtle.exportKey('raw',pair.publicKey));
const start=1700000000;
const scope={shop:'107',account_reference:'fixture-account',credential_reference:'fixture-custody-read',
  source_sha:'b'.repeat(40),source_revision:2,authorization_revision:4,signer_key:pin,start,end:start+120};
const method='GET', endpoint='/v1/shops/107/orders/order-1.json', operation='ORDER_READ', required_scope='orders.read';
const typed_request_sha256=await hash(canonical({operation,provider_shop_id:'107',method,endpoint,body:[],required_scope}));
const wire=(id,changes={})=>JSON.stringify({schema:'DF_PRINTIFY_CUSTODY_READ_REQUEST_1',
  evidence_class:'SIGNED_CUSTODY_FIXTURE',operation_id:String(id).padStart(64,'0'),provider:'printify',
  shop:scope.shop,account_reference:scope.account_reference,credential_reference:scope.credential_reference,
  source_sha:scope.source_sha,source_revision:scope.source_revision,authorization_revision:scope.authorization_revision,
  operation,method,endpoint,required_scope,typed_request_sha256,timeout_ms:2000,max_response_bytes:262144,...changes});
const port=(storage,changes={},clock=()=>start,sign)=>new CustodyBridge(storage,{...scope,...changes},clock,
  sign??((bytes)=>crypto.subtle.sign('Ed25519',pair.privateKey,bytes)));
const rejected=(code)=>result=>assert.deepEqual(result,{state:'DENIED',reason:code,retry_permitted:false});
test('exact PHP order and bytes, private receipt provenance and signer pin',async()=>{
  const bridge=port(new LocalSqliteStorage());
  const result=await bridge.exchange(wire(1));
  const record=JSON.parse(result.record);
  assert.equal(record.request_sha256,await hash(wire(1)));
  assert.equal(record.content_sha256,await hash(result.body));
  assert.equal(record.evidence_class,'SIGNED_CUSTODY_FIXTURE');
  assert.equal(await crypto.subtle.verify('Ed25519',pair.publicKey,
    Buffer.from(result.signature,'base64'),new TextEncoder().encode(record.schema+'\n'+result.record)),true);
});
test('substitution, stale revision, write method, oversized wire and duplicate JSON keys deny',async()=>{
  const bridge=port(new LocalSqliteStorage());
  for (const change of [{shop:'108'},{account_reference:'other'},{credential_reference:'other'},
    {source_revision:1},{authorization_revision:3},{method:'POST'},{typed_request_sha256:'0'.repeat(64)}])
    rejected('REQUEST')(await bridge.exchange(wire(2,change)));
  rejected('REQUEST')(await bridge.exchange(wire(2)+' '.repeat(8192)));
  rejected('REQUEST')(await bridge.exchange(wire(2).replace('"shop":"107"','"shop":"108","shop":"107"')));
  assert.equal(JSON.parse((await bridge.exchange(wire(2))).record).status,200);
});
test('reservation survives restart, concurrent duplicate loses, rate denial is signed and consumed',async()=>{
  const store=new LocalSqliteStorage();
  const bridge=port(store);
  const both=await Promise.all([bridge.exchange(wire(1)),bridge.exchange(wire(1))]);
  assert.equal(both.filter(r=>r.record).length,1);
  const fresh=port(store);
  rejected('REPLAY')(await fresh.exchange(wire(1)));
  await fresh.exchange(wire(2));
  await fresh.exchange(wire(3));
  assert.equal(JSON.parse((await fresh.exchange(wire(4))).record).status,429);
  rejected('REPLAY')(await fresh.exchange(wire(4)));
});
test('revocation, window expiry and clock rollback fail closed',async()=>{
  const store=new LocalSqliteStorage();
  let now=start;
  const bridge=port(store,{},()=>now);
  await bridge.exchange(wire(1));
  now--;
  rejected('WINDOW')(await bridge.exchange(wire(2)));
  now=start+120;
  rejected('WINDOW')(await bridge.exchange(wire(2)));
  now=start;
  bridge.revoke();
  rejected('REVOKED')(await port(store).exchange(wire(2)));
});
test('lost acknowledgement and failed signature fence restart without replay',async()=>{
  const store=new LocalSqliteStorage();
  const bridge=port(store);
  store.loseAck=true;
  assert.equal((await bridge.exchange(wire(1))).state,'UNKNOWN');
  assert.equal((await port(store).exchange(wire(2))).state,'UNKNOWN');
  const other=new LocalSqliteStorage();
  assert.equal((await port(other,{},()=>start,async()=>new Uint8Array(64)).exchange(wire(1))).state,'UNKNOWN');
  assert.equal((await port(other).exchange(wire(2))).state,'UNKNOWN');
});
test('signing deadline consumes the attempt and fences subsequent work',async()=>{
  const store=new LocalSqliteStorage();
  const bridge=port(store,{},()=>start,()=>new Promise(()=>{}));
  assert.equal((await bridge.exchange(wire(1))).state,'TIMEOUT');
  assert.equal((await port(store).exchange(wire(2))).state,'UNKNOWN');
});

test('in-flight revocation suppresses the receipt and survives restart',async()=>{
  const store=new LocalSqliteStorage();
  let release, started;
  const ready=new Promise(resolve=>{started=resolve;});
  const bridge=port(store,{},()=>start,async bytes=>{
    await new Promise(resolve=>{release=resolve; started();});
    return crypto.subtle.sign('Ed25519',pair.privateKey,bytes);
  });
  const pending=bridge.exchange(wire(1));
  await ready;
  bridge.revoke();
  release();
  assert.equal((await pending).state,'UNKNOWN');
  rejected('REVOKED')(await port(store).exchange(wire(2)));
});
