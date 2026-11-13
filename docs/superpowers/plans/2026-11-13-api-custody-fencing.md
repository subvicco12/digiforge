# Offline API custody fencing implementation plan

Goal: close deferred-callback and credential-rotation acceptance races without adding a live sender or changing WordPress runtime.
Architecture: reuse SyntheticFinalSender, existing witness leases/journals, and strict GET allowlist. Pin a positive safe-integer custody generation per send; check it at actual fetch callback and after response lifecycle. Custody flags must be literal true. No restore, deployment or credential provisioning.
Authority: owner accelerated PR1260 engineering directive; existing DURABLE-EMERGENCY-FENCE-QUALIFICATION and dispatcher SECURITY-COMPLETION. No new approval gate requested by owner.

- [x] Add focused adversarial tests in contracts/cloudflare-dispatcher/test/custody-fencing.test.mjs: delayed custody callback after deadline/revocation; rotation during headers/body; strict flags/generation; retry/revocation/emergency behavior. Run to record failures.
- [x] Modify sender.mjs minimally; forward generation in authority-service.mjs and supply explicit synthetic generation in test/support.mjs. Keep generation independent from grant generation. Rerun focused tests, then existing dispatcher contract tests because shared sender changed.
- [x] Reuse focused PHP emergency-fence tests for real local lock/append/inode regressions; no hosted durability claim. Add narrowly scoped offline host-evidence evaluator only if existing contracts lack one.
- [x] Read current companion1263 contract; record compatibility and proposed custody-port requirement as handoff, no companion edit.
- [x] Record exact changed files, fault outcomes, HOST_FAIL_STOP_REQUIRED gates, credentials/recovery limitations, runtime preservation. Commit/push only PR1260; inspect exact-head CI and security gates. Upload reproducible evidence and verify Drive bytes.

Review focus: no callback after deadline; no accepted evidence across custody rotation; no truthy credential permissions; no lease release while physical work uncertain; no hosted/platform certification from synthetic claims.
