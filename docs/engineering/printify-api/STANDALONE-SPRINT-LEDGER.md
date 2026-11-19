# Standalone Printify acceptance ledger

This is an offline engineering checkpoint. The standalone package is passive and
advertises zero provider capabilities. The existing qualified operation ledger
remains reference evidence for the integrated API implementation.

| Component | Verified starting SHA | Scope |
| --- | --- | --- |
| API #1260 | 035b693713fe162cf3f74edd6d5e2f6217cff9e1 | Sole integration target |
| Companion #1263 | 36a713cbbc8af36a04497e9bf4679c1976793f71 | Read-only compatibility reference |
| Core #1264 | c02b248c14eb71a571e5dae881fe0a10823b5560 | Read-only contract reference |

| Lane | Owned paths in this checkpoint | Offline evidence | Remaining standalone acceptance |
| --- | --- | --- | --- |
| A, provider | includes/POD/PrintifyApiRequest.php, includes/POD/PrintifyApiReadEvidence.php, tests/unit/PrintifyApiBoundaryTest.php | Order validation enforces actual request limit and preserves it on continuation. | Bind the qualified catalog, geometry, upload, product, shipping, order, cancellation and tracking operations through explicit standalone services. |
| B, native | contracts/cloudflare-dispatcher/NativeObservationConformance.php and test/core-observer-conformance.test.mjs in that tree | Future held-journal events are rejected. Existing native contract identity stays pinned. | Authoritative REGISTER, CLAIM, allocation and recovery remain gated by the existing Core and independently qualified checkpoint. |
| C, execution | contracts/cloudflare-dispatcher/sender.mjs and test/custody-fencing.test.mjs in that tree | A late synthetic response body is cancelled after a deadline or custody failure. UNKNOWN and one-use slots are retained. | Exclusive usable credential custody, independent recovery and hosted revocation/drain qualification remain external prerequisites. |
| D, standalone | standalone/printify-api/ | Passive bootstrap, inert status, exact Git-blob manifest, deterministic ZIP and extracted bootstrap checks. | Configuration and operator UI, complete capability discovery, supported runtime composition and disposable WordPress installation acceptance. |
| Integration | .gitattributes, .github/workflows/printify-standalone-offline.yml and this ledger | Standalone tree excluded from Core export. Dedicated offline CI tests source and rebuilt artifact. | Await exact-head hosted CI and add end-to-end standalone provider acceptance. |

Existing integrated API operations and their approval rules are documented in
[the operation ledger](OPERATIONS-1.0.103.20.md). Their offline tests do not imply
standalone capability or live provider acceptance. The native and synthetic
sender paths remain distinct from the standalone bootstrap.

The governing 19 November 2026 architecture document and its sections 1–24
were not located during this checkpoint. Obtain the exact source before final
feature-by-feature certification. Do not substitute an earlier blueprint.
Shop 2 and its WordPress diagnostic were excluded.

STOP ALL and external safety lock remain on. Automation remains off and RELEASE
HOLD stays active. No production installation, activation or provider execution
is authorized.

## Day 2, governing architecture reconciliation

The owner supplied **Printify API Plugin FINAL Architecture Complete**, dated
19 November 2026, including Sections 1–24. This table records scope against
that source. Statuses describe this standalone build unless noted.

