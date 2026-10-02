## 1.0.103 — 2026-10-02

- Isolates Approval Inbox and production-provenance database evidence from stale prior query errors while preserving independent later reads.
- Fails provenance anomaly projections closed when the current anomaly, outcome, lifecycle-closure, acknowledgement, historical-evidence, or coverage query is unavailable; unavailable evidence cannot become a false zero.
- Makes downstream attention totals unavailable when provenance evidence is incomplete instead of presenting an authoritative zero.
- Adds adversarial stale-error/current-error/later-success coverage and preserves read-only, no-retry, no-external-authority semantics.
- Database schema remains v23. Installation performs no Etsy publish, POD production/provider dispatch, retry/replay, catalog promotion, money movement, tax filing, or other consequential external action.

## 1.0.93 — 2026-09-30

- Starts Phase-1 Personalized POD operational completion by surfacing bounded authorization-package and current Printify preflight evidence in the live-site Personalized POD portal.
- Distinguishes human-review-required, current-preflight, revalidation-required and evidence-unavailable states with exact blockers while failing closed on database/preflight errors.
- Keeps provider execution, permit issuance and retry unavailable from the view; STOP ALL and the external safety lock remain authoritative.
- Adds a portal-first authenticated human-review transition for pending Personalized POD authorization packages, reusing the existing conditional repository transition and recording audit evidence. It cannot issue permits or call a provider.
- Updates the future-lane comparison to identify the personalized lane as Phase-1 operational / externally locked. Database schema remains v23.

## 1.0.84 — 2026-09-30

- Fixes the governed Etsy publish execution boundary so ETSY_PUBLISH_LISTING is accepted by the shared short-lived authorization verifier and evaluated through the dedicated Etsy publish policy rather than the draft policy.
- Preserves the existing explicit human approval, readiness/evidence binding, verified shop/listing scope, one-time nonce, live publish interlock, fail-closed idempotency, and no-automatic-retry protections.
- Database schema remains v23. Installation itself performs no Etsy publication or other external execution.

## 1.0.83 — 2026-09-30

- Adds the governed Etsy listing publication execution boundary for an explicitly approved listing, approved intent/package, verified shop identity, and previously confirmed CREATE_DRAFT identity.
- Publishes only through a dedicated PUBLISH_LISTING ledger operation using the controlled Etsy transport, with effective Etsy Publish capability enforcement and exact request/evidence binding.
- Prevents replay after confirmed publication and fails closed on prior attempts, conflicting idempotency reuse, stale readiness, invalid identity, or uncertain external outcomes; automatic retry remains disabled and UNKNOWN requires reconciliation.
- Preserves the existing draft planner's publish prohibition. Database schema remains v23. Installation itself does not publish Etsy, repeat draft/file operations, submit POD production, execute orders, move money, or file tax.

## 1.0.82 — 2026-09-29

- Adds the governed live-site Listings & Etsy human decision surface for persisted PENDING Gate 3 listing-readiness reviews.
- Requires listing-management capability and WordPress nonce protection, and routes decisions through the existing transactional readiness-review decision path.
- Fails closed when persisted readiness JSON is malformed, its SHA-256 evidence hash mismatches, required SEO/media/POD evidence is blocked, or the listing is not REVIEW_REQUIRED; decision controls are withheld.
- Database schema remains v23. This release does not make a Gate 3 decision, publish/upload to Etsy, submit POD production, execute orders, move money, file tax, or grant new external authority.

## 1.0.81 — 2026-09-29

- Hardens the legacy Gate 3 repair by locking the target listing row before rechecking persisted review evidence, preventing concurrent duplicate repair.
- Recomputes readiness only after transition to REVIEW_REQUIRED and historical approval fields are cleared, so the new PENDING review stores post-transition evidence.
- Adds an authenticated, capability-protected, idempotency-guarded REST invocation for the internal legacy repair.
- Database schema remains v23. This release does not infer a human decision, publish Etsy, submit POD production, execute orders, move money, file tax, or grant new external authority.

## 1.0.80 — 2026-09-29

- Adds an explicit fail-closed legacy Gate 3 repair for already-approved listings that have no persisted listing-readiness review evidence.
- Revalidates current SEO, approved media, and POD binding prerequisites before repair.
- Transactionally returns the affected listing to REVIEW_REQUIRED, clears historical approval fields, and creates a new PENDING human Gate 3 review.
- Refuses repair when any Gate 3 review evidence already exists and records dedicated audit evidence on successful internal repair.
- Database schema remains v23. The repair does not infer approval, publish or retry Etsy, submit POD production, execute orders, move money, file tax, or authorize external execution.

