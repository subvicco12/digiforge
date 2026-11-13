# Independent dispatch security implementation plan

Goal: extend the existing offline dispatcher with an authenticated independent authority boundary, without creating infrastructure or a production execution path.

Architecture decision: use an independently administered recovery/final-dispatch authority, outside BOTH Cloudflare dispatcher/DO and Hostinger restoration domains. Deployment freezes and provider invalidation are required supporting controls. A same-administrator third database does not qualify. Permanent disabled fallback remains mandatory until independent administration, custody and recovery are proven.

1. Add failing behavioral tests for signed workload certificates, version floor, custody evidence, independent journal and nonce admission, signed client responses and final physical dispatch fencing.
2. Implement an explicit-initialization, CAS SQLite reference authority journal. This is a synthetic reference store, NOT a certified unrewindable service. No rearm/reset/restore API.
3. Add a fixed-operation signed authority client/service. No arbitrary URLs or callbacks cross the client boundary. The service owns the existing runtime and sender. Owner session and stop signatures remain independently required.
4. Wrap the existing witness to anchor every state transition outside the two rollbackable stores; enforce current workload and custody admission at final physical dispatch. No network inside SQL transactions.
5. Test coordinated two-store rollback after acknowledged stop, partitions, lost writes, stale workload keys, version floors, session replay, unknown leases and concurrent operations. Preserve the original all-trusted-store rollback reproducer.
6. Document administrator boundaries, conditional costs, complete future credential invalidation/migration/rollback plan, deployment gates and fault matrix. Run all regressions, exact-head CI and sanitized Drive readback. No installation release.

Review focus: asynchronous certificate expiry/revocation races; secret-safe responses; failed stop acknowledgement and recovered storage; independent journal rollback; physically in-flight drain. Every failure denies or retains UNKNOWN; no automatic retries or rearm.
