import test from 'node:test';
import assert from 'node:assert/strict';
import { checkpointFixture, randomHex } from './native-checkpoint-support.mjs';
import worker from '../worker.mjs';
import { setImmediate as turn } from 'node:timers/promises';

test('native checkpoint observer exists without providing an operational factory', async () => {
  let module;
  try { module = await import('../native-checkpoint.mjs'); }
  catch { assert.fail('Non-authorizing native checkpoint observer is missing'); }
  assert.equal(typeof module.SyntheticNativeCheckpointObserver, 'function');
  assert.throws(() => new module.SyntheticNativeCheckpointObserver({}), /SYNTHETIC_ONLY/);
});

const observer = async f => new (await import('../native-checkpoint.mjs')).SyntheticNativeCheckpointObserver(f.options());
test('fresh independently signed evidence is observation only, with every native identity field bound', async () => {
  const f=await checkpointFixture(), o=await observer(f), q=f.request();
  const r=await o.observe(q);
  assert.equal(r.schema,'DF_NATIVE_CHECKPOINT_OBSERVATION_1');
  assert.equal(r.recovery_epoch,f.root.read().epoch);
  assert.equal(r.checkpoint_epoch,r.recovery_epoch);
  assert.equal(r.cryptographically_verified,true);
  for(const k of ['mutation_authorized','external_execution_authorized','operational_authority','retry_permitted','independent_recovery_qualified'])assert.equal(r[k],false);
  assert.equal(r.release_hold,true);assert.equal(f.calls,2);
  assert.equal('signature' in r,false);assert.equal('binding' in r,false);
});
for(const [field,value] of [['shop_id',3],['shop_key','standard_pod'],['integration_id',4],['credential_reference',4],['actor_id',2],['provider_shop_id','28925875'],['authorization_revision',1],['product_version',2],['expected_product_version',0],['payload_fingerprint','f'.repeat(64)],['approval_reference','f'.repeat(64)],['operation_id','f'.repeat(64)],['intent_fingerprint','f'.repeat(64)],['scope_fingerprint','f'.repeat(64)]]) {
  test(`reject substituted native ${field} before querying authority`,async()=>{
    const f=await checkpointFixture(),o=await observer(f),q=f.request();q.context.binding[field]=value;
    await assert.rejects(()=>o.observe(q));assert.equal(f.calls,0);
  });
}
test('all canonical native fields are required and unknown metadata cannot hide credentials',async()=>{
  const f=await checkpointFixture(),o=await observer(f);
  for(const field of Object.keys(f.context.binding)){const q=f.request();delete q.context.binding[field];await assert.rejects(()=>o.observe(q));}
  const q=f.request();q.context.binding.secret='SYNTHETIC-NEVER-EXPORT';await assert.rejects(()=>o.observe(q));assert.equal(f.calls,0);
});
for(const field of ['provider_account_reference','workload_id','deployment_digest','owner_proof_reference','owner_session_reference','core_source_sha'])test(`immutable physical/owner context ${field} cannot be substituted`,async()=>{
  const f=await checkpointFixture(),o=await observer(f),q=f.request();q.context[field]=['provider_account_reference','workload_id'].includes(field)?'different-identity':field==='core_source_sha'?'f'.repeat(40):randomHex();
  await assert.rejects(()=>o.observe(q));assert.equal(f.calls,0);
});
test('core product revision changing after issuance invalidates the witness join',async()=>{
  const f=await checkpointFixture();f.afterIssue=()=>{f.context.binding.product_version++;};const o=await observer(f);await assert.rejects(()=>o.observe(f.request()));
});
test('production context and secret/header/URL inputs cannot select an operational path',async()=>{
  const f=await checkpointFixture();f.context.binding.environment='production';
  const {SyntheticNativeCheckpointObserver}=await import('../native-checkpoint.mjs');
  assert.throws(()=>new SyntheticNativeCheckpointObserver(f.options()),/CONTEXT/);
  const g=await checkpointFixture(),o=await observer(g);
  for(const extra of ['url','headers','credential','body','method']){
    const q=g.request();q[extra]='SYNTHETIC-UNAPPROVED';await assert.rejects(()=>o.observe(q));
  }
  assert.equal(g.calls,0);
});
test('tampered checkpoint signature is denied before witness observation',async()=>{
  const f=await checkpointFixture(),issue=f.ports.issue;f.ports.issue=async q=>{const r=await issue(q);r.signature='A'.repeat(88);return r;};
  const o=await observer(f);await assert.rejects(()=>o.observe(f.request()));assert.equal(f.calls,1);
});
test('same cryptographic key cannot masquerade as independent witness',async()=>{
  const f=await checkpointFixture();f.options=()=>({synthetic:true,ports:f.ports,authorityPublic:f.issuer.publicKey,witnessPublic:f.issuer.publicKey,policy:f.policy,context:f.context,clock:()=>f.clock.now});
  const o=await observer(f);await assert.rejects(()=>o.observe(f.request()));assert.equal(f.calls,0);
});
test('same administrator labels are rejected but different labels are not independence certification',async()=>{
  const f=await checkpointFixture();f.policy.witness_domain=f.policy.sender_domain;
  await assert.rejects(()=>observer(f));
});
for(const change of ['epoch','generation','revision','purpose','method','context','extra'])test(`reject signed issuer/witness conflict ${change}`,async()=>{
  const f=await checkpointFixture();f.changeCheckpoint=m=>{if(change==='epoch')m.checkpoint_epoch=randomHex();if(change==='generation')m.generation++;if(change==='revision')m.root_revision++;if(change==='purpose')m.purpose='dg-authority-response-v1';if(change==='method')m.method='admitOperation';if(change==='context')m.context={...m.context,provider_account_reference:'other-account'};if(change==='extra')m.credential='SYNTHETIC-DO-NOT-EXPORT';return m;};
  const o=await observer(f);await assert.rejects(()=>o.observe(f.request()));
});
test('wrong signature and token-bearing transport errors never leak through diagnostic errors',async()=>{
  const f=await checkpointFixture();f.ports.issue=async()=>{throw Error('SYNTHETIC-PRIVATE-TOKEN');};const o=await observer(f);
  await assert.rejects(()=>o.observe(f.request()),e=>!e.message.includes('SYNTHETIC')&&e.retry_permitted===false);
  await assert.rejects(()=>o.observe(f.request()),/REVIEW_REQUIRED/);
});
test('a hostile transport error accessor cannot escape bounded diagnostics',async()=>{
  const f=await checkpointFixture();f.ports.issue=async()=>{throw Object.defineProperty({},'code',{get(){throw Error('SYNTHETIC-PRIVATE-ERROR');}});};
  const o=await observer(f);await assert.rejects(()=>o.observe(f.request()),e=>!e.message.includes('PRIVATE')&&e.retry_permitted===false);
});
test('missing witness or expiry after issuer signature denies with no cached fallback',async()=>{
  for(const fault of ['outage','expiry','stop']){
    const f=await checkpointFixture();f.afterIssue=()=>{if(fault==='expiry')f.clock.now+=31;if(fault==='stop')f.root.stop();};
    if(fault==='outage')f.ports.observe=async()=>{throw Error('WITNESS_OFFLINE');};
    await assert.rejects(async()=>{const o=await observer(f);return o.observe(f.request());});
  }
});
test('COUNTEREXAMPLE: revocation after witness signing does not turn an observation into a current dispatch permit',async()=>{
  const f=await checkpointFixture(),observe=f.ports.observe;
  f.ports.observe=async q=>{const signed=await observe(q);f.root.stop();return signed;};
  const o=await observer(f),r=await o.observe(f.request());
  assert.equal(f.root.read().stopped,true);assert.ok(r.generation<f.root.read().generation);
  assert.equal(r.cryptographically_verified,true);assert.equal(r.operational_authority,false);
  assert.equal(r.evidence_currentness,'WITNESSED_AT_SIGNING_ONLY');
});
test('replayed and concurrent duplicate observations consume one durable request',async()=>{
  const f=await checkpointFixture(),a=await observer(f),b=await observer(f),q=f.request();
  const results=await Promise.allSettled([a.observe(q),b.observe(q)]);
  assert.equal(results.filter(x=>x.status==='fulfilled').length,1);
  assert.equal(f.root.read().nonce_count,1);
  await assert.rejects(async()=>{const fresh=await observer(f);return fresh.observe(q);});
});
test('WordPress/database/filesystem rollback cannot override the current witnessed core context',async()=>{
  const f=await checkpointFixture(),o=await observer(f),old=f.request();f.context.binding.authorization_revision++;
  await assert.rejects(()=>o.observe(old));assert.equal(f.calls,1);
});
test('witness restart fences old checkpoints and preserves consumed requests',async()=>{
  const f=await checkpointFixture(),o=await observer(f);await o.observe(f.request());const count=f.root.read().nonce_count;f.root.boot();
  assert.equal(f.root.read().recovery,true);assert.equal(f.root.read().nonce_count,count);await assert.rejects(()=>o.observe(f.request()));
});
test('lost acknowledgement retains consumption and prevents automatic rearm',async()=>{
  const f=await checkpointFixture(),q=f.request(),issue=f.ports.issue;f.ports.issue=async x=>{await issue(x);throw Error('ACK_LOST');};const o=await observer(f);
  await assert.rejects(()=>o.observe(q));assert.equal(f.root.read().nonce_count,1);await assert.rejects(()=>o.observe(f.request()),/REVIEW_REQUIRED/);
});
test('late issuer acknowledgement after timeout cannot start a witness request',async t=>{
  const f=await checkpointFixture(),issue=f.ports.issue;let release,entered;
  const ready=new Promise(r=>entered=r),gate=new Promise(r=>release=r);
  f.ports.issue=async q=>{const cp=await issue(q);entered();await gate;return cp;};
  const o=await observer(f);t.mock.timers.enable({apis:['setTimeout']});
  try{
    const pending=o.observe(f.request());await ready;t.mock.timers.tick(20001);
    await assert.rejects(()=>pending,/TIMEOUT_UNKNOWN/);release();
    for(let i=0;i<8;i++)await turn();
    assert.equal(f.calls,1,'a late checkpoint must not trigger another remote proof read');
    assert.equal(f.root.read().nonce_count,1);await assert.rejects(()=>o.observe(f.request()),/REVIEW_REQUIRED/);
  }finally{release();t.mock.timers.reset();}
});
test('bounded proof observations retain three-per-minute and ten-slot limits',async()=>{
  const f=await checkpointFixture(),o=await observer(f);
  for(let i=0;i<10;i++){
    if(i&&i%3===0)f.clock.now+=61;
    await o.observe(f.request());
    if(i===2)await assert.rejects(()=>o.observe(f.request()),/BUDGET/);
  }
  f.clock.now+=61;await assert.rejects(()=>o.observe(f.request()),/BUDGET/);
  assert.equal(f.root.read().nonce_count,10);
});
test('corrupt witness journal is rejected instead of defaulting to a new epoch',async()=>{
  const f=await checkpointFixture(),o=await observer(f);f.storage.db.prepare('UPDATE df_security_authority SET document=?').run('{bad');
  await assert.rejects(()=>o.observe(f.request()));
});
test('recovery epoch changes require strictly newer independent recovery revision',async()=>{
  const f=await checkpointFixture(),o=await observer(f);const first=await o.observe(f.request());
  f.root.change(d=>{d.epoch=randomHex();});await assert.rejects(()=>o.observe(f.request()));
  const g=await checkpointFixture(),reader=await observer(g);const before=await reader.observe(g.request());
  g.root.change(d=>{d.epoch=randomHex();d.anchor.revision++;d.generation++;});const after=await reader.observe(g.request());
  assert.notEqual(after.recovery_epoch,before.recovery_epoch);assert.ok(after.recovery_revision>before.recovery_revision);assert.ok(first.root_revision>0);
});
for(const kind of ['same_revision_fork','lower_revision'])test(`independent witness ${kind} is rejected while the observer retains its high-water evidence`,async()=>{
  const f=await checkpointFixture(),snapshot=f.snapshot(),o=await observer(f);await o.observe(f.request());
  if(kind==='lower_revision')await o.observe(f.request());
  f.restore(snapshot);await assert.rejects(()=>o.observe(f.request()));
});
test('COUNTEREXAMPLE: restoring every trusted root can revive cryptographic evidence, never operational authority',async()=>{
  const f=await checkpointFixture(),snapshot=f.snapshot(),q=f.request(),a=await observer(f);await a.observe(q);f.root.stop();assert.equal(f.root.read().stopped,true);
  f.restore(snapshot);const restored=await observer(f),r=await restored.observe(q);assert.equal(r.cryptographically_verified,true);assert.equal(r.operational_authority,false);
  const response=await worker.fetch(new Request('https://synthetic.invalid'),{});assert.equal(response.status,503);
});
test('COUNTEREXAMPLE: an independently usable old credential copy bypasses a cooperative witness',async()=>{
  const provider=new Set(['SYNTHETIC-OLD-CREDENTIAL']);let accepted=0;const syntheticProvider=copy=>{if(provider.has(copy))accepted++;};
  syntheticProvider('SYNTHETIC-OLD-CREDENTIAL');assert.equal(accepted,1,'custody is not established by moving a token or signing a claim');
  provider.delete('SYNTHETIC-OLD-CREDENTIAL');syntheticProvider('SYNTHETIC-OLD-CREDENTIAL');assert.equal(accepted,1,'only independent invalidation makes the old copy unusable in this model');
});
