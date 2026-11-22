import http from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { pathToFileURL } from 'node:url';
import { loadConfig, initializeStore, verifyControl } from './config.mjs';
import { CustodySender } from './sender.mjs';
import { WitnessStoreGateway } from './gateway.mjs';
import { VERSION, PURPOSE, canonical, parseCanonical, sign, signWitness, verify, verifyWitness } from './protocol.mjs';
import { BINDING_FIELDS, bindingOf, validateRequest, validateBinding, exactFields, digestValue, integer, reference, token } from './schema.mjs';
import { AuthorityError, requireAuthority as need } from './errors.mjs';

const now = () => Math.floor(Date.now() / 1000);
const LIMIT = 65536;
const same = (a, b) => canonical(a) === canonical(b);
const code = error => error instanceof AuthorityError && /^[a-z][a-z0-9_]{0,100}$/.test(error.code) ? error.code : 'authority_unavailable';
const responseFields = ['version','request_bindings','authority_id','key_id','root_set_revision','recovery_epoch','checkpoint_epoch','generation','recovery_revision','issued_at','expires_at','disposition','evidence_reference','witness_id','witness_head','witness_sequence','result'];

function authenticate(config, envelope) {
  // Selecting a purpose never grants trust: both schema and the fixed role map
  // must verify before an authenticated request reaches durable state.
  const request = envelope?.message;
  validateRequest(request);
  const role = ['currentCheckpoint','admitOperation','readOperation'].includes(request.method) ? 'workload'
    : request.method === 'requestEmergency' ? 'emergency' : 'recovery';
  const keys = request.method === 'readDrain' ? new Map([...config.keys.emergency, ...config.keys.recovery]) : config.keys[role];
  verify(PURPOSE[request.method], envelope, keys);
  need(same(bindingOf(request), bindingOf(config.identity)), 'installation_binding');
  if (request.method === 'requestEmergency') {
    need(request.method_body.emergency_authority_reference === envelope.key_id && request.method_body.scope_of_revocation === 'installation', 'emergency_scope');
  }
  return request;
}

async function readBody(request) {
  need(request.method === 'POST', 'http_method');
  need(/^application\/json(?:\s*;|$)/i.test(request.headers['content-type'] ?? ''), 'http_content_type');
  need(!request.headers['content-encoding'] || request.headers['content-encoding'] === 'identity', 'http_content_encoding');
  const length = request.headers['content-length'];
  need(length === undefined || /^[0-9]+$/.test(length) && Number(length) <= LIMIT, 'message_bound');
  const chunks = []; let size = 0;
  for await (const chunk of request) { size += chunk.length; need(size <= LIMIT, 'message_bound'); chunks.push(chunk); }
  let raw; try { raw = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(Buffer.concat(chunks)); } catch { throw new AuthorityError('invalid_utf8'); }
  return parseCanonical(raw);
}

function post(url, envelope) {
  const destination = new URL(url);
  need(destination.protocol === 'http:' && destination.hostname === '127.0.0.1' && !destination.username && !destination.password && !destination.search && !destination.hash, 'witness_destination');
  const body = canonical(envelope); need(Buffer.byteLength(body) <= LIMIT, 'message_bound');
  return new Promise((resolve, reject) => {
    let complete = false; let response;
    const finish = (error, result) => { if (complete) return; complete = true; clearTimeout(timer); if (error) { response?.destroy(); req.destroy(); reject(error); } else resolve(result); };
    const req = http.request(destination, { method:'POST', agent:false, headers:{'content-type':'application/json','content-length':Buffer.byteLength(body),connection:'close'} }, res => {
      response = res;
      if (res.statusCode !== 200 || !/^application\/json(?:\s*;|$)/i.test(res.headers['content-type'] ?? '') || res.headers['content-encoding'] && res.headers['content-encoding'] !== 'identity') return finish(new AuthorityError('witness_transport'));
      const chunks=[]; let size=0;
      res.on('data', chunk => { size+=chunk.length; if(size>LIMIT) finish(new AuthorityError('message_bound')); else chunks.push(chunk); });
      res.on('end', () => { try { finish(null, parseCanonical(new TextDecoder('utf-8',{fatal:true,ignoreBOM:true}).decode(Buffer.concat(chunks)))); } catch { finish(new AuthorityError('witness_transport')); } });
      res.on('aborted',()=>finish(new AuthorityError('witness_transport')));res.on('error',()=>finish(new AuthorityError('witness_transport')));
    });
    const timer=setTimeout(()=>finish(new AuthorityError('witness_timeout')),5000);
    req.on('error',()=>finish(new AuthorityError('witness_transport')));req.end(body);
  });
}

