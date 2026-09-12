# BUILD BATCH 7 — POD Provider & Personalization Foundation

## Objective
Build the inert, local-first POD provider and personalization foundation on top of merged Batch 6. This batch models provider catalogs, product/variant mappings, print-area specifications, personalization schemas, provider intents, quote/cost snapshots, and review controls without executing any provider API call, order submission, production request, fulfillment action, upload, publishing action, webhook processing, or background worker.

## Safety posture
- STOP ALL remains active and fail-closed.
- `automation_armed` remains internal, non-user-writable, and fail-closed.
- Printify and Gelato integrations are metadata/configuration only in this batch.
- No external HTTP clients or OAuth exchange.
- No provider product creation, mockup generation, file upload, order creation, order approval, production approval, shipping purchase, or fulfillment.
- Personalized-order auto-approval and provider auto-approval remain explicitly deferred.
- All provider-facing intent records are inert and must have no executable state.
- Human review remains mandatory before any later provider execution batch can act on approved mappings or personalization definitions.

## Schema target
Schema **v10**, additive only.

Existing v9 installations receive only Batch 7 tables. Already-current installs must not re-run stable legacy tables. Fresh installs create the complete current schema. Migration failures must not advance the schema version and must preserve failure telemetry.

## Domain model

### 1. POD provider catalog
Normalized local provider/product/variant metadata.

Minimum fields:
- provider (`printify`, `gelato`, future providers)
- environment (`sandbox`, `test`, `production`)
- provider_product_key
- provider_variant_key
- title / variant labels
- size / color / material metadata
- currency
- base_cost snapshot
- shipping_profile metadata
- availability state
- source revision / observed_at
- state (`DRAFT`, `OBSERVED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `SUPERSEDED`)
- idempotency key / actor / timestamps

Rules:
- no provider fetch occurs in this batch;
- catalog data may be entered manually or imported later through a separately reviewed collector;
- production/test/sandbox records remain isolated;
- no credentials are stored in catalog payloads.

### 2. DigiForge-to-provider mappings
Map approved DigiForge products/plans/assets to provider product and variant identities.

Minimum fields:
- product_version_id
- production_plan_id
- provider
- environment
- provider_product_key
- provider_variant_key
- mapping_version
- state (`DRAFT`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `SUPERSEDED`)
- notes / idempotency / actor / timestamps

Rules:
- referenced Product Factory and Production records must exist;
- environment must match the integration registry environment;
- mapping identity is immutable after validation;
- approval requires authenticated human reviewer.

### 3. Print-area specifications
Local deterministic print-area requirements for each mapped variant.

Minimum fields:
- provider mapping id
- area key / placement
- width / height / unit
- dpi target
- bleed / safe-area metadata
- accepted formats
- transparency/background policy
- orientation / rotation policy
- linked Batch 6 asset specification
- state (`DRAFT`, `VALIDATED`, `APPROVED`, `REJECTED`)

Rules:
- bounded dimensions;
- recursive credential-key rejection;
- linked asset spec must belong to the same product version;
- no binary upload or render occurs.

### 4. Personalization schemas
Versioned customer-personalization definitions.

Minimum fields:
- product_version_id
- schema_key / version_label
- field definitions (text, choice, date, number, optional future image reference)
- required/default rules
- min/max lengths and numeric bounds
- normalization rules
- preview instructions metadata
- policy constraints
- state (`DRAFT`, `VALIDATED`, `REVIEW_REQUIRED`, `APPROVED`, `REJECTED`, `SUPERSEDED`)
- reviewer / timestamps / idempotency

Rules:
- deterministic validation and canonical JSON;
- no prompt/model/provider execution;
- no customer PII values stored in schema definitions;
- image-upload personalization remains metadata-only and non-executable.

### 5. Personalization-to-asset bindings
Bind approved personalization fields to Batch 6 asset specifications or future render placeholders.

Rules:
- same product version required;
- field identifiers must exist in the referenced personalization schema;
- bindings are inert transformation instructions only.

### 6. Provider intents
Metadata-only future provider operations.

Intent examples:
- `MAP_PRODUCT`
- `MAP_VARIANT`
- `PREPARE_PRINT_AREA`
- `PREPARE_MOCKUP`
- `PREPARE_UPLOAD`
- `PREPARE_ORDER`
- `PREPARE_PERSONALIZATION`

States:
- `BLOCKED`
- `READY_FOR_REVIEW`
- `APPROVED_INTENT`
- `REJECTED`
- `SUPERSEDED`

There is no execution, submitted, queued, sent, approved-by-provider, or fulfilled state.

### 7. Cost and quote snapshots
Local bounded snapshots for expected provider economics.

Minimum fields:
- provider mapping id
- currency
- base production cost
- shipping estimate
- tax/fee metadata where known
- observed/source timestamp
- source type (`manual`, `imported`, `future_provider`)
- state (`DRAFT`, `VALIDATED`, `APPROVED`, `SUPERSEDED`)

Rules:
- no live quote request in this batch;
- deterministic arithmetic validation;
- no checkout, payment, tax filing, or GST automation.

## Validation and readiness
Implement deterministic validators for provider/environment enums, identifiers, dimensions, structured payload bounds, recursive credential rejection, canonical JSON, and same-product relationships.

Readiness for a mapping must fail closed unless required provider mapping, print areas, approved production assets, approved personalization schema when applicable, and human review are present.

## REST and admin boundaries
Add capability `manage_digiforge_pod`.

Required local REST/admin surfaces:
- provider catalog list/create/review
- mapping list/create/review
- print-area list/create/review
- personalization schema list/create/review
- bindings
- provider intent create/list/review
- cost snapshot create/list/review
- deterministic readiness endpoint

Every mutation requires capability check, validation, bounded payload, idempotency, audit logging, and no credentials/network execution.

## Tests
Required hosted coverage:
- fresh v10 activation;
- v9→v10 Batch-7-only migration;
- repeat activation no-op;
- migration failure does not advance schema;
- environment isolation;
- parent/same-product validation;
- recursive credential rejection;
- bounded dimensions/payloads;
- legal/illegal lifecycle transitions;
- provider intents always inert;
- personalization schema validation;
- print-area validation;
- deterministic readiness;
- idempotency/replay behavior;
- optimistic concurrency where implemented;
- REST authorization/validation;
- audit output contains no secrets;
- no external HTTP clients;
- no legacy platform identifier;
- PHP syntax, PHPUnit, PHPStan, PHPCS/policy checks;
- deterministic ZIP/checksum and package boundaries.

## Exit criteria
Batch 7 is SAFE TO MERGE only when the exact final PR head passes every hosted engineering/safety gate, WordPress/MariaDB logs show no hidden database errors, no unresolved P0/P1/P2 defects remain, reproducible packaging succeeds, and no live provider side effect exists.

Merging Batch 7 does not authorize deployment, provider API execution, personalized-order auto-approval, Printify/Gelato auto-approval, Etsy publishing, fulfillment, or activation of external services.