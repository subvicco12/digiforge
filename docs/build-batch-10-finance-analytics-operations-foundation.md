# BUILD BATCH 10 — Finance, Analytics & Operations Foundation

## Objective
Build DigiForge's local-first finance, profitability, tax-readiness, analytics, retention, recovery-readiness, and operational reporting foundation on top of merged Batch 9. This batch must remain non-executing: it may normalize, calculate, reconcile, review, evaluate retention/recovery readiness, and report local business data, but must not file taxes/GST, move money, issue refunds, alter marketplace/provider records, execute ads, send payouts, create accounting transactions in external systems, delete protected records automatically, perform a live restore, or make external network calls.

## Safety posture
- STOP ALL remains active and fail-closed.
- `automation_armed` remains internal and non-user-writable.
- No payment, banking, accounting, tax/GST, Etsy, Printify, Gelato, ads, AI, shipping, or other external HTTP execution.
- No tax/GST filing, payment initiation, refund execution, payout action, invoice dispatch, ad-spend mutation, or financial account connection in this batch.
- Human approval is mandatory before any local finance period may reach an approved/finalized state.
- All future external mutation intents remain inert and non-executable.
- Secrets and financial credentials must never be committed, logged, returned, or stored outside existing credential-vault boundaries.
- Environment isolation is mandatory across sandbox, test, and production.
- Retention is fail-closed: no automatic destructive purge is enabled in this batch.
- Backup/recovery work is verification-only: no live database restore or destructive recovery action is executed.

## Schema
Target schema: **v13**, additive only.

Fresh install must create the complete current schema. Exact v12→v13 migration must create only Batch 10 tables. Migration failure must not advance the stored schema version and must preserve failure telemetry.

### Finance ledger entries
Local normalized entries derived from DigiForge orders, provider cost snapshots, manual adjustments, and future authorized imports.
Minimum fields:
- environment
- source type and local source reference
- entry type (`REVENUE`, `COGS`, `PLATFORM_FEE`, `PAYMENT_FEE`, `SHIPPING_COST`, `TAX_COLLECTED`, `TAX_EXPENSE`, `REFUND_RESERVE`, `AD_SPEND`, `OTHER_INCOME`, `OTHER_EXPENSE`, `ADJUSTMENT`)
- currency
- gross/base amount metadata
- event/effective date
- canonical metadata payload/hash
- reconciliation state
- actor/timestamps/idempotency metadata

Ledger entries are append-only and immutable after creation. Corrections, reversals, reconciliation adjustments, or superseding facts must be represented by new linked entries or new review records rather than by rewriting or deleting historical ledger amounts or evidence. No ledger entry may imply money was moved externally.

### Currency rate snapshots
Local immutable FX snapshots used only for deterministic reporting.
Requirements:
- base/quote currency
- rate
- as-of timestamp/date
- source metadata
- environment
- canonical payload/hash
- no live FX fetch in this batch

### Tax/GST classification records
Local tax-readiness classifications only.
Minimum fields:
- jurisdiction metadata
- tax category
- taxable/non-taxable/review-required classification
- amount/currency basis
- evidence metadata
- review status
- human approval metadata

This batch must not calculate statutory filing obligations beyond configured deterministic rules, submit returns, create invoices externally, or provide legal/tax advice as an automated action.

