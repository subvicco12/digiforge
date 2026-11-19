import { constants, openSync, closeSync, fstatSync, lstatSync, readFileSync, readdirSync, realpathSync, existsSync } from 'node:fs';
import { dirname, resolve, isAbsolute, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash, createPrivateKey, createPublicKey } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { AuthorityStore } from './store.mjs';
import { canonical, digest, verify } from './protocol.mjs';
import { BINDING_FIELDS, validateBinding, exactFields, reference, integer, digestValue } from './schema.mjs';
import { AuthorityError, requireAuthority as need } from './errors.mjs';

const sourceRoot=dirname(fileURLToPath(import.meta.url)), repositoryRoot=resolve(sourceRoot,'../..');
const roles=['workload','issuer','witness','owner','emergency','recovery'];
const loaded=new WeakMap();
const controls=['REGISTER_GRANT','REGISTER_APPROVAL','ADVANCE_PRODUCT','ADVANCE_ORDER','RECOVERY_APPROVAL','ROTATION_APPROVAL','RECONCILE_OPERATION','ABANDON_UNSENT'];
const hashBytes=v=>createHash('sha256').update(v).digest('hex');
const now=()=>Math.floor(Date.now()/1000);
function boundary(path, absent=false){
 need(typeof path==='string'&&isAbsolute(path)&&resolve(path)===path,'private_path');
 need(path!==repositoryRoot&&!path.startsWith(repositoryRoot+sep),'private_outside_repository');
 let parent=dirname(path);const stat=lstatSync(parent);need(stat.isDirectory()&&!stat.isSymbolicLink()&&(stat.mode&0o777)===0o700,'private_parent');
 if(typeof process.getuid==='function')need(stat.uid===process.getuid(),'private_owner');
 for(let p=parent;;p=dirname(p)){
  need(!lstatSync(p).isSymbolicLink()&&!/(?:^|\/)(?:wordpress|wp-content|wp-admin|wp-includes)(?:\/|$)/i.test(p),'private_outside_wordpress');
  need(!existsSync(resolve(p,'wp-config.php'))&&!existsSync(resolve(p,'wp-settings.php')),'private_outside_wordpress');
  if(dirname(p)===p)break;
 }
 need(realpathSync(parent)===parent,'private_path');
 if(!existsSync(path)){need(absent,'private_missing');return;}
 const s=lstatSync(path);need(s.isFile()&&!s.isSymbolicLink()&&(s.mode&0o777)===0o600,'private_permissions');
 if(typeof process.getuid==='function')need(s.uid===process.getuid(),'private_owner');
}
function privateRead(path){
 boundary(path);let fd;
 try{fd=openSync(path,constants.O_RDONLY|constants.O_NOFOLLOW);const s=fstatSync(fd);need(s.isFile()&&(s.mode&0o777)===0o600&&s.size<=1048576&&(typeof process.getuid!=='function'||s.uid===process.getuid()),'private_file_bound');return readFileSync(fd,'utf8');}finally{if(fd!==undefined)closeSync(fd);}
}
function parse(raw){let v;try{v=JSON.parse(raw);}catch{throw new AuthorityError('configuration_json');}canonical(v);return v;}
function lifetime(r){need(integer(r.issued_at)&&integer(r.expires_at)&&r.issued_at<=now()&&now()<r.expires_at&&r.expires_at-r.issued_at<=300,'configuration_expired');}
function dual(config,envelope,prefix){
 exactFields(envelope,['owner','recovery'],'dual_signature_required');
 need(Buffer.byteLength(canonical(envelope.owner?.message))<=65536&&Buffer.byteLength(canonical(envelope.recovery?.message))<=65536,'message_bound');
 const a=verify(prefix+'.owner.v1',envelope.owner,config.keys.owner),b=verify(prefix+'.recovery.v1',envelope.recovery,config.keys.recovery);
 need(Buffer.byteLength(canonical(a))<=65536&&canonical(a)===canonical(b),'dual_message_mismatch');return a;
}
function sameBinding(b,c){validateBinding(b);need(canonical(b)===canonical(Object.fromEntries(BINDING_FIELDS.map(k=>[k,c.identity[k]]))),'configuration_binding');}
export function serviceBuildDigest(){
 return digest(readdirSync(sourceRoot).filter(n=>n.endsWith('.mjs')).sort().map(name=>{const p=resolve(sourceRoot,name);need(lstatSync(p).isFile()&&!lstatSync(p).isSymbolicLink(),'service_source');return {path:name,sha256:hashBytes(readFileSync(p))};}));
}
export function loadConfig(configPath,role){
 try{
  need(['issuer','witness'].includes(role),'configuration_role');const c=parse(privateRead(configPath));
  exactFields(c,['role','mode','listen','identity','keys','provisioning','database_path','witness_url','credentials_path','payloads_path'],'configuration_fields');
  need(c.role===role&&c.mode==='OFFLINE_TEST','configuration_role_or_mode');exactFields(c.listen,['host','port']);need(c.listen.host==='127.0.0.1'&&integer(c.listen.port,0)&&c.listen.port<=65535,'listen_scope');
  exactFields(c.identity,[...BINDING_FIELDS,'witness_id','root_set_digest','root_set_revision']);validateBinding(Object.fromEntries(BINDING_FIELDS.map(k=>[k,c.identity[k]])));
  need(c.identity.environment==='test'&&reference(c.identity.witness_id)&&integer(c.identity.root_set_revision)&&digestValue(c.identity.root_set_digest)&&c.identity.deployment_digest===serviceBuildDigest(),'installation_identity');
  exactFields(c.keys,['signing_key_id','signing_private_key_path',...roles]);const rawKeys=c.keys;const fingerprints=new Set();const ids=new Set();const pinned={};
  for(const r of roles){const map=rawKeys[r];need(map&&Object.getPrototypeOf(map)===Object.prototype&&Object.keys(map).length>0&&Object.keys(map).length<=16,'pinned_keys');pinned[r]=new Map();
   for(const [id,pem]of Object.entries(map)){need(/^[A-Za-z0-9_-]{1,80}$/.test(id)&&!ids.has(id)&&typeof pem==='string'&&pem.startsWith('-----BEGIN PUBLIC KEY-----'),'principal_separation');ids.add(id);const k=createPublicKey(pem);need(k.asymmetricKeyType==='ed25519','fixed_algorithm');const h=hashBytes(k.export({type:'spki',format:'der'}));need(!fingerprints.has(h),'principal_separation');fingerprints.add(h);pinned[r].set(id,k);}
  }
  need(c.identity.root_set_digest===digest(rawKeys.issuer),'root_set_digest');const key=createPrivateKey(privateRead(rawKeys.signing_private_key_path));need(key.asymmetricKeyType==='ed25519'&&pinned[role].has(rawKeys.signing_key_id),'signing_identity');need(createPublicKey(key).export({type:'spki',format:'der'}).equals(pinned[role].get(rawKeys.signing_key_id).export({type:'spki',format:'der'})),'signing_identity');
  c.keys={...pinned};c.pinned=c.keys;c.privateKey=key;c.signingKey=key;c.keyId=rawKeys.signing_key_id;c.signingKeyId=c.keyId;
  const p=dual(c,c.provisioning,'installation');exactFields(p,['schema','version','identity','mode','principal_set_digest','provider_base_url','holds','products','orders','grants','approvals','credential_inventory','payload_inventory','issued_at','expires_at']);
  need(digestValue(p.principal_set_digest)&&p.principal_set_digest===digest(Object.fromEntries(roles.map(role=>[role,rawKeys[role]]))),'principal_set_digest');
  need(p.schema==='DF_NATIVE_INSTALLATION_1'&&p.version==='1.0.0'&&p.mode==='OFFLINE_TEST'&&canonical(p.identity)===canonical(c.identity),'provisioning_identity');lifetime(p);
  let providerUrl;try{providerUrl=new URL(p.provider_base_url);}catch{throw new AuthorityError('provider_loopback');}
  need(typeof p.provider_base_url==='string'&&providerUrl.protocol==='http:'&&['127.0.0.1','localhost','[::1]'].includes(providerUrl.hostname)&&!providerUrl.username&&!providerUrl.password&&providerUrl.pathname==='/'&&!providerUrl.search&&!providerUrl.hash,'provider_loopback');c.providerBaseUrl=p.provider_base_url;
  exactFields(p.holds,['stop_all','external_lock','automation','release_hold']);need(Object.values(p.holds).every(x=>typeof x==='boolean'),'installation_holds');
  for(const name of ['products','orders','grants','approvals','credential_inventory','payload_inventory'])need(Array.isArray(p[name])&&p[name].length<=100,'provisioning_inventory');
  for(const metadata of p.credential_inventory){exactFields(metadata,['credential_reference','binding','provider_shop_id','secret_sha256']);sameBinding(metadata.binding,c);need(metadata.credential_reference===metadata.binding.credential_reference&&typeof metadata.provider_shop_id==='string'&&/^[1-9][0-9]{0,19}$/.test(metadata.provider_shop_id)&&digestValue(metadata.secret_sha256),'credential_inventory');}
  for(const metadata of p.payload_inventory){exactFields(metadata,['operation_id','order_identity','provider_shop_id','method','path','payload_fingerprint']);need(digestValue(metadata.operation_id)&&reference(metadata.order_identity)&&typeof metadata.provider_shop_id==='string'&&/^[1-9][0-9]{0,19}$/.test(metadata.provider_shop_id)&&metadata.method==='POST'&&metadata.path===`/v1/shops/${metadata.provider_shop_id}/orders.json`&&digestValue(metadata.payload_fingerprint),'payload_inventory');}
  c.holds={...p.holds,STOP:p.holds.stop_all,externalLock:p.holds.external_lock,releaseHold:p.holds.release_hold};c.installation=p;c.credentials=new Map();c.payloads=new Map();
  if(role==='witness'){
   need(c.database_path!==null&&c.witness_url===null&&c.credentials_path===null&&c.payloads_path===null,'witness_custody_separation');boundary(c.database_path,true);
  }else{
   need(c.database_path===null&&typeof c.witness_url==='string'&&c.credentials_path!==null&&c.payloads_path!==null,'issuer_custody_separation');let u;try{u=new URL(c.witness_url);}catch{throw new AuthorityError('witness_url');}
   need(u.protocol==='http:'&&u.hostname==='127.0.0.1'&&Number(u.port)>0&&!u.username&&!u.password&&u.pathname==='/'&&!u.search&&!u.hash,'witness_loopback');
   const credentials=parse(privateRead(c.credentials_path)),payloads=parse(privateRead(c.payloads_path));need(Array.isArray(credentials)&&Array.isArray(payloads)&&credentials.length<=100&&payloads.length<=100,'custody_inventory');const cm=[],pm=[];
   for(const cr of credentials){exactFields(cr,['credential_reference','secret','binding','provider_shop_id']);sameBinding(cr.binding,c);need(cr.credential_reference===cr.binding.credential_reference&&!c.credentials.has(cr.credential_reference)&&typeof cr.secret==='string'&&/^offline-test-[A-Za-z0-9_-]{32,128}$/.test(cr.secret)&&typeof cr.provider_shop_id==='string'&&/^[1-9][0-9]{0,19}$/.test(cr.provider_shop_id),'credential_inventory');c.credentials.set(cr.credential_reference,cr);cm.push({credential_reference:cr.credential_reference,binding:cr.binding,provider_shop_id:cr.provider_shop_id,secret_sha256:hashBytes(cr.secret)});}
   for(const value of payloads){exactFields(value,['operation_id','order_identity','provider_shop_id','method','path','body','payload_fingerprint']);need(digestValue(value.operation_id)&&!c.payloads.has(value.operation_id)&&reference(value.order_identity)&&value.method==='POST'&&typeof value.provider_shop_id==='string'&&/^[1-9][0-9]{0,19}$/.test(value.provider_shop_id)&&value.path===`/v1/shops/${value.provider_shop_id}/orders.json`&&value.body&&Object.getPrototypeOf(value.body)===Object.prototype&&Buffer.byteLength(canonical(value.body))<=65536&&digest(value.body)===value.payload_fingerprint,'payload_inventory');need([...c.credentials.values()].some(cr=>cr.provider_shop_id===value.provider_shop_id),'payload_credential_scope');c.payloads.set(value.operation_id,value);const {body,...metadata}=value;pm.push(metadata);}
   const sort=a=>a.map(v=>canonical(v)).sort();need(canonical(sort(cm))===canonical(sort(p.credential_inventory))&&canonical(sort(pm))===canonical(sort(p.payload_inventory)),'signed_inventory_mismatch');
  }
  loaded.set(c,{identity:digest(c.identity),installation:digest(c.installation),database_path:c.database_path});return c;
 }catch(e){if(e instanceof AuthorityError)throw e;throw new AuthorityError('configuration_unavailable');}
}