## 1.0.79 — 2026-09-29

- Restores persisted Gate 3 listing-readiness review evidence during governed listing preparation.
- Requires listing approval to flow through a persisted pending Gate 3 review; direct listing approval fails closed.
- Makes Gate 3 approval/rejection and listing lifecycle updates transactional, including transition to REJECTED on rejection.
- Adds authenticated, idempotency-guarded review-decision REST handling and regression coverage.
- Database schema remains v23. This release does not publish to Etsy, submit POD production, retry external operations, move money, or file tax.

## 1.0.78 — 2026-09-29

- Fails closed if a controlled capability activation succeeds but its mandatory authorization audit record cannot be persisted.
- Restores the full protected posture for Research audit failure and revokes scoped authorization for AI, Product Development, Printify, Etsy Draft, and later activation stages.
- Adds regression coverage for activation audit rollback while preserving STOP ALL, schema v23, and all separate external-execution gates.
- Performs no production activation or external action during upgrade.

## 1.0.77 — 2026-09-29

- Adds exact read-only operator guidance for registering genuine host-created database-backup evidence and genuine recovery-drill evidence.
- Makes required provenance fields and exact artifact bindings explicit without claiming DigiForge creates, retrieves, verifies, or performs the backup or drill.
- Preserves STOP ALL, external lock, fail-closed recovery gating, schema v23, and all non-authorizing execution boundaries.

## 1.0.76 — 2026-09-29

- Exposes recovery-drill verification state and exact database-backup / rollback-plugin bindings in the live-site Attention & Recovery portal.
- Keeps the recovery view read-only: it cannot perform a drill, retry or replay work, activate integrations, or authorize external execution.
- Preserves the v1.0.75 fail-closed drill-evidence gate and database schema v23.

## 1.0.75 — 2026-09-29

- Requires fresh, concrete recovery-drill evidence before operational recovery readiness can pass.
- Binds verified drill evidence to the current database-backup and rollback-plugin artifact identifiers and fails closed when evidence is stale, incomplete, or mismatched.
- Adds authenticated management endpoints to record/read drill evidence without performing or simulating a restore.
- Preserves STOP ALL, external lock, schema v23, and non-authorizing recovery evidence; no Etsy publish, POD production, money movement, tax filing, or other external action is authorized by this release.

## 1.0.74 — 2026-09-28

- Fixes active-plugin upgrades so normal boot runs the additive v16-v23 migration chain when an in-place plugin replacement does not fire the activation hook.
- Reproduces schema-v22-without-v23-table and proves normal boot repairs it to schema v23.
- Preserves STOP ALL, external lock, recovery gating and non-authorizing execution boundaries; no external action is authorized.

## 1.0.74 — 2026-09-28

- Fixes the active-plugin production upgrade path so normal plugin boot runs the additive v16-v23 migration chain even when WordPress does not fire the activation hook during an in-place plugin replacement.
- Adds regression coverage reproducing an active schema-v22 installation with the v23 scoped-policy table absent and proving normal boot repairs it to schema v23.
- Preserves STOP ALL, external lock, recovery gating and all non-authorizing execution boundaries; no external action is authorized by this hotfix.

## 1.0.74 — 2026-09-28

- Advances the additive database schema to v23 with durable append-only, versioned shop/workflow scoped capability-policy evidence and hash lineage.
- Adds bounded current/history policy projections; policy evidence remains read-only/non-authorizing and cannot override STOP ALL.
- Adds fail-closed queue recovery classification and correlated orphan/rate-limit/idempotency/dead-letter evidence; automatic recovery, replay and retry remain disabled.
- Expands governed AI/budget planning evidence without running AI or granting external execution authority.
- Adds bounded non-secret audit object correlation and consolidated read-only Printify UNKNOWN/reconciliation evidence; UNKNOWN requires reconciliation before retry.
- Hardens direct v22→v23 migration, missing-table repair, and schema-v23 reactivation/idempotency preservation.
- Consolidates live-site portal and release-readiness safety regressions for the integrated v23 baseline.
- Recovery readiness remains fail-closed; this release does not deploy production or authorize Etsy publish, POD production, provider execution, refund, tax filing, money movement, or other external action.

## 1.0.72 — 2026-09-28

