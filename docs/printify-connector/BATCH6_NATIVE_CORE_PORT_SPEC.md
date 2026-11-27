# Batch6 native core-port contract1.0.0

Canonical machine-readable registry: `companion/digiforge-printify-connector/contracts/native-core-port.v1.json`.

SHA-256: `6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434`.

Status: OFFLINE_IMPLEMENTED / SYNTHETICALLY_TESTED. No native production port, deployment, provider authority or hosted durability certification is supplied. Existing Batches1–5 typed interfaces remain1.0.0. Companion version0.7.0 adds a composite conformance adapter; it does not replace the authoritative core.

## Contract boundary

`Preparation\NativeCorePortInterface` extends the existing mutation, inspection, publication, workflow and operations interfaces. It adds contractManifest(), typed GET read(), allocation(), recovery() and panelAccess(). The fixed future factory is `DigiForge\Connector\Printify\NativeBridge::connect('1.0.0')`. Discovery is inert and denies if missing. Existing GET interface1.0.0 is preserved through the optional NativeGetConnector shim; no second provider transport is introduced. Existing Plugin::connector()/mutationHost() behavior is unchanged.

NativePortAdapter requires the exact interface, protocol versions, declared capability, canonical spec digest and a trusted expected core source SHA before every delegated call. The default source reference remains the inspected PR1264 SHA702a4d078555ac9cb75fd115357db5387281c74a. A future core implementation must report its genuine new SHA; the companion composition must separately pin that reviewed SHA. Request parameters, manifest self-claims, WordPress administrator status and method availability cannot override this pin or grant authority.

Manifest capabilities describe supported software only: GET_READ, MUTATION_ADMISSION, JOURNAL, INSPECTION, PUBLICATION_ADMISSION, WORKFLOW, OPERATIONS, ORDER_ALLOCATION, RECOVERY, PANEL. They do not authorize live requests. Factory construction, manifest and version methods must perform no provider request, credential read, activation or mutation. The current core reference has neither companion bridge and remains BLOCKED_NATIVE_PORT.

## Structural and semantic validation

The canonical JSON is a registry of bounded JSON-schema-subset definitions plus exact native method/capability mappings, semantic validators and invariants. NativePortSchema reads at most65537 bytes once, checks its pinned digest and parses those same bytes. Remote references, executable schemas and caller-selected schema files are unsupported. Values are bounded to4MiB/depth32; objects/resources, unknown fields in closed definitions and incompatible types deny. The larger value bound accommodates prepared base64 upload data, not unbounded provider responses.

**Structural schema success is not authorization.** Every `methods.*.semantic` rule is mandatory. Existing MutationIntent/ArtworkContract/UploadContract/ProductDraftContract/PublicationContract/ProviderReceipt/ReadProjection/ReviewContract validators remain required, as do current native authority and transactional checks. Generic JSON objects are only bounded typed-service payload containers; they are never an HTTP/SQL/command proxy. The adapter additionally constrains mutation action payloads, fixed upload/publication grammar, receipt and journal hashes, scope echoes and safe response fields. Core must reconstruct trusted scope and regenerate/validate prepared payloads before persistence.

GET schema covers the13 existing qualified symbolic operations, operation-specific grammar through PrintifyConnectorReadContract, bounded pagination and upload ownership references. No arbitrary URL/headers/body/credentials are accepted. Returned ProviderResult receipts must echo the original actor-scoped intent's operation/shop/integration identity and response hash. Core must use its existing transport/vault/ReadProjection and redact known secret values while inside custody. The adapter rejects protected keys; this cannot replace secret-aware core redaction.

## Exact authority and transaction obligations

Every method must verify the current WordPress actor/session, native capability and exact shop/module scope. Owner-sensitive publication/review requires the actual independently enrolled Global Primary Owner and native fresh-email step-up where required. No email/ID/admin match is an ownership grant.

Shop/integration/provider-shop/environment/credential reference and scope fingerprint bind to current independently reviewed core mapping. Unknown account/credential association, disabled or revoked relationships, stale permission/approval/product/order revisions, missing evidence and unavailable audit/journal persistence deny. The core must resolve real receipts and native IDs; companion hashes or supplied versions are claims, not proof.

