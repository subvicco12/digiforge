# DigiForge v6 release-exit evidence matrix

Authority: Ultimate Master Blueprint v6.0, completion ledger #959 and current certified repository evidence. This document is an operator checklist, **not** an authorization to deploy or execute.

| Gate | Current classification | Evidence required to advance |
| --- | --- | --- |
| Repository implementation | COMPLETE for authorized software scope | Exact-head Engineering & Safety Audit for each new candidate, artifact manifest, commit and package checksums |
| Isolated staging | ACCEPTED for certified PR #992 artifact only | Revalidate exact newly selected artifact after any subsequent installation; do not infer staging acceptance for later merges |
| Runtime safety | Last staging evidence: STOP ALL ON, external lock ON, automation OFF | Fresh read-only health/readiness and direct effective-switch evidence for exact installed artifact |
| Backup and recovery | REVIEW_REQUIRED — explicitly deferred | Genuine retrievable backup, exact compatible restore artifact/checksum, controlled recovery drill PASS and evidence binding |
| Etsy webhook provider delivery/signing | NOT CERTIFIED externally | Provider-side configuration and signed delivery evidence, see `docs/operations/ETSY_WEBHOOK_PROVIDER_CERTIFICATION_CHECKLIST.md` |
| Master 500 v2 | MIGRATION_CANDIDATE, not promoted | Governed candidate validation, immutable human decision, separate explicit promotion authorization |
| Production deployment | NOT AUTHORIZED | Owner's separate explicit deployment approval after required release and recovery gates |
| Etsy/Printify/Gelato/order/GST external execution | NOT AUTHORIZED | Separate scoped authorization, STOP ALL/external-lock governance, live provider readiness and human approval where required |

## Evidence discipline

1. Never describe a successful PR-head audit as an exact-main audit. Record both SHAs.
2. Record the installed artifact ID, manifest SHA, ZIP SHA-256, plugin version, core package schema and runtime additive schema separately.
3. The known accepted staging package is PR #992 / Audit #4190, exact head `0d91bfd4027fd70fe7425d09f92f553a5415b120`, inner ZIP SHA-256 `bc23f36018fe101f6ab4fe3b93f454f70e50805dec0120794a5e5b2069eaac89`, plugin 1.0.103, core schema metadata 23 and post-install runtime schema 24/24.
4. A later merge does **not** update the installed staging package or its acceptance evidence.
5. Failed, stale, missing or unavailable evidence means REVIEW_REQUIRED, never PASS. Do not manually change schema options or disable safety controls.
6. Provider setup, promotion, recovery drill, production deployment and external mutations remain separate operations requiring their own authority.

## Release decision

Until deferred backup/recovery evidence and required explicit approvals exist, keep production deployment blocked and external execution disabled. Do not substitute repository tests, provider mocks or prior staging acceptance for those gates.
