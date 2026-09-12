# BUILD BATCH 4 — Research & Opportunity Intelligence

**Branch:** `build/batch-4-research-intelligence`  
**Base:** `main` after merged Batch 3  
**Production posture:** STOP ALL remains active. No schedules, provider calls, AI execution, Etsy/POD actions, publishing, fulfillment, deployment, or live automation.

## 1. Objective

Implement the inert, evidence-first Research & Opportunity Intelligence foundation required by the Batch 0 dependency plan. Batch 4 must create the data contracts, persistence, scoring, provenance, deduplication, review queues, REST/admin surfaces, migration coverage, and audit evidence needed to support later AI and product-production batches without performing live research collection or scheduled execution.

## 2. Authoritative scope

Batch 4 implements:

- research-source registry;
- ingestion contracts and normalized observation records;
- source/evidence provenance;
- deterministic scoring primitives;
- deduplication and canonical opportunity matching;
- review queues and review decisions;
- promotion of reviewed research candidates into the existing Product Factory opportunity boundary;
- capability-gated REST and read-only/admin-review surfaces;
- idempotent mutations;
- audit evidence for all state changes;
- schema migration and uninstall cleanup;
- unit and WordPress/MariaDB integration tests;
- documentation, static analysis, security scans, and reproducible packaging.

Schedules remain disabled. No network collectors, scraping, external APIs, AI calls, marketplace calls, or background execution may be introduced in this batch.

## 3. Data model

Add schema version 7 with additive tables sufficient for the following records.

### Research sources

Store local metadata describing a future source without contacting it:

- id;
- source_key;
- source_type;
- display_name;
- environment (`sandbox`, `test`, `production` where relevant);
- enabled flag, default false;
- configuration metadata that contains no credentials;
- provenance policy/version;
- created_by, created_at, updated_at.

Require stable unique keys and reject credential-shaped configuration recursively.

### Research runs / ingestion envelopes

Represent a future collection/import attempt as an inert persisted record:

- source_id;
- run_key / idempotency key;
- ingestion_contract_version;
- state;
- requested_at / completed_at;
- item counts;
- failure code/summary;
- actor and audit metadata.

No scheduler or worker may execute runs in Batch 4. Manual/local creation represents intent only.

### Research observations

Normalized source observations must include:

- source_id;
- research_run_id where applicable;
- external/source-local reference identifier when available;
- title/name;
- normalized text/summary fields;
- canonical URL/reference as metadata only;
- observed_at;
- source-published-at when known;
- raw evidence reference or compact evidence payload subject to size limits;
- evidence hash/checksum;
- provenance metadata and schema version;
- deduplication fingerprint;
- created_at / updated_at.

Do not store credentials, session cookies, authorization headers, or arbitrary unbounded HTML.

### Opportunity candidates

Represent scored candidate ideas before promotion into the existing Product Factory `opportunities` table:

- candidate_key;
- canonical fingerprint;
- title;
- normalized summary;
- evidence count;
- scoring-version;
- deterministic score components;
- aggregate score;
- state (`NEW`, `NEEDS_REVIEW`, `APPROVED`, `REJECTED`, `PROMOTED`);
- review metadata;
- promoted opportunity id when applicable;
- timestamps and actor metadata.

### Candidate-evidence links

Support many-to-many traceability between candidates and observations. Evidence must remain attributable to its source and ingestion record.

### Review decisions

Persist review decisions with reviewer id, decision, reason code, notes, prior/new state, timestamp, and audit reference. Human review remains mandatory before promotion.

## 4. Deterministic scoring

Implement a pure, versioned scoring service that accepts normalized numeric/boolean components and returns a bounded score plus component breakdown. Batch 4 must not use AI. Scoring rules must be deterministic, unit-testable, versioned, and capable of later replacement without mutating historical evidence.

Initial scoring dimensions should support at minimum:

- demand signal;
- competition signal;
- margin/value potential;
- evidence quality;
- recency/freshness;
- strategic fit;
- risk/policy penalty.

Store both total and component values with the scoring version used.

