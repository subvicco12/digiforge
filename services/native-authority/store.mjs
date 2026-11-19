import { DatabaseSync } from 'node:sqlite';
import { createHash, createPublicKey, randomBytes, randomUUID } from 'node:crypto';
import { closeSync, constants, existsSync, lstatSync, openSync, chmodSync } from 'node:fs';
import { canonical, digest } from './protocol.mjs';
import { AuthorityError, requireAuthority as need } from './errors.mjs';
import { BINDING_FIELDS, validateBinding } from './schema.mjs';

import { authenticateProvenanceV1 } from './provenance-contract.mjs';

const FIELDS=BINDING_FIELDS;
const UNRESOLVED=new Set(['ADMITTED','SENDING','UNKNOWN']);
const TERMINAL=new Set(['SUCCEEDED','FAILED','UNKNOWN']);
const MAX=Number.MAX_SAFE_INTEGER;
const fail=code=>{throw new AuthorityError(code);};
const object=v=>v!==null&&typeof v==='object'&&Object.getPrototypeOf(v)===Object.prototype;
function keys(v,required,optional=[]){need(object(v),'record_required');need(required.every(k=>Object.hasOwn(v,k))&&Object.keys(v).every(k=>required.includes(k)||optional.includes(k)),'record_fields');}
function integer(v,min=1){need(Number.isSafeInteger(v)&&v>=min,'integer_required');return v;}
function next(v){integer(v);need(v<MAX,'counter_overflow');return v+1;}
function ref(v){need(typeof v==='string'&&/^[A-Za-z0-9_.:-]{1,160}$/.test(v),'reference_required');return v;}
function hash(v){need(typeof v==='string'&&/^[a-f0-9]{64}$/.test(v),'digest_required');return v;}
function nonce(v){need(typeof v==='string'&&/^[A-Za-z0-9_-]{43}$/.test(v),'request_nonce');const b=Buffer.from(v,'base64url');need(b.length===32&&b.toString('base64url')===v,'request_nonce');}
function binding(v){validateBinding(v);return JSON.parse(canonical(v));}
function lines(v){need(Array.isArray(v)&&v.length>0&&v.length<=100,'lines_required');const seen=new Set();const out=v.map(l=>{keys(l,['line_identity','quantity']);ref(l.line_identity);integer(l.quantity);need(!seen.has(l.line_identity),'line_duplicate');seen.add(l.line_identity);return {...l};});out.sort((a,b)=>a.line_identity<b.line_identity?-1:a.line_identity>b.line_identity?1:0);return out;}
function evidence(v,reconciliation=false){
 const allowed=['provider_reference','observation_sha256','failure_category'];if(reconciliation)allowed.push(...FIELDS,'operation_id','allocation_id','allocation_revision','payload_fingerprint','generation','outcome','observed_at');
 keys(v,[],allowed);need(Buffer.byteLength(canonical(v))<=8192,'evidence_bound');
 if(Object.hasOwn(v,'provider_reference'))ref(v.provider_reference);if(Object.hasOwn(v,'observation_sha256'))hash(v.observation_sha256);if(Object.hasOwn(v,'failure_category'))ref(v.failure_category);if(Object.hasOwn(v,'observed_at'))integer(v.observed_at);return JSON.parse(canonical(v));
}

/** Local witness-owned durable state. Public authentication belongs to the calling server.
 * No network, credential access, rearm, or hosted rollback-resistance claim is made here.
 */
