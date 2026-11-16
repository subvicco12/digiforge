# Printify API safety sprint — release held

API PR1260, branch `feat/printify-api-completion-20261108`, starting source `200e6101bf8635453b2186be2f45ec87a5946465`. This is a source security correction, not production or hosted certification. The exact final commit is the commit containing this ledger; use its PR head and matching Engineering & Safety run, never the starting audit as certification of the correction.

## Demonstrated defects and corrections

The ordinary independent synthetic sender did not join its final grant generation and workload identity to the current independent root after asynchronous admission. It could also continue with expired inner owner/client authority despite refreshed outer workload proofs, or release successful evidence after expiry during receipt signing. The final service now repeats exact root identity/version/generation joins and all inner/outer lease checks at physical dispatch and successful response release. No new authority, witness root, credential adapter or operational epoch is issued.

The governed PHP workflows validated authorization time at entry but could cross expiry while reserving a claim, querying approval/binding evidence, persisting account quota or waiting for a response. API reads also lacked an authorization-bound approval revision and explicit issuance time. The existing workflow/vault paths now recheck typed server-owned leases and current approval/binding evidence before vault consumption, after final database queries, immediately before the sender and after response projection. The controlled mutation interlock additionally rejects exact expiry before and after its existing joins. The shared core verifier and canonical mutation contract are unchanged.

Read grants issued before this correction lack the required issuance/revision fields and fail closed; any future authorized use needs a newly reviewed grant. Existing three-attempt safe-read budgets are unchanged, but cannot continue after expiry. Writes still have no automatic retry. An attempted request whose evidence expires produces UNKNOWN without releasing data, restoring claims or permitting replay. Historical synthetic receipts can remain in an immutable terminal journal; they do not grant current authority and are not released as successful evidence after admission expires.

## Requirement ledger

| Sprint requirement | Implemented / verified boundary | Remaining disposition |
|---|---|---|
| Verify current source and reuse evidence | PR1260 was open/draft at exact starting SHA; prior source audit43687155980 succeeded. Earlier artifacts remain historical evidence. | Final SHA needs its own ordinary source CI. |
| Authority and stale-proof rejection | Final current root epoch/account/credential/workload key/code/version/generation joins; inner owner/client and outer workload/custody expiry checks. Six new synthetic timing regressions. | CODE COMPLETE / OFFLINE TESTED for these corrections; independent administration and operational issuer remain unqualified. |
| Credential lifecycle and custody | Existing vault only; optional denial-only admission callback before secret access/decryption, before/after consumer and after last binding query. All active governed read/product/lifecycle/controlled mutation callers provide their existing authoritative checks. | No credentials accessed/admitted. Real token/account/scope/expiry association, exclusive usable custody and invalidation of old copies remain BLOCKED. Legacy direct reconciliation lookup remains unconditionally denied. |
| Native mutation admission | Existing permit, approved request, shop/integration/credential/package/dependency joins, durable claims and global gates retained; strict exact-expiry checks repeated through the existing transport. | Native REGISTER/CLAIM and epoch-dependent receipt/publication/allocation/recovery authority remain core-owned and denied without qualified independent checkpoint evidence. No invented RESERVE alias or epoch. No native positive mutation pass claimed. |
| Journals, leases and UNKNOWN | Existing atomic slot/nonce/quota/journal/lease machinery retained. New expiry test checks terminal historical receipt, consumed original nonces, settled root lease, UNKNOWN caller disposition and no replay. | Durability/rollback of all trusted roots is not qualified. No rearm, retry or historical reservation22 replay. |
| Rollback/concurrency/cancellation/STOP | Existing focused authority/custody/checkpoint suites retain concurrency, drain, outage, rollback and replay coverage. Actual isolated WordPress tests exercise STOP during quota and cancellation expiry with synthetic transport. | All-root rollback and independently usable old-token-copy counterexamples remain release blockers. |
| Companion compatibility | Frozen core78e7f973 / companion4da802a1 ZIP hashes and CRCs reverified; exact canonical v1 contract matches all three copies. Portable real-source schema/parity tests pass. | CorePR1264 and companionPR1263 untouched. This is not a new native WordPress/core mutation integration pass. Missing native authority must be supplied by the separate core lane after independent checkpoint qualification. |
| Regression/security | Full PHP/WordPress, focused Node, portable parity, offline, lint, configured static/standards and security gates below. | Source CI and local evidence are separate; neither establishes hosted/provider acceptance. |
| Delivery | Corrections remain on existing API draft PR1260; tracked inventory workflow and protected execution branch/rulesets are unchanged. | No merge, deployment, production package installation or release authorization. |

