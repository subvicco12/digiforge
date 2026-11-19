import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WitnessStoreGateway } from '../gateway.mjs';
import { AuthorityStore } from '../store.mjs';
import { canonical, digest, parseCanonical, sign, verify, signWitness } from '../protocol.mjs';

const binding={authority_id:'gateway-authority',installation_id:'gateway-installation',owner_id:'gateway-owner',owner_proof_reference:'gateway-owner-proof',workload_id:'gateway-workload',deployment_digest:digest('gateway-deployment'),compatibility_floor:'1.0.0',provider:'printify',provider_account_reference:'gateway-account',environment:'test',integration_id:123,credential_reference:123,shop_id:2,shop_key:'personalized_pod'};
const now=()=>Math.floor(Date.now()/1000);
const token=()=>randomBytes(32).toString('base64url');
async function fixture(t,mode={}){
 const dir=mkdtempSync(join(tmpdir(),'df-witness-gateway-'));const store=new AuthorityStore(join(dir,'witness.sqlite'));const workload=generateKeyPairSync('ed25519'),witness=generateKeyPairSync('ed25519');
 store.bootstrap({...binding,witness_id:'gateway-witness',root_set_digest:digest('gateway-root-set')},now());store.registerProduct({product_identity:'gateway-product',binding,core_reference:'gateway-core'});store.registerOrder({order_identity:'gateway-order',product_identity:'gateway-product',lines:[{line_identity:'gateway-line',quantity:2}],binding,core_reference:'gateway-core'});
 store.registerGrant({grant_reference:'gateway-grant',binding,issued_at:now()-1,expires_at:now()+120,revoked:false});
 const opid=digest('gateway-operation');const payload=digest('gateway-payload');store.registerApproval({approval_reference:'gateway-approval',approval_revision:1,policy_revision:1,operation_id:opid,order_identity:'gateway-order',order_version:1,listing_identity:'gateway-listing',product_identity:'gateway-product',product_version:1,expected_product_version:1,payload_fingerprint:payload,lines:[{line_identity:'gateway-line',quantity:2}],binding});
 const original={...binding,version:'1.0.0',method:'admitOperation',message_purpose:'operation.request.v1',request_id:token(),challenge:token(),method_body:{grant_reference:'gateway-grant',operation_id:opid,payload_fingerprint:payload,product_version:1,expected_product_version:1,approval_reference:'gateway-approval',approval_revision:1,policy_revision:1,expected_epoch:store.state().epoch,expected_generation:1}};
 store.admit(original,now());const originalEnvelope=sign('operation.request.v1',original,workload.privateKey,'workload-key');let calls=0;let previous=null;const seen=[];
 const server=createServer(async(req,res)=>{
  try{
   ++calls;assert.equal(req.url,'/dispatch');assert.equal(req.method,'POST');let raw='';for await(const chunk of req)raw+=chunk.toString('utf8');assert.ok(!raw.includes('PRIVATE KEY'));
   const rpc=verify('sender.store.request.v1',parseCanonical(raw),new Map([['workload-key',workload.publicKey]]));seen.push(rpc);assert.deepEqual(rpc.binding,binding);assert.deepEqual(rpc.original_envelope,originalEnvelope);
   store.claimDispatchRequest(rpc.request_id,rpc.challenge,rpc.action,now());
   let result;if(rpc.action==='state')result=store.state();else if(rpc.action==='publicResult'){
    const s=store.state(),op=store.operation(rpc.operation_id);let nested={version:'1.0.0',request_bindings:original,authority_id:binding.authority_id,key_id:'witness-key',root_set_revision:s.root_set_revision,recovery_epoch:s.recovery_epoch,checkpoint_epoch:s.epoch,generation:s.generation,recovery_revision:s.recovery_revision,issued_at:now()-1,expires_at:now()+20,disposition:op.state,evidence_reference:'witness-event-'+s.witness_sequence,witness_id:s.witness_id,witness_head:s.witness_head,witness_sequence:s.witness_sequence,result:{schema:'DF_NATIVE_AUTHORITY_RESULT_1',method:'admitOperation',state:op.state,operation:{operation_id:op.operation_id,state:op.state},drain:null,reason:null,external_execution_authorized:false}};
    if(mode.nested)nested=mode.nested(nested);result=mode.nestedOrdinary?sign('witness.response.v1',nested,witness.privateKey,'witness-key'):signWitness('witness.response.v1',nested,witness.privateKey,'witness-key');
   }else result=store[rpc.action](rpc.operation_id,...rpc.arguments);
   let message={schema:'DF_NATIVE_STORE_RESPONSE_1',version:'1.0.0',binding,action:rpc.action,operation_id:rpc.operation_id,request_id:rpc.request_id,challenge:rpc.challenge,issued_at:now()-1,expires_at:now()+20,ok:true,result,error:null};
   if(mode.change)message=mode.change(message);let envelope=mode.ordinary?sign('witness.store.response.v1',message,witness.privateKey,'witness-key'):signWitness('witness.store.response.v1',message,witness.privateKey,mode.wrongKey?'unknown-key':'witness-key');
   if(mode.replay&&previous)envelope=previous;previous=envelope;
   if(mode.timeout)return;
   if(mode.redirect){res.writeHead(302,{location:'/untrusted-redirect'});res.end();return;}
   const text=mode.raw?mode.raw(canonical(envelope)):canonical(envelope);res.writeHead(200,{'content-type':'application/json',...mode.headers});res.end(text);
  }catch(e){if(!res.headersSent){res.writeHead(500);res.end(canonical({error:e.code??'component_fixture_failed'}));}}
 });await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 t.after(async()=>{server.closeAllConnections();await new Promise(resolve=>server.close(resolve));store.close();rmSync(dir,{recursive:true,force:true});});
 const options={url:'http://127.0.0.1:'+server.address().port,binding,keyId:'workload-key',privateKey:workload.privateKey,witnessKeys:new Map([['witness-key',witness.publicKey]]),originalEnvelope,timeoutMs:500};
 return {gateway:new WitnessStoreGateway(options),options,store,opid,mode,calls:()=>calls,seen};
}
async function denied(promise,code){await assert.rejects(promise,e=>e?.name==='AuthorityError'&&(!code||e.code===code));}
test('real signed HTTP gateway reads, starts and finishes the actual remote SQLite operation',async t=>{
 const f=await fixture(t);assert.deepEqual(await f.gateway.state(),f.store.state());assert.equal((await f.gateway.operation(f.opid)).state,'ADMITTED');assert.equal((await f.gateway.markSending(f.opid,1,now())).state,'SENDING');assert.equal((await f.gateway.finish(f.opid,'UNKNOWN',{failure_category:'timeout'},now())).state,'UNKNOWN');
 const op=f.store.operation(f.opid);const proof={...binding,operation_id:f.opid,allocation_id:op.allocation_id,allocation_revision:op.allocation_revision,payload_fingerprint:op.payload_fingerprint,generation:op.generation,outcome:'FAILED',provider_reference:'component-custodian-not-found',observed_at:now()};
 assert.equal((await f.gateway.reconcile(f.opid,proof,now())).state,'FAILED');assert.equal(f.calls(),5);assert.deepEqual(f.seen.map(r=>r.action),['state','operation','markSending','finish','reconcile']);assert.deepEqual(f.seen[2].arguments,[1,f.seen[2].arguments[1]]);assert.equal(new Set(f.seen.flatMap(r=>[r.request_id,r.challenge])).size,10);assert.equal(JSON.stringify(f.gateway),'{}');
});
for(const [name,change] of [
 ['foreign binding',m=>({...m,binding:{...binding,provider_account_reference:'foreign'}})],['wrong action',m=>({...m,action:'finish'})],['wrong operation',m=>({...m,operation_id:digest('foreign')})],['wrong request ID',m=>({...m,request_id:token()})],['wrong challenge',m=>({...m,challenge:token()})],['future issuance',m=>({...m,issued_at:now()+1})],['expired response',m=>({...m,expires_at:now()})],['excessive lifetime',m=>({...m,expires_at:now()+60})],['wrong schema',m=>({...m,schema:'OTHER'})],['wrong version',m=>({...m,version:'2.0.0'})],['nonboolean status',m=>({...m,ok:'true'})],['array result',m=>({...m,result:[]})],['unexpected member',m=>({...m,extra:true})],['unbounded error text',m=>({...m,ok:false,result:null,error:'sensitive\nmessage'})],
])test('signed '+name+' cannot become a store observation',async t=>{const f=await fixture(t,{change});await denied(f.gateway.state());});
test('same witness key in the ordinary issuer domain cannot authenticate a witness response',async t=>{const f=await fixture(t,{ordinary:true});await denied(f.gateway.state(),'signature_invalid');});
test('unknown witness key and a replayed signed response deny without cached fallback',async t=>{const f=await fixture(t,{wrongKey:true});await denied(f.gateway.state(),'untrusted_key');f.mode.wrongKey=false;await f.gateway.state();f.mode.replay=true;await denied(f.gateway.state());});
test('signed bounded remote denial becomes a bounded AuthorityError',async t=>{const f=await fixture(t,{change:m=>({...m,ok:false,result:null,error:'operation_not_current'})});await denied(f.gateway.state(),'operation_not_current');});
test('HTTP redirects and absolute request timeout never reuse an older state',async t=>{const f=await fixture(t);await f.gateway.state();f.mode.redirect=true;await denied(f.gateway.state());f.mode.redirect=false;f.mode.timeout=true;const gateway=new WitnessStoreGateway({...f.options,timeoutMs:20});await denied(gateway.state());});
test('oversized and duplicate-member wire responses are rejected before returning data',async t=>{const f=await fixture(t,{raw:raw=>raw+' '.repeat(65537)});await denied(f.gateway.state());f.mode.raw=raw=>raw.replace('{','{"key_id":"duplicate",');await denied(f.gateway.state());});
test('gateway configuration is fixed to a safe base and private inputs are snapshotted',async t=>{
 const f=await fixture(t);for(const url of ['http://example.com','http://127.0.0.1/x','http://user:pass@127.0.0.1','http://127.0.0.1/?x=1','http://127.0.0.1/#x'])assert.throws(()=>new WitnessStoreGateway({...f.options,url}));
 f.options.binding={...binding,provider_account_reference:'mutated'};f.options.witnessKeys.clear();assert.equal((await f.gateway.state()).identity.provider_account_reference,binding.provider_account_reference);
 assert.throws(()=>new WitnessStoreGateway({...f.options,binding,witnessKeys:new Map(),originalEnvelope:{text:'x'.repeat(70000)}}));
});
test('publicResult returns the genuine witness-domain final envelope for the original admission without readmitting',async t=>{
 const f=await fixture(t);await f.gateway.markSending(f.opid,1,now());await f.gateway.finish(f.opid,'FAILED',{failure_category:'component-denial'},now());const before=f.store.operation(f.opid);const envelope=await f.gateway.publicResult(f.opid);assert.equal(envelope.message.result.state,'FAILED');assert.deepEqual(envelope.message.request_bindings,f.options.originalEnvelope.message);assert.deepEqual(f.store.operation(f.opid),before);
});
for(const [name,nested]of[
 ['different original request',m=>({...m,request_bindings:{...m.request_bindings,request_id:token()}})],['expired attestation',m=>({...m,expires_at:now()})],['wrong result operation',m=>({...m,result:{...m.result,operation:{operation_id:digest('foreign')}}})],['additional field',m=>({...m,extra:true})],['execution grant',m=>({...m,result:{...m.result,external_execution_authorized:true}})],
])test('publicResult rejects signed '+name,async t=>{const f=await fixture(t,{nested});await denied(f.gateway.publicResult(f.opid));});
test('publicResult cannot reuse the issuer signature domain for its nested witness attestation',async t=>{const f=await fixture(t,{nestedOrdinary:true});await denied(f.gateway.publicResult(f.opid),'signature_invalid');});
test('wire content type and encoding remain fixed despite otherwise authentic signatures',async t=>{const f=await fixture(t,{headers:{'content-type':'text/plain'}});await denied(f.gateway.state(),'witness_http_headers');f.mode.headers={'content-encoding':'gzip'};await denied(f.gateway.state(),'witness_http_headers');});
test('a UTF-8 BOM cannot be stripped into an otherwise canonical signed wire response',async t=>{const f=await fixture(t,{raw:raw=>'\uFEFF'+raw});await denied(f.gateway.state(),'invalid_json');});
