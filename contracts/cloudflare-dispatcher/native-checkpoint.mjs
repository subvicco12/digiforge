import { canonical, hash, verify, freshness, keys, hex, insist, Denied, b64, unb64, LIMITS } from './protocol.mjs';

// Offline diagnostic protocol only. No native response/grant, epoch issuer, sender or live factory.
export const NATIVE_CHECKPOINT_VERSION = '1.0.0';
const BINDING = ['operation_id','payload_fingerprint','intent_fingerprint','scope_fingerprint','approval_reference','shop_id','integration_id','credential_reference','actor_id','authorization_revision','shop_key','provider_shop_id','environment','product_version','expected_product_version'];
const CONTEXT = ['binding','provider','provider_account_reference','installation_id','workload_id','deployment_digest','policy_revision','core_source_sha','owner_proof_reference','owner_session_reference'];
const POLICY = ['authority_id','witness_id','authority_domain','witness_domain','sender_domain','root_set_revision','minimum_recovery_revision','minimum_generation','minimum_root_revision'];
const VECTOR = ['recovery_epoch','checkpoint_epoch','recovery_revision','generation','root_revision'];
const COMMON = ['version','method','purpose','request_id','challenge','authority_id','witness_id','root_set_revision',...VECTOR,'issued_at','expires_at'];
const ref = v => typeof v==='string' && /^[A-Za-z0-9._:-]{1,160}$/.test(v);
const integer = (v,min=1) => Number.isSafeInteger(v) && v>=min;
const clone = v => JSON.parse(canonical(v));
function freeze(v) { if(v && typeof v==='object'){for(const x of Object.values(v))freeze(x);Object.freeze(v);}return v; }
function token(v) {
  if(typeof v!=='string'||!/^[A-Za-z0-9_-]{43}$/.test(v))return false;
  try { return b64(unb64(v.replaceAll('-','+').replaceAll('_','/')+'=',32)).replaceAll('+','-').replaceAll('/','_').replaceAll('=','')===v; }
  catch { return false; }
}
function context(v) {
  keys(v,CONTEXT);keys(v.binding,BINDING);const b=v.binding;
  for(const k of ['operation_id','payload_fingerprint','intent_fingerprint','scope_fingerprint','approval_reference'])insist(hex(b[k]),'CHECKPOINT_CONTEXT');
  for(const k of ['shop_id','integration_id','credential_reference','actor_id','authorization_revision'])insist(integer(b[k]),'CHECKPOINT_CONTEXT');
  for(const k of ['product_version','expected_product_version'])insist(integer(b[k],0),'CHECKPOINT_CONTEXT');
  insist(b.shop_id===2&&b.shop_key==='personalized_pod'&&b.integration_id===3&&b.credential_reference===3&&b.actor_id===1&&b.provider_shop_id==='28930593'&&b.environment==='test','CHECKPOINT_CONTEXT');
  insist(v.provider==='printify'&&ref(v.provider_account_reference)&&ref(v.installation_id)&&ref(v.workload_id)&&hex(v.deployment_digest)&&hex(v.core_source_sha,40)&&integer(v.policy_revision)&&hex(v.owner_proof_reference)&&hex(v.owner_session_reference),'CHECKPOINT_CONTEXT');
}