function released(config) { const h=config.holds; return h.stop_all===false && h.external_lock===false && h.automation===true && h.release_hold===false; }
function rootGate(config, store) { const state=store.state();need(state.root_set_digest===config.identity.root_set_digest && state.root_set_revision===config.identity.root_set_revision,'root_configuration_stale');return state; }
function operationView(op) {
  if (!op) return null;
  const fields=['operation_id','allocation_id','allocation_revision','product_identity','product_version','order_identity','order_version','approval_reference','approval_revision','policy_revision','payload_fingerprint','generation','state'];
  return {...Object.fromEntries(fields.map(k=>[k,op[k]])),checkpoint_epoch:op.epoch,provider_reference:op.reconciliation?.provider_reference??op.evidence?.provider_reference??null};
}
function originalMatchesOperation(request,op) {
  need(request.request_id===op.request_id&&request.challenge===op.challenge&&same(bindingOf(request),bindingOf(op)),'operation_request_binding');
  for(const key of ['grant_reference','operation_id','payload_fingerprint','product_version','expected_product_version','approval_reference','approval_revision','policy_revision'])need(request.method_body[key]===op[key],'operation_request_binding');
  need(request.method_body.expected_epoch===op.epoch&&request.method_body.expected_generation===op.generation,'operation_request_binding');
}
function publicResponse(config, store, request, disposition, operation=null, drain=null, reason=null, readback=null) {
  const state=readback?.state??store.state(), time=now();
  const result={schema:'DF_NATIVE_AUTHORITY_RESULT_1',method:request.method,state:disposition,operation:readback?{...operationView(operation),admission_event:readback.admission_event}:operationView(operation),drain:drain?{state:drain.state,pending_operations:drain.pending_leases}:null,reason,external_execution_authorized:false};
  return signWitness('witness.response.v1',{version:VERSION,request_bindings:request,authority_id:request.authority_id,key_id:config.keyId,root_set_revision:state.root_set_revision,recovery_epoch:state.recovery_epoch,checkpoint_epoch:state.epoch,generation:state.generation,recovery_revision:state.recovery_revision,issued_at:time,expires_at:time+30,disposition,evidence_reference:'witness-event-'+state.witness_sequence,witness_id:state.witness_id,witness_head:state.witness_head,witness_sequence:state.witness_sequence,result},config.privateKey,config.keyId);
}
function verifiedResponse(config, envelope, request) {
  const message=verifyWitness('witness.response.v1',envelope,config.keys.witness);
  exactFields(message,responseFields,'witness_response_fields');
  need(message.version===VERSION && message.key_id===envelope.key_id && message.authority_id===config.identity.authority_id && message.witness_id===config.identity.witness_id && same(message.request_bindings,request),'witness_response_binding');
  need(integer(message.issued_at)&&integer(message.expires_at)&&message.issued_at<=now()&&now()<message.expires_at&&message.expires_at-message.issued_at<=300,'witness_response_expired');
  need(message.root_set_revision===(request.method==='rotateRoot'&&message.disposition==='ROOT_ROTATED_HELD'?request.method_body.new_root_set_revision:config.identity.root_set_revision),'witness_root_revision');
  need(digestValue(message.witness_head)&&digestValue(message.recovery_epoch)&&digestValue(message.checkpoint_epoch)&&integer(message.generation)&&integer(message.witness_sequence)&&integer(message.recovery_revision),'witness_state');
  exactFields(message.result,['schema','method','state','operation','drain','reason','external_execution_authorized']);
  need(message.result.schema==='DF_NATIVE_AUTHORITY_RESULT_1'&&message.result.method===request.method&&message.result.state===message.disposition&&message.result.external_execution_authorized===false,'witness_result');
  return message;
}

