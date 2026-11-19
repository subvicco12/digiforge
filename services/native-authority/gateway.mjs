import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { createPrivateKey, createPublicKey, randomBytes } from 'node:crypto';
import { canonical, parseCanonical, sign, verifyWitness, VERSION } from './protocol.mjs';
import { exactFields, validateBinding, digestValue, integer, reference } from './schema.mjs';
import { AuthorityError, requireAuthority as need } from './errors.mjs';

const LIMIT=65536;
const RESPONSE=['schema','version','binding','action','operation_id','request_id','challenge','issued_at','expires_at','ok','result','error'];
const PUBLIC_RESPONSE=['version','request_bindings','authority_id','key_id','root_set_revision','recovery_epoch','checkpoint_epoch','generation','recovery_revision','issued_at','expires_at','disposition','evidence_reference','witness_id','witness_head','witness_sequence','result'];
const plain=v=>v!==null&&typeof v==='object'&&Object.getPrototypeOf(v)===Object.prototype;
const clone=v=>parseCanonical(canonical(v));
const fresh=(m,seconds)=>{const now=Math.floor(Date.now()/1000);return integer(m.issued_at)&&integer(m.expires_at)&&m.issued_at<=now&&now<m.expires_at&&m.expires_at>m.issued_at&&m.expires_at-m.issued_at<=seconds;};
const error=code=>new AuthorityError(code);

