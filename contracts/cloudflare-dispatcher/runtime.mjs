import {
  canonical,
  hash,
  verify,
  sign,
  keys,
  insist,
  hex,
  freshness,
  binding,
  CONTRACT_SHA,
} from "./protocol.mjs";
import { inspect } from "./allowlist.mjs";
/** External witness is mandatory. This abstract port NEVER supplies local fallback. */
export class RecoveryWitness {
  async snapshot() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async checkpoint() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async register() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async reserve() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async stop() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async lock() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async admitAndSend() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
  async finish() {
    throw Error("RECOVERY_UNQUALIFIED");
  }
}
export class OfflineRuntime {
  constructor({ state, witness, issuer, sender, signer, config, clock }) {
    Object.assign(this, {
      state,
      witness,
      issuer,
      sender,
      signer,
      config,
      clock,
    });
    this.mode = "TESTED_SYNTHETIC";
    this.ready = false;
    this.fenced = false;
  }
  now() {
    const n = this.clock();
    insist(Number.isSafeInteger(n) && n > 0, "CLOCK");
    return n;
  }
  async checked(active = true) {
    insist(!this.fenced, "RECOVERY_REQUIRED");
    const { doc, events } = this.state.read(),
      c = this.config,
      w = await this.witness.snapshot(c);
    insist(
      doc.version === 1 &&
        doc.epoch === c.epoch &&
        doc.namespace === c.namespace &&
        doc.code === c.code,
      "DEPLOYMENT_BINDING",
    );
    const currentHash = await hash(canonical(doc, 1048576));
    insist(
      w.revision === doc.revision && w.hash === currentHash,
      "STATE_ROLLBACK",
    );
    let previous = w.initial_hash;
    for (let i = 0; i < events.length; i++) {
      const e = await verify(events[i], "state-v1", this.signer.publicKey);
      insist(
        e.protocol === "dg-state-v1" &&
          e.revision === i + 1 &&
          e.previous_hash === previous,
        "AUDIT_CORRUPT",
      );
      previous = e.next_hash;
    }
    insist(previous === currentHash, "AUDIT_CORRUPT");
    for (const claim of Object.values(doc.claims))
      insist(
        ["RESERVED", "ADMITTED", "TERMINAL"].includes(claim.state),
        "CLAIM_CORRUPT",
      );
    if (active)
      insist(
        this.ready &&
          !doc.recovery_required &&
          !doc.stopped &&
          !w.stopped &&
          !w.recovery &&
          doc.generation === w.generation &&
          doc.pause_until <= this.now(),
        "AUTHORITY_INACTIVE",
      );
    return { doc, w, hash: currentHash };
  }
  async commit(snapshot, next, event, correlation = "none") {
    next.revision = snapshot.doc.revision + 1;
    const nextHash = await hash(canonical(next, 1048576));
    const entry = await this.signer.sign({
      protocol: "dg-state-v1",
      revision: next.revision,
      event,
      previous_hash: snapshot.hash,
      next_hash: nextHash,
      generation: next.generation,
      correlation,
      timestamp: this.now(),
    });
    // Recheck witness after asynchronous signing, immediately before synchronous CAS.
    const w = await this.witness.snapshot(this.config);
    insist(
      w.revision === snapshot.doc.revision && w.hash === snapshot.hash,
      "CONCURRENT_CHANGE",
    );
    if (!["EMERGENCY", "TERMINAL", "START", "RESTART"].includes(event))
      insist(
        !w.stopped && !w.recovery && next.generation === w.generation,
        "REVOKED",
      );
    this.state.cas(snapshot.doc.revision, next, entry);
    await this.witness.checkpoint(
      snapshot.doc.revision,
      snapshot.hash,
      next.revision,
      nextHash,
      entry,
      this.config,
      next,
    );
  }
  async startup() {
    try {
      const s = await this.checked(false);
      if (s.doc.booted) {
        const w = await this.witness.lock(this.config);
        const n = structuredClone(s.doc);
        n.recovery_required = true;
        n.stopped = true;
        n.generation = w.generation;
        await this.commit(s, n, "RESTART");
        this.fenced = true;
        this.ready = false;
        return false;
      }
      const n = structuredClone(s.doc);
      n.booted = true;
      await this.commit(s, n, "START");
      this.ready = true;
      return true;
    } catch {
      this.fenced = true;
      return false;
    }
  }
  async createSession(input) {
    try {
      const s = await this.checked();
      this.sender.check();
      const grant = await this.issuer.prepare(
        input,
        this.now(),
        s.doc.generation,
      );
      await this.witness.register(grant, input, this.config, this.now());
      const n = structuredClone(s.doc);
      insist(Object.keys(n.sessions).length < 32, "SESSION_LIMIT");
      n.sessions[grant.session] = {
        expires_at: grant.expires_at,
        owner_proof: grant.owner_proof,
        review: grant.review,
        client_public: grant.client_public,
        requests: await Promise.all(
          grant.requests.map((x) => hash(canonical(x))),
        ),
        revoked: false,
      };
      await this.commit(s, n, "SESSION", grant.session);
      await this.checked();
      return {
        status: "ISSUED",
        session: grant.session,
        requests: grant.requests,
        expires_at: grant.expires_at,
      };
    } catch {
      return { status: "DENIED", retry_permitted: false };
    }
  }
  async authorize(input) {
    keys(input, ["authority", "authentication"]);
    const m = await verify(
      input.authority,
      "read-v1",
      this.issuer.ports.readPublic,
    );
    keys(m, [
      "version",
      "protocol",
      "authority",
      "epoch",
      "generation",
      "session",
      "nonce",
      "slot",
      "actor",
      "owner_proof",
      "review",
      "shop_key",
      "internal_shop_id",
      "integration_id",
      "account",
      "provider_shop_id",
      "credential_binding",
      "issued_at",
      "expires_at",
      "owner_verified_at",
      "request",
      "catalog_grant",
      "contract_sha256",
    ]);
    insist(
      m.version === 1 &&
        m.protocol === "printify-independent-get-v1" &&
        m.contract_sha256 === CONTRACT_SHA &&
        hex(m.session, 32) &&
        hex(m.nonce, 32) &&
        Number.isInteger(m.slot) &&
        m.slot >= 0 &&
        m.slot < 10,
      "SCHEMA",
    );
    binding(m, this.config);
    freshness(m, this.now());
    inspect(m.request);
    insist(
      Number.isSafeInteger(m.owner_verified_at) &&
        m.owner_verified_at <= this.now() &&
        m.owner_verified_at > this.now() - 600,
      "OWNER_PROOF",
    );
    const s = await this.checked(),
      session = s.doc.sessions[m.session];
    insist(
      session &&
        !session.revoked &&
        Number.isSafeInteger(session.expires_at) &&
        session.expires_at > this.now() &&
        session.expires_at >= m.expires_at &&
        session.owner_proof === m.owner_proof &&
        session.review === m.review &&
        session.requests[m.slot] === (await hash(canonical(input.authority))) &&
        m.generation === s.doc.generation,
      "SESSION",
    );
    const publicKey = await crypto.subtle.importKey(
      "raw",
      Uint8Array.from(atob(session.client_public), (x) => x.charCodeAt(0)),
      "Ed25519",
      false,
      ["verify"],
    );
    const a = await verify(input.authentication, "client-v1", publicKey);
    keys(a, [
      "protocol",
      "session",
      "request_hash",
      "nonce",
      "issued_at",
      "expires_at",
    ]);
    freshness(a, this.now(), 30);
    insist(
      a.protocol === "dg-ingress-v1" &&
        a.session === m.session &&
        a.request_hash === (await hash(canonical(input.authority))) &&
        hex(a.nonce, 32),
      "AUTHENTICATION",
    );
    return { m, a, s };
  }
  async dispatch(input) {
    let sent = false;
    try {
      const { m, a } = await this.authorize(input);
      this.sender.check();
      let s = await this.checked();
      const id = m.session + ":" + m.slot;
      await this.witness.reserve(m, a, id, this.config, this.now(), input);
      let n = structuredClone(s.doc);
      insist(
        !n.claims[id] && !n.nonces[m.nonce] && !n.nonces[a.nonce],
        "REPLAY",
      );
      n.rates = n.rates.filter((t) => t > this.now() - 60);
      insist(n.rates.length < 3, "QUOTA");
      n.rates.push(this.now());
      n.nonces[m.nonce] = true;
      n.nonces[a.nonce] = true;
      n.claims[id] = {
        state: "RESERVED",
        generation: m.generation,
        session: m.session,
      };
      await this.commit(s, n, "RESERVED", id);
      s = await this.checked();
      n = structuredClone(s.doc);
      insist(n.claims[id].state === "RESERVED", "REPLAY");
      n.claims[id].state = "ADMITTED";
      await this.commit(s, n, "ADMITTED", id);
      // Only the final service starts physical GET, with a witness-owned lease.
      sent = true;
      const result = await this.sender.send(m.request, {
        id,
        generation: m.generation,
        session: m.session,
        nonce: m.nonce,
      });
      s = await this.checked(false);
      n = structuredClone(s.doc);
      const w = await this.witness.snapshot(this.config);
      if (
        w.stopped ||
        w.recovery ||
        w.generation !== m.generation ||
        m.expires_at <= this.now()
      ) {
        result.status = "UNKNOWN";
        result.observations = { values_disclosed: false };
      }
      const receipt = await this.signer.receipt({
        status: result.status,
        operation: m.request.operation,
        ...inspect(m.request),
        scope: {
          shop: 2,
          integration: 3,
          provider_shop: "28930593",
          account_sha256: await hash(m.account),
        },
        generation: m.generation,
        correlation: await hash(id),
        timestamp: this.now(),
        http_status: result.http_status,
        observations: result.observations,
        qualification: "TESTED_SYNTHETIC",
        compatibility: "UNVERIFIED",
      });
      n.claims[id].state = result.uncertain_inflight ? "ADMITTED" : "TERMINAL";
      n.claims[id].receipt = receipt;
      n.claims[id].uncertain_inflight = Boolean(result.uncertain_inflight);
      n.pause_until = result.retry_after
        ? this.now() + result.retry_after
        : n.pause_until;
      await this.commit(s, n, "TERMINAL", id);
      if (!result.uncertain_inflight)
        await this.witness.finish(id, this.config);
      if ([401, 403].includes(result.http_status)) await this.quarantine();
      return { receipt, receipt_committed: true, retry_permitted: false };
    } catch {
      return {
        status: sent ? "UNKNOWN" : "DENIED",
        receipt_committed: false,
        retry_permitted: false,
      };
    }
  }
  async quarantine() {
    this.fenced = true;
    this.ready = false;
    try {
      await this.witness.lock(this.config);
    } catch {}
    try {
      const s = this.state.read(),
        n = structuredClone(s.doc);
      n.revision++;
      n.recovery_required = true;
      n.stopped = true;
      const envelope = await this.signer.sign({
        protocol: "dg-state-v1",
        revision: n.revision,
        event: "RECOVERY_REQUIRED",
        previous_hash: await hash(canonical(s.doc, 1048576)),
        next_hash: await hash(canonical(n, 1048576)),
        generation: n.generation,
        correlation: "unacknowledged-emergency",
        timestamp: this.now(),
      });
      this.state.cas(s.doc.revision, n, envelope);
    } catch {}
  }
  async emergency(input) {
    let authorized = false;
    try {
      keys(input, ["authority"]);
      const m = await verify(
        input.authority,
        "stop-v1",
        this.issuer.ports.stopPublic,
      );
      keys(m, [
        "version",
        "protocol",
        "authority",
        "epoch",
        "actor",
        "nonce",
        "owner_proof",
        "review",
        "issued_at",
        "expires_at",
      ]);
      freshness(m, this.now(), 60);
      insist(
        m.version === 1 &&
          m.protocol === "printify-independent-stop-v1" &&
          m.actor === 1 &&
          m.authority === this.config.authority &&
          m.epoch === this.config.epoch &&
          hex(m.nonce, 32) &&
          hex(m.owner_proof) &&
          hex(m.review),
        "STOP_AUTHORITY",
      );
      authorized = true;
      // Independent intent FIRST. DO write failure cannot undo this synthetic latch.
      const w = await this.witness.stop(m, this.config, input.authority);
      const s = await this.checked(false),
        n = structuredClone(s.doc);
      n.stopped = true;
      n.generation = w.generation;
      await this.commit(s, n, "EMERGENCY", m.nonce);
      const after = await this.witness.snapshot(this.config);
      const pending =
        after.recovery ||
        n.recovery_required ||
        after.leases > 0 ||
        Object.values(n.claims).some((x) => x.state === "ADMITTED");
      return {
        receipt: await this.signer.receipt({
          status: pending ? "DRAIN_PENDING" : "DRAIN_ACKNOWLEDGED",
          generation: after.generation,
          timestamp: this.now(),
          qualification: "TESTED_SYNTHETIC",
        }),
        receipt_committed: true,
        durable_revocation_recorded: true,
      };
    } catch {
      if (authorized) await this.quarantine();
      return {
        status: "UNKNOWN",
        receipt_committed: false,
        durable_revocation_recorded: false,
        retry_permitted: false,
      };
    }
  }
}
