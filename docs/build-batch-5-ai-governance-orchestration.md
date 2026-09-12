# BUILD BATCH 5 — AI Governance & Orchestration

Target: continue dependency-ordered DigiForge implementation after merged Batch 4 while preserving all fail-closed controls.

## Outcome

Batch 5 introduces an inert, provider-neutral AI governance and orchestration foundation. It defines task/model routing policy, prompt/version registries, structured request and response envelopes, schema validation, output provenance, usage/cost accounting, human-review gates, and local auditability. AI execution remains disabled in this batch.

## Non-negotiable safety posture

- `stop_all` remains ON by default and effective controls remain fail-closed.
- `automation_armed` remains internal and false.
- No external AI provider calls, HTTP clients, SDK calls, inference execution, embeddings, image generation, webhooks, workers, schedules, Etsy/POD actions, publishing, fulfillment, order automation, GST automation, or deployment.
- No provider credential is stored outside the existing encrypted integration-secret boundary.
- Sandbox/test/production records remain environment-isolated.
- Every mutation is capability-gated, validated, audited, and idempotent where replay could create duplicate state.
- Human review remains mandatory before any AI output can advance a business record into a downstream action boundary.

## Required implementation

### 1. Schema v8

Add only Batch 5 tables on schema-v7 upgrades. Fresh installs create all prior tables plus Batch 5 tables.

Required tables:

- `digiforge_ai_tasks`
- `digiforge_ai_models`
- `digiforge_ai_prompts`
- `digiforge_ai_prompt_versions`
- `digiforge_ai_runs`
- `digiforge_ai_outputs`
- `digiforge_ai_usage`
- `digiforge_ai_reviews`

Minimum requirements:

- provider/environment/model identifiers are metadata only; no secrets.
- prompt versions are immutable once referenced by a run.
- run records store task, model-policy decision, prompt-version reference, input schema/version, output schema/version, state, provenance, and audit timestamps.
- outputs store structured payloads or references only; no unbounded secret-bearing diagnostic dumps.
- usage records support request units, input/output tokens or equivalent metering units, estimated cost, currency, and source of estimate.
- review records capture decision, reviewer, notes, and timestamp.
- all relevant uniqueness and lookup indexes are deterministic and documented.

### 2. Task registry and routing policy

Implement provider-neutral local registries for:

- AI task types;
- model classes/capabilities;
- environment eligibility;
- quality tier;
- latency/cost constraints;
- deterministic fallback ordering;
- prohibited combinations.

Routing must be a pure local policy decision. It must not call a provider or probe live availability.

### 3. Prompt registry and versioning

Implement prompt definitions and immutable prompt versions with:

- stable prompt key;
- version label;
- system/instruction/template fields;
- input schema identifier/version;
- output schema identifier/version;
- lifecycle status;
- created/reviewed metadata;
- checksum/fingerprint for reproducibility.

Existing referenced prompt versions must never be modified in place.

### 4. Run intent model

Create AI run intents only. A run must capture enough information to reproduce the local orchestration decision without executing inference.

Run states must include a safe graph such as:

`DRAFT -> VALIDATED -> REVIEW_REQUIRED -> APPROVED_FOR_EXECUTION`

Execution states must not be reachable in this batch. Any attempt to mark a run as executed/completed by inference must fail closed.

### 5. Schema validation

Implement local JSON-schema-style validation or an equivalent deterministic validator for structured inputs and outputs. At minimum support required fields, scalar types, arrays, objects, enums, numeric ranges, string length bounds, and additional-field rejection where configured.

Validation failures must be structured, auditable, and must not persist unsafe raw diagnostics containing credential-like fields.

### 6. Output provenance

Every stored AI output record must reference:

- run id;
- task id;
- model metadata decision;
- prompt version;
- schema version;
- source input fingerprint;
- creation actor/time;
- review state.

No output can promote a Research candidate, Product Factory record, Digital Factory record, listing, Etsy/POD action, or any external side effect in this batch.

### 7. Usage and cost ledger

Implement an append-oriented local usage ledger supporting:

- provider/model metadata;
- environment;
- metering unit type;
- input/output units;
- estimated cost;
- currency;
- estimate source/version;
- run linkage;
- timestamps.

The ledger may record manually supplied or test-fixture usage estimates only. No live billing/provider query.

### 8. Human-review gates

Implement explicit review records for AI run/output approval. Review is local only and must not trigger execution. Re-review history must remain append-oriented.

### 9. REST and admin surfaces

Add capability-gated, bounded, local-only REST/admin surfaces for task/model/prompt registries, run intents, outputs, usage, and reviews.

Mutations require `manage_digiforge_ai` (add capability if absent) and idempotency protection where duplicate writes are possible. Reads may use the same capability for this phase.

No REST route may execute a provider call or return decrypted credentials.

### 10. Audit and secret handling

Audit all mutations and routing/review decisions. Reuse recursive credential-key redaction. AI payload/config fields must reject or redact credential-like keys rather than persisting them casually.

### 11. Uninstall and lifecycle

Explicit opt-in uninstall cleanup must include all Batch 5 tables while preserving default data retention behavior. Multisite destructive uninstall remains disabled.

### 12. Tests

Add/update:

- schema-v8 migration tests from fresh install and v7 upgrade;
- migration reactivation/idempotency tests;
- task/model routing-policy unit tests;
- prompt immutability/versioning tests;
- schema-validation positive/negative tests;
- run state transition tests proving execution remains unreachable;
- output provenance tests;
- usage ledger tests;
- human-review gate tests;
- REST authorization tests;
- mutation idempotency/replay tests;
- secret-like payload rejection/redaction tests;
- WordPress/MariaDB integration tests;
- existing safety, secret-pattern, external-HTTP and deterministic-package gates.

## Acceptance gates

Batch 5 is mergeable only when:

1. exact final head passes the complete hosted engineering/safety workflow;
2. WordPress/MariaDB logs contain no hidden migration/database warnings;
3. no external HTTP client/provider SDK usage is introduced;
4. STOP ALL and automation_armed fail-closed behavior is unchanged;
5. schema-v7 upgrades touch only additive Batch 5 schema;
6. prompt versions referenced by runs are immutable;
7. AI execution remains impossible through REST/admin/domain transitions;
8. human review is required before any future execution boundary;
9. package is reproducible and boundary checks pass;
10. there are no unresolved P0/P1/P2 blockers.

## Explicitly out of scope

- live OpenAI/Anthropic/Gemini/other inference;
- embeddings/vector databases;
- image-generation execution;
- external AI SDKs or HTTP clients;
- scheduled AI jobs/workers;
- automatic promotion of AI output into marketplace actions;
- Etsy/Printify/Gelato operations;
- publishing, fulfillment, orders, GST, deployment, or activation.
