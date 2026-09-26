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
