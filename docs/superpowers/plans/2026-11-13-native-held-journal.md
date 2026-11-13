# Native held-journal implementation plan

Goal: independently test local persistence and current protected evidence joins without granting native execution.
Spec: ../specs/2026-11-13-native-held-journal-design.md; pinned companion Batch6/Batch8.

- [x] Pure state tests first: immutable closed binding, local fence one-shot, cancel-before-fence, UNKNOWN terminal, no time-based rearm; then NativeHeldJournalState.
- [x] Passive SQL proposal plus scoped wpdb repository: isolated tables, engines/session/durability checks, transaction+row/CAS+named lock, append-linked events and audit anchors. Missing/corrupt/uncertain evidence never acknowledges.
- [x] Actual disposable WordPress DB tests: two-worker contention, restart/lost ack, rollback/storage/audit failures, changed payload/approval, cross-shop, stale/revoked roles, cancellation and UNKNOWN. No provider/custody calls.
- [x] Protected NativePlanEvidence resolver: existing pipeline.plan/approvalPayload and current ReviewedLocalEvidence/connection identity only. Missing approvals/receipt/allocations stay blocked. Test native unapproved inputs and real synthetic reviewed records where available.
- [ ] Independent review, focused regressions, exact-head CI, capability ledger COMPLETE/PARTIAL/BLOCKED/NOT_IMPLEMENTED, byte-verified Drive receipts and Issue1252. PR1265 remains exact8517778a; do not merge/activate/install/migrate.
