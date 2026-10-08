# Master 500 v2 promotion gate — no execution authority

Authority: DigiForge Ultimate Master Blueprint v6.0 and completion ledger #959. The governed Master 500 v2 migration/ingestion and immutable human ACCEPT/REJECT evidence are implemented. A candidate or accepted review record is **not** a production catalog promotion.

## Read-only candidate review

- [ ] Record exact source workbook identity, migration batch ID, candidate version, immutable v1 parent identity and source checksum.
- [ ] Reconcile all expected rows and product families against the governed migration plan; identify exclusions, duplicates, collisions and unresolved mappings explicitly.
- [ ] Verify candidate provenance, business/store/program scope, deterministic fingerprints and any reviewer decisions against authoritative repository/database evidence.
- [ ] Confirm no missing, stale, contradictory or unavailable acceptance evidence; fail closed on any discrepancy.
- [ ] Record proposed diff from current authoritative catalog, including affected listings, templates, prices and dependencies; do not silently overwrite published identities.
- [ ] Require a human reviewer to explicitly approve the exact immutable candidate, not a mutable alias or a future batch.

## Separate promotion boundary

Promotion remains BLOCKED until the owner explicitly authorizes the exact candidate and the system's existing publication/certification gates pass. Do not interpret migration import, ACCEPT status, a passing repository audit, or a staged preview as permission to promote. No Etsy publish, Printify/Gelato dispatch, financial/tax action or order fulfillment is implied.

## Evidence record

Capture UTC time, candidate and parent identifiers, source/checksum, row counts and exceptions, human reviewer identity and immutable decision, exact code/artifact SHA, gate outcomes, and explicit owner promotion authorization reference. Unavailable evidence means REVIEW_REQUIRED, not PASS.
