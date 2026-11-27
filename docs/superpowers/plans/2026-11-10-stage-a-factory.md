# Stage A synthetic factory implementation plan

Base b2d523b709fec8765a14317ce3a40308cb161159. Owner supplied the resource contract and approved offline implementation; no provisioning or execution is authorized.

1. Add failing behavioral tests for strict synthetic mode/bindings, HTTP503, private fixed-intent RPC, egress sealing, persistent SQLite state and case/budget limits.
2. Implement a bounded SQL probe reusing SqliteState, dedicated per-case tables and a durable singleton run budget. One private invocation intent advances one fixed case; repeated intent returns its persisted result without advancing; no caller-selected faults/reset/URLs. Interrupted cases fence the run; completed boundaries rehydrate without rearming an old session.
3. Implement synthetic runtime factory and private WorkerEntrypoint/DurableObject classes. Exact named binding/object, strict vars, window/build/object pinning; global network APIs sealed plus frozen dependency graph. No provider adapter/credentials/queues/alarms.
4. Implement deterministic fault cases and file-backed SQLite tests; retain existing104 tests and all-root counterexample as RECOVERY_BLOCKED.
5. Produce a deterministic source bundle with source SHA/file hashes, dependency lock, disabled configuration template, setup/cleanup instructions and usage assumptions. Run full regressions and exact-head audit; upload/read back canonical Drive artifact.

Security review focus: public HTTP never reaches a binding; unknown bindings/modes fail before storage; no dynamic network imports; quotas/accounting are atomic; unknown/in-flight work cannot be retried or rearmed; storage and test-window limits deny conservatively; source-level no-egress is not a platform firewall against malicious redeployment.
