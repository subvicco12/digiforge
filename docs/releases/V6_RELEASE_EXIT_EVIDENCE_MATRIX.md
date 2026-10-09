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


## Audit #4409 staging installation evidence (2026-10-09; read-only observations)

- Certified main: `313db42f44c4ab1b378b6c414356ad31d3f1dc62`; Engineering & Safety Audit #4409, workflow run `37959119236`, SUCCESS.
- GitHub artifact ID `11630856973`, outer artifact digest `sha256:37c14c53a79897769594b9a7bbb9c982434b64d355c039921317ad200cce7a41`; packaged inner ZIP digest reported `sha256:e6da619ba041746e99601e6ad424072f9900fa516b515dca0a498f964e58e225`.
- Owner reported installing the supplied plugin ZIP on isolated staging. Read-only authenticated WordPress checks afterward show DigiForge active at version 1.0.103; health OK; runtime schema 24/24; STOP ALL true; externally locked true; automation false; activation not authorized; automation unarmed; no effective feature switches.
- **Classification: STAGING HEALTH VERIFIED; EXACT PACKAGE IDENTITY UNVERIFIED.** Version 1.0.103 is shared by prior accepted packages; the live endpoints do not expose the exact installed commit or file checksum. Do not upgrade this to exact-artifact ACCEPTED without an independent on-host checksum or signed installation manifest matching Audit #4409.
- Readiness remains REVIEW_REQUIRED: backup retrievability gate false and current recovery-drill evidence stale/unbound. This installation authorization did not authorize a recovery drill, production deployment, provider execution or Master 500 promotion.