Mutation registration must atomically revalidate authority, immutable payload/approval and physical duplicate identity. CLAIM must atomically compare PREPARED→FENCED and permanently consume one use. Core must persist journal plus audit before durable acknowledgement. Lost acknowledgement, timeout and interrupted requests cannot restore slots or trigger automatic retry. Receipt processing must validate producer provenance, exact fence/epoch, unique event/payload/sequence and terminal history. A self-hashed caller response cannot become a trusted receipt. Review commits CAS the exact subject/payload/review/product/order/auth revisions and epoch; review state never changes operational outcome or grants execution.

ControlledExecutionTransaction::execute actually invokes an execution adapter: **do not call it as a local journal or review helper**. Existing Etsy PublishReviewRepository is not Printify publication authority. No competing store/approval system is introduced here; schema insufficiency is a core-owned blocker, not permission to fabricate successful persistence or create an unreviewed production migration.

## Allocation and recovery

allocation(scope,actor,request) is SELECT-only, binding exact order identity/version, authorization/approval revisions and current checkpoint. Quantities must come from existing approved order/product/proof allocations. Tracking keys are local hashes scoped by parent order; never infer quantities from membership or invent carrier/shipment IDs. Missing allocations remain unverified. NativePortAdapter rechecks local authority and validates bound receipt hashes.

recovery(scope,actor,{action:INSPECT}) is SELECT-only. It exposes journal classification, current/checkpoint generation and epoch, pending lease count and recovery-required evidence. There is no RESET/REARM/RETRY/RESTORE or emergency writer in the companion interface. Missing/stale journals cannot be reported ready. Acknowledgement is rejected if leases remain or checkpoints disagree.

Emergency STOP is a separate core-owned event, not merely a Boolean transition: **each event advances or invalidates the independent revocation generation even while STOP ALL is already ON**. Bind approvals/sessions/journals to that generation; check it before custody and immediately before physical dispatch. Persist emergency intent before new admissions; track in-flight work; acknowledge only after drain and durable independent revocation. Failed persistence must deny acknowledgement and keep an independently enforceable fail-stop. Unknown in-flight outcomes remain UNKNOWN without retry.

A single restored database/hash chain/receipt cannot prove monotonic revocation. If coordinated rollback of all trusted roots can revive authority, retain non-executable admission and report the independent checkpoint/credential-custody blocker. The existing Hostinger durability predicate is not weakened. No hosted rollback or recovery qualification is claimed by these synthetic tests.

## Protected panel integration

ProtectedPanel supports publication, orders, fulfillment, conflicts and UNKNOWN views. Context1.0.0 binds view, current session fingerprint and native CSRF token. Core panelAccess() must verify the actual current WordPress session/nonce and scoped read capabilities; it must not trust supplied fingerprints/booleans. Panel access is checked before data reads and again before return. Actual WordPress session-token and nonce functions are exercised against disposable accounts in tests.

Snapshots/exports omit session fingerprints and CSRF values. Rows come from the separately authorized versioned review queue; scopes and hashes remain strict. Rendering adds no route, form, mutation button, scheduler or public proxy. Future core portal integration must use its existing protected router, native login/logout and nonce handling. No protected screen is made live in this batch.

ExceptionReconciler produces bounded triage for cancelled, partial, stale, conflicting, missing-journal and UNKNOWN evidence. UNKNOWN/CONFLICTING/CANCELLED cannot be downgraded to a partial-tracking classification. Operational state is copied unchanged; apply_state, resolution_authorized, retry_permitted and external_execution_authorized remain false. Triage hashes require core verification and cannot resolve uncertainty.

## Synthetic scope and operational gates

Fixtures model one native API and delegate to preserved synthetic mutation/workflow/operations components. Positive ordinary-provider admission tests explicitly use unlocked **synthetic-only** flags; recovery/locked tests retain STOP ALL and external lock ON. No real DigiForge safety option is changed. Synthetic GET results report request_attempted=false. Concurrent reservation tests use the existing isolated file-journal fixture, not a new production store.

Schema and source-level checks do not certify native owner authority, hosted persistence, provider connectivity, independent revocation or exclusive credential custody. Installation, binding, credential use and live GET/write acceptance remain separately unauthorized. STOP ALL, external lock, automation OFF and RELEASE HOLD are preserved. Historical reservation22 is not read, modified or retried.
