import test from 'node:test';
import assert from 'node:assert/strict';
import { setImmediate as turn } from 'node:timers/promises';
import { fixture } from './support.mjs';

for (const field of ['available','exclusive','hostCopiesInvalidated']) {
 test(`custody ${field} requires literal true`, async () => {
  const f=await fixture(); const s=await f.session(); f.custody[field]='true';
  assert.equal((await f.dispatch(s,0)).status,'DENIED');assert.equal(f.calls.length,0);
 });
}
for (const generation of [undefined,0,-1,'1',Number.MAX_SAFE_INTEGER+1]) {
 test(`custody rejects invalid generation ${generation}`,async()=>{
  const f=await fixture();const s=await f.session();f.custody.generation=generation;
  assert.equal((await f.dispatch(s,0)).status,'DENIED');assert.equal(f.calls.length,0);
 });
}
test('deferred custody callback cannot fetch after deadline',async t=>{
 const f=await fixture();f.custody.generation=1;const s=await f.session();
 let release,entered,credentials=0;const ready=new Promise(r=>entered=r);
 const authorization=f.custody.syntheticAuthorization;f.custody.syntheticAuthorization=()=>{credentials++;return authorization();};
 f.custody.perform=fn=>new Promise(resolve=>{release=()=>resolve(Promise.resolve().then(fn));entered();});
 t.mock.timers.enable({apis:['setTimeout']});
 try {
  const pending=f.dispatch(s,0);await ready;t.mock.timers.tick(20001);const result=await pending;
  assert.equal(result.receipt.manifest.status,'UNKNOWN');release();await turn();
  assert.equal(f.calls.length,0,'expired deferred callback must never reach fetch');
  assert.equal(credentials,0,'expired callback must not acquire even synthetic authorization');
  assert.equal((await f.dispatch(s,0)).status,'DENIED');
 }finally{t.mock.timers.reset();}
});
for (const change of ['revoke','rotate']) {
 test(`deferred custody callback rechecks ${change} at fetch boundary`,async()=>{
  const f=await fixture();f.custody.generation=1;const s=await f.session();
  f.custody.perform=async fn=>{if(change==='revoke')f.custody.available=false;else f.custody.generation++;return fn();};
  const result=await f.dispatch(s,0);assert.equal(result.receipt.manifest.status,'UNKNOWN');
  assert.equal(f.calls.length,0);assert.equal(result.retry_permitted,false);
 });
 test(`custody ${change} during headers suppresses response evidence`,async()=>{
  const f=await fixture();f.custody.generation=1;const s=await f.session();
  f.provider.during=()=>{if(change==='revoke')f.custody.available=false;else f.custody.generation++;};
  const result=await f.dispatch(s,0);assert.equal(result.receipt.manifest.status,'UNKNOWN');
  assert.deepEqual(result.receipt.manifest.observations,{values_disclosed:false});
  assert.equal(f.calls.length,1);assert.equal((await f.dispatch(s,0)).status,'DENIED');
 });
}
test('rotation while reading body suppresses otherwise valid JSON',async()=>{
 const f=await fixture();f.custody.generation=1;const s=await f.session();
 f.provider.fetchOverride=()=>new Response(new ReadableStream({start(c){c.enqueue(new TextEncoder().encode('[{"id":1}]'));},pull(c){f.custody.generation++;c.close();}}));
 const r=await f.dispatch(s,0);assert.equal(r.receipt.manifest.status,'UNKNOWN');assert.deepEqual(r.receipt.manifest.observations,{values_disclosed:false});
});
test('unchanged valid custody emits minimized exact-GET receipt',async()=>{
 const f=await fixture();f.custody.generation=1;const s=await f.session();const r=await f.dispatch(s,0);
 assert.equal(r.receipt.manifest.status,'OBSERVED');assert.equal(f.calls.length,1);
 assert.equal(f.calls[0].options.method,'GET');assert.equal(r.retry_permitted,false);
 assert.ok(!JSON.stringify(r).includes('SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL'));
 assert.ok(!JSON.stringify(r).includes('buyer-private'));
});
test('custody cannot replay a physical callback to retry the same slot',async()=>{
 const f=await fixture();const s=await f.session();
 f.custody.perform=async fn=>{await fn();return fn();};
 const r=await f.dispatch(s,0);assert.equal(f.calls.length,1);assert.equal(r.receipt.manifest.status,'UNKNOWN');
 assert.equal((await f.dispatch(s,0)).status,'DENIED');
});
test('caller mutation during deferred custody cannot substitute request endpoint',async()=>{
 const f=await fixture();const s=await f.session();const original=f.witness.admitAndSend.bind(f.witness);
 f.witness.admitAndSend=(claim,request,work)=>{
  f.custody.perform=fn=>{try{request.endpoint='/v1/shops/999/products.json';}catch{}return fn();};
  return original(claim,request,work);
 };
 const r=await f.dispatch(s,0);assert.equal(f.calls.length,1);
 assert.equal(f.calls[0].url,'https://api.printify.com/v1/shops.json');
 assert.equal(r.receipt.manifest.status,'OBSERVED');
});
test('custody change while rejection body cancellation awaits suppresses evidence',async()=>{
 const f=await fixture();const s=await f.session();
 f.provider.fetchOverride=()=>({status:403,headers:new Headers(),body:{async cancel(){f.custody.generation++;}}});
 const r=await f.dispatch(s,0);assert.equal(r.receipt.manifest.status,'UNKNOWN');
 assert.deepEqual(r.receipt.manifest.observations,{values_disclosed:false});
});
for (const settlement of ['return','reject']) {
 test(`custody early ${settlement} permanently closes retained callback`,async()=>{
  const f=await fixture();const s=await f.session();let callback;
  f.custody.perform=async fn=>{callback=fn;if(settlement==='reject')throw Error('private custody fault');};
  const r=await f.dispatch(s,0);assert.notEqual(r.receipt?.manifest.status,'OBSERVED');assert.equal(f.calls.length,0);
  await assert.rejects(async()=>callback());assert.equal(f.calls.length,0);assert.equal(r.retry_permitted,false);
 });
}
for (const change of ['revoke','rotate']) {
 test(`authority wrapper rechecks custody ${change} after asynchronous guard`,async()=>{
  const {authorityFixture}=await import('./authority-support.mjs');const f=await authorityFixture();
  const s=await f.client.call('SESSION',await f.base.ownerAdmission());let issued=false;
  const authorization=f.base.custody.syntheticAuthorization;
  f.base.custody.syntheticAuthorization=()=>{issued=true;return authorization();};
  const guard=f.service.guard.bind(f.service);
  f.service.guard=async id=>{await guard(id);if(issued){if(change==='revoke')f.base.custody.available=false;else f.base.custody.generation++;}};
  const r=await f.client.call('EXECUTE',await f.base.message(s,0));
  assert.notEqual(r.receipt?.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,0);
 });
}
test('concurrent custody callback retry cannot create a second GET',async()=>{
 const f=await fixture();const s=await f.session();
 f.custody.perform=fn=>Promise.all([Promise.resolve().then(fn),Promise.resolve().then(fn)]);
 const r=await f.dispatch(s,0);assert.equal(f.calls.length,1);assert.equal(r.receipt.manifest.status,'UNKNOWN');
 assert.equal(r.retry_permitted,false);
});
test('revoked header response is aborted and its unread body cancelled',async()=>{
 const f=await fixture();const s=await f.session();let cancelled=false,signal;
 f.provider.fetchOverride=(_url,options)=>{signal=options.signal;f.custody.available=false;return new Response(new ReadableStream({cancel(){cancelled=true;}}));};
 const r=await f.dispatch(s,0);assert.equal(r.receipt.manifest.status,'UNKNOWN');
 assert.equal(signal.aborted,true);assert.equal(cancelled,true);assert.equal(f.calls.length,1);
});
for (const change of ['revoke','rotate']) {
 test(`open body is cancelled after custody ${change} during reader.read`,async()=>{
  const f=await fixture();const s=await f.session();let cancelled=false;
  f.provider.fetchOverride=()=>new Response(new ReadableStream({pull(c){if(change==='revoke')f.custody.available=false;else f.custody.generation++;c.enqueue(new TextEncoder().encode('{}'));},cancel(){cancelled=true;}},{highWaterMark:0}));
  const r=await f.dispatch(s,0);assert.equal(r.receipt.manifest.status,'UNKNOWN');
  assert.equal(cancelled,true);assert.equal(f.calls.length,1);assert.equal(r.retry_permitted,false);
 });
}
