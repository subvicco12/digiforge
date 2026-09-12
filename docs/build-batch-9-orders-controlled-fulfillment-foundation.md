# BUILD BATCH 9 — Orders & Controlled Fulfillment Foundation

## Objective
Build the local-first order intake, review, fulfillment-planning, and provider handoff preparation foundation on top of merged Batch 8. This batch must not execute any Etsy order mutation, provider fulfillment request, payment action, refund, cancellation, shipping update, tax/GST filing, webhook processing, worker, schedule, or external network call.

## Safety posture
- STOP ALL remains active and fail-closed.
- `automation_armed` remains internal and non-user-writable.
- No Etsy, Printify, Gelato, AI, payment, shipping, tax, or other external HTTP execution.
- No OAuth exchange, webhook processing, background worker, cron, or queue dispatch to an external system.
- Human approval is mandatory before a local fulfillment plan may reach an approved state.
- Personalized-order auto-approval and provider auto-approval remain explicitly deferred.
- All externally visible future mutation intents remain inert and non-executable.
- Secrets must never be committed, logged, returned, or persisted outside the existing credential vault boundaries.
- Environment isolation is mandatory across sandbox, test, and production.

## Schema
Target schema: **v12**, additive only.

Fresh install must create the complete current schema. Exact v11→v12 migration must create only Batch 9 tables. Migration failure must not advance the stored schema version and must preserve failure telemetry.

### Order records
Local order snapshots representing marketplace/order data that may later be ingested by an authorized connector.
Minimum fields:
- channel (`etsy` initially)
- environment
- external order reference metadata
- shop reference metadata
- buyer reference metadata with no unnecessary sensitive data
- currency and totals metadata
- order state
- personalization-required flag
- timestamps, actor and idempotency metadata

Local states:
`RECEIVED`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `ON_HOLD`, `REJECTED`, `SUPERSEDED`, `CLOSED`.

No state may imply that a provider request has been sent.

### Order line items
Local normalized items linked to an order and existing DigiForge product/version/listing records.
Minimum fields:
- order relationship
- listing/product/version relationship
- quantity
- price/currency metadata
- personalization payload metadata
- POD mapping reference where applicable
- environment
- validation status

Same-product and environment checks are mandatory.

### Personalization submissions
Local normalized buyer-provided personalization data bound to approved Batch 7 personalization schemas.
Requirements:
- schema-bound validation
- canonical payload
- payload hash
- review status
- no AI interpretation or provider submission in this batch
- recursive credential-like key rejection
- strict size and item bounds

### Fulfillment plans
Local deterministic plans that describe how an approved order could later be fulfilled.
Minimum fields:
- order identity
- provider selection metadata
- provider mapping snapshot
- print-area/personalization snapshot references
- shipping method metadata
- cost snapshot references
- readiness result
- human approval metadata
- immutable plan version/hash once approved

States:
`DRAFT`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `SUPERSEDED`, `ON_HOLD`.

### Fulfillment intents
Allowed intent types:
- `PREPARE_ORDER`
- `PREPARE_FULFILLMENT`
- `PREPARE_PERSONALIZATION`
- `PREPARE_SHIPPING`
- `PREPARE_CANCELLATION`
- `PREPARE_REFUND_REVIEW`

Allowed states only:
- `BLOCKED`
- `READY_FOR_REVIEW`
- `APPROVED_INTENT`
- `REJECTED`
- `SUPERSEDED`

Forbidden states include `QUEUED`, `EXECUTING`, `SUBMITTED`, `ACCEPTED`, `PROCESSING`, `FULFILLED`, `SHIPPED`, `REFUNDED`, `CANCELLED_REMOTE`, `SYNCED`, and any state implying an external mutation occurred.

There must be no execution method in this batch.

### Fulfillment readiness reviews
Persist deterministic evidence proving all required gates passed or failed.
Readiness must fail closed and verify at minimum:
- valid order and line-item relationships
- approved listing relationship where required
- approved/release-ready product assets
- approved POD mapping/readiness where applicable
- valid personalization submission where applicable
- local cost/shipping metadata present where required
- environment consistency
- authenticated human approval before release-ready local state

Persist canonical evidence and evidence hash for auditability.

## Capability
Add capability: **`manage_digiforge_orders`**.

## REST/admin boundaries
Provide capability-gated local-only surfaces for:
- order CRUD/list/review
- order line items
- personalization submissions
- fulfillment plan generation/review
- inert fulfillment intent creation/review
- deterministic readiness evaluation

All mutations must enforce:
- authorization
- validation
- max 64 KiB request body
- idempotency/replay protection
- audit logging
- recursive credential-key rejection
- environment isolation
- relationship integrity

## Privacy and data minimization
- Store only order/buyer metadata needed for deterministic local planning and audit.
- Do not store full payment credentials or unnecessary personal data.
- Do not expose secrets or sensitive fields through REST responses.
- Audit logs must use existing redaction rules.

## Required hosted tests
- fresh v12 activation
- exact v11→v12 Batch 9-only migration
- repeat no-op
- migration failure does not advance version
- order/line-item parent validation
- listing/product/version same-product validation
- environment isolation
- personalization schema validation
- recursive credential rejection
- bounded payloads/body size
- lifecycle transition guards
- fulfillment intents always inert
- deterministic readiness and evidence hashes
- idempotency/replay
- REST authorization/validation/body limits
- audit redaction/safety
- no external HTTP clients
- no deprecated pre-DigiForge platform identifier
- PHP syntax, PHPUnit, PHPStan and PHPCS
- deterministic ZIP/checksum and package boundaries

## Exit criteria
The exact final PR head must pass the hosted Engineering & Safety Audit. WordPress/MariaDB logs must contain no hidden schema or migration failure. There must be no unresolved P0/P1/P2 review thread affecting correctness or safety. Reproducible packaging must pass.

Merging Batch 9 does **not** authorize deployment, webhook activation, Etsy order ingestion, external provider submission, personalized-order auto-approval, Printify/Gelato auto-approval, cancellations, refunds, shipping updates, workers, schedules, tax/GST automation, payments, or any external side effect.