export class AuthorityStore {
 #db;
 constructor(dbPath){
  try{
   need(typeof dbPath==='string'&&dbPath!==''&&dbPath!==':memory:','storage_path');
   if(!existsSync(dbPath)){const fd=openSync(dbPath,constants.O_CREAT|constants.O_EXCL|constants.O_RDWR,0o600);closeSync(fd);}
   need(lstatSync(dbPath).isFile()&&!lstatSync(dbPath).isSymbolicLink(),'storage_path');chmodSync(dbPath,0o600);
   this.#db=new DatabaseSync(dbPath);this.#db.exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA foreign_keys=ON;');
   this.#db.exec(`CREATE TABLE IF NOT EXISTS authority_state(id INTEGER PRIMARY KEY CHECK(id=1),payload TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS grants(reference TEXT PRIMARY KEY,payload TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS products(identity TEXT PRIMARY KEY,payload TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS orders(identity TEXT PRIMARY KEY,payload TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS approvals(reference TEXT PRIMARY KEY,operation_id TEXT NOT NULL UNIQUE,payload TEXT NOT NULL,allocation_id TEXT NOT NULL UNIQUE,allocation_revision INTEGER NOT NULL,consumed INTEGER NOT NULL CHECK(consumed IN(0,1)));
    CREATE TABLE IF NOT EXISTS operations(operation_id TEXT PRIMARY KEY,request_id TEXT NOT NULL UNIQUE,challenge TEXT NOT NULL UNIQUE,allocation_id TEXT NOT NULL UNIQUE,payload TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS emergencies(event_id TEXT PRIMARY KEY,generation INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS request_claims(request_id TEXT PRIMARY KEY,challenge TEXT NOT NULL UNIQUE,method TEXT NOT NULL,claimed_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS request_tokens(token TEXT PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS witness_events(sequence INTEGER PRIMARY KEY,head TEXT NOT NULL UNIQUE,event TEXT NOT NULL);`);
   need(this.#db.prepare('PRAGMA journal_mode').get().journal_mode==='wal'&&this.#db.prepare('PRAGMA synchronous').get().synchronous===2,'storage_durability');
  }catch(e){if(e instanceof AuthorityError)throw e;fail('storage_unavailable');}
 }
 #tx(fn){try{this.#db.exec('BEGIN IMMEDIATE');try{const value=fn();this.#db.exec('COMMIT');return value;}catch(e){try{this.#db.exec('ROLLBACK');}catch{}throw e;}}catch(e){if(e instanceof AuthorityError)throw e;fail('storage_unavailable');}}
 #get(table,id,column='identity'){const row=this.#db.prepare(`SELECT payload FROM ${table} WHERE ${column}=?`).get(id);return row?JSON.parse(row.payload):null;}
 #put(table,id,value,column='identity'){if(table==='operations'){need(this.#db.prepare('UPDATE operations SET payload=? WHERE operation_id=?').run(canonical(value),id).changes===1,'operation_missing');return;}this.#db.prepare(`INSERT INTO ${table}(${column},payload) VALUES(?,?) ON CONFLICT(${column}) DO UPDATE SET payload=excluded.payload`).run(id,canonical(value));}
 #seal(s){
  const {witness_sequence,witness_head,...material}=s;const inventory={};
  // These rows define admission, revocation and consumed allocations. Request
  // claims deliberately remain head-stable; they are not an authority frontier.
  for(const [table,key]of [['grants','reference'],['products','identity'],['orders','identity'],['approvals','reference'],['operations','operation_id'],['emergencies','event_id']]){
   inventory[table]=this.#db.prepare(`SELECT * FROM ${table} ORDER BY ${key}`).all().map(row=>({...row}));
  }
  return digest({state:material,authoritative_rows:inventory});
 }
 #state(){
  const row=this.#db.prepare('SELECT payload FROM authority_state WHERE id=1').get();need(row,'not_provisioned');const s=JSON.parse(row.payload);const events=this.#db.prepare('SELECT sequence,head,event FROM witness_events ORDER BY sequence').all();
  let sequence=0,head=null,last=null;
  for(const stored of events){const event=JSON.parse(stored.event);need(stored.sequence===sequence+1&&canonical(event)===stored.event&&event.previous_head===head&&digest({sequence:stored.sequence,event})===stored.head,'witness_state_inconsistent');sequence=stored.sequence;head=stored.head;last=event;}
  need(last&&sequence===s.witness_sequence&&head===s.witness_head&&last.state_digest===this.#seal(s),'witness_state_inconsistent');return s;
 }
 #event(s,type,data,now=Math.floor(Date.now()/1000)){
  integer(now);const sequence=s.witness_sequence===0?1:next(s.witness_sequence);const event={type,at:now,data,previous_head:s.witness_head,state_digest:this.#seal(s)};const head=digest({sequence,event});
  this.#db.prepare('INSERT INTO witness_events(sequence,head,event) VALUES(?,?,?)').run(sequence,head,canonical(event));s.witness_sequence=sequence;s.witness_head=head;
  this.#db.prepare('INSERT INTO authority_state(id,payload) VALUES(1,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload').run(canonical(s));
 }
 #sameBinding(b,s){const actual=binding(b);need(canonical(actual)===canonical(s.identity),'binding_mismatch');return actual;}
 #invalidate(s){s.generation=next(s.generation);s.paused=true;}
 bootstrap(identity,now){
  keys(identity,[...FIELDS,'witness_id','root_set_digest'],['root_set_revision']);integer(now);ref(identity.witness_id);hash(identity.root_set_digest);const base=binding(Object.fromEntries(FIELDS.map(k=>[k,identity[k]])));
  return this.#tx(()=>{need(!this.#db.prepare('SELECT id FROM authority_state').get(),'already_provisioned');
   const s={identity:base,witness_id:identity.witness_id,epoch:randomBytes(32).toString('hex'),recovery_epoch:randomBytes(32).toString('hex'),recovery_revision:1,generation:1,paused:false,root_set_revision:integer(identity.root_set_revision??1),root_set_digest:identity.root_set_digest,witness_sequence:0,witness_head:null};
   this.#event(s,'BOOTSTRAP',{identity:base,witness_id:s.witness_id,root_set_digest:s.root_set_digest},now);return this.#view(s);
  });
 }
 #unfinished(){return this.#db.prepare('SELECT payload FROM operations ORDER BY operation_id').all().map(r=>JSON.parse(r.payload)).filter(o=>UNRESOLVED.has(o.state));}
 #view(s){const pending=this.#unfinished();return {...s,sequence:s.witness_sequence,head:s.witness_head,inventory_hash:digest(pending),unresolved_operations:pending.length};}
 state(){try{return this.#view(this.#state());}catch(e){if(e instanceof AuthorityError)throw e;fail('storage_unavailable');}}
 inventoryHash(){return this.state().inventory_hash;}
 registerProduct(record){
  keys(record,['binding','core_reference'],['product_identity','product_version']);ref(record.core_reference);need(record.product_version===undefined||record.product_version===1,'native_version_required');
  return this.#tx(()=>{const s=this.#state();const b=this.#sameBinding(record.binding,s);const id=ref(record.product_identity??randomUUID());need(!this.#get('products',id),'product_exists');const product={product_identity:id,product_version:1,binding:b,core_reference:record.core_reference};this.#put('products',id,product);this.#event(s,'PRODUCT_REGISTERED',{product_identity:id,product_version:1,core_reference:record.core_reference});return product;});
 }
 advanceProduct(identity,expectedVersion,coreReference){
  ref(identity);integer(expectedVersion);ref(coreReference);return this.#tx(()=>{const s=this.#state();const p=this.#get('products',identity);need(p&&p.product_version===expectedVersion,'product_version_cas');p.product_version=next(p.product_version);p.core_reference=coreReference;this.#invalidate(s);this.#put('products',identity,p);this.#event(s,'PRODUCT_ADVANCED',{product_identity:identity,product_version:p.product_version,core_reference:coreReference});return p;});
 }
 registerOrder(record){
  keys(record,['product_identity','lines','binding','core_reference'],['order_identity','order_version']);ref(record.core_reference);ref(record.product_identity);need(record.order_version===undefined||record.order_version===1,'native_version_required');const ls=lines(record.lines);
  return this.#tx(()=>{const s=this.#state();const b=this.#sameBinding(record.binding,s);const p=this.#get('products',record.product_identity);need(p,'product_missing');const id=ref(record.order_identity??randomUUID());need(!this.#get('orders',id),'order_exists');const order={order_identity:id,order_version:1,product_identity:p.product_identity,product_version:p.product_version,lines:ls,binding:b,core_reference:record.core_reference};this.#put('orders',id,order);this.#event(s,'ORDER_REGISTERED',{order_identity:id,order_version:1,product_identity:p.product_identity,core_reference:record.core_reference});return order;});
 }
 advanceOrder(identity,expectedVersion,coreReference,newLines=null){
  ref(identity);integer(expectedVersion);ref(coreReference);const ls=newLines===null?null:lines(newLines);
  return this.#tx(()=>{const s=this.#state();const o=this.#get('orders',identity);need(o&&o.order_version===expectedVersion,'order_version_cas');const p=this.#get('products',o.product_identity);need(p,'product_missing');o.order_version=next(o.order_version);o.product_version=p.product_version;o.core_reference=coreReference;if(ls!==null)o.lines=ls;this.#invalidate(s);this.#put('orders',identity,o);this.#event(s,'ORDER_ADVANCED',{order_identity:identity,order_version:o.order_version,core_reference:coreReference});return o;});
 }
 registerGrant(grant){
  keys(grant,['grant_reference','binding','issued_at','expires_at','revoked']);ref(grant.grant_reference);integer(grant.issued_at);integer(grant.expires_at);need(typeof grant.revoked==='boolean'&&grant.expires_at>grant.issued_at&&grant.expires_at-grant.issued_at<=300,'grant_lifetime');
  return this.#tx(()=>{const s=this.#state();this.#sameBinding(grant.binding,s);const old=this.#get('grants',grant.grant_reference,'reference');
   if(old){if(canonical(old)===canonical(grant))return old;need(old.revoked===false&&grant.revoked===true&&canonical({...old,revoked:true})===canonical(grant),'grant_replacement_denied');this.#invalidate(s);}
   const result=JSON.parse(canonical(grant));this.#put('grants',grant.grant_reference,result,'reference');this.#event(s,'GRANT_REGISTERED',{grant_reference:grant.grant_reference,revoked:grant.revoked});return result;
  });
 }
 currentGrant(reference,now){ref(reference);integer(now);return this.#tx(()=>{const s=this.#state();const g=this.#get('grants',reference,'reference');need(g&&!g.revoked&&g.issued_at<=now&&now<g.expires_at,'grant_not_current');this.#sameBinding(g.binding,s);return g;});}
 #claimTokens(requestId,challenge,method,now){
  need(!this.#db.prepare('SELECT token FROM request_tokens WHERE token IN (?,?)').get(requestId,challenge),'request_replay');
  need(!this.#db.prepare('SELECT operation_id FROM operations WHERE request_id IN (?,?) OR challenge IN (?,?)').get(requestId,challenge,requestId,challenge),'request_replay');
  this.#db.prepare('INSERT INTO request_tokens(token) VALUES(?)').run(requestId);this.#db.prepare('INSERT INTO request_tokens(token) VALUES(?)').run(challenge);
  this.#db.prepare('INSERT INTO request_claims(request_id,challenge,method,claimed_at) VALUES(?,?,?,?)').run(requestId,challenge,method,now);
 }
 claimRequest(requestId,challenge,method,now){nonce(requestId);nonce(challenge);need(requestId!==challenge,'request_nonce');integer(now);need(['currentCheckpoint','requestEmergency','readDrain','recoverInstallation','rotateRoot'].includes(method),'request_method');return this.#tx(()=>{this.#state();this.#claimTokens(requestId,challenge,method,now);return {request_id:requestId,challenge,method,claimed_at:now};});}
 claimDispatchRequest(requestId,challenge,action,now){nonce(requestId);nonce(challenge);need(requestId!==challenge,'request_nonce');integer(now);need(['state','operation','markSending','finish','reconcile','publicResult'].includes(action),'request_method');return this.#tx(()=>{this.#state();const method='dispatch.'+action;this.#claimTokens(requestId,challenge,method,now);return {request_id:requestId,challenge,method,claimed_at:now};});}
 claimRejectedAdmission(requestId,challenge,now){nonce(requestId);nonce(challenge);need(requestId!==challenge,'request_nonce');integer(now);return this.#tx(()=>{this.#state();this.#claimTokens(requestId,challenge,'admitOperation',now);return {request_id:requestId,challenge,method:'admitOperation',claimed_at:now};});}
 #source(approval,s){
  this.#sameBinding(approval.binding,s);const p=this.#get('products',approval.product_identity);const o=this.#get('orders',approval.order_identity);
  need(p&&o&&o.product_identity===p.product_identity,'native_identity_mismatch');
  need(p.product_version===approval.product_version&&p.product_version===approval.expected_product_version&&o.product_version===p.product_version&&o.order_version===approval.order_version,'native_version_stale');
  need(canonical(o.lines)===canonical(lines(approval.lines)),'native_lines_mismatch');
 }
 registerApproval(approval){
  const fields=['approval_reference','approval_revision','policy_revision','operation_id','order_identity','order_version','listing_identity','product_identity','product_version','expected_product_version','payload_fingerprint','lines','binding'];keys(approval,fields,['revoked']);
  for(const k of ['approval_reference','order_identity','listing_identity','product_identity'])ref(approval[k]);for(const k of ['approval_revision','policy_revision','order_version','product_version','expected_product_version'])integer(approval[k]);hash(approval.operation_id);hash(approval.payload_fingerprint);lines(approval.lines);need(approval.revoked===undefined||typeof approval.revoked==='boolean','approval_revocation');
  return this.#tx(()=>{const s=this.#state();this.#source(approval,s);const a={...approval,lines:lines(approval.lines),revoked:approval.revoked??false};const old=this.#db.prepare('SELECT * FROM approvals WHERE reference=?').get(a.approval_reference);
   if(old){const previous=JSON.parse(old.payload);if(canonical(previous)===canonical(a))return {...previous,allocation_id:old.allocation_id,allocation_revision:old.allocation_revision};
    need(a.operation_id===previous.operation_id&&a.approval_revision===next(previous.approval_revision)&&a.policy_revision>=previous.policy_revision,'approval_revision_cas');need(!previous.revoked||a.revoked,'approval_revival_denied');
    this.#invalidate(s);const revision=next(old.allocation_revision);this.#db.prepare('UPDATE approvals SET payload=?,allocation_revision=? WHERE reference=? AND allocation_revision=?').run(canonical(a),revision,a.approval_reference,old.allocation_revision);this.#event(s,'APPROVAL_REVISED',{approval_reference:a.approval_reference,approval_revision:a.approval_revision,allocation_id:old.allocation_id,allocation_revision:revision});return {...a,allocation_id:old.allocation_id,allocation_revision:revision};
   }
   need(!this.#db.prepare('SELECT reference FROM approvals WHERE operation_id=?').get(a.operation_id),'operation_approval_exists');const allocation_id=randomUUID();this.#db.prepare('INSERT INTO approvals(reference,operation_id,payload,allocation_id,allocation_revision,consumed) VALUES(?,?,?,?,1,0)').run(a.approval_reference,a.operation_id,canonical(a),allocation_id);this.#event(s,'ALLOCATION_CREATED',{approval_reference:a.approval_reference,allocation_id,allocation_revision:1});return {...a,allocation_id,allocation_revision:1};
  });
 }
 #currentApproval(op,s,now){
  const grant=this.#get('grants',op.grant_reference,'reference');need(grant&&!grant.revoked&&grant.issued_at<=now&&now<grant.expires_at,'grant_not_current');this.#sameBinding(grant.binding,s);
  const row=this.#db.prepare('SELECT * FROM approvals WHERE reference=?').get(op.approval_reference);need(row,'approval_missing');const a=JSON.parse(row.payload);need(!a.revoked&&a.approval_revision===op.approval_revision&&a.policy_revision===op.policy_revision&&a.operation_id===op.operation_id&&a.payload_fingerprint===op.payload_fingerprint&&row.allocation_id===op.allocation_id,'approval_not_current');this.#source(a,s);return row;
 }
 /** Only the witness server may supply authenticated offline control records. */
 applyHeldProvenanceControl(control,now){
  const d=control.data;
  return this.#tx(()=>{
   const s=this.#state();this.#sameBinding(control.binding,s);
   need(s.identity.environment==='test'&&control.issued_at<=now&&now<control.expires_at,'provenance_offline_only');
   need(d.generation===s.generation,'checkpoint_stale');
   const registry=s.held_provenance??={producers:[],mappings:[],controls:[]};
   need(registry.controls.length<1024&&!registry.controls.includes(control.reference),'provenance_control_replay');
   ref(d.producer);
   const producer=registry.producers.find(x=>x.producer===d.producer);
   if(control.type==='ENROLL'){
    keys(d,['producer','source_sha','key_der','key_pin','business_id','etsy_shop_id','generation']);
    need(!producer&&/^[a-f0-9]{40}$/.test(d.source_sha),'provenance_enrollment');
    integer(d.business_id);ref(d.etsy_shop_id);hash(d.key_pin);
    need(typeof d.key_der==='string'&&/^[A-Za-z0-9_-]{59}$/.test(d.key_der),'provenance_pin');
    const der=Buffer.from(d.key_der,'base64url');
    const key=createPublicKey({key:der,format:'der',type:'spki'});
    need(der.toString('base64url')===d.key_der&&key.asymmetricKeyType==='ed25519'
      &&key.export({format:'der',type:'spki'}).equals(der)
      &&createHash('sha256').update(der).digest('hex')===d.key_pin,'provenance_pin');
    registry.producers.push({...d,revoked:false});
   }else{
    need(producer&&!producer.revoked,'provenance_revoked');
    if(control.type==='REVOKE'){
     keys(d,['producer','generation']);producer.revoked=true;
    }else{
     hash(d.mapping_id);integer(d.expected_revision,0);
     const m=registry.mappings.find(x=>x.mapping_id===d.mapping_id);
     need((m?.revision??0)===d.expected_revision&&(!m||m.producer===d.producer),'provenance_mapping_cas');
     if(control.type==='BLOCK'){
      keys(d,['producer','mapping_id','expected_revision','generation']);
      need(m?.active===true,'provenance_mapping_state');m.active=false;m.revision=next(m.revision);
      m.block={control_reference:control.reference,control_digest:digest(control),
       blocked_revision:m.revision,block_generation:s.generation,block_epoch:s.epoch,
       journal_sequence:next(s.witness_sequence)};
     }else{
      need(control.type==='MAP'&&(!m||m.active===false),'provenance_mapping_state');
      keys(d,['producer','mapping_id','expected_revision','generation','snapshot_hash','snapshot_revision','order_id','line_id','listing_id','product_id','native_order_id','native_line_id','native_listing_id','native_product_id','order_version','product_version','artwork_source_hash','expires_at']);
      for(const k of ['order_id','line_id','listing_id','product_id','native_order_id','native_line_id','native_listing_id','native_product_id'])ref(d[k]);
      for(const k of ['snapshot_revision','order_version','product_version','expires_at'])integer(d[k]);
      for(const k of ['snapshot_hash','artwork_source_hash'])hash(d[k]);
      need(d.mapping_id===digest([d.producer,d.order_id,d.line_id])&&now<d.expires_at
        &&(!m||d.snapshot_revision>m.snapshot_revision),'provenance_mapping_identity');
      const record={...d,revision:d.expected_revision===0?1:next(d.expected_revision),active:true};
      if(m)Object.assign(m,record);else registry.mappings.push(record);
     }
    }
   }
   registry.controls.push(control.reference);
   this.#event(s,'HELD_PROVENANCE_CONTROL',{reference:control.reference,type:control.type,digest:digest(d)},now);
   return {reference:control.reference,witness_head:s.witness_head};
  });
 }
 readBlockedProvenanceV1(request,now){
  return this.#tx(()=>{
   const s=this.#state(),b=request.method_body;
   this.#sameBinding(Object.fromEntries(FIELDS.map(k=>[k,request[k]])),s);
   nonce(request.request_id);nonce(request.challenge);integer(now);
   need(request.request_id!==request.challenge&&s.identity.environment==='test'
     &&b.expected_generation===s.generation&&b.expected_epoch===s.epoch,'checkpoint_stale');
   const m=s.held_provenance?.mappings.find(x=>x.mapping_id===b.mapping_id);
   const proof=m?.block;
   const producer=s.held_provenance?.producers.find(x=>x.producer===m?.producer);
   need(producer&&!producer.revoked&&now<m.expires_at,'provenance_not_current');
   need(m?.active===false&&proof&&m.revision===b.blocked_revision
     &&proof.blocked_revision===m.revision&&proof.control_reference===b.control_reference
     &&proof.control_digest===b.control_digest,'provenance_block_readback');
   const journal_event_hash=this.heldJournalHash(proof.journal_sequence);
   hash(journal_event_hash);
   this.#claimTokens(request.request_id,request.challenge,'readBlockedProvenanceV1',now);
   return {schema:'DF_NATIVE_BLOCK_ATTESTATION_1',version:'1.0.0',state:'BLOCKED',
    mapping_id:m.mapping_id,...proof,journal_event_hash,release_hold:true,external_execution_authorized:false};
  });
 }
 commitHeldProvenanceV1(request,now){return this.#admit(request,now,true);}
 readHeldProvenanceV1(request,now){
  return this.#tx(()=>{
   const s=this.#state();this.#sameBinding(Object.fromEntries(FIELDS.map(k=>[k,request[k]])),s);
   const op=this.#get('operations',hash(request.method_body.operation_id),'operation_id');
   need(s.identity.environment==='test'&&op?.state==='HELD'&&op.provenance
     &&op.provenance.snapshot_hash===request.method_body.snapshot_hash,'provenance_readback');
   this.#claimTokens(request.request_id,request.challenge,'readHeldProvenanceV1',now);
   return op;
  });
 }
 admit(request,now){return this.#admit(request,now,false);}
 #admit(request,now,held){
  integer(now);need(object(request)&&request.method===(held?'commitHeldProvenanceV1':'admitOperation')&&request.version==='1.0.0','request_method');nonce(request.request_id);nonce(request.challenge);need(request.request_id!==request.challenge,'request_nonce');const body=request.method_body;
  keys(body,['grant_reference','operation_id','payload_fingerprint','product_version','expected_product_version','approval_reference','approval_revision','policy_revision','expected_epoch','expected_generation',...(held?['snapshot_json','producer_signature','allocation_revision','order_version']:[])]);ref(body.grant_reference);ref(body.approval_reference);hash(body.operation_id);hash(body.payload_fingerprint);hash(body.expected_epoch);for(const k of ['product_version','expected_product_version','approval_revision','policy_revision','expected_generation'])integer(body[k]);
  return this.#tx(()=>{const s=this.#state();need(!s.paused,'authority_paused');need(body.expected_epoch===s.epoch&&body.expected_generation===s.generation,'checkpoint_stale');const b=this.#sameBinding(Object.fromEntries(FIELDS.map(k=>[k,request[k]])),s);
   need(!this.#db.prepare('SELECT operation_id FROM operations WHERE operation_id=? OR request_id=? OR challenge=?').get(body.operation_id,request.request_id,request.challenge),'operation_consumed');
   const row=this.#db.prepare('SELECT * FROM approvals WHERE reference=?').get(body.approval_reference);need(row,'approval_missing');const a=JSON.parse(row.payload);
   const grant=this.#get('grants',body.grant_reference,'reference');need(grant,'grant_not_current');
   const op={...b,binding:b,...a,grant_reference:body.grant_reference,issued_at:grant.issued_at,expires_at:grant.expires_at,request_id:request.request_id,challenge:request.challenge,allocation_id:row.allocation_id,allocation_revision:next(row.allocation_revision),generation:s.generation,epoch:s.epoch,recovery_epoch:s.recovery_epoch,state:held?'HELD':'ADMITTED',created_at:now,updated_at:now};
   for(const k of ['operation_id','payload_fingerprint','product_version','expected_product_version','approval_reference','approval_revision','policy_revision'])need(body[k]===a[k],'approval_binding_mismatch');this.#currentApproval(op,s,now);
   if(held){
    need(s.identity.environment==='test'&&row.allocation_revision===body.allocation_revision
      &&a.order_version===body.order_version,'provenance_allocation_cas');
    // Locate the pin by the approved mapping, never by an untrusted signature claim.
    const snapshotHash=createHash('sha256').update(body.snapshot_json).digest('hex');
    const m=s.held_provenance?.mappings.find(x=>x.active&&x.snapshot_hash===snapshotHash);
    const p=s.held_provenance?.producers.find(x=>x.producer===m?.producer);
    need(m&&p&&!p.revoked&&m.generation===s.generation&&now<m.expires_at,'provenance_not_current');
    const key=createPublicKey({key:Buffer.from(p.key_der,'base64url'),format:'der',type:'spki'});
    const {snapshot}=authenticateProvenanceV1(body.snapshot_json,body.producer_signature,
      {key,keySha256:p.key_pin,producer:p.producer,sourceSha:p.source_sha,businessId:p.business_id,etsyShopId:p.etsy_shop_id},now);
    need(snapshot.snapshot_revision===m.snapshot_revision&&snapshot.order_id===m.order_id
      &&snapshot.line_id===m.line_id&&snapshot.listing_id===m.listing_id
      &&snapshot.product_id===m.product_id&&snapshot.product_version===m.product_version
      &&snapshot.artwork_source_hash===m.artwork_source_hash&&a.order_identity===m.native_order_id
      &&a.order_version===m.order_version&&a.listing_identity===m.native_listing_id
      &&a.product_identity===m.native_product_id&&a.product_version===m.product_version
      &&a.lines.some(line=>line.line_identity===m.native_line_id),'provenance_native_join');
    op.provenance={snapshot_hash:snapshotHash,snapshot_revision:m.snapshot_revision,
      producer_source_sha:p.source_sha,mapping_revision:m.revision,journal_sequence:next(s.witness_sequence)};
   }
   this.#claimTokens(request.request_id,request.challenge,request.method,now);
   const cas=this.#db.prepare('UPDATE approvals SET consumed=1,allocation_revision=? WHERE reference=? AND allocation_revision=? AND consumed=0').run(op.allocation_revision,a.approval_reference,row.allocation_revision);need(cas.changes===1,'allocation_consumed');
   this.#db.prepare('INSERT INTO operations(operation_id,request_id,challenge,allocation_id,payload) VALUES(?,?,?,?,?)').run(op.operation_id,op.request_id,op.challenge,op.allocation_id,canonical(op));this.#event(s,held?'OPERATION_HELD_PROVENANCE':'OPERATION_ADMITTED',{operation_id:op.operation_id,allocation_id:op.allocation_id,allocation_revision:op.allocation_revision,generation:op.generation,...(held?{snapshot_hash:op.provenance.snapshot_hash}:{})},now);return op;
  });
 }
 heldJournalHash(sequence){return this.#db.prepare('SELECT head FROM witness_events WHERE sequence=?').get(sequence)?.head;}
 operation(operationId){hash(operationId);try{this.#state();const op=this.#get('operations',operationId,'operation_id');need(op,'operation_missing');return op;}catch(e){if(e instanceof AuthorityError)throw e;fail('storage_unavailable');}}
 markSending(operationId,expectedGeneration,now){
  hash(operationId);integer(expectedGeneration);integer(now);return this.#tx(()=>{const s=this.#state();const op=this.operation(operationId);need(!s.paused&&s.generation===expectedGeneration&&op.generation===s.generation,'generation_revoked');need(op.state==='ADMITTED','operation_not_admitted');this.#currentApproval(op,s,now);op.state='SENDING';op.updated_at=now;this.#put('operations',operationId,op,'operation_id');this.#event(s,'OPERATION_SENDING',{operation_id:operationId,generation:op.generation},now);return op;});
 }
 finish(operationId,status,record,now){
  hash(operationId);need(TERMINAL.has(status),'outcome_required');integer(now);const proof=evidence(record);
  return this.#tx(()=>{const s=this.#state();const op=this.operation(operationId);need(op.state==='SENDING','operation_not_sending');op.state=op.generation===s.generation&&now<op.expires_at?status:'UNKNOWN';op.evidence=proof;op.updated_at=now;this.#put('operations',operationId,op,'operation_id');this.#event(s,'OPERATION_FINISHED',{operation_id:operationId,outcome:op.state,evidence:proof},now);return op;});
 }
 emergency(eventId,expectedGeneration,now){
  ref(eventId);integer(expectedGeneration);integer(now);return this.#tx(()=>{const s=this.#state();need(!this.#db.prepare('SELECT event_id FROM emergencies WHERE event_id=?').get(eventId),'emergency_replay');need(s.generation===expectedGeneration,'generation_cas');this.#invalidate(s);this.#db.prepare('INSERT INTO emergencies(event_id,generation) VALUES(?,?)').run(eventId,s.generation);this.#event(s,'EMERGENCY',{event_id:eventId,generation:s.generation},now);return {...this.#view(s),event_id:eventId};});
 }
 abandonUnsent(operationId,allocationId,allocationRevision,operationGeneration,expectedWitnessHead,now){
  hash(operationId);ref(allocationId);integer(allocationRevision);integer(operationGeneration);hash(expectedWitnessHead);integer(now);
  return this.#tx(()=>{
   const s=this.#state();need(s.witness_head===expectedWitnessHead,'witness_head_cas');const op=this.operation(operationId);this.#sameBinding(op.binding,s);
   need(FIELDS.every(k=>op[k]===s.identity[k]),'binding_mismatch');
   need(s.paused&&s.generation>op.generation&&op.state==='ADMITTED'&&op.epoch===s.epoch&&op.recovery_epoch===s.recovery_epoch,'operation_not_abandonable');
   need(op.allocation_id===allocationId&&op.allocation_revision===allocationRevision&&op.generation===operationGeneration,'allocation_binding');
   const allocation=this.#db.prepare('SELECT allocation_id,allocation_revision,consumed,payload FROM approvals WHERE reference=? AND operation_id=?').get(op.approval_reference,operationId);
   need(allocation&&allocation.consumed===1&&allocation.allocation_id===allocationId&&allocation.allocation_revision>=allocationRevision&&JSON.parse(allocation.payload).operation_id===operationId,'allocation_binding');
   op.state='FAILED';op.evidence={failure_category:'revoked_before_send'};op.updated_at=now;this.#put('operations',operationId,op,'operation_id');
   this.#event(s,'OPERATION_ABANDONED_UNSENT',{operation_id:operationId,allocation_id:allocationId,allocation_revision:allocationRevision,operation_generation:operationGeneration,generation:s.generation},now);return op;
  });
 }
 drain(eventId,generation){
  ref(eventId);integer(generation);return this.#tx(()=>{const s=this.#state();const e=this.#db.prepare('SELECT generation FROM emergencies WHERE event_id=?').get(eventId);need(e&&e.generation===generation&&s.generation===generation,'emergency_generation_mismatch');const pending=this.#unfinished();return {event_id:eventId,generation,state:pending.length?'PENDING':'DRAINED',pending_leases:pending.length,inventory_hash:digest(pending),acknowledged:pending.length===0};});
 }
 recover(expectedHead,inventoryHash,now){
  hash(expectedHead);hash(inventoryHash);integer(now);return this.#tx(()=>{const s=this.#state();need(s.witness_head===expectedHead,'witness_head_cas');need(this.#unfinished().length===0&&digest(this.#unfinished())===inventoryHash,'recovery_unresolved');s.epoch=randomBytes(32).toString('hex');s.recovery_epoch=randomBytes(32).toString('hex');s.recovery_revision=next(s.recovery_revision);this.#invalidate(s);this.#event(s,'RECOVERED',{recovery_epoch:s.recovery_epoch,recovery_revision:s.recovery_revision,generation:s.generation},now);return this.#view(s);});
 }
 rotate(previousRevision,newRevision,newDigest,expectedHead,now){
  integer(previousRevision);integer(newRevision);hash(newDigest);hash(expectedHead);integer(now);return this.#tx(()=>{const s=this.#state();need(s.root_set_revision===previousRevision&&newRevision===next(previousRevision)&&s.witness_head===expectedHead,'root_revision_cas');need(this.#unfinished().length===0,'rotation_unresolved');s.root_set_revision=newRevision;s.root_set_digest=newDigest;this.#invalidate(s);this.#event(s,'ROOT_ROTATED',{root_set_revision:newRevision,root_set_digest:newDigest,generation:s.generation},now);return this.#view(s);});
 }
 reconcile(operationId,authenticatedEvidence,now){
  hash(operationId);integer(now);const proof=evidence(authenticatedEvidence,true);for(const k of [...FIELDS,'operation_id','allocation_id','allocation_revision','payload_fingerprint','generation','outcome'])need(Object.hasOwn(proof,k),'reconciliation_binding');need(['SUCCEEDED','FAILED'].includes(proof.outcome),'reconciliation_outcome');
  return this.#tx(()=>{const s=this.#state();const op=this.operation(operationId);need(op.state==='UNKNOWN'||(s.paused&&op.generation<s.generation&&['ADMITTED','SENDING'].includes(op.state)),'operation_not_reconcilable');for(const k of [...FIELDS,'operation_id','allocation_id','allocation_revision','payload_fingerprint','generation'])need(proof[k]===op[k],'reconciliation_binding');op.state=proof.outcome;op.reconciliation=proof;op.updated_at=now;this.#put('operations',operationId,op,'operation_id');this.#event(s,'OPERATION_RECONCILED',{operation_id:operationId,outcome:op.state,evidence:proof},now);return op;});
 }
 close(){try{this.#db?.close();}catch(e){if(e instanceof AuthorityError)throw e;fail('storage_unavailable');}}
}