- Consolidates Etsy and Printify UNKNOWN/reconciliation-required evidence with fail-closed RECONCILE BEFORE ANY RETRY guidance.
- Adds bounded per-order discrepancy evidence and governed operational-alert navigation.
- Adds bounded fulfillment, finance-ledger and tax/GST exception projections without sensitive payload/evidence blobs.
- Adds non-secret audit correlation identities and safe exception drill-down/navigation to Audit/Reconciliation.
- Every new exception projection remains read-only, non-retryable and non-authorizing for external execution; finance/tax evidence cannot move money or file tax.
- Preserves STOP ALL, recovery gating and schema v22; release performs no external action.

## 1.0.71 — 2026-09-28\n\n- Expands the live-site portal with Businesses/Brands/Shops, Automation/Queues, Analytics, Finance & GST, and Settings operational areas.\n- Adds shop-scoped context, bounded non-authorizing queue attention drill-down, and consolidated approval-gate visibility.\n- Adds internal governed-workflow navigation from attention records without inferring approval or execution authority.\n- Hardens operator tables and controls for tablet/mobile use.\n- Preserves STOP ALL, recovery gating, explicit approval boundaries, and schema v22; release performs no external action.\n\n## 1.0.70 — 2026-09-28

- Adds explicit MISSING / VERIFIED / STALE database-backup status projection.
- Adds bounded read-only recovery evidence history drill-down (default 25, maximum 200).
- Every historical evidence item explicitly denies retry and external execution authority.
- Preserves 24-hour independent reverification and fail-closed recovery readiness.
- Database schema remains v22; release performs no external action.

## 1.0.69 — 2026-09-28

- Requires independently verified database-backup evidence provenance before recovery readiness can pass.
- Expires database-backup verification after 24 hours and fails closed on stale, invalid, pre-capture or future verification timestamps.
- Portal distinguishes VERIFIED / FRESH from STALE / REVERIFY.
- Evidence remains non-authorizing; schema remains v22; no external action is performed by this release.

## 1.0.68 — 2026-09-28

- Adds authenticated recovery-evidence management endpoints for concrete backup and rollback-package records.
- Adds read-only live-site operator visibility for artifact identity, retrievability and checksum state.
- Missing or UNKNOWN evidence remains fail-closed and grants no retry, activation, Etsy publish, POD production or provider authority.
- Database schema remains v22; release performs no external action.

## 1.0.67 — 2026-09-28

- Replaces declaration-only recovery artifact readiness with structured database-backup and rollback-package evidence.
- Requires concrete artifact identity, location and retrievability; rollback packages additionally require source commit, SHA-256 and checksum verification.
- Missing or UNKNOWN recovery evidence remains fail-closed and cannot grant production, Etsy publish, POD production, retry, or provider authority.
- Database schema remains v22. No external action is performed by this release.

## 1.0.66 — 2026-09-28

- Consolidates the audited v6 operational-safety work accumulated after v1.0.65 into a distinct deployment candidate.
- Advances the additive database schema contract to v22, including governed provenance bindings, lifecycle closure evidence, immutable provenance-integrity evidence, acknowledgement evidence, and durable permit-persistence observations.
- Hardens production permit consumption around transactional table requirements, package provenance locking, nonce replay prevention, binding conflicts, and ambiguous COMMIT reconciliation.
- Preserves historical integrity anomalies after source-data repair and separates current OPEN, ACKNOWLEDGED, and HISTORICAL operator states.
- Adds deterministic concurrent acknowledgement race coverage: identical winners are idempotent; divergent winners fail closed.
- Adds bounded read-only operator drill-down for permit persistence and exposes integrity, persistence, queue recovery, and release-safety evidence in the live-site Attention & Recovery portal.
- Adds direct v21→v22 migration and v22 reactivation/idempotency coverage.
- Adds a fail-closed release-candidate evidence gate requiring exact-commit audit/package identity, checksum verification, real backup evidence, restore readiness, and locked production-smoke prerequisites.
- Recovery/readiness evidence remains informational only: it cannot restore a consumed nonce, permit retry, authorize Etsy publish, authorize POD production, or activate external execution.
- Adds an authenticated, management-capability protected REST transition that invokes the same transactional protected-posture recovery used by the portal; it can only revoke execution authority/arming/scoped authorizations and assert STOP ALL, while preserving configured feature switches.\n- This release candidate does not deploy production and performs no Etsy publish, POD production, provider mutation, or other external action.

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
