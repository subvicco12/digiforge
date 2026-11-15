# Native checkpoint observation qualification plan

Goal: qualify a non-authorizing synthetic checkpoint observer against the canonical native v1 binding, reusing the existing independent final-sender/fault harness. Owner authorizes offline preparation only. Core 1264 and companion 1263 are read-only references.

Architecture: WordPress owns business approval, product/shop versions and STOP. Cloudflare Worker/DO coordination holds no provider credential and receives no Boolean send permit. A separately governed authority/witness and mandatory final sender must supply fresh authenticated evidence and own physical dispatch. Their actual administrative independence, restoration resistance and exclusive custody remain unqualified. Same-account labels and signatures do not establish those facts. Existing all-root rollback remains a release blocker.

Spec: Ultimate Master Blueprint v6.0 sections 16.2/23/31; Connector Blueprint1.0 sections 6/8; native-core-port.v1.json at core 78e7f973455a3c89319be06f0907caf2103658a7; its proposed independent checkpoint interface 1.0.0. Existing source97df3bce052dc24dc789528ebe709b4959eeee96 must be preserved.

Constraints: no production/runtime/PHP/core/companion changes, deployment, credentials, provider requests, migration, activation or merge. STOP/lock ON, automation OFF, RELEASE HOLD. No guessed epoch or local hash may supply native authority. Preserve frozen Stage A target/caller build identity and disabled inventory workflow.

Review focus: cross-purpose signature replay; stale witness after a cryptographic await; concurrent checkpoint responses; same-root or same-administrator claims; accidental conversion of a diagnostic epoch into a native grant.

1. Baseline: verify current PRs, governing clauses, inherited custody/crash fixes, and complete dispatcher suite. Source97df exact audit43461130260 PASS; 211 Node/PHP parity/fault tests PASS using the documented no-isolation invocation and authorized local child processes.
2. RED/GREEN: add a synthetic-only checkpoint observer and tests. Bind all 15 native binding fields, immutable physical context, request/challenge, signature purpose, independently pinned issuer/witness keys and current monotonic revision vectors. Reject stale/conflicting/fabricated evidence, unavailable witness and replay. Return only a local observation with mutation/network/operational authority false.
3. Fault qualification: reuse actual AuthorityJournal SQLite transitions and existing mandatory synthetic sender; add operation-binding, epoch recovery, signed-response race, all-root rollback and old-credential-copy counterexamples. Preserve permanent503 outside the synthetic factory. No native mutation success is claimed.
4. Review/regression: fresh independent source review, all existing/new Node/fault/PHP parity tests, configured PHP/unit/WordPress/offline/static/security checks and exact-head CI. Package source/evidence only, independently rebuild/read back on canonical Drive. Keep baselines unchanged and record remaining hosted/custody/core producer blockers.

Ruling: the owner already selected Cloudflare and explicitly authorized this isolated offline implementation; no policy adoption or deployment decision is inferred. A further architecture choice is not required to implement a non-authorizing observer. Operational witness/final-sender provisioning remains a separate owner decision.