/** Signed remote witness storage only. No provider credential, cached authority or transport grant. */
export class WitnessStoreGateway {
 #url;#binding;#keyId;#privateKey;#witnessKeys;#original;#control;#timeout;
 constructor({url,binding,keyId,privateKey,witnessKeys,originalEnvelope,controlEnvelope=null,timeoutMs=2000}){
  try{
   need(typeof url==='string','gateway_url');const base=new URL(url);
   need(!base.username&&!base.password&&!base.search&&!base.hash&&base.pathname==='/'&&['http:','https:'].includes(base.protocol),'gateway_url');
   need(base.protocol==='https:'||/^127\.(?:\d{1,3}\.){2}\d{1,3}$/.test(base.hostname)||base.hostname==='[::1]','gateway_url');
   validateBinding(binding);need(typeof keyId==='string'&&/^[A-Za-z0-9_-]{1,80}$/.test(keyId),'key_id');need(witnessKeys instanceof Map&&witnessKeys.size>0&&witnessKeys.size<=16,'witness_keys');need(integer(timeoutMs)&&timeoutMs<=10000,'gateway_timeout');
   this.#privateKey=privateKey?.type==='private'?privateKey:createPrivateKey(privateKey);need(this.#privateKey.asymmetricKeyType==='ed25519','fixed_algorithm');this.#witnessKeys=new Map();
   for(const [id,value]of witnessKeys){need(typeof id==='string'&&/^[A-Za-z0-9_-]{1,80}$/.test(id),'key_id');const publicKey=value?.type==='public'?value:createPublicKey(value);need(publicKey.asymmetricKeyType==='ed25519','fixed_algorithm');this.#witnessKeys.set(id,publicKey);}
   need(originalEnvelope===null||plain(originalEnvelope),'original_envelope');need(controlEnvelope===null||plain(controlEnvelope),'control_envelope');
   this.#url=new URL('/dispatch',base);this.#binding=clone(binding);this.#keyId=keyId;this.#original=clone(originalEnvelope);this.#control=clone(controlEnvelope);this.#timeout=timeoutMs;
  }catch(e){if(e instanceof AuthorityError)throw e;throw error('gateway_configuration');}
 }
 async state(){return this.#call('state',null,[]);}
 async operation(operationId){need(digestValue(operationId),'operation_identity');return this.#call('operation',operationId,[]);}
 async markSending(operationId,expectedGeneration,now){need(digestValue(operationId)&&integer(expectedGeneration)&&integer(now),'dispatch_arguments');return this.#call('markSending',operationId,[expectedGeneration,now]);}
 async finish(operationId,status,evidence,now){need(digestValue(operationId)&&['SUCCEEDED','FAILED','UNKNOWN'].includes(status)&&plain(evidence)&&integer(now),'dispatch_arguments');return this.#call('finish',operationId,[status,evidence,now]);}
 async reconcile(operationId,evidence,now){need(digestValue(operationId)&&plain(evidence)&&integer(now),'dispatch_arguments');return this.#call('reconcile',operationId,[evidence,now]);}
 async publicResult(operationId){
  need(digestValue(operationId)&&this.#original!==null,'operation_identity');const envelope=await this.#call('publicResult',operationId,[]);
  try{
   const response=verifyWitness('witness.response.v1',envelope,this.#witnessKeys);exactFields(response,PUBLIC_RESPONSE,'witness_result_fields');
   need(response.version===VERSION&&response.key_id===envelope.key_id&&response.authority_id===this.#binding.authority_id&&fresh(response,300)&&canonical(response.request_bindings)===canonical(this.#original.message),'witness_result_binding');
   need(integer(response.root_set_revision)&&integer(response.generation)&&integer(response.recovery_revision)&&integer(response.witness_sequence)&&digestValue(response.recovery_epoch)&&digestValue(response.checkpoint_epoch)&&digestValue(response.witness_head)&&reference(response.witness_id)&&reference(response.evidence_reference),'witness_result_state');
   exactFields(response.result,['schema','method','state','operation','drain','reason','external_execution_authorized'],'witness_result_fields');
   need(response.result.schema==='DF_NATIVE_AUTHORITY_RESULT_1'&&response.result.method===this.#original.message.method&&response.result.state===response.disposition&&response.result.external_execution_authorized===false&&plain(response.result.operation)&&response.result.operation.operation_id===operationId,'witness_result_binding');return envelope;
  }catch(e){if(e instanceof AuthorityError)throw e;throw error('witness_result_invalid');}
 }
 async #call(action,operationId,args){
  try{
   const requestId=randomBytes(32).toString('base64url');let challenge;do{challenge=randomBytes(32).toString('base64url');}while(challenge===requestId);
   const message={version:VERSION,binding:this.#binding,action,operation_id:operationId,request_id:requestId,challenge,arguments:args,original_envelope:this.#original,control_envelope:this.#control};
   const envelope=sign('sender.store.request.v1',message,this.#privateKey,this.#keyId);const body=Buffer.from(canonical(envelope),'utf8');need(body.length<=LIMIT,'gateway_request_bound');
   const wire=await this.#exchange(body);const response=verifyWitness('witness.store.response.v1',parseCanonical(wire),this.#witnessKeys);exactFields(response,RESPONSE,'witness_response_fields');
   need(response.schema==='DF_NATIVE_STORE_RESPONSE_1'&&response.version===VERSION,'witness_response_schema');validateBinding(response.binding);
   need(canonical(response.binding)===canonical(this.#binding)&&response.action===action&&response.operation_id===operationId&&response.request_id===requestId&&response.challenge===challenge,'witness_response_binding');need(fresh(response,30),'witness_response_expired');
   need(typeof response.ok==='boolean','witness_response_status');
   if(response.ok){need(response.error===null&&plain(response.result),'witness_response_result');return response.result;}
   need(response.result===null&&typeof response.error==='string'&&/^[a-z][a-z0-9_]{0,100}$/.test(response.error),'witness_response_error');throw error(response.error);
  }catch(e){if(e instanceof AuthorityError)throw e;throw error('witness_unavailable');}
 }
 #exchange(body){
  return new Promise((resolve,reject)=>{
   let settled=false,request;const finish=(failure,value)=>{if(settled)return;settled=true;clearTimeout(timer);if(failure){request?.destroy();reject(failure);}else resolve(value);};
   const timer=setTimeout(()=>finish(error('witness_timeout')),this.#timeout);
   try{
    request=(this.#url.protocol==='https:'?httpsRequest:httpRequest)(this.#url,{method:'POST',agent:false,headers:{'content-type':'application/json','content-length':body.length}},response=>{
     if(response.statusCode<200||response.statusCode>=300){response.resume();finish(error('witness_http_status'));return;}
     if(!/^application\/json(?:\s*;|$)/i.test(response.headers['content-type']??'')||(response.headers['content-encoding']&&response.headers['content-encoding']!=='identity')){response.resume();finish(error('witness_http_headers'));return;}
     const declared=response.headers['content-length'];if(declared!==undefined&&(!/^\d+$/.test(declared)||Number(declared)>LIMIT)){response.resume();finish(error('witness_response_bound'));return;}
     const chunks=[];let size=0;response.on('data',chunk=>{size+=chunk.length;if(size>LIMIT){finish(error('witness_response_bound'));return;}chunks.push(chunk);});
     response.on('error',()=>finish(error('witness_unavailable')));response.on('aborted',()=>finish(error('witness_unavailable')));
     response.on('end',()=>{if(settled)return;try{finish(null,new TextDecoder('utf-8',{fatal:true,ignoreBOM:true}).decode(Buffer.concat(chunks)));}catch{finish(error('witness_response_encoding'));}});
    });request.on('error',()=>finish(error('witness_unavailable')));request.end(body);
   }catch{finish(error('witness_unavailable'));}
  });
 }
}
