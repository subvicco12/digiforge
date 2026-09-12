# BUILD BATCH 3 — Integration Registry & Credential Security (Reconciled)

Target: complete DigiForge by 13 Sep 2026.

This branch was created directly from current `main` after merged BUILD BATCH 2 / PR #9, so Batch 1/2 hardening is the authoritative baseline.

## Required implementation

- Local-only integration registry for Etsy, Printify, Gelato, and AI provider classes.
- Secure credential vault using a dedicated stable `DIGIFORGE_CREDENTIAL_KEY`, domain-separated key derivation, authenticated encryption (XChaCha20-Poly1305 where available, AES-256-GCM fallback), and keyed/HMAC non-secret fingerprints.
- Write-only encrypted integration secrets. Plaintext secrets must never be returned through REST/admin or persisted in ordinary integration config.
- Recursive credential-key detection using the security logger/redactor vocabulary, including nested access tokens, refresh tokens, API keys, client secrets, authorization values, passwords, private keys, signing keys, and credentials.
- Capability-gated REST endpoints under `/wp-json/digiforge/v1/integrations` and read-only Connections admin UI.
- Ordered/resumable schema migration compatible with the existing Batch 2 schema-v5 migration system; preserve Batch 2 migration failure handling and do not regress queue/audit/health behavior.
- Integration and integration-secret tables with appropriate unique/index constraints.
- Audit events for registry changes and credential writes without secret leakage.
- Focused unit/regression tests plus WordPress/MariaDB integration coverage where appropriate.
- Update uninstall cleanup, README/engineering documentation, CI gates, deterministic production ZIP checks, static analysis and coding standards as needed.

## Mandatory safety invariants

- `stop_all` remains ON by default.
- `automation_armed` remains internal, non-writable and fail-closed.
- No Etsy/Printify/Gelato/AI network calls.
- No OAuth exchange.
- No webhook processing.
- No worker execution or schedules.
- No AI execution.
- No publishing, order processing, fulfillment, or deployment.
- No secrets in logs, config responses, source, tests, fixtures, or artifacts.

## Exit gate

Batch 3 is complete only when current CI, PHP syntax, PHPUnit/policy tests, PHPStan, PHPCS, security scans, WordPress/MariaDB integration tests, migration tests, deterministic packaging checks, and artifact integrity checks pass, with no unresolved P0/P1/P2 correctness/security/data-integrity findings. Report exact head SHA and explicit SAFE/NOT SAFE TO MERGE. Do not deploy or activate automation.
