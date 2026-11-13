# Accelerated independent-dispatch security qualification

Base: 085778adfa3a770e44e694d03fde5cf4fbcb13fb. This is an offline source candidate, not an installable dispatcher or permission to execute.

## Architecture decision and supported threat model

| Option | Decision | Limitation |
|---|---|---|
| Independently administered recovery/final-dispatch authority | Selected conditional architecture | Must be outside BOTH Hostinger and dispatcher/Cloudflare restoration and administrative domains. No such qualified service exists yet. |
| Protected deployment and restoration freeze | Required supporting control | A same-account administrator can undo protections; a deployment hash claimed by a workload is not remote attestation. |
| Provider credential invalidation and exclusive custody | Mandatory supporting control | Revoked old credentials prevent WordPress bypass, but do not by themselves fence a rolled-back dispatcher with a usable new token. |
| Permanent non-executable fallback | Current operational decision | Default Worker remains503. There is no production client/service factory, real recovery backend, live credential port or deployment configuration. |

The conditional invariant is: an independently committed stop cannot be undone by restoration of the two subordinate stores while the independent authority remains current and its administration/custody assumptions hold. The reference tests enforce this. They do NOT prove that the independent authority cannot itself be restored.

**Explicit failing security qualification:** restore EVERY trusted root, including the authority journal to before its first boot, and earlier owner proof can again mint executable authority. The test intentionally reproduces this and verifies the default Worker remains disabled. A third SQLite store, signature, journal, hash chain or sole-owner Cloudflare account is not an unrewindable trust boundary. This remains RECOVERY_BLOCKED. No general software-completion or production-readiness claim is made.

## New software and protocol

- `authority-journal.mjs`: actual SQL/CAS reference state for independent epoch, exact namespace/code/account/credential binding, minimum deployment version, active workload public key, request nonces, anchor, leases, boot flag and stop generation. Explicit initialization; atomic synchronous transitions; no reset/rearm/restore API. Nonces stop at2048 instead of being silently discarded. No provider/signature/network operation occurs inside transactions.
- `authority-service.mjs`: privileged service holds the existing runtime and final sender in private fields. Fixed SESSION, EXECUTE and EMERGENCY RPCs; no arbitrary host/method/URL/header, callbacks, credentials or Boolean permission cross the client boundary.
- Independent workload issuer signs a max60-second certificate binding key possession, code, namespace, account, credential reference, epoch and version. The independent journal pins the current workload key and deployment floor. Separate custody issuer signs max60-second custody evidence. Workload request proof is bound to exact certificate, custody, payload, nonce and operation, for max30seconds.
- The workload certificate is NOT owner authority. Original independently verified owner proof/approval, signed slots, five-minute owner lifetime, ten slots, three-per-minute budget, GET allowlist, geometry/catalog grants and shop binding remain mandatory. A short workload certificate refresh cannot renew an owner grant.
- Existing witness state transitions are anchored outside both original stores. Restoring them together fails the independent anchor. Expired certificates, namespace/epoch/code/key/floor mismatch, unknown or nonexclusive custody, missing root state and storage errors fail closed.
- Final physical sender revalidates current authority both within credential admission and at the actual provider fetch callback. It checks synchronously immediately before the callback, avoiding a Boolean permit or asynchronous check-to-use gap. Only synthetic credential/fetch adapters are supported.
- Client verifies pinned response signature, exact request/nonce hash, current epoch and nondecreasing root revision. Transport exceptions become bounded classifications. Twenty-second client deadline returns UNKNOWN semantics; it does not cancel remote physical work or authorize retries. There is no direct-provider fallback.

## Emergency, crash and recovery

A signed stop is authenticated before root writes. Invalid owner stop signatures do not fence otherwise valid access. Authenticated emergency failure fences the service and attempts durable quarantine even when root persistence is unavailable. No failed persistence yields a successful stop acknowledgement. On restart, a previously booted root enters recovery-required state; it never rearms automatically.

Independent stop intent persists before subordinate emergency handling. Physical leases remain tracked through receipt persistence. Unknown physical failures retain leases. ACK requires the existing verified drain conditions AND no independent leases; interrupted/uncertain outcomes stay UNKNOWN or DRAIN_PENDING. Request slots and nonces are never restored. Every repeat stop advances the root generation when it is durably recorded.

There is no cross-service distributed transaction claim. Lost acknowledgements, CAS conflicts and partial anchoring deny future progress. The root, workload signer, custody issuer and physical sender must remain available and independently administered; an outage never falls back to Hostinger.