| Section | Capability | Disposition and evidence |
| --- | --- | --- |
| 1 | Standalone product, one native authority | PARTIAL. Independent bootstrap and ZIP. No authority implementation copied. |
| 2 | Evidence baseline | PARTIAL. Source and packaged smoke tests. Keep inherited integrated API evidence separate. |
| 3 | Components and SDK | PARTIAL. Local runtime, Tools panel and scoped catalog validator. Consumer SDK and governed runtime wiring open. |
| 4 | Trust boundaries | PARTIAL. No sender, credential access or provider calls from the standalone runtime. Hosted trust unqualified. |
| 5 | Data ownership | PARTIAL. Scoped input digest only. Durable provider-attributed snapshot storage open. |
| 6 | Capability and wire versions | PARTIAL. PANEL/DIAGNOSTICS and OFFLINE_CATALOG_VALIDATE are the only discoverable features. GET and mutation families remain unavailable. |
| 7 | End-to-end flows | PARTIAL. Local catalog input checks. Authorized fetch, durable snapshot, product and order journeys open. |
| 8 | Safety invariants | PARTIAL. Standalone holds are fixed and no operational paths exist. Hosted sender fencing is unqualified. |
| 9 | Failure model | PARTIAL. Local invalid input denies. Synthetic UNKNOWN and retry evidence remains in existing dispatcher. |
| 10 | Package and installation | PARTIAL. Deterministic isolated ZIP and extracted bootstrap. Real disposable installation, upgrade and uninstall open. |
| 11 | Operator UI | PARTIAL. Admin Tools health, capability discovery and held execution status. Operational queues, dependency telemetry and audit export open. |
| 12 | Acceptance tiers | PARTIAL. Fixture and source/artifact checks. Standalone WordPress/MariaDB, hosted and provider tiers open. |
| 13 | Release gates | BLOCKED_EXTERNAL. Independent checkpoint, custody and hosted recovery remain open. |
| 14 | Completion semantics | PARTIAL. Local checks only, RELEASE HOLD. |
| 15 | Reference evidence | PARTIAL. Exact architecture and starting source IDs recorded. No transfer of earlier execution authorization. |
| 16 | Standalone runtime and pins | PARTIAL. Missing packaged validator denies its capability. Package records exact input and generated module digests. Runtime dependency attestation open. |
| 17 | Provider registry | PARTIAL. Nine catalog kinds reuse the existing exact request/response validator. Product, image, order and tracking standalone routing open. |
| 18 | State machines | PARTIAL. Local validation and denial only. Existing governed mutation/recovery state separate. |
| 19 | Transactions | PARTIAL. No standalone writes or schema changes. Native owns operation state. |
| 20 | Root and custody | BLOCKED_EXTERNAL. All-root rollback and old usable credential copy counterexamples remain open. |
| 21 | Events and reconciliation | PARTIAL. Existing synthetic contracts remain. No standalone webhook or reconciliation activation. |
| 22 | Budgets | PARTIAL. Catalog input limited to 256 KiB and JSON depth 24. Operational budgets and quota health open. |
| 23 | Compatibility and upgrades | PARTIAL. Separate PHP symbols and reproducible artifact. Runtime coexistence, restore and installation matrix open. |
| 24 | Remaining evidence | PARTIAL. Architecture reconciled here. Provider fields, authority infrastructure, hosted durability, installation and live acceptance unresolved. |

## Day 3, offline lifecycle and disposable storage

The packaged Runtime adds scoped `OFFLINE_LIFECYCLE_REVIEW` when the shop and
account configuration match. The deterministic fixture validates catalog
variant membership, product intent and artwork placement, actual PNG bytes,
existing-product order and shipping intent, and tracking readback against the
same operation and line quantities. The simulated timeout remains UNKNOWN.
A synthetic matching readback neither confirms a provider outcome nor
authorizes retry. Approval and version values are fixture bindings, separate
from trusted native approvals and revisions.

The opt-in InnoDB fixture store inserts catalog snapshots under a unique
identity, records exact scope, source label, digest, observation time and
unverified freshness, and verifies bytes before reading metadata. It is
limited to a disposable local database with no automatic schema or activation
hooks. Real WordPress/MariaDB fixture checks passed for duplicate insert,
scope and actor denial, and retrieval after database restart. This is
fixture snapshot durability, separate from native operation idempotency,
hosted recovery, and authenticated provider snapshot provenance.

No operational GET, mutation, scheduling or custody capability is advertised.
Native admission and recovery, live provider attribution, action currency and
stock evidence, geometry beyond the validated contract, and broader standalone
operation coverage remain open. Shop 2, Core and Companion are unchanged.

## Standalone 0.5.0 and 0.6.0 delta against sections 1–24