export function initializeStore(config){
 const validated=loaded.get(config);need(validated&&validated.identity===digest(config.identity)&&validated.installation===digest(config.installation)&&validated.database_path===config.database_path,'loaded_configuration_required');
 need(config.role==='witness'&&config.installation&&config.credentials_path===null&&config.payloads_path===null,'witness_store_only');lifetime(config.installation);boundary(config.database_path,true);let store,db;
 try{
  const stableIdentity=digest({...Object.fromEntries(BINDING_FIELDS.map(k=>[k,config.identity[k]])),witness_id:config.identity.witness_id});
  store=new AuthorityStore(config.database_path);db=new DatabaseSync(config.database_path);db.exec('PRAGMA busy_timeout=5000; PRAGMA synchronous=FULL; CREATE TABLE IF NOT EXISTS installation_provisioning(id INTEGER PRIMARY KEY CHECK(id=1),identity_digest TEXT NOT NULL,complete INTEGER NOT NULL CHECK(complete IN(0,1)));');
  db.exec('BEGIN IMMEDIATE');const marker=db.prepare('SELECT * FROM installation_provisioning WHERE id=1').get();
  if(marker){db.exec('COMMIT');need(marker.complete===1,'partial_provisioning');const s=store.state();need(canonical(s.identity)===canonical(Object.fromEntries(BINDING_FIELDS.map(k=>[k,config.identity[k]])))&&s.witness_id===config.identity.witness_id&&s.root_set_digest===config.identity.root_set_digest&&s.root_set_revision===config.identity.root_set_revision&&marker.identity_digest===stableIdentity,'existing_identity_mismatch');}
  else{
   // Write intent durably before any Store method commits. A failed or killed
   // provisioning sequence cannot be mistaken for a completed restart.
   try{store.state();throw new AuthorityError('unsealed_existing_state');}catch(e){need(e instanceof AuthorityError&&e.code==='not_provisioned','unsealed_existing_state');}
   db.prepare('INSERT INTO installation_provisioning VALUES(1,?,0)').run(stableIdentity);db.exec('COMMIT');
   store.bootstrap(config.identity,now());for(const p of config.installation.products)store.registerProduct(p);for(const o of config.installation.orders)store.registerOrder(o);for(const g of config.installation.grants)store.registerGrant(g);for(const a of config.installation.approvals)store.registerApproval(a);
   db.prepare('UPDATE installation_provisioning SET complete=1 WHERE id=1 AND complete=0').run();
  }
  db.close();return store;
 }catch(e){try{db?.exec('ROLLBACK');}catch{}try{db?.close();}catch{}try{store?.close();}catch{}if(e instanceof AuthorityError)throw e;throw new AuthorityError('provisioning_unavailable');}
}
export function verifyControl(config,envelope){need(loaded.has(config),'loaded_configuration_required');const r=dual(config,envelope,'control');exactFields(r,['schema','version','reference','binding','issued_at','expires_at','data','type']);need(r.schema==='DF_NATIVE_CONTROL_1'&&r.version==='1.0.0'&&reference(r.reference)&&controls.includes(r.type)&&r.data&&Object.getPrototypeOf(r.data)===Object.prototype,'control_record');sameBinding(r.binding,config);lifetime(r);return r;}
