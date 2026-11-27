# Stage A private caller implementation plan

Base25029994; offline source/testing only. Received user message ends mid-sentence; no additional authorization inferred.

1. Preserve the frozen seven-module target/build b0d29d9f7b362c420b5c56143315ebd1e0cde92d7f00fe4bdff3f46a9836ca1e and all489 WordPress runtime files. Implement separate private caller, not a new target/proxy.
2. Authenticated initiation proposal: Cloudflare Workflows management API manual instance creation, no public Worker HTTP handler, schedule or queue. Runtime token never enters the Worker. Requires future explicitly approved operator/IAM and additional Worker+Workflow+SQLite caller DO resources; none created.
3. Durable journal consumes one explicit operator request ID and persists a unique target intent BEFORE dispatch. A dispatch latch survives timeout/lost ACK/restart/replayed Workflow step. UNKNOWN blocks all further advance; no resend or new intent on recovery. Caller receipt readback can recover its own committed receipt only.
4. Require exact approved target build before and after execution, exact expected next case index and bounded receipt validation. Preserve original driver behavior for existing offline fixtures; share its receipt validator rather than duplicate contracts.
5. Add adversarial tests, file-backed SQL persistence, manual Workflow/SDK shape tests, disabled deployment config and deterministic package/manifest. Run existing136 tests and full regressions/exact-head audit; canonical Drive readback.

Safety: fixed ADVANCE_ONE/READ_RECEIPT/RECONCILE operations only; no case/fault/reset selection; private service binding DF_STAGE_A; default HTTP503; global egress seal; retries0; no rollback handler/rearm; account/cost/operator permissions and hosted initiation remain unverified.