## Administrator and issuer boundaries — not yet established

Future caller identity issuer, custody approver, root restore/deployment administrators and token administrators need separately approved identities and restricted roles. Hostinger and Worker deployment operators must not be able to restore/change the root, obtain its signing keys, issue workload/custody certificates, retrieve the sender token or change the mandatory dispatch service. Same-person/same-account Super Administrator membership is NOT evidence of separation.

The reference has pinned public keys and proof of possession, not hardware/runtime remote attestation. A compromised holder of the current workload private key can claim its certified build identity. Real deployment-key issuance, version attestation, emergency administration, signing-root recovery and restore prohibition are HOSTED_UNVERIFIED. Neither a string naming another administrative domain nor `exclusive=true` is accepted as live evidence: a future custody issuer may sign only after independent provider-side invalidation and access evidence has passed review.

## Future exclusive credential migration — PLAN ONLY

1. Independently inventory non-secret credential IDs, integration3 association, provider account, Printify shop28930593, effective scopes and expiry. Keep account/credential association UNKNOWN until proven. Do not substitute shop28925875 or assign Shop3.
2. Approve the actual independent authority/sender administrative boundary, restore controls, secrets IAM, workload key issuer and emergency channel. Qualify root persistence, code/namespace rollback, outage and operator recovery before admitting any token.
3. Obtain separate owner approval for credential creation/rotation, provider-side invalidation, secret transfer, binding and any controlled provider verification. These are NOT authorized by this source sprint.
4. Provision the approved least-scope credential only into the final sender's restricted secret custody through the provider's supported secure path. Do not pass it through WordPress, Drive, GitHub source, chat or receipts.
5. Inventory all previously usable DigiForge/Hostinger token identities and copies. Review unrelated consumers before independently approved provider-side revocation/invalidation. Moving/deleting a local token copy is insufficient. Old encrypted backup bytes may remain only if provider-side invalidation makes them unusable.
6. Independently demonstrate that restored WordPress backups/old plugin versions cannot reach Printify with any surviving credential. No generic proxy, WordPress fallback or alternate usable token is permitted. If exclusive custody cannot be demonstrated, deny deployment.
7. Record non-secret revocation receipts, key references, binding approval, scope evidence and custody revision. Never store token values in evidence. Require separate approval for every subsequent rotation or recovery epoch change.
8. Recovery must deny first: stop admission, fence the epoch, keep unknown leases, restore subordinate stores without secrets and independently inspect receipts. Never restore an old usable credential, lower the version floor or rearm automatically. Old revoked credentials are not a rollback mechanism. A failed migration leaves the bridge disabled.

## Costs and next isolated hosted acceptance

No discovery, billing request, purchase or provisioning was performed. Reused owner evidence shows zero Workers/DOs/resources and billing403; it proves neither a free entitlement nor independent administration. Conditional cost is Worker request/CPU + DO storage/operations + independent authority/sender hosting + audit retention/egress. None of these are priced or approved; zero-cost hosting is UNVERIFIED. A second namespace in the same administrator's account does not satisfy independence at any price.

Minimum next action: approve or reject a concrete independently administered witness/final-sender boundary and its restore/custody model. Only then authorize disposable hosted qualification (no provider token initially). Qualify SQLite transaction/CPU limits, issuer/workload key isolation, mandatory sender routing, partition/failure behavior and restore controls. Credential migration, binding, installation, live GET acceptance and release each require separate approval. If no enforceable independent boundary is available, retain the permanent disabled fallback.

## Qualification scope and status

Bounded client/service/journal reference software is OFFLINE_VERIFIED. Existing46 provider operations and all489 production runtime hashes are preserved. The original59 tests, including their explicit rollback limitation, remain enabled. Additional tests cover identity, custody, nonce replay, root anchors, emergency recovery, physical timing, client deadlines and the all-root restore limitation. Exact counts, CI run and final source SHA are in the handoff receipt.

Independent delegated review was attempted but the reviewer process was blocked by a platform security filter. No independent-review acceptance is claimed. Automated exact-head source audit is distinct from hosted durability, hosted exact-artifact certification and human acceptance.

Overall: HOSTED_UNVERIFIED / RECOVERY_BLOCKED / CREDENTIAL_CUSTODY_BLOCKED / LIVE_NOT_AUTHORIZED. No installation-ready production ZIP. STOP ALL/external lock ON, automation OFF, activation unauthorized, RELEASE HOLD active. No changes to historical reservation22, production or inventory execution settings.