### Finance periods and reconciliations
Local reporting periods with deterministic rollups.
States:
`OPEN`, `CALCULATED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `SUPERSEDED`, `CLOSED`.

Required deterministic metrics include, where source data exists:
- gross revenue
- refunds/reserves
- platform/payment fees
- shipping cost
- POD/provider cost
- advertising cost
- tax collected/expense
- gross profit
- contribution profit
- net operating profit
- margin percentages
- order count/AOV
- unresolved reconciliation count

### Analytics snapshots
Immutable local analytics snapshots for product, listing, order, provider, shop, and portfolio dimensions.
Requirements:
- dimension type/id
- period
- environment
- canonical metrics payload/hash
- freshness metadata
- deterministic calculation version
- no external analytics API execution

### Operational alerts
Local-only deterministic alerts generated from existing DigiForge data.
Allowed alert classes include:
- negative/low margin
- missing cost data
- unreconciled order
- missing tax classification
- stale FX snapshot
- provider cost variance
- listing/order data mismatch
- readiness regression

States:
`OPEN`, `ACKNOWLEDGED`, `RESOLVED`, `DISMISSED`, `SUPERSEDED`.

### Retention controls
Provide deterministic local retention policy metadata with these rules:
- finance ledger, FX snapshots, analytics snapshots, audit logs, and release revision evidence are immutable/protected records;
- protected records have no automatic deletion path;
- operational records may expose default retention guidance, but automatic deletion remains disabled;
- any future purge must require a separately reviewed human-approved workflow;
- retention decisions must be deterministic and testable without deleting production data.

### Backup and recovery drills
Provide a deterministic local recovery-drill evaluator that verifies at minimum:
- a database backup is reported available;
- an audited plugin package is available;
- package checksum is verified;
- current schema version is known;
- restore instructions are available;
- STOP ALL is confirmed before recovery.

The evaluator must produce fail-closed PASS/REVIEW_REQUIRED status and an evidence hash. It must not perform a live backup, restore, external upload, or destructive recovery action.

### Finance intents
Future external-finance actions remain inert.
Allowed intent types:
- `PREPARE_RECONCILIATION`
- `PREPARE_TAX_REVIEW`
- `PREPARE_EXPORT`
- `PREPARE_ACCOUNTING_SYNC`
- `PREPARE_REFUND_REVIEW`
- `PREPARE_PAYOUT_REVIEW`

Allowed states only:
- `BLOCKED`
- `READY_FOR_REVIEW`
- `APPROVED_INTENT`
- `REJECTED`
- `SUPERSEDED`

Forbidden states include `QUEUED`, `EXECUTING`, `SUBMITTED`, `SYNCED`, `PAID`, `FILED`, `REFUNDED`, `TRANSFERRED`, `POSTED_REMOTE`, and any state implying an external side effect occurred. There must be no execution method in this batch.

## Capability
Add capability: **`manage_digiforge_finance`**.

## REST/admin boundaries
Provide capability-gated local-only surfaces for:
- ledger create/list/review
- FX snapshot create/list
- tax/GST classification create/list/review
- finance period calculate/review
- analytics snapshot generation/list
- operational alert list/review
- inert finance intent create/review

All mutations must enforce:
- authorization
- validation
- max 64 KiB request body
- idempotency/replay protection
- audit logging
- recursive credential-key rejection
- environment isolation
- relationship/source integrity

No REST/admin path may edit or delete immutable ledger entries, FX snapshots, analytics snapshots, or protected audit/release evidence.

## Privacy and data minimization
- Store only finance metadata required for deterministic reporting and audit.
- Do not store bank account credentials, card data, payment secrets, or unnecessary personal data.
- Audit logs must use existing redaction rules.
- REST responses must not expose credential-like or sensitive financial-secret fields.

## Required hosted tests
- fresh v13 activation
- exact v12→v13 Batch 10-only migration
- repeat no-op
- migration failure does not advance version
- source relationship and environment validation
- ledger type/currency/amount validation
- immutable/append-only ledger contract
- deterministic FX conversion math
- deterministic period rollups and hashes
- tax/GST classification remains local-review-only
- operational alert determinism
- finance intents always inert
- retention policy protects immutable records and enables no automatic deletion
- recovery drill requires all safety checks and produces deterministic evidence
- recursive credential rejection
- bounded payload/body size
- lifecycle transition guards
- idempotency/replay
- REST authorization/validation/body limits
- audit redaction/safety
- no external HTTP clients
- no deprecated pre-DigiForge platform identifier
- PHP syntax, PHPUnit, PHPStan and PHPCS
- deterministic ZIP/checksum and package boundaries

## Exit criteria
The exact final PR head must pass the hosted Engineering & Safety Audit. WordPress/MariaDB logs must contain no hidden schema or migration failure. There must be no unresolved P0/P1/P2 review thread affecting correctness or safety. Reproducible packaging must pass.

Merging Batch 10 does **not** authorize deployment, external finance/accounting integrations, banking connections, payment movement, tax/GST filing, refunds, payouts, ad-spend mutation, workers, schedules, marketplace/provider actions, automated retention purges, live restore operations, or any other external side effect.
