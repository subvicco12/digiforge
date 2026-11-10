// Test process only: any accidental use of the real fetch implementation fails.
globalThis.fetch = () => {
  throw Error("LIVE_NETWORK_FORBIDDEN_IN_TESTS");
};
import { DatabaseSync } from "node:sqlite";
import {
  canonical,
  hash,
  sign,
  verify,
  insist,
  freshness,
  b64,
  bindingHash,
  binding,
  hex,
  CONTRACT_SHA,
  CAPABILITY,
  POLICY_REVISION,
} from "../protocol.mjs";
import { inspect } from "../allowlist.mjs";
import { SqliteState, initialState } from "../sqlite-state.mjs";
import { OfflineRuntime } from "../runtime.mjs";
import { IndependentIssuer } from "../issuer.mjs";
import { SyntheticFinalSender } from "../sender.mjs";
import worker from "../worker.mjs";
import { SyntheticWitness } from "../synthetic-witness.mjs";

export class LocalSqliteStorage {
  constructor(filename = ":memory:") {
    this.db = new DatabaseSync(filename);
    this.available = true;
    this.loseAck = false;
    this.sql = {
      exec: (query, ...params) => {
        insist(this.available, "STORAGE_OUTAGE");
        if (/^(INSERT|UPDATE|DELETE|CREATE)/.test(query)) this.changed = true;
        const stmt = this.db.prepare(query);
        const read = stmt.columns().length > 0;
        const rows = read ? stmt.all(...params) : [];
        if (!read) stmt.run(...params);
        return { toArray: () => rows };
      },
    };
  }
  transactionSync(fn) {
    insist(this.available, "STORAGE_OUTAGE");
    this.changed = false;
    this.db.exec("BEGIN IMMEDIATE");
    let result;
    try {
      result = fn();
      this.db.exec("COMMIT");
    } catch (e) {
      this.db.exec("ROLLBACK");
      throw e;
    }
    if (this.loseAck && this.changed) {
      this.loseAck = false;
      throw Error("ACK_LOST");
    }
    return result;
  }
  snapshot() {
    return {
      state: this.db.prepare("SELECT * FROM df_state").all(),
      events: this.db.prepare("SELECT * FROM df_events").all(),
    };
  }
  restore(v) {
    this.db.exec("DELETE FROM df_state; DELETE FROM df_events");
    for (const x of v.state)
      this.db
        .prepare("INSERT INTO df_state VALUES(?,?,?)")
        .run(x.id, x.revision, x.document);
    for (const x of v.events)
      this.db
        .prepare("INSERT INTO df_events VALUES(?,?)")
        .run(x.revision, x.envelope);
  }
}
export async function fixture({ startup = true } = {}) {
  const pair = () =>
    crypto.subtle.generateKey("Ed25519", true, ["sign", "verify"]);
  const identity = await pair(),
    approval = await pair(),
    read = await pair(),
    stop = await pair(),
    stateKeys = await pair(),
    receipt = await pair(),
    client = await pair(),
    untrusted = await pair();
  const c = {
    authority: "synthetic-independent-authority",
    epoch: "e".repeat(64),
    namespace: "d".repeat(64),
    code: "c".repeat(64),
    account: "synthetic-printify-account",
    credential_binding: "a".repeat(64),
    email_hash: await hash("fixture-owner@example.invalid"),
    site_hash: await hash("https://fixture.invalid/"),
  };
  const clock = { now: 1000 },
    storage = new LocalSqliteStorage(),
    state = new SqliteState(storage);
  state.bootstrap(initialState(c));
  const h = await hash(canonical(state.read().doc, 1048576));
  const root = {
    ...c,
    synthetic: true,
    last_time: clock.now,
    config: structuredClone(c),
    available: true,
    generation: 1,
    stopped: false,
    recovery: false,
    revision: 0,
    hash: h,
    initial_hash: h,
    sessions: new Map(),
    pause_until: 0,
    credentialRejected: false,
    proofs: new Set(),
    approvals: new Set(),
    stops: new Set(),
    used: new Set(),
    nonces: new Set(),
    rates: [],
    reservations: new Map(),
    admitted: new Map(),
    physicalDone: new Set(),
    terminal: new Set(),
    leases: new Set(),
  };
  const ports = {
    identityPublic: identity.publicKey,
    approvalPublic: approval.publicKey,
    readPrivate: read.privateKey,
    readPublic: read.publicKey,
    stopPublic: stop.publicKey,
    statePublic: stateKeys.publicKey,
  };
  const witness = new SyntheticWitness(
      root,
      {
        identityPublic: ports.identityPublic,
        approvalPublic: ports.approvalPublic,
        readPublic: ports.readPublic,
        stopPublic: ports.stopPublic,
        statePublic: ports.statePublic,
      },
      () => clock.now,
    ),
    issuer = new IndependentIssuer(c, ports),
    calls = [];
  const provider = {
    mode: "normal",
    status: 200,
    failure: false,
    during: null,
  };
  const syntheticFetch = {
    synthetic: true,
    async fetch(url, options) {
      calls.push({ url, options });
      if (provider.fetchOverride) return provider.fetchOverride(url, options);
      if (provider.during) await provider.during();
      if (provider.failure) throw Error("private-exception");
      if (provider.mode === "redirect")
        return new Response("{}", { status: 302 });
      if (provider.mode === "invalid") return new Response("{");
      if (provider.mode === "oversized")
        return new Response("x".repeat(1048577));
      return new Response('[{"id":28930593,"buyer":"buyer-private"}]', {
        status: provider.status,
        headers: { "Retry-After": "120" },
      });
    },
  };
  const custody = {
    synthetic: true,
    available: true,
    exclusive: true,
    hostCopiesInvalidated: true,
    binding: c.credential_binding,
    account: c.account,
    shop: "28930593",
    perform: (fn) => fn(),
    syntheticAuthorization: () => "Bearer SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL",
  };
  const hooks = new Map();
  const signer = {
    publicKey: stateKeys.publicKey,
    async sign(m) {
      if (hooks.has(m.event)) {
        const fn = hooks.get(m.event);
        hooks.delete(m.event);
        await fn();
      }
      return sign(stateKeys.privateKey, "state-v1", m);
    },
    receipt: (m) => sign(receipt.privateKey, "receipt-v1", m),
  };
  const sender = new SyntheticFinalSender(custody, syntheticFetch, witness, c),
    runtime = new OfflineRuntime({
      state,
      witness,
      issuer,
      sender,
      signer,
      config: c,
      clock: () => clock.now,
    });
  if (startup) insist(await runtime.startup(), "FIXTURE_START");
  const clientPublic = b64(
    await crypto.subtle.exportKey("raw", client.publicKey),
  );
  const f = {
    runtime,
    state,
    storage,
    witness,
    root,
    custody,
    syntheticFetch,
    provider,
    calls,
    clock,
    config: c,
    worker,
    onEvent: (k, fn) => hooks.set(k, fn),
  };
  f.ownerAdmission = async (count = 1, requests = null) => {
    const scope = {
      requests:
        requests ||
        Array.from({ length: count }, () => ({
          operation: "SHOPS_READ",
          method: "GET",
          endpoint: "/v1/shops.json",
          body: [],
        })),
      ttl: 300,
      catalog_grants: [],
    };
    const proof = await sign(identity.privateKey, "owner-identity", {
      protocol: "dg-owner-proof-v1",
      actor: 1,
      email_hash: c.email_hash,
      site_hash: c.site_hash,
      client_public: clientPublic,
      nonce: crypto.randomUUID().replaceAll("-", ""),
      authenticated_at: clock.now,
      issued_at: clock.now,
      expires_at: clock.now + 120,
    });
    const approve = await sign(approval.privateKey, "owner-approval", {
      protocol: "dg-owner-approval-v1",
      actor: 1,
      capability: CAPABILITY,
      policy_revision: POLICY_REVISION,
      proof_hash: await hash(canonical(proof)),
      scope_hash: await hash(canonical(scope)),
      binding_hash: await bindingHash(c),
      epoch: c.epoch,
      namespace: c.namespace,
      code: c.code,
      generation: root.generation,
      nonce: crypto.randomUUID().replaceAll("-", ""),
      issued_at: clock.now,
      expires_at: clock.now + 300,
    });
    return { proof, approval: approve, scope };
  };
  f.session = async (n = 1) => runtime.createSession(await f.ownerAdmission(n));
  f.message = async (s, slot, overrides = {}) => {
    let authority = s.requests[slot];
    if (Object.keys(overrides).length)
      authority = await sign(read.privateKey, "read-v1", {
        ...authority.manifest,
        ...overrides,
      });
    const authentication = await sign(client.privateKey, "client-v1", {
      protocol: "dg-ingress-v1",
      session: s.session,
      request_hash: await hash(canonical(authority)),
      nonce: crypto.randomUUID().replaceAll("-", ""),
      issued_at: clock.now,
      expires_at: clock.now + 30,
    });
    return { authority, authentication };
  };
  f.dispatch = async (s, i, o = {}) =>
    runtime.dispatch(await f.message(s, i, o));
  f.stopMessage = async () => ({
    authority: await sign(stop.privateKey, "stop-v1", {
      version: 1,
      protocol: "printify-independent-stop-v1",
      authority: c.authority,
      epoch: c.epoch,
      actor: 1,
      nonce: crypto.randomUUID().replaceAll("-", ""),
      owner_proof: "f".repeat(64),
      review: "b".repeat(64),
      issued_at: clock.now,
      expires_at: clock.now + 60,
    }),
  });
  f.stop = async () => runtime.emergency(await f.stopMessage());
  f.verifyReceipt = async (e) => {
    await verify(e, "receipt-v1", receipt.publicKey);
    return true;
  };
  f.resignIdentity = (m) => sign(identity.privateKey, "owner-identity", m);
  f.resignApproval = (m) => sign(approval.privateKey, "owner-approval", m);
  f.signWithUntrusted = (m) => sign(untrusted.privateKey, "owner-identity", m);
  f.http = (path, payload, method = "POST", extra = {}) =>
    worker.fetch(
      new Request("https://dispatcher.fixture.invalid" + path, {
        method,
        headers: { "Content-Type": "application/json", ...extra },
        ...(method === "GET" ? {} : { body: canonical(payload) }),
      }),
      { OFFLINE_RUNTIME: runtime },
    );
  return f;
}
