import test from 'node:test';
import assert from 'node:assert/strict';
import { checkpointSenderFixture } from './checkpoint-final-sender-support.mjs';
import { canonical, hash } from '../protocol.mjs';
import { setImmediate as turn } from 'node:timers/promises';

const fresh = options => checkpointSenderFixture({ mode:'SYNTHETIC_CHECKPOINT_QUALIFICATION', ...options });

test('checkpoint-bound final sender is independently selectable and disabled by default', async () => {
  let module;
  try { module = await import('../checkpoint-final-sender.mjs'); }
  catch { assert.fail('Missing disabled-by-default checkpoint/final-sender integration'); }
  assert.equal(typeof module.SyntheticCheckpointFinalSender,'function');
  assert.throws(()=>new module.SyntheticCheckpointFinalSender({}),/SYNTHETIC|DISABLED/);
});

test('default mode and unknown modes cannot send a synthetic provider request',async()=>{
  const f=await checkpointSenderFixture();
  const r=await f.execute();assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
  await assert.rejects(()=>fresh({mode:'production'}));
});
test('checkpoint acknowledgement joins the actual synthetic journal and mandatory final sender',async()=>{
  const f=await fresh(),r=await f.execute();
  assert.equal(r.receipt.manifest.status,'OBSERVED');assert.equal(r.retry_permitted,false);
  assert.equal(r.receipt.manifest.qualification,'TESTED_SYNTHETIC');assert.equal(f.base.calls.length,1);
  assert.equal(await f.base.verifyReceipt(r.receipt),true);
  const d=f.root.read(),op=f.checkpoint.context.binding.operation_id;
  assert.ok(d.nonces.includes('checkpoint-intent:'+op));
  assert.equal(d.nonces.filter(n=>n.startsWith('checkpoint-proof:')).length,1);
  assert.equal(d.leases.length,0);
  const events=f.base.state.read().events.map(e=>e.manifest.event);
  assert.ok(events.includes('RESERVED')&&events.includes('ADMITTED')&&events.includes('TERMINAL'));
});
test('guarded service denies ordinary EXECUTE instead of falling back around checkpoint admission',async()=>{
  const f=await fresh();await assert.rejects(()=>f.client.call('EXECUTE',f.prepared.payload));assert.equal(f.base.calls.length,0);
});
for(const change of ['payload','operation','shop','account','approval','product_version'])test('reject checkpoint substitution '+change,async()=>{
  const f=await fresh(),p=f.payload();
  if(change==='payload')p.authentication.manifest.nonce='1'.repeat(32);
  if(change==='operation')p.checkpoint.context.binding.operation_id='f'.repeat(64);
  if(change==='shop')p.checkpoint.context.binding.provider_shop_id='28925875';
  if(change==='account')p.checkpoint.context.provider_account_reference='other-account';
  if(change==='approval')p.checkpoint.context.binding.approval_reference='f'.repeat(64);
  if(change==='product_version')p.checkpoint.context.binding.product_version++;
  const r=await f.execute(p);assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('concurrent duplicate dispatches consume only one durable operation intent',async()=>{
  const f=await fresh(),p=f.payload();
  const results=await Promise.all([f.execute(p),f.execute(p)]);
  assert.equal(results.filter(r=>r.receipt?.manifest.status==='OBSERVED').length,1);
  assert.equal(f.base.calls.length,1);assert.equal(f.root.read().nonces.filter(n=>n.startsWith('checkpoint-intent:')).length,1);
  const r=await f.execute();assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,1);
});
for(const when of ['before-proof','after-witness-signature','immediately-before-physical-fetch','during-response'])test('STOP fences dispatch '+when,async()=>{
  const f=await fresh();
  if(when==='before-proof')f.root.stop();
  if(when==='after-witness-signature'){const observe=f.checkpoint.ports.observe;f.checkpoint.ports.observe=async q=>{const r=await observe(q);f.root.stop();return r;};}
  if(when==='immediately-before-physical-fetch')f.base.custody.perform=fn=>{f.root.stop();return fn();};
  if(when==='during-response')f.base.provider.during=()=>f.root.stop();
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(r.retry_permitted,false);
  assert.equal(f.base.calls.length,when==='during-response'?1:0);
});
test('an unavailable witness cannot be replaced by cached observer evidence',async()=>{
  const f=await fresh();f.checkpoint.beforeWitness=()=>{throw Error('SYNTHETIC-PRIVATE-WITNESS-OUTAGE');};
  const r=await f.execute();assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);assert.equal(JSON.stringify(r).includes('PRIVATE'),false);
});
test('witness outage at deferred custody callback prevents physical dispatch',async()=>{
  const f=await fresh();f.base.custody.perform=fn=>{f.base.root.available=false;return fn();};
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('lost intent-commit acknowledgement retains the consumed tombstone and UNKNOWN',async()=>{
  const f=await fresh(),observe=f.checkpoint.ports.observe;
  f.checkpoint.ports.observe=async q=>{const r=await observe(q);f.storage.loseAck=true;return r;};
  const r=await f.execute();assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
  assert.ok(f.root.read().nonces.includes('checkpoint-intent:'+f.checkpoint.context.binding.operation_id));
  assert.equal((await f.execute()).status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('crash after reservation retains the intent and fences recovery without automatic replay',async()=>{
  const f=await fresh();f.base.onEvent('ADMITTED',()=>f.root.boot());
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
  assert.equal(f.root.read().recovery,true);assert.ok(f.root.read().nonces.some(n=>n.startsWith('checkpoint-intent:')));
});
test('journal corruption cannot authorize a physical send',async()=>{
  const f=await fresh();f.base.onEvent('ADMITTED',()=>f.base.storage.db.exec('DELETE FROM df_events'));
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('checkpoint expiry during deferred custody cannot produce a fresh observation',async()=>{
  const f=await fresh();f.base.custody.perform=fn=>{f.base.clock.now+=31;return fn();};
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('stable intent and native fingerprint mismatch are rejected before tombstone admission',async()=>{
  const f=await fresh();f.checkpoint.changeCheckpoint=m=>({...m,context:{...m.context,binding:{...m.context.binding,intent_fingerprint:'f'.repeat(64)}}});
  const r=await f.execute();assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
  assert.equal(f.root.read().nonces.filter(n=>n.startsWith('checkpoint-intent:')).length,0);
});
test('invalid inner approval cannot consume a valid intent or query the checkpoint issuer',async()=>{
  const f=await fresh({tamperPrepared:p=>{p.authority.signature='A'.repeat(88);}});
  const r=await f.execute();assert.ok(['UNKNOWN','DENIED'].includes(r.status));assert.equal(f.base.calls.length,0);
  assert.equal(f.checkpoint.calls,0,'validate native read authority before requesting checkpoint evidence');
  assert.equal(f.root.read().nonces.filter(n=>n.startsWith('checkpoint-intent:')).length,0);
});
test('revocation generation alone is rechecked at the last custody boundary',async()=>{
  const f=await fresh();f.base.custody.perform=fn=>{f.root.change(d=>{d.generation++;});return fn();};
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('expired custody identity cannot be replaced by an otherwise valid observation',async()=>{
  const f=await fresh();f.base.custody.perform=fn=>{f.base.custody.generation++;return fn();};
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
test('no provider credentials or private response fields escape a successful receipt',async()=>{
  const f=await fresh(),r=await f.execute(),text=JSON.stringify(r);
  assert.equal(r.receipt.manifest.status,'OBSERVED');assert.equal(text.includes('buyer-private'),false);
  assert.equal(text.includes('SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL'),false);assert.equal(text.includes('Authorization'),false);
});
test('in-flight timeout retains durable consumption and cannot trigger a retry',async t=>{
  const f=await fresh();let entered,release;
  const ready=new Promise(r=>entered=r),pendingResponse=new Promise(r=>release=r);
  f.base.provider.fetchOverride=async()=>{entered();await pendingResponse;return new Response('{}');};
  t.mock.timers.enable({apis:['setTimeout']});
  try {
    const pending=f.execute();await ready;t.mock.timers.tick(20001);f.base.clock.now+=21;
    const r=await pending;assert.equal(r.status,'UNKNOWN');assert.equal(r.retry_permitted,false);
    assert.ok(f.root.read().nonces.includes('checkpoint-intent:'+f.checkpoint.context.binding.operation_id));
    assert.equal((await f.execute()).status,'UNKNOWN');assert.equal(f.base.calls.length,1);
    release();for(let i=0;i<8;i++)await turn();
    assert.equal(f.base.calls.length,1);
  } finally {release();t.mock.timers.reset();}
});
test('restart cannot silently rearm a checkpoint-bound sender',async()=>{
  const f=await fresh();await f.recreate();
  const r=await f.execute();assert.equal(r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);assert.equal(f.root.read().recovery,true);
});
test('restoring subordinate database and witness cannot undo an independent emergency',async()=>{
  const f=await fresh(),snapshot=f.base.storage.snapshot(),witness=structuredClone(f.base.root);
  assert.equal((await f.execute()).receipt.manifest.status,'OBSERVED');f.root.stop();
  f.base.storage.restore(snapshot);Object.assign(f.base.root,witness);await f.recreate();
  assert.equal((await f.execute()).status,'UNKNOWN');assert.equal(f.base.calls.length,1);
});
test('COUNTEREXAMPLE: all-root rollback revives a consumed signed operation in the guarded final-sender model',async()=>{
  const f=await fresh(),snapshot=f.base.storage.snapshot(),witness=structuredClone(f.base.root);
  assert.equal((await f.execute()).receipt.manifest.status,'OBSERVED');
  const stopped=await f.client.call('EMERGENCY',await f.base.stopMessage());assert.equal(stopped.receipt.manifest.status,'DRAIN_ACKNOWLEDGED');
  assert.equal(f.root.read().stopped,true);
  f.base.storage.restore(snapshot);Object.assign(f.base.root,witness);
  f.storage.db.prepare('UPDATE df_security_authority SET revision=?,document=?').run(f.initialRoot.revision,canonical(f.initialRoot));
  f.base.runtime.fenced=false;f.base.runtime.ready=true;await f.recreate();
  assert.equal((await f.execute()).receipt.manifest.status,'OBSERVED');assert.equal(f.base.calls.length,2,'this is a recovery blocker, not a durability pass');
});
test('COUNTEREXAMPLE: a usable old credential copy remains outside a cooperative final-sender fence',async()=>{
  const f=await fresh();f.root.stop();assert.equal((await f.execute()).status,'UNKNOWN');assert.equal(f.base.calls.length,0);
  const usable=new Set(['SYNTHETIC-OLD-COPY']);let outsideAccepted=0;
  const outsideProvider=token=>{if(usable.has(token))outsideAccepted++;};
  outsideProvider('SYNTHETIC-OLD-COPY');assert.equal(outsideAccepted,1);
  usable.delete('SYNTHETIC-OLD-COPY');outsideProvider('SYNTHETIC-OLD-COPY');assert.equal(outsideAccepted,1);
});
for(const fault of ['witness-stop','witness-outage','journal-corruption'])test('last asynchronous verification cannot hide '+fault+' before fetch',async()=>{
  const f=await fresh();let armed=false,injected=false;
  const auth=f.base.custody.syntheticAuthorization;
  f.base.custody.syntheticAuthorization=()=>{armed=true;return auth();};
  const saved=crypto.subtle.verify,verify=saved.bind(crypto.subtle);
  crypto.subtle.verify=async(...args)=>{
    const result=await verify(...args),envelope=JSON.parse(new TextDecoder().decode(args[3]));
    if(armed&&!injected&&envelope.key_id==='state-v1'){
      injected=true;
      if(fault==='witness-stop'){f.base.root.stopped=true;f.base.root.generation++;}
      if(fault==='witness-outage')f.base.root.available=false;
      if(fault==='journal-corruption')f.base.storage.db.exec('DELETE FROM df_events');
    }
    return result;
  };
  try {const r=await f.execute();assert.equal(injected,true);assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0,'late state/witness changes must prevent the physical invocation');}
  finally {crypto.subtle.verify=saved;}
});
for(const signer of ['checkpoint','witness'])test('physical boundary honors shorter signed '+signer+' expiry',async()=>{
  const f=await fresh();f.checkpoint[signer==='checkpoint'?'changeCheckpoint':'changeWitness']=m=>({...m,expires_at:m.issued_at+1});
  f.base.custody.perform=fn=>{f.base.clock.now+=2;return fn();};
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
for(const fault of ['root-stop','witness-outage','journal-corruption','checkpoint-expiry'])test('outer response signing cannot release OBSERVED after '+fault,async()=>{
  const f=await fresh();let injected=false;
  if(fault==='checkpoint-expiry')f.checkpoint.changeWitness=m=>({...m,expires_at:m.issued_at+1});
  const saved=crypto.subtle.sign,sign=saved.bind(crypto.subtle);
  crypto.subtle.sign=async(...args)=>{
    const result=await sign(...args),envelope=JSON.parse(new TextDecoder().decode(args[2]));
    if(!injected&&envelope.key_id==='authority-response'){
      injected=true;
      if(fault==='root-stop')f.root.stop();
      if(fault==='witness-outage')f.base.root.available=false;
      if(fault==='journal-corruption')f.base.storage.db.exec('DELETE FROM df_events');
      if(fault==='checkpoint-expiry')f.base.clock.now+=2;
    }
    return result;
  };
  try {const r=await f.execute();assert.equal(injected,true);assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,1);assert.equal(r.retry_permitted,false);}
  finally {crypto.subtle.sign=saved;}
});
for(const result of ['true','false','promise'])test('final witness boundary rejects '+result+' permission substitutes',async()=>{
  const f=await fresh();f.base.runtime.witness.currentGuard=()=>result==='promise'?Promise.resolve():result==='true';
  const r=await f.execute();assert.equal(r.receipt?.manifest.status||r.status,'UNKNOWN');assert.equal(f.base.calls.length,0);
});