function controlRegistry(config) {
  const db=new DatabaseSync(config.database_path);
  db.exec('PRAGMA busy_timeout=5000; PRAGMA synchronous=FULL; CREATE TABLE IF NOT EXISTS service_controls(reference TEXT PRIMARY KEY,payload TEXT NOT NULL,consumed INTEGER NOT NULL CHECK(consumed IN(0,1)));');
  return {
    register(control,envelope) { db.prepare('INSERT INTO service_controls VALUES(?,?,0)').run(control.reference,canonical(envelope)); },
    consume(ref,type,expected) {
      // Approval consumption commits independently before a Store mutation.
      db.exec('BEGIN IMMEDIATE');
      try { const row=db.prepare('SELECT * FROM service_controls WHERE reference=?').get(ref);need(row&&row.consumed===0,'control_consumed');const c=verifyControl(config,parseCanonical(row.payload));need(c.reference===ref&&c.type===type&&same(c.data,expected),'control_approval_mismatch');need(db.prepare('UPDATE service_controls SET consumed=1 WHERE reference=? AND consumed=0').run(ref).changes===1,'control_consumed');db.exec('COMMIT');return c; }catch(e){try{db.exec('ROLLBACK');}catch{}throw e;}
    }, close(){db.close();},
  };
}

function controlAction(config,store,registry,envelope) {
  rootGate(config,store);const c=verifyControl(config,envelope),d=c.data;
  switch(c.type) {
    case 'REGISTER_GRANT': return store.registerGrant(d);
    case 'REGISTER_APPROVAL': return store.registerApproval(d);
    case 'ADVANCE_PRODUCT': exactFields(d,['product_identity','expected_version','core_reference']);return store.advanceProduct(d.product_identity,d.expected_version,d.core_reference);
    case 'ADVANCE_ORDER': exactFields(d,['order_identity','expected_version','core_reference','lines']);return store.advanceOrder(d.order_identity,d.expected_version,d.core_reference,d.lines);
    case 'ABANDON_UNSENT': {
      exactFields(d,['operation_id','allocation_id','allocation_revision','generation','expected_witness_head']);
      need(digestValue(d.operation_id)&&reference(d.allocation_id)&&integer(d.allocation_revision)&&integer(d.generation)&&digestValue(d.expected_witness_head),'abandonment_binding');
      registry.register(c,envelope);registry.consume(c.reference,'ABANDON_UNSENT',d);
      const operation=store.abandonUnsent(d.operation_id,d.allocation_id,d.allocation_revision,d.generation,d.expected_witness_head,now());
      return {state:operation.state,operation_id:operation.operation_id,external_execution_authorized:false};
    }
    case 'RECOVERY_APPROVAL': exactFields(d,['previous_epoch','expected_witness_head','outstanding_lease_inventory_hash']);need(digestValue(d.previous_epoch)&&digestValue(d.expected_witness_head)&&digestValue(d.outstanding_lease_inventory_hash),'control_approval');break;
    case 'ROTATION_APPROVAL': exactFields(d,['previous_root_set_revision','new_root_set_revision','new_root_set_digest','expected_witness_head']);need(integer(d.previous_root_set_revision)&&d.new_root_set_revision===d.previous_root_set_revision+1&&digestValue(d.new_root_set_digest)&&digestValue(d.expected_witness_head),'control_approval');break;
    default: throw new AuthorityError('control_role');
  }
  registry.register(c,envelope);return {registered:true,reference:c.reference};
}

