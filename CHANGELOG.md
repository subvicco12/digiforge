## 1.0.65 — 2026-09-27

- Replaces inline Etsy media multipart assembly with a byte-exact builder that emits required text fields and binary media with a matching quoted boundary and explicit content length.
- Adds executable binary-preservation regression coverage for governed Etsy digital-file upload.
- Corrects Etsy webhook signature verification to derive the HMAC key from the documented `whsec_` Base64 signing secret format.
- Adds a public signature-first Etsy webhook REST ingress backed only by server-side signing configuration; request parameters cannot supply the secret.
- Adds a protected, non-secret webhook readiness endpoint for operational visibility.
- Preserves the five-minute replay window, webhook event deduplication, supported order lifecycle events, publish-disabled execution, and no automatic Etsy mutation retry.
- No Etsy publish, POD order, or GST action is performed by this release.

## 1.0.64 — 2026-09-27

- Hardens the governed Etsy digital-file runtime after the first confirmed provider-side HTTP 400.
- Fixes multipart callback capture so the validated digital-file plan is available when emitting the Etsy file-name form part.
- Binds post-create media response parsing to the persisted confirmed listing resource identity when the operation has no separate external reference.
- Adds bounded, allowlisted provider failure diagnostics (error/code/message only) while continuing to suppress raw response bodies, headers, credentials, and request data.
- Preserves exact approved ZIP checksum binding, confirmed CREATE_DRAFT sequencing, no automatic retry, and publish-disabled execution.
- Adds regression coverage for multipart runtime capture, bounded diagnostics, raw-body suppression, and media resource identity.

## 1.0.63 — 2026-09-27

- Adds the exact controlled Etsy `UPLOAD_FILE` pipeline target: POST to the confirmed listing's `/files` endpoint.
- Binds digital-file listing identity to the persisted `resource_reference` from the confirmed CREATE_DRAFT parent.
- Preserves exact prepared-payload fingerprint equality, numeric shop scope, network-disabled planning, and publish-disabled execution.
- Adds regression coverage for the UPLOAD_FILE operation, /files endpoint, and parent resource binding.
- No Etsy external action is performed by this release.

## 1.0.62 — 2026-09-27

- Adds the governed `ETSY_DRAFT_FILE` action to execution authorization and verification for controlled digital-file upload.
- Aligns the controlled upload preparation payload with the exact request payload already fingerprinted and persisted.
- Preserves the existing confirmed CREATE_DRAFT parent requirement, draft-only Etsy execution policy, one-time authorization, and request fingerprint checks.
- Adds regression coverage proving no Etsy publish authorization is introduced.
- No Etsy external action is performed by this release.

## 1.0.61 — 2026-09-27

- Fixes the controlled Etsy digital-file planner to consume the governed multipart `filename` metadata produced by the approved customer-package builder.
- Preserves the existing binder mapping of that exact filename to Etsy's multipart `name` field and the executor's file disposition.
- Adds regression coverage across planner, binder, and executor for the filename/name boundary.
- Does not alter approved Product #25 bytes, checksum, confirmed draft state, or publish controls.
- No Etsy external action is performed by this release.

## 1.0.60 — 2026-09-27

- Fixes controlled Etsy CREATE_DRAFT payload binding so authorization, ledger fingerprint, preparation, and planned transport use the exact sanitized external payload.
- Keeps compiler-only `_digiforge` compliance evidence internal and outside the Etsy request fingerprint/body.
- Preserves fail-closed request-fingerprint validation; no guard is bypassed or weakened.
- Adds regression coverage for the sanitized CREATE_DRAFT boundary.
- No Etsy external action is performed by this release.

## 1.0.59 — 2026-09-27

- Adds fail-closed governed Etsy AI-assisted compliance evidence for controlled draft compilation.
- Requires explicit authenticated seller attestation and AI disclosure approval before compiling an Etsy draft.
- Preserves the immutable approved listing snapshot while appending the approved AI-assisted disclosure only to the external draft description.
- Binds deterministic compliance evidence and hash internally; existing verified taxonomy, explicit quantity, digital-download, idempotency, and no-publish controls remain enforced.
- No Etsy external action is performed by this release.

## 1.0.40 — 2026-09-26

- Adds the fail-closed Stage 5 Etsy Draft scoped authorization foundation.
- Adds non-user-writable Etsy Draft authorization, read-only activation preflight, and protected-state revocation.
- Keeps Etsy Draft ineffective until separately authorized and keeps Etsy Publish, Printify/Gelato, orders, fulfillment, finance, and GST unauthorized.
- Bumps the plugin release metadata so the certified Stage 5 foundation can be deployed as a distinct WordPress upgrade package without changing database schema v14.

## 1.0.39 — 2026-09-26

- Adds separately governed Stage 3 Product Development authorization with a non-user-writable gate.
- Adds a read-only Stage 3 preflight requiring effective Research and AI before Product Development authorization.
- Adds atomic scoped activation, front-end authorization control, and audit evidence.
- Keeps Printify, Gelato, Etsy, orders/fulfillment, finance, and GST ineffective.

## 1.0.38 — 2026-09-26

