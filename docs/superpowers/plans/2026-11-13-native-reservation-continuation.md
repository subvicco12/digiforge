# Native held reservation continuation implementation plan

**Goal:** extend independently testable local persistence without promoting candidate evidence to native execution authority.
**Authority:** owner completion-sprint instruction; existing canonical1.0.0 contract and Batch10 missing-port matrix. Existing isolated worktree retained; no competing branch or PR.
**Architecture:** reuse NativeHeldJournal transactions, sealed history and current signed admission. Add exact idempotent registration and head/fence-pinned transitions; no lease expiry, rearm, migration, sender or operational receipt. Do not create a substitute identity registry from caller claims.

## Tasks
- [x] Add real SQL failing tests for exact registration replay without new events/audit, changed-binding/key denial, cancellation/UNKNOWN non-revival, lost acknowledgement, generation/head/fence substitution and revoked session.
- [x] Implement register() returning only existing exact current candidate state, and transitionPinned() checking expected revision/event head/fence inside the existing SQL transaction. Preserve strict prepare() and existing transition() compatibility.
- [x] Test two-worker registration/cancellation races and storage/readback uncertainty; preserve all-root rollback counterexample and false authority flags.
- [x] Reconcile current operational identity/custody gaps using actual committed contracts. Do not claim closed capabilities without authority roots.
- [ ] Run focused source/extracted WordPress, standalone and actual companion checks; independent review. Commit/push after fresh remote-head reconciliation, build reproducibly, trigger fresh exact-head audit and publish verified receipts.

## Review focus
A changed owner/role/session must deny exact replay too. Matching IDs are insufficient without full immutable binding. A duplicate registration must never append, reset or return a prior fence as current authority. Generation is local record revision/head, not independently enforceable credential generation. A lost acknowledgement stays uncertain until an explicit read-only reconciliation; it never authorizes dispatch. All-root rollback remains a blocker.
