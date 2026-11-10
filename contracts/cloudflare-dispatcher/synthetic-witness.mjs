import {
  canonical,
  hash,
  verify,
  insist,
  freshness,
  bindingHash,
  binding,
  hex,
  CONTRACT_SHA,
  CAPABILITY,
  POLICY_REVISION,
  keys,
  unb64,
} from "./protocol.mjs";
import { inspect } from "./allowlist.mjs";
import { RecoveryWitness } from "./runtime.mjs";
/** Synthetic independent root: lives outside both simulated DO and WordPress stores. */
export class SyntheticWitness extends RecoveryWitness {
  #root;
  #ports;
  #clock;
  constructor(root, ports, clock) {
    super();
    insist(root?.synthetic === true, "LIVE_WITNESS_NOT_IMPLEMENTED");
    this.#root = root;
    this.#ports = ports;
    this.#clock = clock;
  }
  now() {
    const n = this.#clock();
    insist(
      Number.isSafeInteger(n) && n > 0 && n >= this.#root.last_time,
      "WITNESS_CLOCK",
    );
    this.#root.last_time = n;
    return n;
  }
  check(c, active = false) {
    const r = this.#root;
    insist(r.available, "WITNESS_PARTITION");
    for (const k of [
      "epoch",
      "namespace",
      "code",
      "account",
      "credential_binding",
    ])
      insist(r[k] === c[k], "WITNESS_BINDING");
    if (active)
      insist(
        !r.stopped &&
          !r.recovery &&
          !r.credentialRejected &&
          r.pause_until <= this.now(),
        "REVOKED",
      );
    return r;
  }
  async snapshot(c) {
    const r = this.check(c);
    return Object.freeze({
      epoch: r.epoch,
      namespace: r.namespace,
      code: r.code,
      generation: r.generation,
      stopped: r.stopped,
      recovery: r.recovery,
      revision: r.revision,
      hash: r.hash,
      initial_hash: r.initial_hash,
      leases: new Set([...r.leases, ...r.admitted.keys()]).size,
    });
  }
  async checkpoint(rev, old, next, value, envelope, c, doc) {
    const m = await verify(envelope, "state-v1", this.#ports.statePublic);
    const r = this.check(c);
    insist(
      r.revision === rev &&
        r.hash === old &&
        next === rev + 1 &&
        m.previous_hash === old &&
        m.next_hash === value &&
        m.revision === next,
      "ANCHOR_CONFLICT",
    );
    insist(value === (await hash(canonical(doc, 1048576))), "STATE_HASH");
    this.check(c);
    insist(r.revision === rev && r.hash === old, "ANCHOR_CONFLICT");
    if (m.event === "ADMITTED") {
      insist(
        doc.claims[m.correlation]?.state === "ADMITTED" &&
          r.reservations.has(m.correlation),
        "FINAL_ADMISSION",
      );
      r.admitted.set(m.correlation, value);
    }
    if (
      m.event === "TERMINAL" &&
      doc.claims[m.correlation]?.state === "TERMINAL"
    )
      r.terminal.add(m.correlation);
    r.revision = next;
    r.hash = value;
  }
  async register(grant, input, c, _callerNow) {
    keys(input, ["proof", "approval", "scope"]);
    keys(input.scope, ["requests", "ttl", "catalog_grants"]);
    keys(grant, [
      "session",
      "requests",
      "expires_at",
      "owner_proof",
      "review",
      "client_public",
      "proof_nonce",
      "approval_nonce",
      "scope_hash",
    ]);
    let now = this.now();
    const proof = await verify(
        input.proof,
        "owner-identity",
        this.#ports.identityPublic,
      ),
      approval = await verify(
        input.approval,
        "owner-approval",
        this.#ports.approvalPublic,
      );
    keys(proof, [
      "protocol",
      "actor",
      "email_hash",
      "site_hash",
      "client_public",
      "nonce",
      "authenticated_at",
      "issued_at",
      "expires_at",
    ]);
    keys(approval, [
      "protocol",
      "actor",
      "capability",
      "policy_revision",
      "proof_hash",
      "scope_hash",
      "binding_hash",
      "epoch",
      "namespace",
      "code",
      "generation",
      "nonce",
      "issued_at",
      "expires_at",
    ]);
    unb64(proof.client_public, 32);
    insist(
      proof.protocol === "dg-owner-proof-v1" &&
        approval.protocol === "dg-owner-approval-v1" &&
        approval.epoch === c.epoch &&
        approval.namespace === c.namespace &&
        approval.code === c.code &&
        hex(proof.nonce, 32) &&
        hex(approval.nonce, 32),
      "APPROVAL_DOMAIN",
    );
    freshness(proof, now, 120);
    freshness(approval, now, 300);
    insist(
      proof.actor === 1 &&
        Number.isSafeInteger(proof.authenticated_at) &&
        proof.authenticated_at <= now &&
        proof.authenticated_at > now - 600 &&
        proof.email_hash === c.email_hash &&
        proof.site_hash === c.site_hash &&
        proof.client_public === grant.client_public &&
        approval.actor === 1 &&
        approval.capability === CAPABILITY &&
        approval.policy_revision === POLICY_REVISION &&
        approval.proof_hash === (await hash(canonical(input.proof))) &&
        approval.scope_hash === (await hash(canonical(input.scope))) &&
        approval.binding_hash === (await bindingHash(c)) &&
        grant.owner_proof === approval.proof_hash &&
        grant.review === (await hash(canonical(input.approval))),
      "INDEPENDENT_APPROVAL",
    );
    insist(
      input.scope.requests.length === grant.requests.length &&
        grant.expires_at <= now + input.scope.ttl,
      "SCOPE",
    );
    for (let i = 0; i < grant.requests.length; i++) {
      const m = await verify(
        grant.requests[i],
        "read-v1",
        this.#ports.readPublic,
      );
      binding(m, c);
      freshness(m, now);
      insist(
        Number.isSafeInteger(m.owner_verified_at) &&
          m.owner_verified_at > now - 600 &&
          m.owner_verified_at <= now,
        "OWNER_FRESHNESS",
      );
      inspect(m.request);
      insist(
        m.version === 1 &&
          m.protocol === "printify-independent-get-v1" &&
          m.session === grant.session &&
          m.slot === i &&
          m.contract_sha256 === CONTRACT_SHA &&
          m.generation === approval.generation &&
          m.owner_verified_at === proof.authenticated_at &&
          m.owner_proof === grant.owner_proof &&
          m.review === grant.review &&
          m.expires_at === grant.expires_at &&
          m.expires_at <= approval.expires_at &&
          canonical(m.request) === canonical(input.scope.requests[i]) &&
          hex(m.nonce, 32),
        "REGISTERED_SLOT",
      );
      if (m.request.endpoint.includes("/catalog/"))
        insist(
          input.scope.catalog_grants.includes(await hash(canonical(m.request))),
          "CATALOG_GRANT",
        );
    }
    now = this.now();
    freshness(proof, now, 120);
    freshness(approval, now, 300);
    insist(
      proof.authenticated_at > now - 600 && grant.expires_at > now,
      "FINAL_REGISTRATION_EXPIRY",
    );
    const r = this.check(c, true);
    insist(
      !r.proofs.has(proof.nonce) &&
        !r.approvals.has(approval.nonce) &&
        approval.generation === r.generation &&
        grant.requests.length <= 10 &&
        grant.expires_at <= now + 300,
      "REPLAY",
    );
    r.proofs.add(proof.nonce);
    r.approvals.add(approval.nonce);
    r.sessions.set(grant.session, structuredClone(grant));
  }
  async reserve(m, a, id, c, _callerNow, input) {
    let now = this.now();
    const verified = await verify(
      input.authority,
      "read-v1",
      this.#ports.readPublic,
    );
    insist(canonical(verified) === canonical(m), "SIGNED_SLOT");
    binding(m, c);
    freshness(m, now);
    insist(
      Number.isSafeInteger(m.owner_verified_at) &&
        m.owner_verified_at > now - 600 &&
        m.owner_verified_at <= now,
      "OWNER_FRESHNESS",
    );
    inspect(m.request);
    let r = this.check(c, true),
      session = r.sessions.get(m.session);
    insist(
      session &&
        session.requests[m.slot] &&
        canonical(session.requests[m.slot]) === canonical(input.authority) &&
        id === m.session + ":" + m.slot,
      "SIGNED_SLOT",
    );
    const publicKey = await crypto.subtle.importKey(
      "raw",
      Uint8Array.from(atob(session.client_public), (x) => x.charCodeAt(0)),
      "Ed25519",
      false,
      ["verify"],
    );
    const auth = await verify(input.authentication, "client-v1", publicKey);
    freshness(auth, now, 30);
    insist(
      canonical(auth) === canonical(a) &&
        auth.request_hash === (await hash(canonical(input.authority))) &&
        auth.session === m.session &&
        hex(auth.nonce, 32),
      "CLIENT_PROOF",
    );
    const requestHash = await hash(canonical(m.request));
    now = this.now();
    freshness(m, now);
    freshness(a, now, 30);
    r = this.check(c, true);
    insist(
      session.expires_at > now &&
        m.generation === r.generation &&
        !r.used.has(id) &&
        !r.nonces.has(m.nonce) &&
        !r.nonces.has(a.nonce),
      "REPLAY",
    );
    r.rates = r.rates.filter((x) => x > now - 60);
    insist(r.rates.length < 3, "QUOTA");
    r.rates.push(now);
    r.used.add(id);
    r.nonces.add(m.nonce);
    r.nonces.add(a.nonce);
    r.reservations.set(id, {
      ...m,
      state: "RESERVED",
      request_hash: requestHash,
      authentication: { issued_at: a.issued_at, expires_at: a.expires_at },
    });
  }
  async admitAndSend(claim, request, send) {
    const requestHash = await hash(canonical(request));
    const r = this.check(this.#root.config, true),
      reserved = r.reservations.get(claim.id);
    insist(
      reserved?.state === "RESERVED" &&
        reserved.request_hash === requestHash &&
        r.admitted.has(claim.id) &&
        reserved.session === claim.session &&
        reserved.nonce === claim.nonce &&
        claim.generation === r.generation &&
        !r.leases.has(claim.id),
      "FINAL_LEASE",
    );
    const now = this.now();
    freshness(reserved, now);
    insist(
      reserved.owner_verified_at > now - 600 &&
        reserved.owner_verified_at <= now,
      "OWNER_FRESHNESS",
    );
    freshness(reserved.authentication, now, 30);
    insist(r.sessions.get(claim.session)?.expires_at > now, "FINAL_EXPIRY");
    reserved.state = "ADMITTED";
    r.leases.add(claim.id);
    let result = await send();
    this.check(this.#root.config);
    const finishedAt = this.now();
    if (!result.uncertain_inflight) r.physicalDone.add(claim.id);
    if ([401, 403].includes(result.http_status)) r.credentialRejected = true;
    if (result.http_status === 429)
      r.pause_until = finishedAt + result.retry_after;
    if (
      r.stopped ||
      r.recovery ||
      r.generation !== claim.generation ||
      reserved.expires_at <= finishedAt
    )
      result = {
        ...result,
        status: "UNKNOWN",
        observations: { values_disclosed: false },
      };
    return result;
  }

  async finish(id, c) {
    const r = this.check(c);
    insist(
      r.leases.has(id) && r.physicalDone.has(id) && r.terminal.has(id),
      "LEASE_COMPLETION_REQUIRED",
    );
    r.leases.delete(id);
    r.admitted.delete(id);
    r.physicalDone.delete(id);
    r.terminal.delete(id);
    r.reservations.get(id).state = "TERMINAL";
  }
  async stop(m, c, envelope) {
    const verified = await verify(envelope, "stop-v1", this.#ports.stopPublic);
    insist(canonical(verified) === canonical(m), "STOP_SIGNATURE");
    freshness(m, this.now(), 60);
    const r = this.check(c);
    insist(!r.stops.has(m.nonce), "STOP_REPLAY");
    r.stops.add(m.nonce);
    r.stopped = true;
    r.generation++;
    return this.snapshot(c);
  }
  async lock(c) {
    const r = this.check(c);
    r.recovery = true;
    r.stopped = true;
    r.generation++;
    return this.snapshot(c);
  }
}