- Adds a front-end controlled AI provider connectivity test available only while Research and AI are effective and Product Development remains ineffective.
- The controlled test performs exactly one minimal provider request with no web search, downstream workflow, persistence into Product Factory, or automatic retry.
- Adds sanitized audit evidence for success, transport/provider failure, and connector/credential setup failure.
- Extends unit and Engineering & Safety Audit coverage for the third audited OpenAI POST call site.

## 1.0.37 — 2026-09-26

- Fixes the Stage 2 AI activation preflight rendering path so the separately authorized AI activation control appears when its read-only preflight is ready.
- Adds regression coverage for AI preflight initialization in the front-end controls renderer.
- Performs no capability activation, provider request, or external action during upgrade.

## 1.0.36 — 2026-09-26

- Adds separately governed AI capability authorization after Research activation.
- Adds read-only AI activation preflight for connector and encrypted credential readiness.
- AI activation fails closed and is transactional; activation itself performs no provider request or external action.
- Product Development, Etsy, Printify, Gelato, Orders and GST remain separately ineffective.

## 1.0.35 — 2026-09-26

- Replaces the legacy global production release with fail-closed Research-only capability authorization.
- Requires the dedicated Research activation preflight immediately before authorization and releases no provider request or external action by itself.
- Keeps AI, Product Development, Etsy, Printify, Gelato, order, and GST capabilities ineffective until separately governed authorization stages.
- Protected-state recovery revokes Research authorization while preserving feature configuration.

## 1.0.34 — 2026-09-26

- Adds a front-end protected-state recovery action for safely returning activation gates to the certified pre-release posture.
- Recovery requires transactional settings storage and fails closed before changing gates when atomicity cannot be guaranteed.
- Feature switch configuration is preserved; no provider request or external action is performed by recovery.

## 1.0.33 — 2026-09-26

- Fixes Research Activation Preflight credential decryptability detection to use the runtime integration credential vault.
- Keeps the read-only preflight non-provisioning: it never creates or persists a managed credential key while checking decryptability.
- No production activation, provider request, or external action is enabled by this release.

## 1.0.32 — 2026-09-26

- Front-end Operations Console now exposes Research activation preflight, Attention & Recovery, separate Digital Products, Personalized POD, and Future Non-Personalized POD lanes, plus secure front-end AI credential replacement.
- Production activation remains fail-closed; no external actions are enabled by this release.

# Changelog

All notable DigiForge changes are recorded here.

## 1.0.31 — 2026-09-25

### Changed

- Final release certification now emits the exact plugin ZIP, SHA-256 checksum, and deterministic release manifest bound to the certified commit.
- Final readiness metadata is aligned to plugin version 1.0.31 and database schema version 14.
- Added regression coverage for the DigitalFactory create-response insert ID defect already fixed in current runtime code.

### Safety

- Production remains READY_LOCKED.
- No Etsy, Printify/Gelato, fulfillment, finance, tax/GST, or other external action is activated by this release.

## 1.0.0 — 2026-09-16

### Added

- Complete DigiForge operational architecture for Research, Product Factory, Digital Products, POD, Listings & Etsy, Orders & Fulfillment, Finance & Analytics, Integrations, Audit, and system controls.
- Three explicit human approval gates for opportunity approval, finished-product approval, and listing/publish approval.
- Run-aware Product Factory orchestration with protected repair storage, deterministic replay protection, asset QA, semantic QA, customer-package isolation, and release-bundle evidence.
- Digital and POD listing readiness with approved media, personalization, provider mapping, economic evidence, and blocked external intents until the required approvals and controls are satisfied.
- Order and fulfillment readiness with human personalization review, readiness hashes, approved fulfillment plans, and blocked provider intents.
- Finance and analytics safety controls including currency-consistent calculations, immutable ledger protections, human tax-classification review, and blocked tax-review intents.
- Secure integration registry and credential vault with encrypted provider credentials, read-only connection testing, Etsy OAuth token refresh, and sanitized audit evidence.
- Operational readiness, recovery-drill evidence, retention policy, structured health checks, and auditable control-state evaluation.
- Canonical Git-based plugin packaging with SHA-256 checksum and package-boundary verification.

### Changed

- Research candidate idempotency now preserves exact replay after human review and opportunity promotion while continuing to reject immutable candidate-data conflicts.
- Listing, POD, order, finance, and Research repositories use stricter idempotency/replay compatibility and stale-readiness checks.
- Product, marketing, release, repair, and customer-package identities are isolated by run/revision to prevent stale artifact reuse.
- DigiForge frontend/control-center architecture is the canonical operational interface; WordPress administration remains a maintenance surface.

### Safety

- Human approval remains mandatory at Gate 1 (opportunity), Gate 2 (product), and Gate 3 (listing/publish).
- External Etsy publishing, Printify/Gelato provider actions, order/fulfillment automation, and GST/tax execution are not activated by this release.
- External-action intents remain preparation/blocked records until their applicable human approvals, readiness checks, and runtime controls permit execution.
- STOP ALL and effective-switch safeguards remain part of the runtime control model.
- Database schema version remains 13; this release metadata change does not introduce a schema migration.
