# BUILD BATCH 6 — Asset & Product Production Pipeline

## Objective
Build the inert, local-first Asset & Product Production Pipeline that converts approved Product Factory / Digital Factory work into versioned production plans, asset specifications, production jobs, QA evidence, and release-ready bundles without executing AI, image generation, external provider calls, marketplace actions, publishing, fulfillment, or background workers.

This batch follows merged Batch 5 and preserves all Batch 1–5 safety guarantees.

## Safety posture
- STOP ALL remains active and fail-closed.
- `automation_armed` remains internal, non-user-writable, and fail-closed.
- No external HTTP clients, AI/model calls, image-generation calls, Canva/API actions, Etsy/Printify/Gelato actions, uploads, publishing, fulfillment, order processing, GST automation, deployment, schedules, or workers.
- Production records represent intent and local governance only.
- Human review is mandatory before a production bundle can reach RELEASE_READY.
- No record transition may enqueue executable external work.

## Schema target
Schema **v9**, additive only.

Existing v8 installs receive only Batch 6 tables. Already-current installations must not re-run stable legacy tables. Fresh installs create the full current schema. Migration failure must not advance the schema version and must preserve failure telemetry.

## Domain model

### 1. Asset specifications
A normalized specification for a required deliverable or source asset.

Minimum fields:
- id
- product_version_id
- digital_product_id (nullable where not applicable)
- asset_key
- asset_type
- purpose
- format
- width_px / height_px / dpi where applicable
- color_space
- orientation
- variant_key
- locale
- content_requirements (structured JSON)
- design_constraints (structured JSON)
- source_policy
- state (`DRAFT`, `SPECIFIED`, `APPROVED`, `REJECTED`)
- idempotency_key
- created_by / created_at / updated_at

Rules:
- parent Product Factory / Digital Factory records must exist;
- structured fields reject credential-shaped keys recursively;
- dimensions and numeric limits are bounded;
- no binary upload or network fetch occurs in this batch.

### 2. Production plans
A versioned local plan that groups specifications and defines production requirements.

Minimum fields:
- id
- product_version_id
- plan_key
- version_label
- channel (`digital`, `pod`, `hybrid`)
- production_type
- requirements_version
- state (`DRAFT`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `RELEASE_READY`)
- notes
- idempotency_key
- created_by / created_at / updated_at

Rules:
- unique plan key/version within a product version;
- immutable identity/version fields after validation;
- optimistic concurrency on state transitions where practical;
- RELEASE_READY requires explicit human approval and passing QA gates.

### 3. Plan-to-asset links
Many-to-many relationship between production plans and asset specifications, including required/optional designation and sequence/order metadata.

### 4. Production intents
Local records representing a future production operation. These are not executable jobs.

Minimum fields:
- id
- production_plan_id
- asset_spec_id
- intent_type (`DESIGN`, `RENDER`, `EXPORT`, `PACKAGE`, `PREVIEW`, `COPY`, `LOCAL_TRANSFORM`)
- provider_class (`internal`, `ai`, `canva`, `manual`, `future_provider`)
- requested_capability
- input_contract_version
- input_payload (structured)
- state (`BLOCKED`, `READY_FOR_REVIEW`, `APPROVED_INTENT`, `REJECTED`, `SUPERSEDED`)
- idempotency_key
- created_by / created_at / updated_at

Rules:
- default state BLOCKED;
- no state represents execution;
- no provider credential or network configuration;
- AI provider intent may reference Batch 5 task/prompt/model governance identifiers but must not execute them.

### 5. Asset revisions
Metadata-only revisions representing produced or manually supplied local artifacts.

Minimum fields:
- id
- asset_spec_id
- revision_label
- storage_reference (local/opaque reference only)
- checksum_sha256
- mime_type
- byte_size
- width_px / height_px
- provenance
- state (`PENDING_QA`, `QA_PASSED`, `QA_FAILED`, `APPROVED`, `REJECTED`)
- idempotency_key
- created_by / created_at / updated_at

Rules:
- checksum format validation;
- stable revision identity;
- provenance is append-preserving and must not be silently rewritten;
- storage_reference must not contain credentials, signed URLs, session tokens, or unrestricted remote fetch instructions.

### 6. Production QA checks
Evidence-oriented technical, visual, content, commercial, copyright/policy, packaging, and compatibility checks.

Minimum check types:
- dimensions
- DPI/resolution
- file type / MIME
- checksum
- file size bounds
- transparency/background requirement
- bleed/safe-area metadata
- text/content completeness
- variant completeness
- copyright/policy review
- commercial/readiness review
- package manifest consistency