## 5. Deduplication and provenance

- Normalize candidate text deterministically before fingerprinting.
- Use stable hashes for observation evidence and candidate identity.
- Prevent duplicate ingestion for the same source/reference/evidence hash where contracts permit.
- Candidate deduplication must never silently discard conflicting evidence; link new evidence to the canonical candidate.
- Preserve source, timestamp, ingestion contract version, and evidence hash for every scored candidate.
- No evidence record may be rewritten to fabricate provenance.

## 6. Review and promotion workflow

Legal candidate transitions:

`NEW -> NEEDS_REVIEW -> APPROVED -> PROMOTED`

and

`NEW|NEEDS_REVIEW|APPROVED -> REJECTED`

Reject illegal transitions with optimistic compare-and-set semantics where practical.

Promotion into the existing Product Factory opportunity table must:

- require an approved candidate;
- require explicit human action;
- be idempotent;
- validate that it was not previously promoted;
- create or resolve exactly one Product Factory opportunity;
- retain the candidate-to-opportunity link;
- emit audit evidence;
- not trigger any downstream automation.

## 7. REST and admin boundaries

Use a dedicated capability such as `manage_digiforge_research` or the repository's existing capability convention.

REST endpoints may provide local CRUD/review operations for research sources, observations, candidates, review decisions, and promotion. Every mutation must have:

- capability check;
- strict validation;
- idempotency where externally visible;
- audit event;
- bounded payloads and pagination;
- no credential material in responses;
- no network side effect.

Admin pages should show research-source metadata, candidates, evidence/provenance summaries, scores, review state, and promoted opportunity references. Avoid exposing secrets or unrestricted raw payloads.

## 8. Migration requirements

- Advance database schema from v6 to v7 using the ordered/resumable migration framework.
- Existing v6 installs must receive only additive Batch 4 schema changes; do not re-run stable legacy `dbDelta` tables unnecessarily.
- Fresh installs must create the complete current schema.
- Preserve failure telemetry and do not mark a migration complete after a database error.
- Add WordPress/MariaDB tests for fresh install, v6 -> v7 upgrade, indexes/uniqueness, repeat activation/no-op behavior, and failure-safe schema versioning.

## 9. Tests and quality gates

Required tests include:

- source validation and credential-field rejection;
- deterministic scoring and score bounds;
- scoring-version persistence;
- observation/evidence deduplication;
- candidate canonicalization and evidence linking;
- legal/illegal review transitions;
- optimistic concurrency where used;
- idempotent candidate creation and promotion;
- promotion creates exactly one Product Factory opportunity;
- provenance cannot be silently overwritten;
- REST authorization and validation;
- pagination and payload bounds;
- audit events contain no secrets;
- WordPress/MariaDB fresh-install and v6 -> v7 migration coverage;
- repeat activation without `dbDelta` regressions;
- PHP syntax, PHPUnit, PHPStan, PHPCS;
- legacy-name scan, secret-pattern scan, external HTTP-client scan;
- deterministic plugin ZIP/checksum and package-boundary verification.

## 10. Safety invariants

Batch 4 must preserve all prior guarantees:

- `STOP ALL` remains ON/effective fail-closed;
- internal `automation_armed` remains non-user-writable and fail-closed;
- no schedules or workers execute research;
- no external HTTP clients are introduced;
- no Etsy, Printify, Gelato, AI, scraping, browser automation, publishing, fulfillment, order, GST, or production deployment actions occur;
- research-source `enabled` metadata does not constitute permission to execute anything;
- human review remains mandatory before opportunity promotion;
- promotion does not enqueue executable jobs.

## 11. Exit criteria

Batch 4 is SAFE TO MERGE only when the exact PR head passes all hosted engineering/safety gates including WordPress/MariaDB integration tests and log review, has no unresolved P0/P1/P2 correctness/security/data-integrity findings, produces a deterministic audited package, and independent review confirms there are no live external side effects.

Do not merge, deploy, activate schedules, or contact external providers merely to satisfy the Batch 4 timeline.