function witnessCall(config,store,registry,request) {
  rootGate(config,store);
  try {
    const body=request.method_body,time=now();let op=null,drain=null,state;
    if(request.method!=='admitOperation')store.claimRequest(request.request_id,request.challenge,request.method,time);
    switch(request.method) {
      case 'currentCheckpoint': { const s=store.state();store.currentGrant(body.grant_reference,time);need(body.expected_epoch===s.epoch&&body.expected_generation===s.generation,'checkpoint_stale');state=s.paused?'HELD':'CURRENT';break; }
      case 'admitOperation': {
        if(!released(config)){store.claimRejectedAdmission(request.request_id,request.challenge,time);state='HELD';break;}
        try {op=store.admit(request,time);state='ADMITTED';}
        catch(error){
          // Store.admit rolls its transaction back on refusal. Consume only
          // that refused attempt in the same global ledger; a racing success
          // already owns the tokens and is never overwritten or reallocated.
          try{store.claimRejectedAdmission(request.request_id,request.challenge,time);}catch(claimError){if(code(claimError)==='request_replay')throw claimError;throw new AuthorityError('admission_claim_unavailable');}
          throw error;
        }
        break;
      }
      case 'readOperation': {
        const readback=store.readCommittedOperation(request);
        return publicResponse(config,store,request,readback.operation.state,readback.operation,null,null,readback);
      }
      case 'requestEmergency': {const s=store.emergency(body.emergency_event_id,body.expected_generation,time);drain=store.drain(body.emergency_event_id,s.generation);state=drain.state;break;}
      case 'readDrain':drain=store.drain(body.emergency_event_id,body.generation);state=drain.state;break;
      case 'recoverInstallation': { const s=store.state();need(body.previous_epoch===s.epoch,'recovery_epoch_cas');registry.consume(body.independent_recovery_approval,'RECOVERY_APPROVAL',{previous_epoch:body.previous_epoch,expected_witness_head:body.expected_witness_head,outstanding_lease_inventory_hash:body.outstanding_lease_inventory_hash});store.recover(body.expected_witness_head,body.outstanding_lease_inventory_hash,time);state='RECOVERED_HELD';break; }
      case 'rotateRoot':registry.consume(body.independent_rotation_approval,'ROTATION_APPROVAL',{previous_root_set_revision:body.previous_root_set_revision,new_root_set_revision:body.new_root_set_revision,new_root_set_digest:body.new_root_set_digest,expected_witness_head:body.expected_witness_head});store.rotate(body.previous_root_set_revision,body.new_root_set_revision,body.new_root_set_digest,body.expected_witness_head,time);state='ROOT_ROTATED_HELD';break;
      default:throw new AuthorityError('method_denied');
    }
    return publicResponse(config,store,request,state,op,drain);
  }catch(error){if(['admission_claim_unavailable','storage_unavailable'].includes(code(error)))throw error;return publicResponse(config,store,request,'DENIED',null,null,code(error));}
}

