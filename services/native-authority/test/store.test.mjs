import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { DatabaseSync } from 'node:sqlite';
import { AuthorityStore } from '../store.mjs';
import { canonical, digest } from '../protocol.mjs';

const now=1800000000;
const binding={authority_id:'test-authority',installation_id:'test-installation',owner_id:'test-owner',owner_proof_reference:'test-owner-proof',workload_id:'test-workload',deployment_digest:digest('test-deployment'),compatibility_floor:'1.0.0',provider:'printify',provider_account_reference:'test-provider-account',environment:'test',integration_id:123,credential_reference:123,shop_id:2,shop_key:'personalized_pod'};
function fixture(t){
 const dir=mkdtempSync(join(tmpdir(),'df-authority-store-'));const path=join(dir,'authority.sqlite');const store=new AuthorityStore(path);
 t.after(()=>{try{store.close();}catch{}rmSync(dir,{recursive:true,force:true});});
 store.bootstrap({...binding,witness_id:'test-witness',root_set_digest:digest('test-root-set')},now);
 const product=store.registerProduct({product_identity:'product-1',binding,core_reference:'test-core-product'});
 const order=store.registerOrder({order_identity:'order-1',product_identity:product.product_identity,lines:[{line_identity:'line-1',quantity:2}],binding,core_reference:'test-core-order'});
 const grant={grant_reference:'grant-1',binding,issued_at:now-1,expires_at:now+120,revoked:false};store.registerGrant(grant);
 const approval={approval_reference:'approval-1',approval_revision:1,policy_revision:1,operation_id:digest('test-operation'),order_identity:order.order_identity,order_version:order.order_version,listing_identity:'listing-1',product_identity:product.product_identity,product_version:product.product_version,expected_product_version:product.product_version,payload_fingerprint:digest('test-payload'),lines:order.lines,binding};
 const allocation=store.registerApproval(approval);
 const state=store.state();const request={...binding,version:'1.0.0',method:'admitOperation',request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url'),method_body:{grant_reference:grant.grant_reference,operation_id:approval.operation_id,payload_fingerprint:approval.payload_fingerprint,product_version:1,expected_product_version:1,approval_reference:approval.approval_reference,approval_revision:1,policy_revision:1,expected_epoch:state.epoch,expected_generation:state.generation}};
 return {store,path,request,approval,allocation,grant};
}
function denies(fn,code){assert.throws(fn,e=>e?.name==='AuthorityError'&&(!code||e.code===code));}
test('operator bootstrap creates durable independent random epochs and no implicit bootstrap',t=>{
 const f=fixture(t);const s=f.store.state();assert.match(s.epoch,/^[a-f0-9]{64}$/);assert.match(s.recovery_epoch,/^[a-f0-9]{64}$/);assert.notEqual(s.epoch,s.recovery_epoch);assert.equal(s.generation,1);assert.equal(s.paused,false);assert.ok(s.witness_sequence>=1);assert.match(s.witness_head,/^[a-f0-9]{64}$/);denies(()=>f.store.bootstrap({...binding,witness_id:'test-witness',root_set_digest:digest('root')},now));
 const other=new AuthorityStore(f.path);assert.deepEqual(other.state(),s);other.close();
});
test('real allocation IDs and one-use admission survive connection reopen',t=>{
 const f=fixture(t);assert.match(f.allocation.allocation_id,/^[a-f0-9-]{36}$/);assert.equal(f.allocation.allocation_revision,1);
 const op=f.store.admit(f.request,now);assert.equal(op.state,'ADMITTED');assert.equal(op.allocation_revision,2);assert.equal(op.allocation_id,f.allocation.allocation_id);
 const other=new AuthorityStore(f.path);assert.deepEqual(other.operation(op.operation_id),op);denies(()=>other.admit({...f.request,request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url')},now));other.close();
});
test('reservation-only records cannot reach the physical writer even before STOP',t=>{
 const f=fixture(t);
 const op=f.store.admit({...f.request,method:'reserveOperation'},now);
 denies(()=>f.store.markSending(op.operation_id,op.generation,now),'separate_sender_admission_required');
 assert.deepEqual(f.store.operation(op.operation_id),op);
});
test('binding, expiry, revocation and actual product/order counters deny stale admission',t=>{
 const f=fixture(t);denies(()=>f.store.admit({...f.request,provider_account_reference:'foreign'},now));denies(()=>f.store.admit(f.request,now+120));
 const p=f.store.advanceProduct('product-1',1,'test-core-next');assert.equal(p.product_version,2);denies(()=>f.store.advanceProduct('product-1',1,'test-core-replay'));denies(()=>f.store.admit(f.request,now));
 const o=f.store.advanceOrder('order-1',1,'test-core-order-next');assert.equal(o.order_version,2);assert.equal(o.product_version,2);denies(()=>f.store.advanceOrder('order-1',1,'test-core-order-replay'));
 f.store.registerGrant({...f.grant,revoked:true});denies(()=>f.store.registerGrant(f.grant));
});
test('approval cannot invent a native version or quantities',t=>{
 const f=fixture(t);denies(()=>f.store.registerApproval({...f.approval,approval_reference:'bad-version',product_version:999,expected_product_version:999}));
 denies(()=>f.store.registerApproval({...f.approval,approval_reference:'bad-quantity',lines:[{line_identity:'line-1',quantity:3}]}));
 denies(()=>f.store.registerProduct({product_identity:'invented',product_version:99,binding,core_reference:'test-core'}));
});
test('generation invalidation precedes emergency response; uncertain work remains consumed',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);f.store.markSending(op.operation_id,1,now);const e=f.store.emergency('emergency-1',1,now);assert.equal(e.generation,2);assert.equal(f.store.state().paused,true);assert.equal(f.store.drain('emergency-1',2).state,'PENDING');
 assert.equal(f.store.finish(op.operation_id,'SUCCEEDED',{provider_reference:'test-provider-result'},now).state,'UNKNOWN');denies(()=>f.store.markSending(op.operation_id,2,now));denies(()=>f.store.admit(f.request,now));denies(()=>f.store.recover(f.store.state().witness_head,f.store.inventoryHash(),now));
 const e2=f.store.emergency('emergency-2',2,now);assert.equal(e2.generation,3);denies(()=>f.store.emergency('emergency-2',3,now));
});
test('bound custody reconciliation permits drain but never releases allocation for reuse',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);f.store.markSending(op.operation_id,1,now);f.store.finish(op.operation_id,'UNKNOWN',{failure_category:'timeout'},now);f.store.emergency('emergency-1',1,now);
 const evidence={...binding,operation_id:op.operation_id,allocation_id:op.allocation_id,allocation_revision:op.allocation_revision,payload_fingerprint:op.payload_fingerprint,generation:op.generation,outcome:'SUCCEEDED',provider_reference:'test-reconciled-result',observed_at:now};
 denies(()=>f.store.reconcile(op.operation_id,{...evidence,allocation_id:'wrong'},now));denies(()=>f.store.reconcile(op.operation_id,{...evidence,provider_account_reference:'foreign'},now));
 assert.equal(f.store.reconcile(op.operation_id,evidence,now).state,'SUCCEEDED');assert.equal(f.store.drain('emergency-1',2).state,'DRAINED');
 const before=f.store.state();const recovered=f.store.recover(before.witness_head,f.store.inventoryHash(),now);assert.notEqual(recovered.epoch,before.epoch);assert.notEqual(recovered.recovery_epoch,before.recovery_epoch);assert.equal(recovered.recovery_revision,2);assert.equal(recovered.paused,true);denies(()=>f.store.admit(f.request,now));
 const rotated=f.store.rotate(1,2,digest('test-new-root-set'),recovered.witness_head,now);assert.equal(rotated.root_set_revision,2);assert.equal(rotated.paused,true);denies(()=>f.store.rotate(1,2,digest('replay'),rotated.witness_head,now));
});
test('request ID and challenge are one-use across operations',t=>{
 const f=fixture(t);f.store.admit(f.request,now);const a={...f.approval,approval_reference:'approval-2',operation_id:digest('second-operation')};f.store.registerApproval(a);
 denies(()=>f.store.admit({...f.request,method_body:{...f.request.method_body,operation_id:a.operation_id,approval_reference:a.approval_reference}},now));
 denies(()=>f.store.admit({...f.request,challenge:randomBytes(32).toString('base64url'),method_body:{...f.request.method_body,operation_id:a.operation_id,approval_reference:a.approval_reference}},now));
 denies(()=>f.store.admit({...f.request,request_id:randomBytes(32).toString('base64url'),method_body:{...f.request.method_body,operation_id:a.operation_id,approval_reference:a.approval_reference}},now));
});
test('grant withdrawal after admission durably invalidates the sender generation',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);f.store.registerGrant({...f.grant,revoked:true});assert.equal(f.store.state().generation,2);assert.equal(f.store.state().paused,true);denies(()=>f.store.markSending(op.operation_id,op.generation,now));
});
test('approval revision invalidates already sending work without reallocating a consumed slot',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);f.store.markSending(op.operation_id,1,now);
 const next=f.store.registerApproval({...f.approval,approval_revision:2,policy_revision:2});assert.equal(next.allocation_id,op.allocation_id);assert.ok(next.allocation_revision>op.allocation_revision);assert.equal(f.store.state().generation,2);assert.equal(f.store.state().paused,true);
 assert.equal(f.store.finish(op.operation_id,'SUCCEEDED',{provider_reference:'test-response'},now).state,'UNKNOWN');denies(()=>f.store.admit({...f.request,request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url'),method_body:{...f.request.method_body,expected_generation:2,approval_revision:2,policy_revision:2}},now));
});
test('partial witness event or state corruption cannot issue an apparently current checkpoint',t=>{
 const f=fixture(t);const raw=new DatabaseSync(f.path);const row=raw.prepare('SELECT payload FROM authority_state WHERE id=1').get();const state=JSON.parse(row.payload);state.generation+=10;raw.prepare('UPDATE authority_state SET payload=? WHERE id=1').run(JSON.stringify(state));denies(()=>f.store.state());
 raw.prepare('UPDATE authority_state SET payload=? WHERE id=1').run(row.payload);raw.prepare("UPDATE witness_events SET event='{}' WHERE sequence=1").run();denies(()=>f.store.state());raw.close();
});
test('authenticated no-send readback can drain a revoked admitted lease, preserving consumption',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);
 const proof={...binding,operation_id:op.operation_id,allocation_id:op.allocation_id,allocation_revision:op.allocation_revision,payload_fingerprint:op.payload_fingerprint,generation:op.generation,outcome:'FAILED',provider_reference:'test-custodian-confirmed-not-sent',observed_at:now};
 denies(()=>f.store.reconcile(op.operation_id,proof,now));f.store.emergency('before-send-emergency',1,now);assert.equal(f.store.drain('before-send-emergency',2).state,'PENDING');assert.equal(f.store.reconcile(op.operation_id,proof,now).state,'FAILED');assert.equal(f.store.drain('before-send-emergency',2).state,'DRAINED');denies(()=>f.store.admit(f.request,now));
});
test('unrevoked SENDING cannot be reconciled; actual revoked crash readback may resolve it',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);f.store.markSending(op.operation_id,1,now);
 const proof={...binding,operation_id:op.operation_id,allocation_id:op.allocation_id,allocation_revision:op.allocation_revision,payload_fingerprint:op.payload_fingerprint,generation:op.generation,outcome:'SUCCEEDED',provider_reference:'test-custodian-readback',observed_at:now};
 denies(()=>f.store.reconcile(op.operation_id,proof,now));f.store.emergency('during-send-emergency',1,now);assert.equal(f.store.reconcile(op.operation_id,proof,now).state,'SUCCEEDED');assert.equal(f.store.drain('during-send-emergency',2).state,'DRAINED');denies(()=>f.store.reconcile(op.operation_id,proof,now));
});
test('non-admission request claims persist cross-method and cross-role nonce consumption without changing witness head',t=>{
 const f=fixture(t);const before=f.store.state();const id=randomBytes(32).toString('base64url'),challenge=randomBytes(32).toString('base64url');
 f.store.claimRequest(id,challenge,'currentCheckpoint',now);assert.deepEqual(f.store.state(),before);const other=new AuthorityStore(f.path);
 denies(()=>other.claimRequest(id,randomBytes(32).toString('base64url'),'readDrain',now));denies(()=>other.claimRequest(randomBytes(32).toString('base64url'),id,'readDrain',now));
 denies(()=>other.admit({...f.request,request_id:challenge},now));other.close();
 f.store.admit(f.request,now);denies(()=>f.store.claimRequest(randomBytes(32).toString('base64url'),f.request.request_id,'readDrain',now));
});
test('currentGrant accessor checks exact stored binding, revocation and time',t=>{
 const f=fixture(t);assert.deepEqual(f.store.currentGrant(f.grant.grant_reference,now),f.grant);denies(()=>f.store.currentGrant(f.grant.grant_reference,now+120));f.store.registerGrant({...f.grant,revoked:true});denies(()=>f.store.currentGrant(f.grant.grant_reference,now));
});
test('dispatch request claims share the durable native nonce ledger and keep witness head stable',t=>{
 const f=fixture(t);const state=f.store.state();const id=tokenForDispatch(),challenge=tokenForDispatch();
 f.store.claimDispatchRequest(id,challenge,'state',now);assert.deepEqual(f.store.state(),state);
 const other=new AuthorityStore(f.path);denies(()=>other.claimRequest(tokenForDispatch(),id,'currentCheckpoint',now));denies(()=>other.claimDispatchRequest(challenge,tokenForDispatch(),'operation',now));denies(()=>other.admit({...f.request,request_id:id},now));other.close();
 f.store.admit(f.request,now);denies(()=>f.store.claimDispatchRequest(tokenForDispatch(),f.request.challenge,'publicResult',now));denies(()=>f.store.claimDispatchRequest(tokenForDispatch(),tokenForDispatch(),'arbitraryMethod',now));
});
function tokenForDispatch(){return randomBytes(32).toString('base64url');}
test('authenticated rejected admission consumes its request and challenge without creating authority events',t=>{
 const f=fixture(t);const before=f.store.state();f.store.claimRejectedAdmission(f.request.request_id,f.request.challenge,now);assert.deepEqual(f.store.state(),before);
 denies(()=>f.store.admit(f.request,now),'request_replay');denies(()=>f.store.claimRejectedAdmission(f.request.request_id,tokenForDispatch(),now),'request_replay');denies(()=>f.store.claimRequest(tokenForDispatch(),f.request.challenge,'currentCheckpoint',now),'request_replay');
});
test('failed admission rollback can be consumed permanently and cannot claim a successful allocation twice',t=>{
 const f=fixture(t);const wrong={...f.request,method_body:{...f.request.method_body,payload_fingerprint:digest('wrong-payload')}};denies(()=>f.store.admit(wrong,now));f.store.claimRejectedAdmission(wrong.request_id,wrong.challenge,now);denies(()=>f.store.admit(f.request,now),'request_replay');
 const fresh={...f.request,request_id:tokenForDispatch(),challenge:tokenForDispatch()};const op=f.store.admit(fresh,now);denies(()=>f.store.claimRejectedAdmission(fresh.request_id,fresh.challenge,now),'request_replay');assert.equal(f.store.operation(op.operation_id).state,'ADMITTED');
});
test('selectively restoring a finished operation to its admitted row cannot authorize a second send',t=>{
 const f=fixture(t);const admitted=f.store.admit(f.request,now);f.store.markSending(admitted.operation_id,admitted.generation,now);f.store.finish(admitted.operation_id,'SUCCEEDED',{provider_reference:'actual-component-result'},now);
 const raw=new DatabaseSync(f.path);raw.prepare('UPDATE operations SET payload=? WHERE operation_id=?').run(canonical(admitted),admitted.operation_id);raw.close();
 denies(()=>f.store.state(),'witness_state_inconsistent');denies(()=>f.store.operation(admitted.operation_id),'witness_state_inconsistent');denies(()=>f.store.markSending(admitted.operation_id,admitted.generation,now),'witness_state_inconsistent');
});
for(const table of ['grants','products','orders','approvals','emergencies'])test('selective '+table+' rollback cannot preserve an apparently valid witness state',t=>{
 const f=fixture(t);const raw=new DatabaseSync(f.path);let undo;
 if(table==='grants'){const before=raw.prepare('SELECT payload FROM grants WHERE reference=?').get('grant-1');f.store.registerGrant({...f.grant,revoked:true});undo=()=>raw.prepare('UPDATE grants SET payload=? WHERE reference=?').run(before.payload,'grant-1');}
 if(table==='products'){const before=raw.prepare('SELECT payload FROM products WHERE identity=?').get('product-1');f.store.advanceProduct('product-1',1,'advanced-core');undo=()=>raw.prepare('UPDATE products SET payload=? WHERE identity=?').run(before.payload,'product-1');}
 if(table==='orders'){const before=raw.prepare('SELECT payload FROM orders WHERE identity=?').get('order-1');f.store.advanceOrder('order-1',1,'advanced-core');undo=()=>raw.prepare('UPDATE orders SET payload=? WHERE identity=?').run(before.payload,'order-1');}
 if(table==='approvals'){const before=raw.prepare('SELECT payload,allocation_revision,consumed FROM approvals WHERE reference=?').get('approval-1');f.store.admit(f.request,now);undo=()=>raw.prepare('UPDATE approvals SET payload=?,allocation_revision=?,consumed=? WHERE reference=?').run(before.payload,before.allocation_revision,before.consumed,'approval-1');}
 if(table==='emergencies'){f.store.emergency('retained-emergency',1,now);undo=()=>raw.prepare('DELETE FROM emergencies WHERE event_id=?').run('retained-emergency');}
 undo();raw.close();denies(()=>f.store.state(),'witness_state_inconsistent');denies(()=>f.store.admit({...f.request,request_id:tokenForDispatch(),challenge:tokenForDispatch()},now),'witness_state_inconsistent');
});
test('revoked still-admitted work can be abandoned without a provider receipt or reusable allocation',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);const raw=new DatabaseSync(f.path);const before=raw.prepare('SELECT * FROM approvals WHERE reference=?').get(op.approval_reference);const e=f.store.emergency('abandon-emergency',1,now);
 const result=f.store.abandonUnsent(op.operation_id,op.allocation_id,op.allocation_revision,op.generation,e.witness_head,now);assert.equal(result.state,'FAILED');assert.deepEqual(result.evidence,{failure_category:'revoked_before_send'});assert.equal(Object.hasOwn(result.evidence,'provider_reference'),false);assert.equal(result.allocation_id,op.allocation_id);assert.equal(result.allocation_revision,op.allocation_revision);
 assert.deepEqual(raw.prepare('SELECT * FROM approvals WHERE reference=?').get(op.approval_reference),before);raw.close();assert.equal(f.store.state().paused,true);assert.equal(f.store.drain('abandon-emergency',2).state,'DRAINED');
 denies(()=>f.store.abandonUnsent(op.operation_id,op.allocation_id,op.allocation_revision,op.generation,f.store.state().witness_head,now));denies(()=>f.store.markSending(op.operation_id,op.generation,now));denies(()=>f.store.admit({...f.request,request_id:tokenForDispatch(),challenge:tokenForDispatch()},now));
});
test('unsent abandonment denies unrevoked work, stale heads and every foreign allocation tuple',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);const initial=f.store.state();const call=(allocation=op.allocation_id,revision=op.allocation_revision,generation=op.generation,head=f.store.state().witness_head)=>f.store.abandonUnsent(op.operation_id,allocation,revision,generation,head,now);
 denies(()=>call());const emergency=f.store.emergency('negative-abandon',1,now);denies(()=>call(op.allocation_id,op.allocation_revision,op.generation,initial.witness_head));denies(()=>call('foreign-allocation'));denies(()=>call(op.allocation_id,op.allocation_revision+1));denies(()=>call(op.allocation_id,op.allocation_revision,emergency.generation));assert.equal(f.store.operation(op.operation_id).state,'ADMITTED');
});
test('actual approval withdrawal may advance the allocation while abandonment binds the original admitted revision',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);const withdrawn=f.store.registerApproval({...f.approval,approval_revision:2,revoked:true});assert.equal(withdrawn.allocation_revision,op.allocation_revision+1);const head=f.store.state().witness_head;
 const raw=new DatabaseSync(f.path);const before=raw.prepare('SELECT * FROM approvals WHERE reference=?').get(op.approval_reference);
 denies(()=>f.store.abandonUnsent(op.operation_id,op.allocation_id,withdrawn.allocation_revision,op.generation,head,now),'allocation_binding');
 const abandoned=f.store.abandonUnsent(op.operation_id,op.allocation_id,op.allocation_revision,op.generation,head,now);assert.equal(abandoned.state,'FAILED');assert.deepEqual(abandoned.evidence,{failure_category:'revoked_before_send'});assert.equal(abandoned.allocation_revision,op.allocation_revision);assert.deepEqual(raw.prepare('SELECT * FROM approvals WHERE reference=?').get(op.approval_reference),before);raw.close();assert.equal(f.store.state().paused,true);
});
for(const state of ['SENDING','UNKNOWN','SUCCEEDED','FAILED'])test('unsent abandonment never reclassifies '+state+' as not sent',t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);f.store.markSending(op.operation_id,1,now);if(state!=='SENDING')f.store.finish(op.operation_id,state,{failure_category:'component-observed'},now);const emergency=f.store.emergency('not-unsent',1,now);
 denies(()=>f.store.abandonUnsent(op.operation_id,op.allocation_id,op.allocation_revision,op.generation,emergency.witness_head,now));assert.equal(f.store.operation(op.operation_id).state,state);
});
function child(path,request,action='admit'){return new Promise((resolve,reject)=>{const p=spawn(process.execPath,[new URL('./store-worker.mjs',import.meta.url).pathname,path,JSON.stringify(request),String(now),action],{stdio:['ignore','pipe','pipe']});let out='',err='';p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);p.on('error',reject);p.on('close',code=>{try{resolve({code,...JSON.parse(out.trim())});}catch{reject(new Error('Worker failed: '+err));}});});}
test('two actual Node processes race one allocation; only one commits',async t=>{
 const f=fixture(t);const requests=[f.request,{...f.request,request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url')}];
 const results=await Promise.all(requests.map(r=>child(f.path,r)));assert.equal(results.filter(r=>r.ok).length,1);assert.equal(results.filter(r=>!r.ok).length,1);assert.equal(f.store.operation(f.request.method_body.operation_id).state,'ADMITTED');
});
test('separate worker exits after durable SENDING without finish; crash stays consumed after reopen',async t=>{
 const f=fixture(t);const result=await child(f.path,f.request,'crash-sending');assert.equal(result.code,23);assert.equal(f.store.operation(f.request.method_body.operation_id).state,'SENDING');
 const reopened=new AuthorityStore(f.path);denies(()=>reopened.admit(f.request,now));assert.equal(reopened.emergency('emergency-crash',1,now).generation,2);assert.equal(reopened.drain('emergency-crash',2).state,'PENDING');reopened.close();
});
test('actual separate-process markSending and emergency-abandon race cannot both succeed',async t=>{
 const f=fixture(t);const op=f.store.admit(f.request,now);const results=await Promise.all([child(f.path,f.request,'race-send'),child(f.path,f.request,'race-abandon')]);
 assert.equal(results.filter(r=>r.ok).length,1);const final=f.store.operation(op.operation_id);if(results[0].ok){assert.equal(final.state,'SENDING');assert.equal(results[1].ok,false);assert.equal(f.store.drain('process-abandon',2).state,'PENDING');}else{assert.equal(results[1].ok,true);assert.equal(final.state,'FAILED');assert.deepEqual(final.evidence,{failure_category:'revoked_before_send'});assert.equal(f.store.drain('process-abandon',2).state,'DRAINED');}assert.equal(final.allocation_revision,op.allocation_revision);assert.equal(f.store.state().paused,true);
});
