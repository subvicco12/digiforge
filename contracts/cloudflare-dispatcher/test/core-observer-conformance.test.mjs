import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { conformanceFixture,coreProbe,CORE } from './core-conformance-support.mjs';
import { canonical,hash } from '../protocol.mjs';

test('read-only native observation evaluator exists without adding a dispatch or epoch producer',()=>{
  assert.ok(existsSync(new URL('../NativeObservationConformance.php',import.meta.url)),'Missing non-authorizing real-core/observer conformance evaluator');
});
test('real core schema and synthetic proof compare without supplying native epochs or authority',async()=>{
 const {input}=await conformanceFixture(),r=coreProbe(input);
 assert.equal(r.status,'READ_ONLY_CONFORMANT');assert.equal(r.core_reference_sha,CORE);
 assert.equal(r.scope_comparison,'MATCH');assert.equal(r.signature_verification,'NOT_PERFORMED_BY_COMPARATOR');
 for(const k of ['mutation_authorized','dispatch_permitted','network_request_permitted','external_execution_authorized','independent_checkpoint_verified','retry_permitted','automatic_rearm','native_runtime_verified'])assert.equal(r[k],false,k);
 assert.equal(r.recovery_epoch,null);assert.equal(r.checkpoint_epoch,null);assert.equal(r.release_hold,true);
});
for(const [key,value]of [['generation',2],['now',1031]])test('read-only comparison rejects stale reference '+key,async()=>{
 const {input}=await conformanceFixture();input[key]=value;const r=coreProbe(input);assert.equal(r.status,'READ_ONLY_INCOMPATIBLE');assert.equal(r.dispatch_permitted,false);
});
for(const field of ['mutation_authorized','operational_authority','independent_recovery_qualified','external_execution_authorized','retry_permitted'])test('observer flag '+field+' cannot promote synthetic evidence',async()=>{
 const {input}=await conformanceFixture();input.observation[field]=true;const r=coreProbe(input);assert.equal(r.status,'READ_ONLY_INCOMPATIBLE');assert.equal(r.independent_checkpoint_verified,false);
});
test('wrong shop or context is incompatible rather than a reviewed mapping',async()=>{
 const {input}=await conformanceFixture();input.observer_binding.provider_shop_id='28925875';
 const r=coreProbe(input);assert.equal(r.scope_comparison,'MISMATCH');assert.equal(r.status,'READ_ONLY_INCOMPATIBLE');assert.equal(r.mutation_authorized,false);
});
test('native source pin, manifest and native inspection integrity are enforced',async()=>{
 for(const fault of ['pin','capability','inspection']){
  const {input}=await conformanceFixture();
  if(fault==='pin')input.manifest.core_source_sha='f'.repeat(40);
  if(fault==='capability')input.manifest.capabilities.push('REGISTER');
  if(fault==='inspection')input.inspection.actor_id=2;
  assert.equal(coreProbe(input).status,'READ_ONLY_INCOMPATIBLE');
 }
});
test('a forged canonical response can pass shape checks but is never independent authority',async()=>{
 const {input}=await conformanceFixture();const r=coreProbe({mode:'contract',binding:input.binding,observation:input.observation});
 assert.equal(r.binding_valid,true);assert.equal(r.observation_is_mutation_response,false);assert.equal(r.fabricated_shape_valid,true);
 assert.ok(r.actions.includes('REGISTER')&&r.actions.includes('CLAIM'));assert.equal(r.actions.includes('RESERVE'),false);
 assert.equal(coreProbe(input).independent_checkpoint_verified,false);
});
test('actual held-state implementation keeps UNKNOWN irreversible and restored local fences non-authorizing',async()=>{
 const {input}=await conformanceFixture(),r=coreProbe({mode:'held',binding:input.binding});
 assert.equal(r.state,'UNKNOWN');assert.equal(r.dispatch_permitted,false);assert.equal(r.independent_checkpoint_verified,false);
 assert.deepEqual(Object.values(r.retry_denied),[true,true,true,true,true,true]);
 assert.equal(r.restored_held_can_fence,true,'local state restoration is a counterexample, not recovery qualification');
});
test('missing or unexpected journal/proof data produces bounded non-authorizing diagnostics',async()=>{
 const {input}=await conformanceFixture();input.inspection={};input.observation.private='SYNTHETIC-PRIVATE-DATA';
 const r=coreProbe(input);assert.equal(r.status,'READ_ONLY_INCOMPATIBLE');assert.equal(JSON.stringify(r).includes('PRIVATE'),false);
});
test('conflicting observation epochs and earlier signed expiry are not normalized into authority',async()=>{
 const {input}=await conformanceFixture();input.observation.checkpoint_epoch='f'.repeat(64);assert.equal(coreProbe(input).status,'READ_ONLY_INCOMPATIBLE');
 const next=await conformanceFixture();next.input.observation.evidence_expires_at=1001;next.input.now=1002;assert.equal(coreProbe(next.input).status,'READ_ONLY_INCOMPATIBLE');
});
test('canonical JSON object ordering does not invalidate genuine readiness semantics',async()=>{
 const {input}=await conformanceFixture();input.inspection.observation=Object.fromEntries(Object.entries(input.inspection.observation).reverse());
 assert.equal(coreProbe(input).status,'READ_ONLY_CONFORMANT');
});
test('UNKNOWN local inspection remains non-authorizing and stale state revisions are rejected',async()=>{
 const {input}=await conformanceFixture();input.inspection.kind='HELD_OPERATION';
 input.inspection.observation={state:'UNKNOWN',journal_revision:3,occurred_at:1000,event_hash:'e'.repeat(64),fence_present:true,approval_verified:false,native_product_version:null,manual_review_required:true};
 const rehash=async()=>{const unsigned={...input.inspection};delete unsigned.evidence_hash;input.inspection.evidence_hash=await hash(canonical(unsigned));};
 await rehash();const valid=coreProbe(input);assert.equal(valid.status,'READ_ONLY_CONFORMANT');assert.equal(valid.native_state,'UNKNOWN');assert.equal(valid.retry_permitted,false);
 input.inspection.observation.journal_revision=1;await rehash();assert.equal(coreProbe(input).status,'READ_ONLY_INCOMPATIBLE');
});
for(const slot of ['core','observer'])test('malformed in-process '+slot+' objects cannot invoke serialization callbacks',async()=>{
 const {input}=await conformanceFixture(),r=coreProbe({...input,mode:'objects',object_slot:slot});
 assert.equal(r.status,'READ_ONLY_INCOMPATIBLE');assert.equal(r.serializer_calls,0,'reject objects before any json_encode call');
 assert.equal(r.dispatch_permitted,false);assert.equal(JSON.stringify(r).includes('SYNTHETIC-PRIVATE'),false);
});
test('a held event from the future is not a conformant inspection',async()=>{
 const {input}=await conformanceFixture();
 input.inspection.kind='HELD_OPERATION';
 input.inspection.observation={state:'HELD',journal_revision:1,occurred_at:input.now+1,event_hash:'e'.repeat(64),fence_present:false,approval_verified:false,native_product_version:null,manual_review_required:true};
 const unsigned={...input.inspection};delete unsigned.evidence_hash;
 input.inspection.evidence_hash=await hash(canonical(unsigned));
 const result=coreProbe(input);
 assert.equal(result.status,'READ_ONLY_INCOMPATIBLE');
 assert.equal(result.dispatch_permitted,false);
});
