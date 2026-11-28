# Schema-25 MVP Reconciliation Implementation Plan

Goal: preserve the observed production lineage and integrate the already audited held MVP without production changes.
Architecture: baseline bf23a7a6 plus merged main3758d138, keeping every baseline module. New MVP paths use existing signed ShopAccess grants, never administrator inference. Exact installed raw identity remains a release gate because the authorized reader masks/limits source.
Spec: Issue1252 and user schema25 reconciliation mission; existing Four-Day MVP Blueprint.

- [x] Reverify main and inventory492 installed include paths; compare observed critical sources with history. Use bf23 as reproducible repository baseline, not an assertion of raw installed identity.
- [x] Isolated branch; merge certified MVP. Resolve Portal registration/render by retaining both old and new workflows; retain stricter released-core test fixture installer.
- [x] Regression tests: unassigned privileged actor, wrong shop, incomplete grants, valid signed grants, authority revocation and admin-post admission. Reuse signed ShopAccessFixture for existing MVP positives; no production bypass.
- [x] Add one narrow PersonalizedPodAccess helper over existing grants. Apply to four repo access checks, governed provider preflight and portal admissions. Exact two admin-post actions require helper; unknown actions stay denied.
- [ ] Run focused, unit/static/standards, full WordPress/catalog and offline suites. Inspect schema25/provider/event tests, held outputs and no external execution.
- [ ] Independent security review; resolve confirmed defects. Publish one consolidated draft PR, audit exact head and independently verify built ZIP/manifest/modules.
- [ ] Compatibility report with observed-vs-raw identity limits, additive schema effects, exact candidate evidence and unrecovered backup/files/keys/restore gates; update Issue1252. No merge/deploy/backup/restore.

Review focus: direct repository calls bypassing route guards; stale grants after approval; actual admin-post denial before handler; schema25 no-op data retention; held assets/exports retaining provenance and execution denial. Existing production-only financial/Google/native/editor modules are preserved verbatim through baseline ancestry, not rewritten.
