# Lane B integration dependencies — 15 November 2026

This is a source-linked request to the independent owners, not an implemented interface, authority grant or ownership transfer. Core baseline: `d46374057aa993dea5e863228be2c4d962a4f6bb`. Separately verified API #1260: `200e6101bf8635453b2186be2f45ec87a5946465`; companion #1263: `4da802a1568b13bcf1bbca28eba009b8b6148ef1`. Their source and pins remain unchanged.

## Required source receipts before a positive native integration

The fixed native port remains `DF_NATIVE_CORE_PORT_1`, version `1.0.0`, SHA256 `6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434`. See `includes/Connector/Printify/native-core-port.v1.json`, `NativeCorePort.php` and `NativeAuthorityAdapter.php`. Existing typed denial is the correct result until the real joins exist.

| Independent owner handoff | Required evidence and integration behavior |
| --- | --- |
| Operational issuer and verifier | Exact repository/commit, exported entry points and fixed schemas; purpose-bound signature verifier and independently pinned trust configuration. Requests bind actor/owner proof, installation/workload/deployment, physical provider account, integration/credential reference, environment, exact shop and operation/payload/product/approval/policy versions. No caller-selected key, endpoint or algorithm. |
| Checkpoint and witness | Authenticated currentCheckpoint and separately authenticated witness attestation binding epoch, generation, deployment/workload and challenge. Monotonic root/recovery revisions and freshness must be verified by the real owner implementation. Local counters and matching strings cannot establish provenance. |
| Allocation and admission | Source-owned transactional compare-and-swap and persistent one-use consumption for exact operation, payload, order, listing, product/version and current approval. Identify actual allocation ID/revision source and the fixed native allocation-request/response adapter. No content hash is an allocation counter or independent grant. Concurrent/replayed requests deny. |
| Durable journal and uncertain commit | Actual journal entry points and recovery/readback identity, lease persistence before network activity, disposition/receipt binding and consumed UNKNOWN behavior. Lost acknowledgement, timeout or restart cannot free a slot or enable automatic retry. Provider calls do not execute inside retryable storage transactions. |
| Mandatory sender and custody | Source for the final sender enforcing current generation at credential admission and outbound boundary; usable provider credentials exclusively outside WordPress/backups. Provide the enforcement and independent qualification plan, without sharing secrets. No Boolean permit enabling a WordPress direct sender or fallback. |
| Emergency, drain, recovery and revocation | Real requestEmergency/readDrain/recoverInstallation/rotateRoot entry points. Durable generation invalidation precedes acknowledgement; acknowledge only after verified drain. Recovery/root rotation need independent out-of-band approval and witness reconciliation; stale/restored authority must remain denied. |
| Immutable receipt consumption | Exact authenticated result schema and verified store accessor. Join physical account/shop/integration, operation/allocation, order/listing/version, payload and policy/approval revisions. Omit credentials, buyer PII and raw provider payloads. Receipt hashes alone do not establish issuer authenticity. |

The field inventory and qualification conditions already exist in `docs/printify-native-core/independent-checkpoint-authority.v1.json` and `INDEPENDENT_CHECKPOINT_AUTHORITY_V1.md`; both remain PROPOSED / NOT QUALIFIED. Owners must provide real source receipts and resolve method-specific implementation semantics before Lane B writes a production client. This request does not promote that proposal into authority or demand a specific hosted purchase.

Positive acceptance must exercise the actual owner modules with offline provider responses and disposable storage, including concurrent consumption, wrong shop/account/version, stale/revoked grant, crash/lost commit, timeout/UNKNOWN and sender bypass denial. Mock signatures, synthetic epochs, local counter substitutes and an isolated fake authority are not acceptance. Hosted independent witness, rollback resistance, exclusive credential custody and installation qualification remain separately required even after source integration succeeds. No live execution or credential operation is authorized.

## Lane A currentness and explicit buyer correction

Lane B's `Orders/PersonalizationCurrentness.php` reads all submissions before filtering and validates typed schema/product/environment/payload identity. Production package creation/review and readiness use this guard. It intentionally denies contradictory approved inputs and newer unresolved/orphaned inputs; old approvals stay immutable.

Lane A owns renderer, verifier and preview consumers. Required handoff: exact source receipt for applying current order-line/schema/submission/payload identity before accepting an old artifact or generating a new render. A selected old payload must not hide a newer unresolved or separately approved submission. Inspection of stored metadata is not artifact certification.

Complete correction recovery additionally needs an explicit, authenticated, purpose-confirmed order-line selection/supersession primitive: exact actor/shop/order/line, source schema and submission/payload, expected current selection revision, durable compare-and-swap, one-use confirmation and immutable selection history. Recheck actor and source under the same business transaction; stale/replayed/foreign references deny. Lane A and B must agree the actual existing/source-backed record and consumer API before implementing it. Neither a supplier proof nor a recycled factory approval selects a buyer's input. Until agreement, selection-required is the fail-closed result, not a recoverable automatic retry.

## Work that can proceed without those interfaces

Lane B continues local governed order/plan review, BLOCKED held intent preparation, scoped artifact metadata inspection and authenticated event-receipt reconciliation. These can be tested against actual WordPress/repository modules without claiming native allocation, final artifact currentness or authoritative carrier tracking. No exclusive API, companion or Lane A source is edited.

All safety holds remain active: STOP ALL ON, external lock ON, automation OFF, manual production approval, RELEASE HOLD. No merge, deployment, production database/credential operation, provider write, publication or live order is authorized.
