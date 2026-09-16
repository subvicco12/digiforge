# Changelog

All notable DigiForge changes are recorded here.

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
