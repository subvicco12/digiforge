import { SyntheticFinalSender } from "./sender.mjs";
import { RecoveryWitness, OfflineRuntime } from "./runtime.mjs";
import {
  canonical,
  hash,
  verify,
  sign,
  keys,
  insist,
  freshness,
  hex,
  b64,
  unb64,
} from "./protocol.mjs";
const OPERATIONS = Object.freeze(["SESSION", "EXECUTE", "EMERGENCY"]);
const payloadKeys = {
  SESSION: ["proof", "approval", "scope"],
  EXECUTE: ["authority", "authentication"],
  EMERGENCY: ["authority"],
};
/** Independent final service. Constructor is synthetic-only, with no live transport/custody factory. */
export class IndependentAuthorityService {
  #runtime;
  #root;
  #ports;
  #clock;
  #fenced = false;
  #contexts = new Map();
  constructor({ runtime, root, ports, clock, synthetic = false }) {
    insist(
      synthetic === true && runtime instanceof OfflineRuntime,
      "LIVE_AUTHORITY_NOT_IMPLEMENTED",
    );
    this.#runtime = runtime;
    this.#root = root;
    this.#ports = ports;
    this.#clock = clock;
    root.boot();
    runtime.witness = new FencedWitness(runtime.witness, this, root);
    // Sender and credential ports stay private to the independent service, never the workload client.
    insist(
      ports.custody?.synthetic === true &&
        ports.syntheticFetch?.synthetic === true,
      "MANDATORY_FINAL_SENDER",
    );
    runtime.sender = new AuthorityBoundSender(
      ports.custody,
      ports.syntheticFetch,
      runtime.witness,
      runtime.config,
      this,
    );
  }
  async admission(certificate, custody, active = true) {
    const c = await verify(
      certificate,
      "workload-certificate",
      this.#ports.workloadIssuer,
    );
    keys(c, [
      "protocol",
      "public_key",
      "epoch",
      "namespace",
      "code",
      "version",
      "account",
      "credential_binding",
      "issued_at",
      "expires_at",
    ]);
    const e = await verify(
      custody,
      "custody-evidence",
      this.#ports.custodyIssuer,
    );
    keys(e, [
      "protocol",
      "epoch",
      "account",
      "credential_binding",
      "exclusive",
      "host_copies_invalidated",
      "backup_copies_unusable",
      "direct_fallback_disabled",
      "issued_at",
      "expires_at",
    ]);
    const now = this.#clock();
    freshness(c, now, 60);
    freshness(e, now, 60);
    const d = active ? this.#root.active() : this.#root.read();
    insist(
      c.protocol === "dg-workload-certificate-v1" &&
        e.protocol === "dg-custody-evidence-v1",
      "ATTESTATION_DOMAIN",
    );
    for (const k of ["epoch", "account", "credential_binding"])
      insist(c[k] === d[k] && e[k] === d[k], "ATTESTATION_BINDING");
    insist(
      c.namespace === d.namespace &&
        c.code === d.code &&
        c.public_key === d.workload_key &&
        Number.isSafeInteger(c.version) &&
        c.version >= d.minimum_version,
      "WORKLOAD_FLOOR",
    );
    insist(
      e.exclusive === true &&
        e.host_copies_invalidated === true &&
        e.backup_copies_unusable === true &&
        e.direct_fallback_disabled === true,
      "CUSTODY_BLOCKED",
    );
    unb64(c.public_key, 32);
    return c;
  }
  async guard(id) {
    insist(!this.#fenced, "SERVICE_FENCED");
    const context = this.#contexts.get(id);
    insist(context, "WORKLOAD_CONTEXT");
    await this.admission(context.certificate, context.custody);
    freshness(context.authentication.manifest, this.#clock(), 30);
  }
  async physical(id, send) {
    await this.guard(id);
    // Final independent root read and expiry checks are synchronous with physical callback invocation.
    const context = this.#contexts.get(id),
      d = this.#root.active();
    freshness(context.certificate.manifest, this.#clock(), 60);
    freshness(context.custody.manifest, this.#clock(), 60);
    freshness(context.authentication.manifest, this.#clock(), 30);
    insist(d.leases.includes(id), "ROOT_LEASE_MISSING");
    return send();
  }
  async validateStop(input) {
    const m = await verify(
      input.authority,
      "stop-v1",
      this.#runtime.issuer.ports.stopPublic,
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
    freshness(m, this.#clock(), 60);
    insist(
      m.version === 1 &&
        m.protocol === "printify-independent-stop-v1" &&
        m.actor === 1 &&
        m.authority === this.#runtime.config.authority &&
        m.epoch === this.#runtime.config.epoch &&
        hex(m.nonce, 32) &&
        hex(m.owner_proof) &&
        hex(m.review),
      "STOP_AUTHORITY",
    );
    return m;
  }
  async handle(input) {
    let operation,
      nonce,
      requestHash,
      authorizedStop = false;
    try {
      insist(!this.#fenced, "SERVICE_FENCED");
      keys(input, ["certificate", "custody", "authentication", "payload"]);
      const raw = input.authentication.manifest;
      operation = raw.operation;
      insist(OPERATIONS.includes(operation), "OPERATION");
      keys(input.payload, payloadKeys[operation]);
      canonical(input, 16384);
      if (operation === "EMERGENCY") {
        await this.validateStop(input.payload);
        authorizedStop = true;
      }
      const c = await this.admission(
        input.certificate,
        input.custody,
        operation !== "EMERGENCY",
      );
      const publicKey = await crypto.subtle.importKey(
        "raw",
        unb64(c.public_key, 32),
        "Ed25519",
        false,
        ["verify"],
      );
      const a = await verify(
        input.authentication,
        "workload-request",
        publicKey,
      );
      keys(a, [
        "protocol",
        "operation",
        "nonce",
        "certificate_hash",
        "custody_hash",
        "payload_hash",
        "issued_at",
        "expires_at",
      ]);
      freshness(a, this.#clock(), 30);
      insist(
        a.protocol === "dg-authority-request-v1" &&
          hex(a.nonce, 32) &&
          a.certificate_hash === (await hash(canonical(input.certificate))) &&
          a.custody_hash === (await hash(canonical(input.custody))) &&
          a.payload_hash === (await hash(canonical(input.payload))),
        "WORKLOAD_REQUEST",
      );
      nonce = a.nonce;
      requestHash = await hash(canonical(input));
      // Revalidate after all asynchronous signature/hash work, immediately before atomic nonce use.
      await this.admission(
        input.certificate,
        input.custody,
        operation !== "EMERGENCY",
      );
      freshness(a, this.#clock(), 30);
      this.#root.claim(nonce, operation !== "EMERGENCY");
      let result;
      if (operation === "EMERGENCY") {
        const m = await this.validateStop(input.payload);
        this.#root.claim("stop:" + m.nonce, false);
        this.#root.stop();
        result = await this.#runtime.emergency(input.payload);
        if (
          this.#root.read().leases.length &&
          result.receipt?.manifest.status === "DRAIN_ACKNOWLEDGED"
        )
          result = {
            status: "UNKNOWN",
            receipt_committed: false,
            retry_permitted: false,
          };
      } else if (operation === "SESSION")
        result = await this.#runtime.createSession(input.payload);
      else {
        const m = input.payload.authority.manifest,
          id = m.session + ":" + m.slot;
        insist(!this.#contexts.has(id), "WORKLOAD_REPLAY");
        this.#contexts.set(id, structuredClone(input));
        result = await this.#runtime.dispatch(input.payload);
      }
      const d = this.#root.read();
      return await sign(this.#ports.responsePrivate, "authority-response", {
        protocol: "dg-authority-response-v1",
        nonce,
        request_hash: requestHash,
        epoch: d.epoch,
        generation: d.generation,
        root_revision: d.revision,
        issued_at: this.#clock(),
        expires_at: this.#clock() + 30,
        result,
      });
    } catch {
      // A failure after an authenticated emergency was admitted may be a lost durable acknowledgement.
      if (operation === "EMERGENCY" && authorizedStop) {
        this.#fenced = true;
        try {
          this.#root.fence();
        } catch {}
      }
      return { status: "AUTHORITY_DENIED", retry_permitted: false };
    }
  }
}
class AuthorityBoundSender {
  #custody;
  #fetch;
  #witness;
  #config;
  #service;
  constructor(custody, fetch, witness, config, service) {
    this.#custody = custody;
    this.#fetch = fetch;
    this.#witness = witness;
    this.#config = config;
    this.#service = service;
  }
  check() {
    new SyntheticFinalSender(
      this.#custody,
      this.#fetch,
      this.#witness,
      this.#config,
    ).check();
  }
  send(request, claim) {
    const original = this.#custody,
      provider = this.#fetch,
      service = this.#service,
      id = claim.id,
      generation = original.generation;
    const custody = {
      synthetic: true,
      get available() {
        return original.available;
      },
      get exclusive() {
        return original.exclusive;
      },
      get hostCopiesInvalidated() {
        return original.hostCopiesInvalidated;
      },
      get generation() {
        return original.generation;
      },
      get binding() {
        return original.binding;
      },
      get account() {
        return original.account;
      },
      get shop() {
        return original.shop;
      },
      perform: (fn) => original.perform(() => service.physical(id, fn)),
      syntheticAuthorization: () => original.syntheticAuthorization(),
    };
    const physical = {
      synthetic: true,
      fetch: (...args) => service.physical(id, () => {
        // physical() awaits an independent guard; fence custody again after that await.
        this.check();
        insist(original.generation === generation && args[1]?.signal?.aborted === false, "CUSTODY_REVOKED");
        return provider.fetch(...args);
      }),
    };
    return new SyntheticFinalSender(
      custody,
      physical,
      this.#witness,
      this.#config,
    ).send(request, claim);
  }
}
/** No final-admission Boolean crosses the workload boundary. Sender invocation stays inside this service. */
class FencedWitness extends RecoveryWitness {
  #base;
  #service;
  #root;
  constructor(base, service, root) {
    super();
    this.#base = base;
    this.#service = service;
    this.#root = root;
  }
  async snapshot(c) {
    const w = await this.#base.snapshot(c),
      d = this.#root.read();
    insist(
      w.hash === d.anchor.hash &&
        w.revision === d.anchor.revision &&
        w.epoch === d.epoch &&
        w.namespace === d.namespace &&
        w.code === d.code,
      "INDEPENDENT_ROLLBACK",
    );
    return {
      ...w,
      stopped: w.stopped || d.stopped,
      recovery: w.recovery || d.recovery,
    };
  }
  async checkpoint(rev, old, next, value, envelope, c, doc) {
    await this.snapshot(c);
    await this.#base.checkpoint(rev, old, next, value, envelope, c, doc);
    this.#root.anchor(
      { revision: rev, hash: old },
      { revision: next, hash: value },
    );
  }
  async register(...args) {
    this.#root.active();
    return this.#base.register(...args);
  }
  async reserve(...args) {
    this.#root.active();
    return this.#base.reserve(...args);
  }
  async admitAndSend(claim, request, send) {
    await this.#service.guard(claim.id);
    return this.#base.admitAndSend(claim, request, async () => {
      await this.#service.guard(claim.id);
      this.#root.lease(claim.id);
      return send();
    });
  }
  async finish(id, c) {
    await this.#base.finish(id, c);
    this.#root.complete(id);
  }
  async stop(...args) {
    return this.#base.stop(...args);
  }
  async lock(...args) {
    this.#root.fence();
    return this.#base.lock(...args);
  }
}
/** No URLs, headers, credentials, provider callbacks or authorization Boolean are accepted/returned. */
export class IndependentAuthorityClient {
  #port;
  #keys;
  #cert;
  #custody;
  #epoch;
  #clock;
  #lastRevision = -1;
  #lastGeneration = 1;
  constructor({ port, keys, certificate, custody, epoch, clock }) {
    insist(
      port?.synthetic === true && typeof port.send === "function",
      "LIVE_AUTHORITY_CLIENT_NOT_IMPLEMENTED",
    );
    this.#port = port;
    this.#keys = keys;
    this.#cert = certificate;
    this.#custody = custody;
    this.#epoch = epoch;
    this.#clock = clock;
  }
  async message(operation, payload) {
    insist(OPERATIONS.includes(operation), "OPERATION");
    keys(payload, payloadKeys[operation]);
    const certificate = structuredClone(this.#cert),
      custody = structuredClone(this.#custody);
    return {
      certificate,
      custody,
      payload: structuredClone(payload),
      authentication: await sign(
        this.#keys.workloadPrivate,
        "workload-request",
        {
          protocol: "dg-authority-request-v1",
          operation,
          nonce: crypto.randomUUID().replaceAll("-", ""),
          certificate_hash: await hash(canonical(certificate)),
          custody_hash: await hash(canonical(custody)),
          payload_hash: await hash(canonical(payload)),
          issued_at: this.#clock(),
          expires_at: this.#clock() + 30,
        },
      ),
    };
  }
  async call(operation, payload) {
    const input = await this.message(operation, payload);
    let timer;
    const deadline = new Promise((_, reject) => {
      timer = setTimeout(
        () => reject(Error("AUTHORITY_TIMEOUT_UNKNOWN")),
        20000,
      );
    });
    let response;
    try {
      response = await Promise.race([this.#port.send(input), deadline]);
    } catch (error) {
      throw Error(
        error?.message === "AUTHORITY_TIMEOUT_UNKNOWN"
          ? "AUTHORITY_TIMEOUT_UNKNOWN"
          : "AUTHORITY_UNAVAILABLE_UNKNOWN",
      );
    } finally {
      clearTimeout(timer);
    }
    const m = await verify(
      response,
      "authority-response",
      this.#keys.responsePublic,
    );
    keys(m, [
      "protocol",
      "nonce",
      "request_hash",
      "epoch",
      "generation",
      "root_revision",
      "issued_at",
      "expires_at",
      "result",
    ]);
    freshness(m, this.#clock(), 30);
    insist(
      m.protocol === "dg-authority-response-v1" &&
        m.nonce === input.authentication.manifest.nonce &&
        m.request_hash === (await hash(canonical(input))) &&
        m.epoch === this.#epoch &&
        Number.isSafeInteger(m.root_revision) &&
        m.root_revision >= this.#lastRevision &&
        Number.isSafeInteger(m.generation) &&
        m.generation >= this.#lastGeneration,
      "AUTHORITY_RESPONSE",
    );
    this.#lastRevision = m.root_revision;
    this.#lastGeneration = m.generation;
    return structuredClone(m.result);
  }
}
