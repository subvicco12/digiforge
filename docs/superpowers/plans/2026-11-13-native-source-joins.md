# Native source evidence and observation journal implementation plan

**Goal:** Bind native order observations to actual governed core read producers and immutable source versions, without creating dispatch authority.

**Architecture:** Resolve sealed receipts, reservation audit anchors, original authorization revisions and current reviewed policy before deriving the companion's canonical order identity. Persist nonexecuting observations under transactional source-version CAS; independent checkpoint, custody and allocation authority remain denied when absent.

**Tech stack:** PHP 8.3+, WordPress, InnoDB, PHPUnit; synthetic provider responses only.

**Spec:** `docs/printify-native-core/BATCH10_PORT_JOIN_GAPS.md`, canonical `native-core-port.v1.json`, Issue #1252 owner instructions.

## Constraints
- PR #1264 only; PR #1265 and separate API source untouched.
- No source changes before final Audit #4981 diagnosis (completed: catalog fixture isolation failure).
- No producer IDs, allocation decisions, epochs, grants or commercial approvals invented.
- STOP ALL/external lock ON; automation OFF; manual approval and RELEASE HOLD.
- Passive SQL definitions only; no migration/bootstrap/production changes.

## Review focus
- Retained malformed fixture inventory: isolate and restore exact bytes; never relax runtime ambiguity rejection.
- Renewed approval: historical producer must remain tied to original protected authorization revision.
- Cross-shop and connection revision substitution: current scoped session and exact binding required.
- Crash/commit uncertainty: no acknowledgement, replay rearm or dispatch permission.
- Empty unavailable snapshots: deny rather than substitute empty success.

### Task 1: Diagnose and fix Audit #4981 fixture contamination
- [x] Reproduce `provider_connection_ambiguous_identity` with retained malformed test inventory.
- [x] Isolate synthetic integration rows after snapshot; restore original bytes.
- [x] Focused red 1/3 -> green 1/5; rerun catalog profile after subsequent code work.

### Task 2: Protected producer/source-version resolver
Files: `includes/POD/PrintifyApiReadWorkflow.php`, new `includes/Connector/Printify/NativeOrderSourceEvidence.php`, new `tests/wordpress/NativeOrderSourceEvidenceTest.php`.
Interface: `NativeOrderSourceEvidence::resolve(ShopBinding,int,string): array|WP_Error`; no sender and no operational acknowledgement.
- [x] Write/run failing positive test using actual sealed synthetic core receipt.
- [x] Join exact receipt/reservation audit actor, claim and sealed authorization to current policy and scope.
- [x] Derive canonical provider-order identity only from protected source; immutable source version is its receipt hash, not a numeric business version.
- [x] Test changed receipt/audit, stale policy, cross-shop, role/session revocation and non-ORDER_READ sources.

### Task 3: Transactional held observation registry
Files: new native source journal/schema and focused WordPress tests; minimal read-only port integration only when qualified.
- [x] Write red source-version CAS/replay/concurrent ownership tests.
- [x] Implement explicit observation revisions, immutable event history, transactional audit and postcommit readback.
- [x] Test unavailable storage, stale generations, uncertain commits, replay substitution and retained-audit rollback detection.
- [x] Do not map observation revision to business order/product version or independent recovery epoch.

### Task 4: Acceptance and handoff
- [ ] Run focused source/security and actual companion GET cases, retaining unresolved combined failures.
- [ ] Review exact diff independently, commit only owned changes, reconcile remote advancement without force push.
- [ ] Build twice, compare member/archive hashes, run fresh exact-head audit and report actual outcome.
- [ ] Upload verified immutable receipts/package to canonical Drive and update #1252.

Measured focused WordPress run: 46 tests/273 assertions PASS. Final fixture-specific run: 2/14 PASS. Catalog profile: 58/11805 PASS. Source-only standalone boundary suite: 17/95 PASS. Final exact-head artifact/composition/audit remain separate pending gates. Independent reviewer snapshot findings reproduced red and fixed; no broader operational completeness claim. Peer fixture isolation commit 63780e9bc4df6623f02c0a2b74731e5dc7cd50bc preserved unchanged.
