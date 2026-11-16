# Explicit offline transformation response execution

Lane A software candidate only. STOP ALL/external lock ON, automation OFF, manual approval and RELEASE HOLD. No provider call, credential access, monetary charge, owner approval or production permission.

`TransformationProviderAdapter::executeOfflineFixture(shop, jobKey, configuration, response)` is separate from ordinary `execute()`, which still always denies live execution. It delegates to `TransformationFixtureExecution` and reuses the existing `TransformationWorkflow` state, immutable source/result retention, technical QA and human review. It does not create a second artwork repository or orchestrator.

Only currently signed Shop 2 POD+AI operators and `SYNTHETIC_TEST_ONLY` jobs in INPUT_VERIFIED are admitted. All five existing engine contracts can execute recorded synthetic responses: PET_PORTRAIT, WATERCOLOR, LINE_ART, VINTAGE_PORTRAIT and AI_TRANSFORM. They retain engine/mode/options/rights/template/source binding. This replays fixture PNG bytes through the real result-import path; it does not run an artistic model, create new sale artwork or establish style quality. Owner-supplied/licensed live jobs remain outside this entrypoint.

The existing adapter configuration is reused, but provider must equal `offline_fixture` and max_retries must be zero. Provider/model/prompt/config/terms identities are bounded; configuration limits use the existing exact decimal-to-micros parser. No API credentials, URLs, transport callback or buyer-selected model is accepted. Runtime portal wiring and live image generation remain unfinished requirements.

Response v1 has exactly these fields:

- `version`: `offline-transformation-response-v1`
- `engine_id`, `request_sha256`, `source_sha256`: exact current persisted job identities
- `model`, `currency`: exact selected configuration
- `simulated_maximum_charge`: exact positive decimal bounded by maximum_job_charge
- `output_sha256`, `png_base64`: canonical bounded PNG bytes matching the source-custodied fixture result hash, CRC/type/pixel restrictions and engine resource caps
- `outcome`: SUCCEEDED, TIMEOUT or FAILED

A durable reservation precedes result import. Simulated maximum exposure is aggregated across the Shop 2 UTC day and within each engine's UTC month. It is explicitly `SIMULATED_NOT_PROVIDER_CHARGE`; no actual-spend, tariff, provider invoice, AI usage-settlement or live budget authority is fabricated. A future live adapter must use the existing authoritative monetary reservation/quote boundary, not this simulation ledger.

Reservations have sealed immutable identity and matching option/audit roots, exclusive shop accounting lock, bounded 1,000-record scans and transactional InnoDB reservation writes. Both root sets must match one-to-one before calculating limits; an orphan option, missing option, corrupt record or duplicate identity blocks subsequent work. Timeout/failure consumes its simulated reservation and stays held; no automatic retry/refund. Cancellation/current job is rechecked, and the existing import handler checks source state again. A completed identical replay returns the original receipt; changed response/config, cancelled input or interrupted/uncertain attempt blocks. All-root rollback and actual host crash durability are unqualified.

Tests exercise the existing real WordPress transformation journal, all five synthetic engine responses, exact replay, provider/model/source/malformed-image rejection, quota limits, timeout-held retry, cancellation, unauthorized scope and zero HTTP requests under active safety holds. Independent review found a missing-audit-root aggregate-accounting defect; RED reproduced and fixed by reconciling both sets. A canonical array-key-order replay defect and codec metadata-key mismatch were corrected before final regression. Initial failures are retained in the artifact receipt.

No engine/capability ledger promotion or live AI acceptance is implied. Research acquisition/orchestration, governed live image generation, asynchronous retry/UNKNOWN recovery, portal mounting, style fixtures and commercial/provider approvals remain separate unfinished work.
