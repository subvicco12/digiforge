import assert from 'node:assert/strict';
import { createPrivateKey, createPublicKey } from 'node:crypto';
import { b64, hash } from '../protocol.mjs';
import CustodyFixture,{CustodyFixtureState} from '../custody-bridge/entrypoint.mjs';
import callerWorker,{DigiForgeStageAManual,DigiForgeStageACallerState} from '../custody-bridge/caller-entrypoint.mjs';
import { LocalSqliteStorage } from './support.mjs';

let input='';
for await (const chunk of process.stdin) {
  input+=chunk;
  if (input.length>4097) process.exit(2);
}
const wire=input.trimEnd(), request=JSON.parse(wire);
const seed=await hash('public-test-only-custody-mock-v1');
const raw=Buffer.from('302e020100300506032b657004220420'+seed,'hex');
const signer_key=b64(createPublicKey(createPrivateKey({key:raw,format:'der',type:'pkcs8'}))
  .export({format:'der',type:'spki'}).subarray(-32));
const start=Math.floor(Date.now()/1000), end=start+60;
const scope={
  shop:request.shop,account_reference:request.account_reference,credential_reference:request.credential_reference,
  source_sha:request.source_sha,source_revision:request.source_revision,authorization_revision:request.authorization_revision,
  signer_key,start,end,
};
const id='stage-a-custody-'+request.operation_id, approval='e'.repeat(64);
const event={instanceId:id,payload:{schema:'DF_STAGE_A_CUSTODY_READ_1',approval,wire}};
assert.equal(callerWorker.fetch().status,503);
assert.equal(new CustodyFixture({},{DF_CUSTODY_MODE:'DISABLED'}).fetch().status,503);
assert.equal((await new CustodyFixture({},{DF_CUSTODY_MODE:'DISABLED'}).exchange(wire)).state,'DENIED');
const context=()=>{
  const storage=new LocalSqliteStorage();
  Object.defineProperty(storage.sql,'databaseSize',{value:4096});
  return {id:'fixture-object',storage,blockConcurrencyWhile:fn=>fn()};
};
const bridgeEnv={DF_CUSTODY_MODE:'SYNTHETIC_TEST',DF_CUSTODY_SCOPE_JSON:JSON.stringify(scope),
  DF_CUSTODY_FIXTURE_SIGNER_PKCS8:b64(raw)};
const bridgeObject=new CustodyFixtureState(context(),bridgeEnv);
let bridgeCalls=0;
bridgeEnv.DF_CUSTODY_STATE={idFromName:()=> 'fixture-object',get:()=>{
  ++bridgeCalls; return bridgeObject;
}};
const bridge=new CustodyFixture({},bridgeEnv);
const callerEnv={DF_CUSTODY_CALLER_MODE:'DISABLED',DF_CUSTODY_BRIDGE:bridge,
  DF_CUSTODY_CALLER_CONFIG:JSON.stringify({...scope,approval,operation_id:request.operation_id,request_sha256:await hash(wire)})};
const workflow=new DigiForgeStageAManual({},callerEnv);
const step={do:(_name,opts,fn)=>{
  assert.equal(opts.retries.limit,0); return fn();
}};
assert.equal((await workflow.run(event,step)).state,'DENIED');
assert.equal(bridgeCalls,0);
callerEnv.DF_CUSTODY_CALLER_MODE='SYNTHETIC_TEST';
const ctx=context();
let caller;
callerEnv.DF_CALLER_STATE={idFromName:()=>ctx.id,get:()=>caller};
caller=new DigiForgeStageACallerState(ctx,callerEnv);
assert.equal((await workflow.run({...event,schedule:'blocked'},step)).state,'DENIED');
const output=await workflow.run(event,step);
assert.equal(bridgeCalls,1);
assert.equal(JSON.parse(output.record).status,200);
caller=new DigiForgeStageACallerState(ctx,callerEnv);
assert.equal((await workflow.run(event,step)).reason,'CALLER_REPLAY');
assert.equal(bridgeCalls,1);
process.stdout.write(JSON.stringify({instance_id:id,status:'complete',output}));
