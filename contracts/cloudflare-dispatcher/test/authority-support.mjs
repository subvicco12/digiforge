import { fixture, LocalSqliteStorage } from "./support.mjs";
import { AuthorityJournal } from "../authority-journal.mjs";
import {
  IndependentAuthorityService,
  IndependentAuthorityClient,
} from "../authority-service.mjs";
import { SyntheticFinalSender } from "../sender.mjs";
import { b64, sign } from "../protocol.mjs";
export async function authorityFixture() {
  const base = await fixture(),
    pair = () => crypto.subtle.generateKey("Ed25519", true, ["sign", "verify"]);
  const workload = await pair(),
    issuer = await pair(),
    custodian = await pair(),
    response = await pair(),
    untrusted = await pair();
  const storage = new LocalSqliteStorage(),
    root = new AuthorityJournal(storage, () => base.clock.now),
    publicKey = b64(await crypto.subtle.exportKey("raw", workload.publicKey));
  root.initialize(
    base.config,
    { revision: base.root.revision, hash: base.root.hash },
    publicKey,
  );
  const certificate = await sign(issuer.privateKey, "workload-certificate", {
    protocol: "dg-workload-certificate-v1",
    public_key: publicKey,
    epoch: base.config.epoch,
    namespace: base.config.namespace,
    code: base.config.code,
    version: 1,
    account: base.config.account,
    credential_binding: base.config.credential_binding,
    issued_at: base.clock.now,
    expires_at: base.clock.now + 60,
  });
  const custody = await sign(custodian.privateKey, "custody-evidence", {
    protocol: "dg-custody-evidence-v1",
    epoch: base.config.epoch,
    account: base.config.account,
    credential_binding: base.config.credential_binding,
    exclusive: true,
    host_copies_invalidated: true,
    backup_copies_unusable: true,
    direct_fallback_disabled: true,
    issued_at: base.clock.now,
    expires_at: base.clock.now + 60,
  });
  const ports = {
    workloadIssuer: issuer.publicKey,
    custodyIssuer: custodian.publicKey,
    responsePrivate: response.privateKey,
    custody: base.custody,
    syntheticFetch: base.syntheticFetch,
  };
  const makeService = () =>
    new IndependentAuthorityService({
      runtime: base.runtime,
      root,
      ports,
      clock: () => base.clock.now,
      synthetic: true,
    });
  const initialRoot = root.read();
  const service = makeService(),
    port = { synthetic: true, send: (m) => service.handle(m) },
    keys = {
      workloadPrivate: workload.privateKey,
      responsePublic: response.publicKey,
    };
  const makeClient = (overrides = {}) =>
    new IndependentAuthorityClient({
      port,
      keys,
      certificate,
      custody,
      epoch: base.config.epoch,
      clock: () => base.clock.now,
      ...overrides,
    });
  return {
    base,
    initialRoot,
    storage,
    root,
    service,
    client: makeClient(),
    makeClient,
    makeService,
    port,
    certificate,
    custody,
    keys,
    resignCertificate: (m) =>
      sign(issuer.privateKey, "workload-certificate", m),
    resignCustody: (m) => sign(custodian.privateKey, "custody-evidence", m),
    signResponse: (m) => sign(response.privateKey, "authority-response", m),
    untrusted: async (m) =>
      sign(untrusted.privateKey, "workload-certificate", m),
  };
}
