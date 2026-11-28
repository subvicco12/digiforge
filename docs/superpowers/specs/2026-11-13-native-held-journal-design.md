# Native held-journal continuation

Owner-authorized continuation of PR1264 only; PR1265/frozen8517778a and separate API plugin untouched. Companion ac3f61c1 keeps the Batch6 canonical contract unchanged; Batch8 explicitly distinguishes synthetic recovery proofs from independent durability. Audit4966 succeeded for baseline5fc4c7af.

Implement a core-owned **held candidate** journal with actual SQL transactions, append-linked sealed events, immutable binding, candidate-key dedupe, expected-revision CAS, permanent one-shot local fencing, cancellation before fencing only, UNKNOWN preservation and audit-before-ack. This is NOT native dispatch authority, a trusted physical duplicate identity, an execution lease or a qualified recovery checkpoint. All receipts say approval_verified=false, dispatch_permitted=false and independent_checkpoint_verified=false. Do not advertise JOURNAL or enable exchange.

Approaches: legacy wp_options is prohibited for native journals; existing provider-intent listing is unscoped and would expose new records to legacy views; use two isolated core-owned proposed tables. Schema statements are passive, unregistered and never auto-installed. Only explicit disposable WP fixtures execute DDL. Missing tables/engines/durability/session/audit evidence deny. No migration/version/Activator edit.

Reuse native signed module/session checks, wpdb, core Logger and WordPress salt sealing. Scope-first and original actor checks protect reads/transitions; no public routes, cron, callback, provider or credential access. NativeHeldJournalState is pure; NativeHeldJournalSchema supplies passive DDL; NativeHeldJournal is bounded persistence. NativePlanEvidence separately resolves protected core plan+current policy+connection records and returns a minimized non-executable evidence snapshot; raw display/request arrays cannot issue approvals.

Limits: database+salt+audit coordinated rollback remains unqualified; no independent emergency generation/drain/custody root, operational grants, native allocation proof or provider receipt producer is invented. Native factory remains fail-closed. SQL components are development-only and inactive in existing installations.
