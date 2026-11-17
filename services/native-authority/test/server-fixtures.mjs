import { mkdtempSync, chmodSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
import { serviceBuildDigest, loadConfig, initializeStore } from '../config.mjs';
import { sign, digest, VERSION, PURPOSE, canonical } from '../protocol.mjs';
import { bindingOf } from '../schema.mjs';

export function witnessFixture(t,options={}) {
  const dir=mkdtempSync(join(tmpdir(),'df-server-'));chmodSync(dir,0o700);t.after(()=>rmSync(dir,{recursive:true,force:true}));
  const pairs={},maps={};for(const role of ['workload','issuer','witness','owner','emergency','recovery']){pairs[role]=generateKeyPairSync('ed25519');maps[role]={[role]:pairs[role].publicKey.export({type:'spki',format:'pem'})};}
  const write=(name,value)=>{const path=join(dir,name);writeFileSync(path,typeof value==='string'?value:JSON.stringify(value),{mode:0o600});return path;};
  const identity={authority_id:'authority',installation_id:'installation',owner_id:'owner',owner_proof_reference:'proof',workload_id:'workload',deployment_digest:serviceBuildDigest(),compatibility_floor:VERSION,provider:'printify',provider_account_reference:'account',environment:'test',integration_id:7,credential_reference:7,shop_id:2,shop_key:'personalized_pod',witness_id:'witness',root_set_digest:digest(maps.issuer),root_set_revision:1};
  const binding=bindingOf(identity),time=Math.floor(Date.now()/1000);
  const installation={schema:'DF_NATIVE_INSTALLATION_1',version:VERSION,identity,principal_set_digest:digest(maps),mode:'OFFLINE_TEST',provider_base_url:'http://127.0.0.1:1/',holds:{stop_all:true,external_lock:true,automation:false,release_hold:true},products:[],orders:[],grants:[{grant_reference:'grant',binding,issued_at:time-1,expires_at:time+120,revoked:false}],approvals:[],credential_inventory:[],payload_inventory:[],issued_at:time-1,expires_at:time+120};
  if(options.released)installation.holds={stop_all:false,external_lock:false,automation:true,release_hold:false};
  if(options.native){
    const lines=[{line_identity:'line',quantity:2}];
    installation.products=[{binding,product_identity:'product',core_reference:'core-product'}];
    installation.orders=[{binding,product_identity:'product',order_identity:'order',core_reference:'core-order',lines}];
    installation.approvals=[{binding,approval_reference:'approval',approval_revision:1,policy_revision:1,operation_id:digest('operation'),order_identity:'order',order_version:1,listing_identity:'listing',product_identity:'product',product_version:1,expected_product_version:1,payload_fingerprint:digest('payload'),lines}];
  }
  const config={role:'witness',mode:'OFFLINE_TEST',listen:{host:'127.0.0.1',port:0},identity,keys:{signing_key_id:'witness',signing_private_key_path:write('witness.pem',pairs.witness.privateKey.export({type:'pkcs8',format:'pem'})),...maps},provisioning:null,database_path:join(dir,'witness.sqlite'),witness_url:null,credentials_path:null,payloads_path:null};
  const save=()=>{config.provisioning={owner:sign('installation.owner.v1',installation,pairs.owner.privateKey,'owner'),recovery:sign('installation.recovery.v1',installation,pairs.recovery.privateKey,'recovery')};return write('config.json',config);};
  const path=save(),store=initializeStore(loadConfig(path,'witness'));const state=store.state();store.close();
  const request=(method,body,role=method==='requestEmergency'?'emergency':method==='readDrain'||method==='recoverInstallation'||method==='rotateRoot'?'recovery':'workload')=>sign(PURPOSE[method],{...binding,version:VERSION,method,message_purpose:PURPOSE[method],request_id:randomBytes(32).toString('base64url'),challenge:randomBytes(32).toString('base64url'),method_body:body},pairs[role].privateKey,role);
  const forward=envelope=>sign('issuer.forward.v1',{schema:'DF_NATIVE_FORWARD_1',version:VERSION,binding,request_envelope:envelope},pairs.issuer.privateKey,'issuer');
  const control=(type,reference,data)=>{const message={schema:'DF_NATIVE_CONTROL_1',version:VERSION,reference,binding,issued_at:time-1,expires_at:time+120,type,data};return {owner:sign('control.owner.v1',message,pairs.owner.privateKey,'owner'),recovery:sign('control.recovery.v1',message,pairs.recovery.privateKey,'recovery')};};
  return {path,config,installation,identity,binding,state,pairs,maps,request,forward,control,save,write};
}

export async function postRaw(address,path,raw) {
  const {request}=await import('node:http');const bytes=Buffer.isBuffer(raw)?raw:Buffer.from(raw);
  return new Promise((resolve,reject)=>{const req=request(new URL(path,address),{method:'POST',agent:false,headers:{'content-type':'application/json','content-length':bytes.length}},res=>{const chunks=[];res.on('data',v=>chunks.push(v));res.on('end',()=>resolve({status:res.statusCode,body:JSON.parse(Buffer.concat(chunks).toString('utf8'))}));});req.on('error',reject);req.end(bytes);});
}
export const post=(address,path,value)=>postRaw(address,path,canonical(value));
