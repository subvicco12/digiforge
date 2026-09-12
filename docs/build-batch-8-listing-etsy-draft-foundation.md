# BUILD BATCH 8 — Listing & Etsy Draft Foundation

## Objective
Build the local-first listing and Etsy draft preparation layer on top of merged Batch 7. DigiForge may model, validate, review, and package listing metadata, but this batch MUST NOT call Etsy APIs, exchange OAuth tokens, create/update Etsy drafts remotely, publish listings, upload listing media, change inventory, process orders, run webhooks, or execute workers/schedules.

## Safety posture
- STOP ALL remains active and fail-closed.
- `automation_armed` remains internal and non-user-writable.
- Etsy integration remains registry/credential metadata only; no network execution.
- Human approval is mandatory before a listing can become locally release-ready.
- All Etsy-facing intents remain inert and have no executable/submitted/published state.
- No order, fulfillment, tax/GST, payment, advertising, or deployment automation is introduced.

## Schema target
Schema **v11**, additive only. Existing v10 installations receive only Batch 8 tables. Fresh installs create the complete current schema. Migration failure must not advance the schema version and must preserve failure telemetry.

## Domain model

### Listing records
Versioned local listing metadata linked to an existing DigiForge product version. Minimum data: product/version identity, channel (`etsy` initially), environment, shop reference metadata, title, description, taxonomy/category metadata, price/currency metadata, quantity/inventory policy metadata, personalization flag, state, actor, timestamps, and idempotency key.

States: `DRAFT`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `SUPERSEDED`.

### Listing SEO metadata
Deterministic local metadata for tags, keywords, materials, attributes, audience/occasion/style metadata, and validation evidence. Enforce bounded counts/lengths and canonical structured payloads. No live Etsy keyword research or external AI execution occurs in this batch.

### Listing media bindings
Metadata-only links from listing records to approved Batch 6 asset revisions/release bundles. Validate same-product ownership and approved/release-ready status. No binary upload or remote media operation.

### POD/listing bindings
Optional links to approved Batch 7 provider mappings, print areas, personalization schemas, and readiness results. Same product version and environment are mandatory. A POD listing cannot become release-ready when required POD readiness is false.

### Etsy draft packages
Immutable/local package snapshots containing the normalized payload that a future separately authorized Etsy connector could use. Include package version, canonical payload, payload hash, readiness result, approval metadata, and timestamps. Packages are local artifacts only.

### Etsy intents
Allowed intent types include `PREPARE_DRAFT`, `PREPARE_MEDIA`, `PREPARE_INVENTORY`, `PREPARE_PERSONALIZATION`, and `PREPARE_UPDATE`.

Allowed states only: `BLOCKED`, `READY_FOR_REVIEW`, `APPROVED_INTENT`, `REJECTED`, `SUPERSEDED`.

There MUST be no `EXECUTING`, `QUEUED`, `SUBMITTED`, `SYNCED`, `LIVE`, or `PUBLISHED` state and no execution method.

### Listing review/readiness
Deterministic fail-closed readiness must verify required product/version relationships, listing validation, SEO constraints, approved media/release bundle, approved POD readiness where applicable, approved personalization where applicable, and authenticated human approval. Persist readiness evidence/hash for review/audit.

## Capability and API boundary
Add dedicated capability `manage_digiforge_listings`.

Provide local capability-gated REST/admin surfaces for listing CRUD/list/review, SEO metadata, media/POD bindings, local draft package generation, inert Etsy intent creation/review, and deterministic readiness.

All mutations MUST require authorization, validation, max 64 KiB JSON body, idempotency/replay protection, audit logging, recursive credential-key rejection, and environment isolation.

## Required tests
Hosted tests must cover fresh v11 activation; exact v10→v11 Batch-8-only migration; repeat no-op; migration failure without version advance; parent/same-product validation; environment isolation; bounded listing/SEO payloads; recursive credential rejection; lifecycle transitions; Etsy intents always inert; media and POD binding gates; deterministic readiness; idempotency/replay; REST authorization/body limits/validation; audit safety; no external HTTP; no legacy identifier; syntax/PHPUnit/PHPStan/PHPCS; deterministic ZIP/checksum and package boundaries.

## Exit criteria
Batch 8 is complete only when the exact final PR head passes hosted engineering/safety audit, WordPress/MariaDB logs show no hidden database/migration errors, no unresolved P0/P1/P2 review findings remain, and reproducible packaging passes. Merge does not authorize deployment, Etsy OAuth/API execution, remote draft creation, media upload, listing publication, order processing, fulfillment, workers, schedules, or any other external side effect.