/** Synthetic transport boundary; different domain labels/keys are NOT hosted independence evidence. */
export class SyntheticNativeCheckpointObserver {
  #ports;#authority;#witness;#policy;#context;#clock;
  #seen=new Set();#times=[];#last=null;#witnessHead=null;#time=0;#fenced=false;
  constructor(options={}) {
    insist(options.synthetic===true&&options.ports?.synthetic===true,'SYNTHETIC_ONLY');
    keys(options,['synthetic','ports','authorityPublic','witnessPublic','policy','context','clock']);
    insist(typeof options.ports.issue==='function'&&typeof options.ports.observe==='function'&&typeof options.clock==='function','SYNTHETIC_ONLY');
    this.#policy=freeze(clone(options.policy));this.#context=freeze(clone(options.context));context(this.#context);keys(this.#policy,POLICY);
    for(const k of ['authority_id','witness_id','authority_domain','witness_domain','sender_domain'])insist(ref(this.#policy[k]),'CHECKPOINT_TRUST_ROOT');
    for(const k of ['root_set_revision','minimum_recovery_revision','minimum_generation','minimum_root_revision'])insist(integer(this.#policy[k]),'CHECKPOINT_TRUST_ROOT');
    insist(this.#policy.authority_id!==this.#policy.witness_id&&this.#policy.authority_domain!==this.#policy.witness_domain&&this.#policy.sender_domain!==this.#policy.witness_domain,'CHECKPOINT_TRUST_ROOT');
    for(const key of [options.authorityPublic,options.witnessPublic])insist(key?.type==='public'&&key.algorithm?.name==='Ed25519'&&key.usages.includes('verify'),'CHECKPOINT_TRUST_ROOT');
    this.#authority=options.authorityPublic;this.#witness=options.witnessPublic;this.#clock=options.clock;
    this.#ports=Object.freeze({issue:options.ports.issue.bind(options.ports),observe:options.ports.observe.bind(options.ports)});
  }
  #validate(m,q,purpose) {
    insist(m.version===NATIVE_CHECKPOINT_VERSION&&m.method==='currentCheckpoint'&&m.purpose===purpose&&m.request_id===q.request_id&&m.challenge===q.challenge,'CHECKPOINT_DOMAIN');
    insist(m.authority_id===this.#policy.authority_id&&m.witness_id===this.#policy.witness_id&&m.root_set_revision===this.#policy.root_set_revision,'CHECKPOINT_TRUST_ROOT');
    insist(hex(m.recovery_epoch)&&m.recovery_epoch===m.checkpoint_epoch&&integer(m.recovery_revision)&&integer(m.generation)&&integer(m.root_revision),'CHECKPOINT_EPOCH');
    freshness(m,this.#clock(),30);
  }
  async observe(input) {
    insist(!this.#fenced,'CHECKPOINT_REVIEW_REQUIRED');
    const q=freeze(clone(input));keys(q,['request_id','challenge','context']);context(q.context);
    insist(token(q.request_id)&&token(q.challenge)&&q.request_id!==q.challenge&&canonical(q.context)===canonical(this.#context),'CHECKPOINT_BINDING');
    const now=this.#clock();insist(integer(now)&&now>=this.#time,'CHECKPOINT_CLOCK');
    insist(!this.#seen.has(q.request_id)&&!this.#seen.has(q.challenge),'CHECKPOINT_REPLAY');
    this.#times=this.#times.filter(t=>t>now-60);
    insist(this.#times.length<LIMITS.perMinute&&this.#seen.size<2*LIMITS.slots,'CHECKPOINT_BUDGET');
    this.#seen.add(q.request_id);this.#seen.add(q.challenge);this.#times.push(now);this.#time=now;
    let timer,closed=false;
    try {
      const current=()=>insist(!closed&&!this.#fenced,'CHECKPOINT_REVIEW_REQUIRED');
      const work=async()=>{
        const a=new Uint8Array(await crypto.subtle.exportKey('raw',this.#authority)),b=new Uint8Array(await crypto.subtle.exportKey('raw',this.#witness));
        current();insist(b64(a)!==b64(b),'CHECKPOINT_TRUST_ROOT');
        const checkpoint=freeze(clone(await this.#ports.issue(q)));
        current();
        const c=await verify(checkpoint,'native-checkpoint-v1',this.#authority);keys(c,[...COMMON,'context']);
        this.#validate(c,q,'DF_INDEPENDENT_CHECKPOINT_AUTHORITY_1');insist(canonical(c.context)===canonical(q.context),'CHECKPOINT_BINDING');
        const checkpointHash=await hash(canonical(checkpoint)),contextHash=await hash(canonical(q.context));
        current();
        const attestation=freeze(clone(await this.#ports.observe(Object.freeze({request_id:q.request_id,challenge:q.challenge,checkpoint_hash:checkpointHash,context_hash:contextHash}))));
        current();
        const w=await verify(attestation,'native-witness-v1',this.#witness);keys(w,[...COMMON,'checkpoint_hash','context_hash','state','witness_head','witness_sequence']);
        this.#validate(w,q,'DF_INDEPENDENT_RECOVERY_WITNESS_1');
        insist(w.state==='ACTIVE'&&w.checkpoint_hash===checkpointHash&&w.context_hash===contextHash,'CHECKPOINT_WITNESS');
        insist(hex(w.witness_head)&&w.witness_sequence===c.root_revision,'CHECKPOINT_WITNESS');
        for(const k of VECTOR)insist(c[k]===w[k],'CHECKPOINT_WITNESS');
        const observationHash=await hash(canonical({checkpoint,attestation}));
        // Recheck after all asynchronous cryptography; never return a dispatch permit.
        current();
        const finished=this.#clock();insist(integer(finished)&&finished>=this.#time,'CHECKPOINT_CLOCK');
        freshness(c,finished,30);freshness(w,finished,30);
        const last=this.#last;
        insist(c.recovery_revision>=this.#policy.minimum_recovery_revision&&c.generation>=this.#policy.minimum_generation&&c.root_revision>=this.#policy.minimum_root_revision,'CHECKPOINT_ROLLBACK');
        if(last){
          for(const k of ['recovery_revision','generation','root_revision'])insist(c[k]>=last[k],'CHECKPOINT_ROLLBACK');
          if(c.root_revision===last.root_revision)insist(w.witness_head===this.#witnessHead,'CHECKPOINT_WITNESS');
          insist((c.recovery_epoch===last.recovery_epoch)===(c.recovery_revision===last.recovery_revision),'CHECKPOINT_EPOCH');
          if(c.recovery_epoch!==last.recovery_epoch)insist(c.root_revision>last.root_revision,'CHECKPOINT_ROLLBACK');
        }
        this.#last=Object.fromEntries(VECTOR.map(k=>[k,c[k]]));this.#witnessHead=w.witness_head;this.#time=finished;
        return Object.freeze({schema:'DF_NATIVE_CHECKPOINT_OBSERVATION_1',version:NATIVE_CHECKPOINT_VERSION,...this.#last,witness_head:w.witness_head,witness_sequence:w.witness_sequence,witness_observed_at:w.issued_at,evidence_currentness:'WITNESSED_AT_SIGNING_ONLY',observation_hash:observationHash,cryptographically_verified:true,independent_recovery_qualified:false,mutation_authorized:false,external_execution_authorized:false,operational_authority:false,retry_permitted:false,release_hold:true});
      };
      const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Denied('CHECKPOINT_TIMEOUT_UNKNOWN')),LIMITS.timeout);});
      return await Promise.race([work(),deadline]);
    } catch(error) {
      closed=true;this.#fenced=true;
      const allowed=new Set(['CHECKPOINT_TRUST_ROOT','CHECKPOINT_DOMAIN','CHECKPOINT_BINDING','CHECKPOINT_EPOCH','CHECKPOINT_WITNESS','CHECKPOINT_ROLLBACK','CHECKPOINT_CLOCK','CHECKPOINT_TIMEOUT_UNKNOWN','SIGNATURE','EXPIRED']);
      let code='CHECKPOINT_UNAVAILABLE_UNKNOWN';
      try { if(error instanceof Denied&&allowed.has(error.code))code=error.code; } catch { /* Foreign exception accessors are not diagnostic evidence. */ }
      const e=new Denied(code);e.checkpoint_outcome='UNKNOWN';e.retry_permitted=false;throw e;
    } finally { closed=true;clearTimeout(timer); }
  }
}
