import { IndependentAuthorityService } from './authority-service.mjs';
import { SyntheticNativeCheckpointObserver } from './native-checkpoint.mjs';
import { canonical, hash, keys, insist } from './protocol.mjs';

/** Isolated one-intent fixture. No environment flag, production factory or native mutation authority. */
export class SyntheticCheckpointFinalSender extends IndependentAuthorityService {
  constructor(options = {}) {
    insist(options.synthetic === true, 'SYNTHETIC_ONLY');
    keys(options, ['runtime','root','ports','clock','synthetic','observer','context','payload',...(Object.hasOwn(options,'mode')?['mode']:[])]);
    const mode = options.mode ?? 'DISABLED';
    insist(['DISABLED','SYNTHETIC_CHECKPOINT_QUALIFICATION'].includes(mode), 'SYNTHETIC_MODE');
    const gate = new CheckpointGate(options, mode === 'SYNTHETIC_CHECKPOINT_QUALIFICATION');
    super({runtime:options.runtime,root:options.root,ports:options.ports,clock:options.clock,synthetic:true,checkpointGate:gate});
  }
}

class CheckpointGate {
  #observer; #root; #clock; #context; #payload; #enabled; #record=null; #fenced=false;
  synthetic = true;
  constructor(options, enabled) {
    insist(options.observer instanceof SyntheticNativeCheckpointObserver, 'CHECKPOINT_OBSERVER_REQUIRED');
    this.#observer=options.observer;this.#root=options.root;this.#clock=options.clock;
    this.#context=JSON.parse(canonical(options.context));this.#payload=canonical(options.payload);this.#enabled=enabled;
    insist(this.#context.binding.environment==='test', 'SYNTHETIC_ONLY');
  }
  async prepare(id, request, payload) {
    insist(this.#enabled&&!this.#fenced&&!this.#record, 'CHECKPOINT_INACTIVE');
    try {
      insist(canonical(payload)===this.#payload&&canonical(request.context)===canonical(this.#context), 'CHECKPOINT_INTENT');
      const m=payload.authority.manifest, c=this.#context, b=c.binding;
      insist(b.operation_id===await hash(id)&&b.intent_fingerprint===await hash(this.#payload)&&b.payload_fingerprint===await hash(canonical(m.request)), 'CHECKPOINT_INTENT');
      insist(b.actor_id===m.actor&&b.shop_id===m.internal_shop_id&&b.shop_key===m.shop_key&&b.integration_id===m.integration_id&&b.provider_shop_id===m.provider_shop_id&&b.approval_reference===m.review&&c.owner_proof_reference===m.owner_proof&&c.owner_session_reference===await hash(m.session), 'CHECKPOINT_INTENT');
      const observation=await this.#observer.observe(request);
      insist(observation.cryptographically_verified===true&&observation.operational_authority===false&&observation.mutation_authorized===false&&observation.evidence_currentness==='WITNESSED_AT_SIGNING_ONLY', 'CHECKPOINT_OBSERVATION');
      const intent='checkpoint-intent:'+b.operation_id, proof='checkpoint-proof:'+observation.observation_hash;
      // One existing SQLite CAS persists both references before runtime reservation.
      // No network or asynchronous work is inside this transaction.
      this.#root.change(d=>{
        insist(!d.stopped&&!d.recovery&&d.epoch===observation.recovery_epoch&&d.generation===observation.generation&&d.revision>=observation.root_revision, 'CHECKPOINT_REVOKED');
        insist(d.account===c.provider_account_reference&&d.code===c.deployment_digest&&d.credential_binding===m.credential_binding, 'CHECKPOINT_PHYSICAL_BINDING');
        insist(d.nonces.length<=2046&&!d.nonces.includes(intent)&&!d.nonces.includes(proof), 'CHECKPOINT_INTENT_CONSUMED');
        d.nonces.push(intent,proof);d.nonce_count+=2;
      });
      this.#record=Object.freeze({id,intent,proof,epoch:observation.recovery_epoch,generation:observation.generation,revision:observation.root_revision,observedAt:observation.witness_observed_at,expiresAt:observation.evidence_expires_at});
      this.assert(id);
    } catch(error) {
      this.#fenced=true;
      throw error;
    }
  }
  assert(id) {
    insist(this.#enabled&&!this.#fenced&&this.#record?.id===id, 'CHECKPOINT_INACTIVE');
    const r=this.#record,d=this.#root.active(),now=this.#clock();
    insist(Number.isSafeInteger(now)&&Number.isSafeInteger(r.expiresAt)&&now>=r.observedAt&&now<r.expiresAt&&now<r.observedAt+30, 'CHECKPOINT_EXPIRED');
    insist(d.epoch===r.epoch&&d.generation===r.generation&&d.revision>=r.revision&&d.nonces.includes(r.intent)&&d.nonces.includes(r.proof), 'CHECKPOINT_REVOKED');
  }
}