## Qualification

- PHP8.3.36, MariaDB10.11 and WordPress7.1.4 in disposable local containers with networking disabled. Only synthetic identities, independently reviewed fixture approvals and synthetic credential material were used. The test database durability setting1 is isolated; production setting2 was not changed or treated as acceptable.
- Full PHP unit:1578 tests /8791 assertions; all configured legacy suites pass.
- New lease predicate:3 unit tests; real WordPress lease/approval/vault/final-query regression class:15 tests /39 assertions.
- Focused Node authority/custody/checkpoint/native-observer suites:173 tests,0 failures/skips. Includes six new regressions, observed RED before source correction.
- Portable PHP/Node GET grammar and frozen real-core conformance:22 tests,0 failures/skips. These do not bootstrap a new native WordPress mutation authority.
- Offline Python:143 tests, one pre-existing optional Playwright/browser skip; no failed tests. Expected malformed-evidence negative fixtures emit REVIEW_REQUIRED diagnostics.
- Full WordPress:549 tests /5151 assertions,0 failures/errors,2 existing conditional skips (absent/unintegrated dependency denial cases skip when the corresponding dependencies are present). Clean serial run completed in8m54s. The concurrent-worker claim test passed with corrected fixture dependencies.
- Configured PHPStan level8, direct new-lease level8, configured PHPCS, full PHP lint, existing external-HTTP/secret security gate and git diff checks pass.
- Fresh technical review reproduced additional owner/client expiry and final-query races; fixes and RED/GREEN regressions were reviewed. No remaining Critical/Important finding; this is technical review, not independent human operational approval.
- Invalidated local harness runs are excluded from acceptance evidence: overlapping probes shared a config through a fixture symlink; a nested ignored vendor symlink also prevented child bootstrap. No assertions or tracked test gates were weakened. The accepted serial run uses corrected dependencies, a clean database and no concurrent WordPress probes. Existing audit-table dbDelta warnings, inherited unit-fixture undefined-variable/dynamic-property notices and intentional missing-table/fault diagnostics are recorded separately from test failures.

## Preservation and compatibility identities

`SAFETY-CORRECTION-OVERLAY-20261116.json` records the exact old/new runtime hashes:483 of the historical489 files unchanged, six intentional existing runtime corrections and one new negative-only lease helper. The frozen original preservation manifest is not rewritten as current evidence. Settings, provider mapping, owner authentication, scheduler and ordinary safety policy files remain unchanged. No new schema/migration or approval store.

Canonical v1 contract SHA256: `6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434`.

- Frozen core78e7f973 ZIP SHA256: `6c07cdd99397ee3423420e3db97519e027d1eff87dd6b4c944fbec65a83c2270`.
- Frozen companion4da802a1 ZIP SHA256: `9091ec0a029ebe228cf0cc47c631a787adeb7a28d3333a13579a0157be94f361`.

These are preserved reference packages, not replacement builds or installation approval. The existing46-operation API ledger `operations-1.0.103.25.json` remains historical scoped offline coverage; its SOFTWARE_COMPLETE_OFFLINE label must not be generalized to native operational mutation completion.

## Remaining software and external gates

Overall full-version native mutation integration is **not CODE COMPLETE**. A real independently governed checkpoint issuer/verifier and mandatory final-sender/credential-custody adapter remain operationally unimplemented or unqualified. Core-owned registration/claim/journal/receipt/publication/allocation/recovery joins cannot safely be supplied by this API session or by synthetic observer evidence. The separate core lane must retain the canonical v1 denial until it has authenticated, current independent epochs and authoritative identity/version/approval joins.

The three retained counterexamples are coordinated rollback of every trusted root, replay through an independently usable old credential copy, and an administrator replacing cooperative witness/dispatch code within the same control domain. Passing their counterexample tests records the blocker; it does not establish recovery safety.

Independent recovery administration, rollback-resistant witness/final dispatch, hostile restore tests, exclusive usable credentials, old-token invalidation, account/credential/Shop2 association, hosted durability/entitlement evidence and separate installation/binding/live acceptance approvals remain BLOCKED. No HOSTED VERIFIED or PRODUCTION AUTHORIZED result exists for this correction. STOP ALL ON, external lock ON, automation OFF and RELEASE HOLD remain in force. No provider requests, real credential access, infrastructure provisioning, production changes, merge or operational policy activation occurred.
