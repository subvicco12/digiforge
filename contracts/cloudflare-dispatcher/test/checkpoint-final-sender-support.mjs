import { authorityFixture } from './authority-support.mjs';
import { checkpointFixture } from './native-checkpoint-support.mjs';
import { SyntheticNativeCheckpointObserver } from '../native-checkpoint.mjs';
import { canonical, hash } from '../protocol.mjs';

export async function checkpointSenderFixture(options = {}) {
  const { SyntheticCheckpointFinalSender } = await import('../checkpoint-final-sender.mjs');
  let checkpoint, serviceArguments;
  const build = async ({ base, storage, prepared, ...service }) => {
      serviceArguments={base,storage,prepared,...service};
      checkpoint = await checkpointFixture({ root: service.root, clock: base.clock, storage, recoveryRevision: () => 1 });
      const m = prepared.payload.authority.manifest;
      Object.assign(checkpoint.context, { provider_account_reference: base.config.account, deployment_digest: base.config.code, owner_proof_reference: m.owner_proof, owner_session_reference: await hash(m.session) });
      Object.assign(checkpoint.context.binding, { operation_id: await hash(m.session + ':' + m.slot), payload_fingerprint: await hash(canonical(m.request)), intent_fingerprint: await hash(canonical(prepared.payload)), approval_reference: m.review });
      const observer = new SyntheticNativeCheckpointObserver({...checkpoint.options(),ports:{synthetic:true,issue:q=>checkpoint.ports.issue(q),observe:q=>checkpoint.ports.observe(q)}});
      return new SyntheticCheckpointFinalSender({ ...service, mode: options.mode, observer, context: checkpoint.context, payload: prepared.payload });
  };
  const f = await authorityFixture({
    beforeAuthority: async base => {
      const session = await base.session();
      const payload=await base.message(session, 0);
      if(options.tamperPrepared)options.tamperPrepared(payload);
      return { session, payload };
    },
    serviceFactory: build
  });
  f.checkpoint = checkpoint;
  f.payload = () => ({ ...structuredClone(f.prepared.payload), checkpoint: checkpoint.request() });
  f.execute = async payload => {
    try { return await f.client.call('CHECKPOINT_EXECUTE', payload || f.payload()); }
    catch { return { status: 'UNKNOWN', receipt_committed: false, retry_permitted: false }; }
  };
  f.recreate = async () => {
    f.base.runtime.witness=f.base.witness;
    f.service=await build(serviceArguments);
    f.checkpoint=checkpoint;
    f.client=f.makeClient({port:{synthetic:true,send:m=>f.service.handle(m)}});
  };
  return f;
}
