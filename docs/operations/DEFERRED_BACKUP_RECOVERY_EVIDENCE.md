# Deferred backup and recovery certification evidence

Authority: DigiForge Blueprint v6.0, completion ledger #959, and release-exit evidence matrix. **Current status: REVIEW_REQUIRED — owner-deferred.** This document does not claim that a backup exists, was restored or passed a drill.

## Read-only readiness inventory

- [ ] Identify exact candidate plugin artifact ID, manifest commit, package SHA-256, plugin version and core schema metadata.
- [ ] Identify runtime database schema separately (core package schema 23; expected additive runtime 24/24 for the known accepted 1.0.103 staging package).
- [ ] Obtain verifiable backup inventory and retrievability evidence for the correct environment without exposing database contents or credentials.
- [ ] Confirm plugin artifact and database checkpoint are a compatible pair, with exact UTC timestamps and immutable checksums.
- [ ] Confirm STOP ALL ON, external safety lock ON, activation OFF, automation unarmed and effective external switches OFF.
- [ ] Identify isolated restore target; never restore over production/current live site.

## Future controlled drill — requires separate authorization

- [ ] Explicitly authorize isolated target, restore inputs, operator and maintenance window.
- [ ] Verify source integrity and compatibility before any write.
- [ ] Restore compatible plugin plus database checkpoint to isolated target only.
- [ ] Check schema migration behavior, health/readiness, audit and queue integrity, idempotency and no unexpected external actions.
- [ ] Capture failure/recovery logs, checksums, independent verification and operator sign-off.
- [ ] Leave STOP ALL and external safety lock enforced; no automatic retries, Etsy/POD execution or catalog promotion.

## Classification

PASS requires actual retrievable backup, completed controlled restore drill and exact artifact/checkpoint evidence. Missing, stale, mismatched or deferred proof remains REVIEW_REQUIRED. Repository audit success and old staging health do not replace backup/restore certification. Production deployment stays unauthorized pending the applicable release gates.
