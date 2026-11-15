// Synthetic issuer/witness fixture only. No credential, provider transport or deployment adapter.
import { LocalSqliteStorage } from './support.mjs';
import { AuthorityJournal } from '../authority-journal.mjs';
import { canonical, hash, sign, insist } from '../protocol.mjs';

export const randomHex = () => Array.from(crypto.getRandomValues(new Uint8Array(32)), x => x.toString(16).padStart(2, '0')).join('');
export const nonce = () => btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32)))).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');
export async function checkpointFixture() {
  const pair = () => crypto.subtle.generateKey('Ed25519', true, ['sign','verify']);
  const issuer = await pair(), witness = await pair();
  const storage = new LocalSqliteStorage();
  const clock = { now: 1000 };
  const root = new AuthorityJournal(storage, () => clock.now);
  const epoch = randomHex(); // Issued by this synthetic independent root, never inferred from a content hash.
  root.initialize({epoch,namespace:randomHex(),code:randomHex(),account:'synthetic-account',credential_binding:randomHex()}, {revision:1,hash:randomHex()}, 'synthetic-workload-key');
  root.boot();
  const binding = {operation_id:randomHex(),payload_fingerprint:randomHex(),intent_fingerprint:randomHex(),scope_fingerprint:randomHex(),approval_reference:randomHex(),shop_id:2,integration_id:3,credential_reference:3,actor_id:1,authorization_revision:2,shop_key:'personalized_pod',provider_shop_id:'28930593',environment:'test',product_version:1,expected_product_version:1};
  const context = {binding,provider:'printify',provider_account_reference:'synthetic-account',installation_id:'synthetic-wordpress',workload_id:'synthetic-dispatcher',deployment_digest:randomHex(),policy_revision:1,core_source_sha:'78e7f973455a3c89319be06f0907caf2103658a7',owner_proof_reference:randomHex(),owner_session_reference:randomHex()};
  const policy = {authority_id:'synthetic-authority',witness_id:'synthetic-witness',authority_domain:'authority-administrator',witness_domain:'witness-administrator',sender_domain:'sender-administrator',root_set_revision:1,minimum_recovery_revision:1,minimum_generation:1,minimum_root_revision:1};
  const f = {root,storage,clock,context,policy,issuer,witness,calls:0,afterIssue:null,beforeWitness:null,changeCheckpoint:null,changeWitness:null};
  const state = () => { const d=root.active(); return {recovery_epoch:d.epoch,checkpoint_epoch:d.epoch,recovery_revision:d.anchor.revision,generation:d.generation,root_revision:d.revision}; };
  f.ports = {
    synthetic:true,
    async issue(q) {
      f.calls++;
      insist(canonical(q.context)===canonical(f.context),'CORE_CHECKPOINT_STALE');
      root.claim(q.request_id);
      let m={version:'1.0.0',method:'currentCheckpoint',purpose:'DF_INDEPENDENT_CHECKPOINT_AUTHORITY_1',request_id:q.request_id,challenge:q.challenge,context:q.context,authority_id:policy.authority_id,witness_id:policy.witness_id,root_set_revision:policy.root_set_revision,...state(),issued_at:clock.now,expires_at:clock.now+30};
      if(f.changeCheckpoint)m=f.changeCheckpoint(m);
      const envelope=await sign(issuer.privateKey,'native-checkpoint-v1',m);
      if(f.afterIssue)await f.afterIssue();
      return envelope;
    },
    async observe(q) {
      f.calls++;
      if(f.beforeWitness)await f.beforeWitness();
      insist(q.context_hash===await hash(canonical(f.context)),'CORE_CHECKPOINT_STALE');
      const current=state();
      let m={version:'1.0.0',method:'currentCheckpoint',purpose:'DF_INDEPENDENT_RECOVERY_WITNESS_1',request_id:q.request_id,challenge:q.challenge,authority_id:policy.authority_id,witness_id:policy.witness_id,root_set_revision:policy.root_set_revision,...current,witness_sequence:current.root_revision,witness_head:await hash(canonical(root.read(),1048576)),checkpoint_hash:q.checkpoint_hash,context_hash:q.context_hash,state:'ACTIVE',issued_at:clock.now,expires_at:clock.now+30};
      if(f.changeWitness)m=f.changeWitness(m);
      return sign(witness.privateKey,'native-witness-v1',m);
    }
  };
  f.options=()=>({synthetic:true,ports:f.ports,authorityPublic:issuer.publicKey,witnessPublic:witness.publicKey,policy,context,clock:()=>clock.now});
  f.request=()=>({request_id:nonce(),challenge:nonce(),context:structuredClone(context)});
  f.snapshot=()=>structuredClone(storage.db.prepare('SELECT revision,document FROM df_security_authority').get());
  // Deliberately privileged fault injection, not an API exported by any runtime.
  f.restore=s=>storage.db.prepare('UPDATE df_security_authority SET revision=?,document=? WHERE id=1').run(s.revision,s.document);
  return f;
}