| Sections | Source capability | Offline verification | External qualification |
| --- | --- | --- | --- |
| 1–4, 10–12, 15–16, 23 | Standalone bootstrap, operator discovery, exact-source packaging and separated runtime symbols. Standalone SDK and operational compatibility matrix remain incomplete. | Source and extracted smoke, deterministic ZIP, disposable activation. | Live composition and release approval unavailable. |
| 5, 8–9, 18–20 | Exact-selector signed synthetic catalog evidence and insert-only fixture snapshots. Native register, claim, checkpoint and journal authority remain outside the standalone runtime. | Duplicate denial, stale and cross-scope denial, restart readback, synthetic UNKNOWN and retry denial. | Qualified issuer/witness, exclusive credentials, historical-token invalidation and recovery evidence required. |
| 6–7, 17, 21–22 | Eight provider read plans and typed projections over supplied bytes. Scoped catalog and local product, artwork and order preparation remain available. Standalone approved-intent submission, trusted receipts, event intake and authoritative reconciliation remain incomplete. | Exercise eight routed reads, scope denial, bounded pagination, malformed evidence and projection of only the selected shop. | No provider-confirmed response, hosted durability or quota evidence. |
| 13–14, 24 | Release held. Read review exposes an explicitly blocked native reconciliation result. | Fixtures do not supply approvals, dispatch authority or successful resolution of UNKNOWN. | Independent authority and explicit operational approval required. |

The typed provider read router reviews unattributed bytes. Signed catalog
snapshots retain their separate fixture evidence contract. Neither grants native
operation idempotency. The existing Core/native ownership boundary still owns
approval and product revision currentness, durable operation transitions, and
the definitive reconciliation decision.

## Standalone 0.7.0 native held inspection

The standalone adapter accepts the frozen Core build
`d64d32159bea4832b962acb6b4c08fa6fbeae910` with its exact native build stamp
and canonical v1 contract digest. It uses Core's protected held inspection
and compares its event digest to the current Core journal read and exact
binding, including the operation, approval reference, product version and
payload digest. The existing Core panel/session and reviewed connection
checks supply currentness and the configured shop/account join. The adapter
returns only a held observation or UNKNOWN with retry denied. This does not
certify the approval or product revision as current for operational execution.

Core #1264 `c02b248c14eb71a571e5dae881fe0a10823b5560` was inspected as
source reference. Its mutation exchange still denies access pending the native
transactional journal and independent checkpoint join, and the Companion
still pins the frozen Core. REGISTER, CLAIM, trusted operational receipts,
reconciliation and recovery remain blocked. No alternate journal or writer
is introduced.

## Standalone 0.8.0 synthetic application

The [official Printify OpenAPI document](https://developers.printify.com/openapi.json)
parsed as OpenAPI 3.0.3 with 47 paths. The typed route subset uses the existing
qualified PHP request and response contracts where available. Route alignment
and fixture behavior do not establish broad schema conformance or live access.

| Architecture sections | Delivered capability | Scope and remaining work |
| --- | --- | --- |
| 1–3, 6–7, 16–17 | Standalone typed client and in-memory transport for shop discovery, catalog and variants, artwork upload/read/list, product create/read/list/update, shipping quote, order create/read/list/cancel, separate simulated production, and isolated webhook fixture management. | Fixed fixture catalog and subset of documented routes. Production client and full provider feature parity remain open. |
| 5, 8–9, 18–22 | Fixture state, bounded plans/pages, validation errors, synthetic rate limits, duplicate and stale-write denial, lost-acknowledgment fault, and order tracking readback. | State survives within a fixture run. Neither fixture identity nor a synthetic readback creates a native journal receipt or resolves a real UNKNOWN. |
| 10–12, 15, 23 | Source-stamped deterministic ZIP and opt-in WordPress Tools journey, with scoped capability checks, nonce admission and sanitized results. | Fixed scenario. General product/order editing and durable audit views remain open. |
| 4, 13–14, 20, 24 | Existing protected native held inspection and standalone execution holds. | Operational registration, claim, independent checkpoint, credential custody, historical-token invalidation and hosted recovery remain blocked. |

## Standalone 0.9.0 disposable operator workspace

| Capability | Source and scope |
| --- | --- |
| Operator operations | Local, scoped, nonce-protected workspace with individual typed JSON operations. Covers 31 provider-shaped fixture operations, plus explicit synthetic production and fulfillment controls and event ingestion. |
| Persistence | Fixture-only InnoDB table. A revision-and-seal CAS commits bounded transport state, event deduplication and sanitized audit together. Pending doubts require readback and consumed operation IDs deny replay. |
| Provider contracts | The route subset adds documented product GPSR read and line-item cancellation with separate 204 acknowledgment and readback. An isolated order:created event follows its documented envelope and checks exact shop and order identity. No webhook delivery over HTTP. |
| Qualification limits | The selector presents the configured fixture shops the current operator can review. General graphical editors, additional documented routes and event topics, and hosted or provider qualification remain open. Core authority, credential custody and independent recovery remain external gates. |
