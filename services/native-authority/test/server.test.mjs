import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { startAuthorityServer } from '../server.mjs';
import { sign, verifyWitness, canonical, digest } from '../protocol.mjs';
import { witnessFixture, post, postRaw } from './server-fixtures.mjs';

async function boot(t,options={}) {const f=witnessFixture(t,options),app=await startAuthorityServer(f.path,'witness');t.after(()=>app.close());return {...f,...app};}
const witness=(f,envelope)=>verifyWitness('witness.response.v1',envelope,new Map([['witness',f.pairs.witness.publicKey]]));

test('actual witness validates forwarded workload and durably denies replay',async t=>{
  const f=await boot(t),request=f.request('currentCheckpoint',{grant_reference:'grant',expected_epoch:f.state.epoch,expected_generation:f.state.generation});
  const response=await post(f.address,'/witness',f.forward(request));assert.equal(response.status,200);const message=witness(f,response.body);assert.equal(message.disposition,'CURRENT');assert.deepEqual(message.request_bindings,request.message);assert.equal(message.result.external_execution_authorized,false);
  const repeated=await post(f.address,'/witness',f.forward(request));assert.equal(repeated.status,200);assert.equal(witness(f,repeated.body).result.reason,'request_replay');
});
test('wrong request role and wrong forward signer never get a witness attestation',async t=>{
  const f=await boot(t),body={grant_reference:'grant',expected_epoch:f.state.epoch,expected_generation:1};
  const wrong=f.request('currentCheckpoint',body,'emergency');assert.equal((await post(f.address,'/witness',f.forward(wrong))).status,401);
  const request=f.request('currentCheckpoint',body),forward=f.forward(request);forward.key_id='workload';forward.signature=sign('issuer.forward.v1',forward.message,f.pairs.workload.privateKey,'workload').signature;
  assert.equal((await post(f.address,'/witness',forward)).status,401);
});
test('canonical UTF8 body bound rejects duplicate JSON members and invalid byte encodings',async t=>{
  const f=await boot(t);
  for(const body of ['{"a":1,"a":1}',Buffer.from([0xc3,0x28]),' '.repeat(65537),'{}\n']){const response=await postRaw(f.address,'/witness',body);assert.notEqual(response.status,200);assert.deepEqual(response.body,{error:'authority_unavailable'});}
  assert.equal((await post(f.address,'/rpc',{})).status,404);
  const request=f.request('currentCheckpoint',{grant_reference:'grant',expected_epoch:f.state.epoch,expected_generation:1});
  const bom=Buffer.concat([Buffer.from([0xef,0xbb,0xbf]),Buffer.from(canonical(f.forward(request)))]);
  assert.notEqual((await postRaw(f.address,'/witness',bom)).status,200,'A UTF8 BOM must not be normalized into a canonical signed request');
});
test('actual emergency advances generation and reports real drained inventory',async t=>{
  const f=await boot(t),request=f.request('requestEmergency',{emergency_authority_reference:'emergency',emergency_event_id:'stop-event',scope_of_revocation:'installation',expected_generation:1});
  const response=await post(f.address,'/witness',f.forward(request));assert.equal(response.status,200);const message=witness(f,response.body);assert.equal(message.generation,2);assert.equal(message.disposition,'DRAINED');assert.deepEqual(message.result.drain,{state:'DRAINED',pending_operations:0});
  const current=f.request('currentCheckpoint',{grant_reference:'grant',expected_epoch:f.state.epoch,expected_generation:2});const held=await post(f.address,'/witness',f.forward(current));assert.equal(witness(f,held.body).disposition,'HELD');
  const fake=f.request('requestEmergency',{...request.message.method_body,emergency_authority_reference:'caller-chosen',expected_generation:2});assert.equal((await post(f.address,'/witness',f.forward(fake))).status,401);
});
test('recovery requires pre-registered actual dual approval and remains held',async t=>{
  const f=await boot(t),data={previous_epoch:f.state.epoch,expected_witness_head:f.state.witness_head,outstanding_lease_inventory_hash:f.state.inventory_hash};
  const body={independent_recovery_approval:'recovery-one',...data};
  const missing=await post(f.address,'/witness',f.forward(f.request('recoverInstallation',body)));assert.equal(witness(f,missing.body).disposition,'DENIED');
  const approval=f.control('RECOVERY_APPROVAL','recovery-one',data);const unsigned={owner:approval.owner,recovery:approval.owner};assert.notEqual((await post(f.address,'/control',unsigned)).status,200);
  assert.equal((await post(f.address,'/control',approval)).status,200);
  const recovered=await post(f.address,'/witness',f.forward(f.request('recoverInstallation',body)));const message=witness(f,recovered.body);assert.equal(message.disposition,'RECOVERED_HELD');assert.notEqual(message.checkpoint_epoch,f.state.epoch);assert.equal(message.generation,2);
  const repeated=await post(f.address,'/witness',f.forward(f.request('recoverInstallation',{...body,previous_epoch:message.checkpoint_epoch})));assert.equal(witness(f,repeated.body).disposition,'DENIED');
});
test('signed dispatch stores use a separate durable cross-domain nonce ledger',async t=>{
  const f=await boot(t,{released:true,native:true}),original=f.request('admitOperation',{grant_reference:'grant',operation_id:digest('operation'),payload_fingerprint:digest('payload'),product_version:1,expected_product_version:1,approval_reference:'approval',approval_revision:1,policy_revision:1,expected_epoch:f.state.epoch,expected_generation:1});
  assert.equal(witness(f,(await post(f.address,'/witness',f.forward(original))).body).disposition,'ADMITTED');
  const message={version:'1.0.0',binding:f.binding,action:'state',operation_id:null,request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url'),arguments:[],original_envelope:original,control_envelope:null};
  const envelope=sign('sender.store.request.v1',message,f.pairs.issuer.privateKey,'issuer');const response=await post(f.address,'/dispatch',envelope);assert.equal(response.status,200);const result=verifyWitness('witness.store.response.v1',response.body,new Map([['witness',f.pairs.witness.publicKey]]));assert.equal(result.ok,true);assert.equal(result.result.epoch,f.state.epoch);
  const replay=await post(f.address,'/dispatch',envelope);assert.notEqual(replay.status,200);
  assert.equal(canonical(response.body).includes('offline-test-'),false);
});
test('actual dispatch binds original admission, replaces caller time, and signs only durable terminal results',async t=>{
  const f=await boot(t,{released:true,native:true}),operationId=digest('operation');
  const original=f.request('admitOperation',{grant_reference:'grant',operation_id:operationId,payload_fingerprint:digest('payload'),product_version:1,expected_product_version:1,approval_reference:'approval',approval_revision:1,policy_revision:1,expected_epoch:f.state.epoch,expected_generation:1});
  const admitted=await post(f.address,'/witness',f.forward(original));assert.equal(witness(f,admitted.body).disposition,'ADMITTED');
  const dispatch=async(action,args=[],source=original)=>{
    const message={version:'1.0.0',binding:f.binding,action,operation_id:operationId,request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url'),arguments:args,original_envelope:source,control_envelope:null};
    return post(f.address,'/dispatch',sign('sender.store.request.v1',message,f.pairs.issuer.privateKey,'issuer'));
  };
  const early=await dispatch('publicResult');assert.equal(early.status,200);assert.equal(verifyWitness('witness.store.response.v1',early.body,new Map([['witness',f.pairs.witness.publicKey]])).ok,false);
  const wrong=f.request('admitOperation',original.message.method_body);assert.notEqual((await dispatch('operation',[],wrong)).status,200);
  const altered=sign('operation.request.v1',{...original.message,method_body:{...original.message.method_body,grant_reference:'another-grant'}},f.pairs.workload.privateKey,'workload');assert.notEqual((await dispatch('operation',[],altered)).status,200);
  const sending=await dispatch('markSending',[1,1]);const sent=verifyWitness('witness.store.response.v1',sending.body,new Map([['witness',f.pairs.witness.publicKey]]));assert.equal(sent.result.state,'SENDING');assert.ok(sent.result.updated_at>1);
  const finish=await dispatch('finish',['SUCCEEDED',{provider_reference:'offline-provider'},1]);const done=verifyWitness('witness.store.response.v1',finish.body,new Map([['witness',f.pairs.witness.publicKey]]));assert.equal(done.result.state,'SUCCEEDED');assert.ok(done.result.updated_at>1);
  const terminal=await dispatch('publicResult');const wrapper=verifyWitness('witness.store.response.v1',terminal.body,new Map([['witness',f.pairs.witness.publicKey]])),publicResult=witness(f,wrapper.result);
  assert.equal(publicResult.disposition,'SUCCEEDED');assert.equal(publicResult.result.operation.provider_reference,'offline-provider');assert.deepEqual(publicResult.request_bindings,original.message);
  assert.equal(Object.hasOwn(publicResult.result.operation,'challenge'),false);assert.equal(Object.hasOwn(publicResult.result.operation,'request_id'),false);
});
test('held authenticated admission stays consumed after actual dual-signed hold release and restart',async t=>{
  const f=await boot(t,{native:true}),body={grant_reference:'grant',operation_id:digest('operation'),payload_fingerprint:digest('payload'),product_version:1,expected_product_version:1,approval_reference:'approval',approval_revision:1,policy_revision:1,expected_epoch:f.state.epoch,expected_generation:1},original=f.request('admitOperation',body);
  const held=await post(f.address,'/witness',f.forward(original));assert.equal(witness(f,held.body).disposition,'HELD');await f.close();
  f.installation.holds={stop_all:false,external_lock:false,automation:true,release_hold:false};const reopened=await startAuthorityServer(f.save(),'witness');t.after(()=>reopened.close());
  const replay=await post(reopened.address,'/witness',f.forward(original));assert.equal(witness(f,replay.body).disposition,'DENIED');assert.equal(witness(f,replay.body).result.reason,'request_replay');
  const fresh=await post(reopened.address,'/witness',f.forward(f.request('admitOperation',body)));assert.equal(witness(f,fresh.body).disposition,'ADMITTED');
});
test('denied authenticated admission stays consumed after actual grant registration',async t=>{
  const f=await boot(t,{native:true,released:true}),body={grant_reference:'new-grant',operation_id:digest('operation'),payload_fingerprint:digest('payload'),product_version:1,expected_product_version:1,approval_reference:'approval',approval_revision:1,policy_revision:1,expected_epoch:f.state.epoch,expected_generation:1},original=f.request('admitOperation',body);
  const denied=await post(f.address,'/witness',f.forward(original));assert.equal(witness(f,denied.body).disposition,'DENIED');assert.equal(witness(f,denied.body).result.reason,'grant_not_current');
  const time=Math.floor(Date.now()/1000),grant={grant_reference:'new-grant',binding:f.binding,issued_at:time-1,expires_at:time+120,revoked:false};assert.equal((await post(f.address,'/control',f.control('REGISTER_GRANT','register-new-grant',grant))).status,200);
  const replay=await post(f.address,'/witness',f.forward(original));assert.equal(witness(f,replay.body).disposition,'DENIED');assert.equal(witness(f,replay.body).result.reason,'request_replay');
  const fresh=await post(f.address,'/witness',f.forward(f.request('admitOperation',body)));assert.equal(witness(f,fresh.body).disposition,'ADMITTED');
});
test('completed recovery approval cannot repeat after selective spent-flag restoration',async t=>{
  const f=await boot(t),data={previous_epoch:f.state.epoch,expected_witness_head:f.state.witness_head,outstanding_lease_inventory_hash:f.state.inventory_hash},body={independent_recovery_approval:'spent-control',...data};
  assert.equal((await post(f.address,'/control',f.control('RECOVERY_APPROVAL','spent-control',data))).status,200);
  const first=witness(f,(await post(f.address,'/witness',f.forward(f.request('recoverInstallation',body)))).body);assert.equal(first.disposition,'RECOVERED_HELD');
  const db=new DatabaseSync(f.config.database_path);try{assert.equal(db.prepare('UPDATE service_controls SET consumed=0 WHERE reference=?').run('spent-control').changes,1);}finally{db.close();}
  const replay=witness(f,(await post(f.address,'/witness',f.forward(f.request('recoverInstallation',body)))).body);assert.equal(replay.disposition,'DENIED');assert.equal(replay.recovery_revision,first.recovery_revision);assert.equal(replay.checkpoint_epoch,first.checkpoint_epoch);
  const changedBody={...body,previous_epoch:first.checkpoint_epoch,expected_witness_head:first.witness_head};
  const changed=witness(f,(await post(f.address,'/witness',f.forward(f.request('recoverInstallation',changedBody)))).body);assert.equal(changed.disposition,'DENIED');assert.equal(changed.result.reason,'control_approval_mismatch');assert.equal(changed.recovery_revision,first.recovery_revision);
});
test('stored recovery approval requires its actual dual signatures again at consumption',async t=>{
  const f=await boot(t),data={previous_epoch:f.state.epoch,expected_witness_head:f.state.witness_head,outstanding_lease_inventory_hash:f.state.inventory_hash},approval=f.control('RECOVERY_APPROVAL','tampered-control',data);
  assert.equal((await post(f.address,'/control',approval)).status,200);
  const modified=structuredClone(approval);modified.owner.message.reference='not-the-original';modified.recovery.message.reference='not-the-original';
  const db=new DatabaseSync(f.config.database_path);try{db.prepare('UPDATE service_controls SET payload=? WHERE reference=?').run(canonical(modified),'tampered-control');}finally{db.close();}
  const response=witness(f,(await post(f.address,'/witness',f.forward(f.request('recoverInstallation',{independent_recovery_approval:'tampered-control',...data})))).body);assert.equal(response.disposition,'DENIED');assert.equal(response.result.reason,'signature_invalid');assert.equal(response.checkpoint_epoch,f.state.epoch);assert.equal(response.recovery_revision,1);
});

async function nativeAdmission(f) {
  const envelope=f.request('admitOperation',{grant_reference:'grant',operation_id:digest('operation'),payload_fingerprint:digest('payload'),product_version:1,expected_product_version:1,approval_reference:'approval',approval_revision:1,policy_revision:1,expected_epoch:f.state.epoch,expected_generation:1});
  const message=witness(f,(await post(f.address,'/witness',f.forward(envelope))).body);assert.equal(message.disposition,'ADMITTED');return {envelope,message,op:message.result.operation};
}
async function internal(f,original,action,args=[]) {
  const message={version:'1.0.0',binding:f.binding,action,operation_id:original.message.method_body.operation_id,request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url'),arguments:args,original_envelope:original,control_envelope:null};
  const response=await post(f.address,'/dispatch',sign('sender.store.request.v1',message,f.pairs.issuer.privateKey,'issuer'));assert.equal(response.status,200);return verifyWitness('witness.store.response.v1',response.body,new Map([['witness',f.pairs.witness.publicKey]]));
}
async function emergency(f) {
  return witness(f,(await post(f.address,'/witness',f.forward(f.request('requestEmergency',{emergency_authority_reference:'emergency',emergency_event_id:'abandon-stop',scope_of_revocation:'installation',expected_generation:1})))).body);
}
function abandonmentData(op,head) {return {operation_id:op.operation_id,allocation_id:op.allocation_id,allocation_revision:op.allocation_revision,generation:op.generation,expected_witness_head:head};}

test('dual control abandons only actual revoked unsent admission, drains and recovers held without provider evidence',async t=>{
  const f=await boot(t,{native:true,released:true}),{envelope,op}=await nativeAdmission(f),stopped=await emergency(f);assert.equal(stopped.disposition,'PENDING');
  const data=abandonmentData(op,stopped.witness_head),control=f.control('ABANDON_UNSENT','abandon-one',data);
  const result=await post(f.address,'/control',control);assert.equal(result.status,200);assert.deepEqual(result.body,{state:'FAILED',operation_id:op.operation_id,external_execution_authorized:false});
  const actual=await internal(f,envelope,'operation');assert.equal(actual.result.state,'FAILED');assert.deepEqual(actual.result.evidence,{failure_category:'revoked_before_send'});assert.equal(actual.result.allocation_id,op.allocation_id);assert.equal(actual.result.allocation_revision,op.allocation_revision);
  assert.notEqual((await post(f.address,'/control',control)).status,200);
  assert.notEqual((await post(f.address,'/control',f.control('ABANDON_UNSENT','abandon-second',data))).status,200);
  const drained=witness(f,(await post(f.address,'/witness',f.forward(f.request('readDrain',{emergency_event_id:'abandon-stop',generation:stopped.generation})))).body);assert.equal(drained.disposition,'DRAINED');assert.equal(drained.result.drain.pending_operations,0);
  const recovery={previous_epoch:drained.checkpoint_epoch,expected_witness_head:drained.witness_head,outstanding_lease_inventory_hash:digest([])};assert.equal((await post(f.address,'/control',f.control('RECOVERY_APPROVAL','recover-after-abandon',recovery))).status,200);
  const recovered=witness(f,(await post(f.address,'/witness',f.forward(f.request('recoverInstallation',{independent_recovery_approval:'recover-after-abandon',...recovery})))).body);assert.equal(recovered.disposition,'RECOVERED_HELD');assert.equal(recovered.result.external_execution_authorized,false);
});
test('abandon control rejects unsigned, malformed, wrong-allocation and replayed references',async t=>{
  const f=await boot(t,{native:true,released:true}),{envelope,op}=await nativeAdmission(f),stopped=await emergency(f),data=abandonmentData(op,stopped.witness_head);
  const valid=f.control('ABANDON_UNSENT','valid-unused',data);assert.notEqual((await post(f.address,'/control',{owner:valid.owner,recovery:valid.owner})).status,200);
  for(const changed of [{...data,extra:true},{...data,allocation_revision:'1'},{...data,generation:0},{...data,operation_id:'not-a-hash'},{...data,expected_witness_head:null}])assert.notEqual((await post(f.address,'/control',f.control('ABANDON_UNSENT','malformed-'+randomBytes(4).toString('hex'),changed))).status,200);
  const wrong=f.control('ABANDON_UNSENT','wrong-once',{...data,allocation_id:'wrong-allocation'});assert.notEqual((await post(f.address,'/control',wrong)).status,200);
  assert.notEqual((await post(f.address,'/control',f.control('ABANDON_UNSENT','wrong-once',data))).status,200,'A consumed reference cannot be amended after a failed source match');
  assert.equal((await internal(f,envelope,'operation')).result.state,'ADMITTED');
});
test('abandon control refuses current, sending and unknown operations',async t=>{
  for(const target of ['ADMITTED','SENDING','UNKNOWN']){
    const f=await boot(t,{native:true,released:true}),{envelope,op,message}=await nativeAdmission(f);let head=message.witness_head;
    if(target!=='ADMITTED'){assert.equal((await internal(f,envelope,'markSending',[1,1])).result.state,'SENDING');const stopped=await emergency(f);head=stopped.witness_head;
      if(target==='UNKNOWN'){const finished=await internal(f,envelope,'finish',['SUCCEEDED',{provider_reference:'synthetic-observation'},1]);assert.equal(finished.result.state,'UNKNOWN');const stateResponse=await post(f.address,'/witness',f.forward(f.request('readDrain',{emergency_event_id:'abandon-stop',generation:2})));head=witness(f,stateResponse.body).witness_head;}
    }
    const response=await post(f.address,'/control',f.control('ABANDON_UNSENT','refuse-'+target,abandonmentData(op,head)));assert.notEqual(response.status,200);assert.equal((await internal(f,envelope,'operation')).result.state,target);
  }
});