Fields include target type/id, check type/version, status (`PENDING`, `PASS`, `FAIL`, `WAIVED`), bounded structured details, reviewer/actor, timestamps, idempotency key.

WAIVED requires an explicit reviewer and reason.

### 7. Release bundles
A local manifest grouping approved asset revisions for downstream Digital/POD/listing batches.

Minimum fields:
- id
- production_plan_id
- bundle_key
- version_label
- manifest
- checksum_sha256
- state (`DRAFT`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `RELEASE_READY`, `REJECTED`)
- idempotency_key
- created_by / created_at / updated_at

RELEASE_READY requires:
- approved production plan;
- required asset specifications linked;
- approved revision for every required asset;
- required QA gates passing or explicitly waived with reviewer/reason;
- explicit human bundle approval;
- deterministic manifest/checksum.

## Deterministic validation
All validation in Batch 6 is local and deterministic. No AI is used to decide readiness.

Required validation properties:
- bounded payload size and list lengths;
- recursive credential-key rejection;
- normalized identifiers and enums;
- stable canonical JSON for checksums/fingerprints where used;
- deterministic bundle manifest ordering;
- repeat validation produces identical results for identical records.

## Lifecycle / transition policy
Illegal transitions return conflict errors. Execution-like states do not exist.

Suggested plan path:
`DRAFT -> VALIDATED -> REVIEW_REQUIRED -> APPROVED -> RELEASE_READY`
with rejection allowed from pre-release states.

Asset revision path:
`PENDING_QA -> QA_PASSED -> APPROVED`
or
`PENDING_QA|QA_PASSED -> QA_FAILED|REJECTED` as applicable.

Bundle path:
`DRAFT -> VALIDATED -> REVIEW_REQUIRED -> APPROVED -> RELEASE_READY`
with release readiness recomputed from persisted evidence, not caller assertions.

## REST/API
Use dedicated capability `manage_digiforge_production`.

Authenticated, capability-gated local endpoints for:
- asset specs create/list/detail/update/state;
- production plans create/list/detail/state;
- plan asset links;
- production intents create/list/state;
- asset revisions create/list/state;
- QA check create/list/review;
- release bundles create/list/detail/state/validate.

Every externally visible mutation must have:
- capability check;
- validation;
- idempotency key where creation/mutation is replay-sensitive;
- bounded payloads and pagination;
- audit event;
- no credentials;
- no network calls;
- no worker/schedule enqueue.

## Admin UI
Read-only or controlled-review DigiForge admin surface showing:
- production plans and states;
- asset specifications and required variants;
- revision/QA summaries;
- blocked production intents;
- release bundle readiness and missing requirements.

No button may invoke an external provider in Batch 6.

## Integration with prior batches
- Product Factory remains source of Product Version identity.
- Digital Factory may be linked for digital deliverables.
- Batch 5 AI governance may be referenced only as metadata/intents; no AI execution.
- Research and integrations remain inert.
- Queue records must not be used to make Batch 6 executable.

## Migration and uninstall
- v8 -> v9 adds Batch 6 tables only.
- fresh install creates full current schema.
- repeated activation at v9 is a no-op for stable tables.
- migration DB error prevents version advancement.
- uninstall cleanup includes Batch 6 tables only when existing explicit cleanup policy is enabled.

## Tests
Minimum hosted coverage:
- fresh install schema v9;
- v8 -> v9 touches only Batch 6 tables;
- repeat activation/no dbDelta regressions;
- migration failure does not advance version;
- parent relationship validation;
- credential-shaped key rejection in nested structured fields;
- dimension/size/enum bounds;
- plan lifecycle legal/illegal transitions;
- production intent always inert and never execution-capable;
- revision checksum/provenance validation;
- QA PASS/FAIL/WAIVED rules;
- deterministic manifest/checksum;
- RELEASE_READY rejected when any required asset/revision/QA/human approval is missing;
- idempotent mutations/replays;
- optimistic concurrency where implemented;
- REST authorization and validation;
- bounded pagination/payloads;
- audit contains no secrets;
- no external HTTP clients;
- no FIELDORA identifier;
- PHP syntax, PHPUnit, PHPStan, PHPCS/policy checks;
- deterministic ZIP/checksum and package boundaries.

## Exit criteria
Batch 6 is SAFE TO MERGE only when the exact final PR head passes every hosted engineering/safety gate, WordPress/MariaDB logs show no hidden database errors, no unresolved P0/P1/P2 defects remain, the reproducible package is generated successfully, and independent review confirms no live external side effects were introduced.

Merging Batch 6 does not authorize deployment or activation of external services.