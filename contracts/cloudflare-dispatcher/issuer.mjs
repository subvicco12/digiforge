import {
  keys,
  insist,
  verify,
  sign,
  hash,
  canonical,
  freshness,
  binding,
  hex,
  CONTRACT_SHA,
  bindingHash,
  CAPABILITY,
  POLICY_REVISION,
  unb64,
} from "./protocol.mjs";
import { inspect } from "./allowlist.mjs";
/** An external issuer interface. Keys/authentication MUST NOT be restored with WordPress. */
export class IndependentIssuer {
  constructor(config, ports) {
    this.config = config;
    this.ports = ports;
  }
  async prepare(input, now, generation) {
    keys(input, ["proof", "approval", "scope"]);
    keys(input.scope, ["requests", "ttl", "catalog_grants"]);
    const c = this.config,
      p = await verify(
        input.proof,
        "owner-identity",
        this.ports.identityPublic,
      );
    keys(p, [
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
    freshness(p, now, 120);
    insist(
      p.protocol === "dg-owner-proof-v1" &&
        p.actor === 1 &&
        p.email_hash === c.email_hash &&
        p.site_hash === c.site_hash &&
        hex(p.nonce, 32) &&
        Number.isSafeInteger(p.authenticated_at) &&
        p.authenticated_at <= now &&
        p.authenticated_at > now - 600,
      "OWNER_PROOF",
    );
    unb64(p.client_public, 32);
    const a = await verify(
      input.approval,
      "owner-approval",
      this.ports.approvalPublic,
    );
    keys(a, [
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
    freshness(a, now, 300);
    insist(
      a.protocol === "dg-owner-approval-v1" &&
        a.capability === CAPABILITY &&
        a.policy_revision === POLICY_REVISION &&
        a.actor === 1 &&
        a.proof_hash === (await hash(canonical(input.proof))) &&
        a.scope_hash === (await hash(canonical(input.scope))) &&
        a.binding_hash === (await bindingHash(c)) &&
        a.epoch === c.epoch &&
        a.namespace === c.namespace &&
        a.code === c.code &&
        a.generation === generation &&
        hex(a.nonce, 32),
      "OWNER_APPROVAL",
    );
    const scope = input.scope;
    insist(
      Array.isArray(scope.requests) &&
        scope.requests.length >= 1 &&
        scope.requests.length <= 10 &&
        Number.isSafeInteger(scope.ttl) &&
        scope.ttl >= 1 &&
        scope.ttl <= 300 &&
        Array.isArray(scope.catalog_grants),
      "SCOPE",
    );
    for (const r of scope.requests) {
      inspect(r);
      if (r.endpoint.includes("/catalog/"))
        insist(
          scope.catalog_grants.includes(await hash(canonical(r))),
          "CATALOG_GRANT",
        );
    }
    const session = Array.from(
      crypto.getRandomValues(new Uint8Array(16)),
      (x) => x.toString(16).padStart(2, "0"),
    ).join("");
    const ownerProof = await hash(canonical(input.proof)),
      review = await hash(canonical(input.approval)),
      expires = Math.min(now + scope.ttl, a.expires_at),
      requests = [];
    insist(expires > now, "EXPIRED");
    for (let slot = 0; slot < scope.requests.length; slot++) {
      const m = {
        version: 1,
        protocol: "printify-independent-get-v1",
        authority: c.authority,
        epoch: c.epoch,
        generation,
        session,
        nonce: crypto.randomUUID().replaceAll("-", ""),
        slot,
        actor: 1,
        owner_proof: ownerProof,
        review,
        shop_key: "personalized_pod",
        internal_shop_id: 2,
        integration_id: 3,
        account: c.account,
        provider_shop_id: "28930593",
        credential_binding: c.credential_binding,
        issued_at: now,
        expires_at: expires,
        owner_verified_at: p.authenticated_at,
        request: scope.requests[slot],
        catalog_grant: scope.requests[slot].endpoint.includes("/catalog/")
          ? await hash(canonical(scope.requests[slot]))
          : null,
        contract_sha256: CONTRACT_SHA,
      };
      binding(m, c);
      requests.push(await sign(this.ports.readPrivate, "read-v1", m));
    }
    return {
      session,
      requests,
      expires_at: expires,
      owner_proof: ownerProof,
      review,
      client_public: p.client_public,
      proof_nonce: p.nonce,
      approval_nonce: a.nonce,
      scope_hash: a.scope_hash,
    };
  }
}