function dispatchCall(config,store,registry,envelope) {
  const m=verify('sender.store.request.v1',envelope,config.keys.issuer);
  exactFields(m,['version','binding','action','operation_id','request_id','challenge','arguments','original_envelope','control_envelope']);
  validateBinding(m.binding);need(m.version===VERSION&&same(m.binding,bindingOf(config.identity))&&token(m.request_id)&&token(m.challenge)&&m.request_id!==m.challenge&&Array.isArray(m.arguments),'dispatch_binding');
  need(['state','operation','markSending','finish','reconcile','publicResult'].includes(m.action),'dispatch_action');
  rootGate(config,store);
  // Original admission is authenticated again without consuming its native nonce.
  let original=null,op=null;
  if(m.original_envelope!==null){original=authenticate(config,m.original_envelope);need(original.method==='admitOperation','dispatch_original');}
  const reconciliation=m.control_envelope!==null;let control=null;
  if(reconciliation){need(['state','operation','reconcile'].includes(m.action)&&m.original_envelope===null,'reconciliation_action');control=verifyControl(config,m.control_envelope);need(control.type==='RECONCILE_OPERATION','reconciliation_control');exactFields(control.data,['operation_id']);need(digestValue(control.data.operation_id)&&(m.action==='state'||control.data.operation_id===m.operation_id),'reconciliation_control');need(same(bindingOf(store.operation(control.data.operation_id)),m.binding),'reconciliation_binding');}
  else need(m.control_envelope===null,'unexpected_control');
  need(m.action!=='reconcile'||reconciliation,'reconciliation_control');
  if(m.action==='state'){
    need(m.operation_id===null&&m.arguments.length===0&&(original!==null||reconciliation),'dispatch_arguments');
    if(original)originalMatchesOperation(original,store.operation(original.method_body.operation_id));
  }
  else {need(digestValue(m.operation_id),'operation_identity');op=store.operation(m.operation_id);need(same(bindingOf(op),m.binding),'operation_binding');if(!reconciliation){need(original!==null,'operation_request_binding');originalMatchesOperation(original,op);}}
  store.claimDispatchRequest(m.request_id,m.challenge,m.action,now());
  let result=null,error=null,ok=true;
  try {
    switch(m.action){
      case 'state':result=store.state();break;
      case 'operation':need(m.arguments.length===0,'dispatch_arguments');result=op;break;
      case 'markSending':need(m.arguments.length===2&&integer(m.arguments[0])&&integer(m.arguments[1],0),'dispatch_arguments');need(released(config),'holds_active');result=store.markSending(m.operation_id,m.arguments[0],now());break;
      case 'finish':need(m.arguments.length===3&&integer(m.arguments[2],0),'dispatch_arguments');result=store.finish(m.operation_id,m.arguments[0],m.arguments[1],now());break;
      case 'reconcile':need(m.arguments.length===2&&integer(m.arguments[1],0),'dispatch_arguments');registry.register(control,m.control_envelope);registry.consume(control.reference,'RECONCILE_OPERATION',control.data);result=store.reconcile(m.operation_id,m.arguments[0],now());break;
      case 'publicResult':need(m.arguments.length===0,'dispatch_arguments');need(['SUCCEEDED','FAILED','UNKNOWN'].includes(op.state),'operation_not_terminal');result=publicResponse(config,store,original,op.state,op);break;
    }
  }catch(e){ok=false;error=code(e);}
  const time=now();return signWitness('witness.store.response.v1',{schema:'DF_NATIVE_STORE_RESPONSE_1',version:VERSION,binding:m.binding,action:m.action,operation_id:m.operation_id,request_id:m.request_id,challenge:m.challenge,issued_at:time,expires_at:time+30,ok,result:ok?result:null,error},config.privateKey,config.keyId);
}

export async function startAuthorityServer(configPath,role) {
  const config=loadConfig(configPath,role);let store=null,registry=null;
  if(role==='witness'){store=initializeStore(config);try{registry=controlRegistry(config);}catch(e){store.close();throw e;}}
  const gateway=(originalEnvelope=null,controlEnvelope=null)=>new WitnessStoreGateway({url:config.witness_url,binding:bindingOf(config.identity),keyId:config.keyId,privateKey:config.privateKey,witnessKeys:config.keys.witness,originalEnvelope,controlEnvelope});
  const sender=remote=>new CustodySender({store:remote,credentials:config.credentials,payloads:config.payloads,providerBaseUrl:config.installation.provider_base_url,holds:config.holds,mode:config.mode});
  const sockets=new Set();
  const server=http.createServer(async(req,res)=>{
    const reply=(status,value)=>{if(res.destroyed)return;const body=canonical(value);res.writeHead(status,{'content-type':'application/json','content-length':Buffer.byteLength(body),'cache-control':'no-store',connection:'close'});res.end(body);};
    try {
      if(!['/rpc','/witness','/dispatch','/control'].includes(req.url)){reply(404,{error:'not_found'});return;}
      const envelope=await readBody(req);
      if(role==='issuer'&&req.url==='/rpc'){
        const request=authenticate(config,envelope);
        const forwarded=sign('issuer.forward.v1',{schema:'DF_NATIVE_FORWARD_1',version:VERSION,binding:bindingOf(config.identity),request_envelope:envelope},config.privateKey,config.keyId);
        let attestation=await post(new URL('/witness',config.witness_url),forwarded);
        let message=verifiedResponse(config,attestation,request);
        if(request.method==='admitOperation'&&message.disposition==='ADMITTED'){
          const remote=gateway(envelope);await sender(remote).send(request.method_body.operation_id);
          attestation=await remote.publicResult(request.method_body.operation_id);message=verifiedResponse(config,attestation,request);
        }
        reply(200,sign('issuer.response.v1',{...message,key_id:config.keyId,witness_attestation:attestation},config.privateKey,config.keyId));return;
      }
      if(role==='witness'&&req.url==='/witness'){
        const forward=verify('issuer.forward.v1',envelope,config.keys.issuer);exactFields(forward,['schema','version','binding','request_envelope']);need(forward.schema==='DF_NATIVE_FORWARD_1'&&forward.version===VERSION&&same(forward.binding,bindingOf(config.identity)),'forward_binding');
        const request=authenticate(config,forward.request_envelope);reply(200,witnessCall(config,store,registry,request));return;
      }
      if(role==='witness'&&req.url==='/dispatch'){reply(200,dispatchCall(config,store,registry,envelope));return;}
      if(req.url==='/control'){
        if(role==='witness'){reply(200,controlAction(config,store,registry,envelope));return;}
        const control=verifyControl(config,envelope);need(control.type==='RECONCILE_OPERATION','control_role');exactFields(control.data,['operation_id']);need(digestValue(control.data.operation_id),'operation_identity');
        const remote=gateway(null,envelope),evidence=await sender(remote).reconcile(control.data.operation_id);
        const result=await remote.reconcile(control.data.operation_id,evidence,now());reply(200,{state:result.state,operation_id:result.operation_id,external_execution_authorized:false});return;
      }
      reply(404,{error:'not_found'});
    }catch(error){reply(error instanceof AuthorityError&&['signature_invalid','untrusted_key','signed_envelope','signature_encoding','installation_binding','emergency_scope'].includes(error.code)?401:503,{error:'authority_unavailable'});}
  });
  server.requestTimeout=10000;server.headersTimeout=5000;server.keepAliveTimeout=1;
  server.on('connection',socket=>{sockets.add(socket);socket.on('close',()=>sockets.delete(socket));});
  try{await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(config.listen.port,config.listen.host,resolve);});}catch(e){registry?.close();store?.close();throw e;}
  const address='http://127.0.0.1:'+server.address().port;
  let closed=false;
  return {address,server,close:async()=>{if(closed)return;closed=true;await new Promise(resolve=>{server.close(resolve);for(const socket of sockets)socket.destroy();});registry?.close();store?.close();}};
}

if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
  try{need(process.argv.length===4&&['issuer','witness'].includes(process.argv[2]),'usage');const app=await startAuthorityServer(process.argv[3],process.argv[2]);process.stdout.write(canonical({address:app.address,role:process.argv[2]})+'\n');for(const signal of ['SIGTERM','SIGINT'])process.once(signal,async()=>{await app.close();process.exit(0);});}catch{process.stderr.write('authority_start_failed\n');process.exitCode=1;}
}
